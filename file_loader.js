/**
 * Evaluator which files are invoked the upload iterator
 */
function file_iterator_predicate(info) {
    return info.name.slice((info.name.lastIndexOf(".") - 1 >>> 0) + 2)
        === "mrxs";
}

/**
 * Iterator, call uploader for ANY file info you deem necessary
 */
const regExp = /^(.*?([0-9]{4})[_-]([0-9]+).*)\.mrxs$/;
const fallBackRegExp = /^(.*)\.mrxs$/;
function parseFileName(name) {
    let match = regExp.exec(name);
    if (!Array.isArray(match)) {
        match = fallBackRegExp.exec(name);
        if (!Array.isArray(match)) {
            return {};
        }
        return {
            name: match[1]
        };
    } else {
        return {
            name: match[1],
            year: match[2],
            biopsy: match[3]
        };
    }
}

function upload_iterator(infoNode, uploader, errorHandler) {
    const fileInfo = infoNode["."];

    //get name without suffix, year and biopsy from the file [...].mrxs
    let {name, year, biopsy} = parseFileName(fileInfo.name);
    if (!name) {
        errorHandler(`File '${fileInfo.name}' failed to upload: file does not match any pattern!`);
        return false;
    }
    fileInfo.isMrxs = true;
    const binRoot = infoNode[".."]?.[name];
    if (!binRoot) {
        errorHandler(`MRXS data folder missing! Searching for folder: ${name}`);
        return false;
    }

    for (const bin in binRoot) {
        const binFile = binRoot[bin]?.["."];
        if (binFile) {
            binFile.isMrxs = false;
            uploader(binFile, year, biopsy);
        } // todo else fail?
    }
    uploader(fileInfo, year, biopsy);
}






const UI = {
    set visibleUploadPanel(value) {
        document.getElementById("import").style.display = value ? 'block' : 'none';
    },
    get submitButton() {
        return document.getElementById("start-upload");
    },
    get monitorButton() {
        return document.getElementById("start-monitor");
    },
    get form() {
        return document.getElementById("uploader");
    },
    get uploadFileList() {
        return document.getElementById("file-drag-drop").files
    },
    get inputBiopsy() {
        return document.getElementById("biopsy-uploader");
    },
    get inputFileInfo() {
        return document.getElementById("file-uploader");
    },
    get inputYear() {
        return document.getElementById("year-uploader");
    },
    get inputMetaField() {
        return document.getElementById("meta-uploader");
    },
    get inputChecksumField() {
        return document.getElementById("checksum-uploader");
    },
    get inputMiraxFileName() {
        return document.getElementById("mirax-filename-uploader");
    },
    get inputFileName() {
        return document.getElementById("filename-uploader");
    },
    get formSubmitButton() {
        return document.getElementById("send-one-file");
    },
    get progress() {
        return document.getElementById("progress");
    },
    showError(title, ...args) {
        document.getElementById("progress-error").innerHTML = `<div class="error-container">${title}</div>`;
    }
}

const preventExit = () => "Files are still uploading. Are you sure?";

//todo prevent blur not working
const preventBlur = () => "Hiding this tab or window from focus will penalize running session and the uploading might stop. For switching to different tabs, leave this tab in a separate browser window.";


class Uploader {
    constructor() {
        const self = this;
        this.running = false;
        this.jobTimeout = 86400000; //one day
        this.jobCheckRoutinePeriod = 30000; //30 secs per slide;
        this.chunkUploadSizeLimit = 26214400;
        this.chunkUploadParallel = 20;

        window.onbeforeunload = () => {
            return self.running ? "Files are still uploading. Are you sure?" : null;
        };
        window.onblur = () => {
            return self.running ? "Hiding this tab or window from focus will penalize running session and the uploading might stop. For switching to different tabs, leave this tab in a separate browser window." : null;
        };

        $(UI.submitButton).on("click", () => self.start());
        $(UI.monitorButton).on("click", () => self.start({monitorOnly: true}));

        //todo remove, for debug reasons
        $("#remove-upload").on("click", () => {
            if (confirm("Really delete all uploaded data on the server? Present for testing purposes only.")) {
                self.customRequest(
                    () => self.formSubmit("clean", {handler: ()=>{}}, false));
            }
        });

        $(UI.form).ajaxForm({
            beforeSubmit: (arr, form, options) => self._onBeforeSubmit(arr, form, options),
            uploadProgress: (e, p, t, pp) => self._onUploadProgress(t, pp),
            success: () => self._onUploadSuccess(),
            complete: (xhr) => {
                let success = false, data;
                try {
                    data = JSON.parse(xhr.responseText);
                    success = data.status === "success";
                    console.log("Server response:", data);
                } catch (e) {
                    console.error(e, xhr.statusText);
                    data = {
                        status: "error",
                        message: "Unknown error.",
                        payload: xhr.statusText
                    };
                }

                const willContinue = self._onUploadFinish(success, data);
                if (willContinue) {
                    self._uploadStep();
                } else {
                    self._uploadBulkStep(false);
                }
            }
        });

        this.setFormHandlers(undefined);
    }

    /**
     *
     * @param opts options
     * @param {boolean} opts.monitorOnly if true, only monitoring takes place without trying to do actual uploading and processing
     */
    start(opts={}) {
        if (this.running) {
            UI.showError("Upload is running!");
            return;
        }
        this.running = true;

        opts.monitorOnly = opts.monitorOnly || false;

        if (opts.monitorOnly) {
            $(UI.progress).html("<h2 class=\"f2-light\">Uploading and Monitoring</h2><p>This is only a monitoring process. It is safe to close the window anytime.</p>");
        } else {
            $(UI.progress).html("<h2 class=\"f2-light\">Uploading and Monitoring</h2><p>Hiding this tab or window from focus will penalize running session and the uploading might stop. For switching to different tabs, leave this tab <b>opened and focused</b> in a separate browser window.</p>");
        }
        //todo remove?
        this.monitorOnly = opts.monitorOnly;

        this.startUploading(opts.monitorOnly);
        UI.visibleUploadPanel = false;

        this._uploadedFileNames = [];
    }

    finish(error="") {
        if (!this.running) {
            return;
        }
        this.running = false;

        if (error) {
            UI.showError(error);
        }
        //todo do not show probably, force refresh
        //UI.visibleUploadPanel = true;

        const self = this;

        $("#analysis").css('display', 'block');
        // $("#send-one-file-analysis").on('click', function () {
        //     if (self._analysisRun) {
        //         $("#analysis-message").removeClass("error-container").html("Still processing please wait...");
        //     }
        //
        //     const biopsy = $("#request-analysis").val().trim();
        //     const eventName = $("#event-name-input").val().trim();
        //     console.log("Sending analysis request for biopsy", biopsy, "event", eventName);
        //
        //     self._analysisRun = true;
        //     fetch('server/analysis.php', {
        //         method: "POST",
        //         mode: 'cors',
        //         cache: 'no-cache',
        //         credentials: 'same-origin',
        //         headers: {
        //             'Content-Type': 'application/json',
        //             'Access-Control-Allow-Origin': '*'
        //         },
        //         body: JSON.stringify({
        //             biopsy: biopsy,
        //             year: //todo
        //             algorithm: {
        //                 name: eventName
        //             },
        //             ajax: true
        //         })
        //     }).then(response => response.json()).then(data => {
        //         console.log('Analysis', data);
        //         $("#analysis-message").removeClass("error-container").html(data.payload);
        //         self._analysisRun = false;
        //         self.monitorAll(eventName);
        //     }).catch(e => {
        //         console.log('Analysis Error', e);
        //         $("#analysis-message").addClass("error-container").html(e.payload || e);
        //         self._analysisRun = false;
        //     });
        // });

        const files = this._uploadedFileNames;
        $("#send-all-analysis").on('click', function () {
            if (self._analysisRun) {
                $("#analysis-message").removeClass("error-container").html("Still processing please wait...");
            }
            //todo change event approach to algo approach
            const eventName = $("#event-name-input").val().trim();
            console.log("Sending analysis request for file list", files, "event", eventName);

            self._analysisRun = true;
            fetch('server/analysis.php', {
                method: "POST",
                mode: 'cors',
                cache: 'no-cache',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Access-Control-Allow-Origin': '*'
                },
                body: JSON.stringify({
                    fileList: files,
                    algorithm: {
                        name: eventName
                    },
                    ajax: true
                })
            }).then(response => response.json()).then(data => {
                if (data.status === "success") {
                    console.log('Analysis', data);
                    $("#analysis-message").removeClass("error-container").html(data.payload);
                    self._analysisRun = false;
                    self.monitorAll(eventName);
                } else {
                    console.log('Analysis Error', data);
                    const details = data.payload ? `<code>${data.payload}</code>` : "";
                    $("#analysis-message").addClass("error-container").html(`${data.message}${details}`);
                    self._analysisRun = false;
                }
            }).catch(e => {
                console.log('Analysis Error', e);
                $("#analysis-message").addClass("error-container").html(e.payload || e);
                self._analysisRun = false;
            });
        });
        this._uploadedFileNames = [];
    }

    customRequest(job) {
        //by default one bulk of one job unit
        this._joblist = [{
            jobList: [job]
        }];
        this._bi = -1; //bulk steps increases by 1
        this._uploadBulkStep();
    }


///////////////////////////////
///  Form Helpers
///////////////////////////////

    /**
     *
     * @param bulkItem bulk item data
     * @param onFinish perform action when query finished - returns true if the bulk job should continue
     */
    setFormHandlers(bulkItem, onFinish=(success, json)=>{}) {

        if (typeof bulkItem !== "object") {
            //not an upload job but different func to execute
            this._onBeforeSubmit = () => {};
            this._onUploadProgress = (total, percentComplete) => {};
            this._onUploadSuccess = () => {};
            this._onUploadFinish = onFinish.bind(this);
        } else {
            this._onBeforeSubmit = (arr, form, options) => {
                const file = arr.find(x => x.name === "uploadedFile")?.value;
                const year = arr.find(x => x.name === "year")?.value;

                if (!file) throw "Invalid data! No file data provided!";
                if (!year) throw `Invalid data! Relative path not computed for file ${file.name}!`;

                this.updateBulkElementDownloading(this._bi, bulkItem, this._i-1);

                return true;
            };
            this._onUploadProgress = (total, percentComplete) => {
                const percentVal = percentComplete + '%';
                $('#bar1').width(percentVal);
                $('#percent1').html(percentVal);
            };
            this._onUploadSuccess = () => {
                //nothing...
            };
            this._onUploadFinish = (success, json) => {
                if (!success) {
                    this.updateBulkElementError(this._bi, json.message, json.payload);
                }

                //verify only when checksum client-side computed
                if (bulkItem.checksum !== undefined && bulkItem.checksum !== json.payload) {
                    bulkItem.error = `Computed checksum locally: ${bulkItem.checksum}, on server: ${json.payload}`;
                    console.error(bulkItem.error);
                    this.updateBulkElementError(this._bi, `Checksum comparison on file ${bulkItem.fileInfo.name} failed!`,
                        `Computed checksum locally: ${bulkItem.checksum}, on server: ${json.payload}`);
                    return false;
                }
                return success;
            };
        }
    }

    formFill(opts, withFileData=true) {
        if (opts.handler) {
            this.setFormHandlers(undefined, opts.handler);
        } else {
            this.setFormHandlers(opts);
        }

        UI.inputBiopsy.value = opts.biopsy || "";
        UI.inputChecksumField.value = opts.checksum || "";
        UI.inputMiraxFileName.value = opts.miraxFile || "";

        if (opts.fileInfo) {
            if (withFileData) {
                const transfer = new DataTransfer();
                transfer.items.add(opts.fileInfo);
                UI.inputFileInfo.files = transfer.files;
            }
            UI.inputFileName.value = opts.fileInfo.name;
        } else {
            UI.inputFileName.value = opts.fileName || "";
        }
        UI.inputYear.value = opts.year || "";
        UI.inputMetaField.value = JSON.stringify({
            timeStamp: Date.now()
        });
    }

    formSubmit(command, opts=undefined, withFileData=true) {
        if (opts) this.formFill(opts, withFileData);

        const submit = UI.formSubmitButton;
        submit.value = command;
        UI.formSubmitButton.click();
    }

    //html
    createBulkProgressElement(title, index, bulk) {
        $(UI.progress).append(`<div id="bulk-${index}-container" class="py-2 px-4 m-2 rounded border pointer position-relative">
<h3>${title}</h3>
<div id="bulk-element-${index}" class="mt-1 mx-1">
<span class="m-2">Waiting in queue</span>
</div>
<code id="bulk-checksum-${index}" class="my-2 pl-3 d-block">MD5 Checksum: ${bulk.checksum || "not verified."}</code>
<div id="bulk-error-${index}" class="mt-1 mx-1"></div>
</div>`);
    }

    setAsMonitoring(index, monitoring=true) {
        const node = $(`#bulk-${index}-container`);
        if (monitoring) node.addClass("monitoring");
        else node.removeClass("monitoring");
    }

    updateBulkElementChecksum(index, checksum) {
        $(`#bulk-checksum-${index}`).html(`
            MD5 Checksum: ${checksum || "not verified."}
        `);
    }

    disableBulkElementChecksum(index) {
        $(`#bulk-checksum-${index}`).remove();
    }

    updateBulkElementError(index, error, details="") {
        details = details ? `<code>${details}</code>` : "";
        $(`#bulk-error-${index}`).append(`
          <div class="error-container">${error}${details}</div>
        `);
        // this.updateBulkElementChecksum(index, "---");
    }

    clearBulkElementError(index) {
        $(`#bulk-error-${index}`).html("");
    }

    updateBulkElementDownloading(index, bulkItem) {
        let container = $("#progress-active");
        if (!container.length || Number.parseInt(container.data('bulk')) !== index) {
            $(`#bulk-element-${index}`).html(`
            <div id="progress-done"></div>
          <div id="progress-active" data-bulk="${index}"></div>
        `);
            container = $("#progress-active");
        } else {
            const jobId = container.data('id');
            if (!isNaN(Number.parseInt(jobId))) {
                const prevBulk = this._joblist[index].data[jobId];
                $("#progress-done").append(`
<div class="flex-row"><div class="flex-1"><span class="material-icons icon-success">done</span> ${prevBulk.fileName}</div><div class="flex-1" id="bulk-${index}-item-${jobId}"></div></div>`);
            }
        }
        container.data('id', bulkItem.index);
        container.html(`<span class="flex-1">${bulkItem.fileName}<span class="AnimatedEllipsis"></span></span>      
    <span class="flex-1 flex-row">
        <span class='percent mx-2' id='percent1'>0%</span>
        <span class='border rounded d-inline-block' style="width: calc(100% - 60px);"><span class="bar" id="bar1"></span></span></span>`);
    }

    updateBulkElementProcessing(index, customMessage="Currently processing", ellipsis=true) {
        $(`#bulk-element-${index}`).html(`
          <div class="Label mt-2 px-2 py-1">
            <span>${customMessage}</span>
            ${ellipsis ? '<span class="AnimatedEllipsis"></span>' : ''}
        </div>
        `);
    }

    updateBulkElementFinished(index, message=undefined) {
        //todo check mark
        $(`#bulk-element-${index}`).html(`
          <div class="Label mt-2 px-2 py-1" 
          style="background-color: var(--color-bg-success) !important; border-color: var(--color-border-success)">
           <span class="material-icons" style="font-size: 9px">done</span> <span>${message ? message : 'Done'}</span>
        </div>`);
    }

    copyBulkItem(item, withFileInfo = false) {
        return {
            fileInfo: withFileInfo && item.fileInfo,
            biopsy: item.biopsy,
            year: item.year,
            fileName: item.fileName,
            handler: undefined,
            index: item.index,
        }
    }

///////////////////////////////
///  CORE
///////////////////////////////

    _monitoring = async (routine, tstamp, timeout, isMonitoringOnly, eventName, updateUI, updateUIFinish, updateUIError, stopMonitoring) => {
        // switch checking
        if (!eventName && routine.uploaded) {
            //special upload event name
            eventName = "mirax-importer";
        }

        const response = await fetch(UI.form.getAttribute("action"), {
            method: "POST",
            headers: {
                "Content-Type":"application/json",
            },
            body: JSON.stringify({
                command: "checkFileStatus",
                biopsy: routine.biopsy,
                year: routine.year,
                fileName: routine.fileName,
                eventName: eventName || "", //todo re-write to algorithm specs and parse algo specs
            })
        });
        let data = await response.json();
        if (data.status !== "success" || typeof data.payload !== "object") {
            updateUIError("Failed to upload file: please, try again.", data.message);
            stopMonitoring();
            return;
        }

        data = data.payload;

        console.log("Check file: status", data["status"]);
        //todo check whether necessary to inspect if multiple files had been uploaded - employ session id?
        // if (tstamp - new Date(data.tstamp).getTime() > timeout) {
        //     updateUIError("Failed to upload file: timed out. Please, try again.");
        //     clearInterval(id);
        //     return;
        // }

        if ( data["status"] === undefined) {
            data["status"] = routine.uploaded ? "uploaded" : "not-yet-processed";
        }

        switch (data["status"]) {
            case "uploaded":
                if (!routine.uploaded) updateUI("File is uploaded.<br>Biopsy: <b>" + data["biopsy"] + "</b>. You can start the analysis, processed files will be ignored. File is not yet available to the viewer.", false);
                routine.uploaded = true;
                break;
            case "tiff-generated":
                updateUI("File is uploaded. <br>Biopsy: <b>" + data["biopsy"] + "</b>. Tiff file is generated - the file is now available in the browser.", false);
                stopMonitoring();
                break;
            case "tiff-failed":
                updateUI("File is uploaded. <br>Biopsy: <b>" + data["biopsy"] + "</b>. The file can be processed but not viewed: tiff file not generated!", false);
                stopMonitoring();
                break;
            case "not-yet-processed":
                updateUI("File not processed. It might be waiting in a queue.", false);
                break;
            case "processing":
                updateUI();
                break;
            case "processing-finished":
                updateUIFinish("The file has been successfully uploaded and processed.");
                stopMonitoring();
                return;
            case "failed":
                updateUIError("The processing of this file failed. " + (eventName ? `Analysis ${eventName} aborted.`:""));
                if (!eventName || eventName !== "mirax-importer") stopMonitoring();
                return;
            default:
                updateUIError("Unknown error. Some processes might have finished.", data.message);
                console.error(`Invalid server response <code>Unknown file status ${data['status']}</code>`, data);
                stopMonitoring();
                return;
        }

        if (Date.now() - tstamp > timeout) {
            updateUI("Timed out. The session has been running too long.");
            stopMonitoring();
        }
    }

    monitorBulk(bulkIndex, eventName) {
        const routine = this._joblist[bulkIndex]?.checkRoutine;

        if (routine) {
            this.monitor(routine, bulkIndex, eventName);
        }
    }

    monitor(object, htmlListIndex, eventName) {
        if (object.intervalId) {
            return; //running
        }

        const updateUI = this.updateBulkElementProcessing.bind(this, htmlListIndex);
        const updateUIFinish = this.updateBulkElementFinished.bind(this, htmlListIndex);
        const updateUIError = this.updateBulkElementError.bind(this, htmlListIndex);
        const stopMonitor = (() => {
            if (object.intervalId) {
                clearInterval(object.intervalId);
                delete object.intervalId;
                this.setAsMonitoring(htmlListIndex, false);
            }
        }).bind(this);

        const tstamp = Date.now(),
            timeout = this.jobTimeout,
            self = this,
            isMonitoringOnly = this.monitorOnly,
            periodTimeout = this._joblist?.length > 0  //scale with job count
                ? this._joblist.length * this.jobCheckRoutinePeriod : this.jobCheckRoutinePeriod;

        //override
        stopMonitor(object);

        object.intervalId = setInterval(() => {
            self._monitoring(
                object, tstamp, timeout, isMonitoringOnly, eventName,
                updateUI, updateUIFinish, updateUIError, stopMonitor
            );
        }, periodTimeout);

        //run immediately
        self._monitoring(
            object, tstamp, timeout, isMonitoringOnly, eventName,
            updateUI, updateUIFinish, updateUIError, stopMonitor
        );
        this.setAsMonitoring(htmlListIndex);
    }

    monitorAll(eventName=undefined) {
        for (let i = 0; i < this._joblist.length; i++) {
            this.monitorBulk(i, eventName);
        }
    }

    _uploadBulkStep(monitor=true) {
        if (this._bi >= 0 && monitor) { //if routine was skipped, do not initiate checking
            this.monitorBulk(this._bi);
        }

        if (this._bi >= this._joblist.length - 1) {
            this.finish();
            return;
        }
        this._bi++;
        this._i = 0;

        this._uploadStep();
    }

    _uploadStep() {
        const jobs = this._joblist[this._bi].jobList;

        if (this._i >= jobs.length) {
            return this._uploadBulkStep();
        }

        const executor = jobs[this._i++];
        if (typeof executor === "function") {
            return executor(); // not a great design, relies on usage of the firm submit
        }
        //not a great design, relies on 'not' using the submit

        if (executor?.handler.apply(this)) {
            return this._uploadStep();
        }
        return this._uploadBulkStep();
    }

    startBulkUpload(verifyChecksumFinished=true) {
        const {bulkList, monitorOnly} = this._sessionReady;
        if (verifyChecksumFinished && !this.checksumFinished(bulkList)) {
            alert("Checksum computation still not finished, please start uploading after checksums are computed.");
            return;
        }

        delete this._sessionReady;
        $(UI.progress).html("");

        this._joblist = [];

        for (let i = 0; i < bulkList.length; i++) {
            const bulk = bulkList[i];
            bulk.jobList = [];

            bulk.index = i;

            const targetElem = bulk.data.find(x => x.fileInfo.name.endsWith("mrxs"));
            if (bulk.parseErrors.length > 0) {
                this.createBulkProgressElement(targetElem?.fileInfo?.name || "Item " + i, i, bulk);
                this.updateBulkElementError(i, bulk.parseErrors.join("<br>"));
                continue;
            }

            const bulkData = bulk.data;

            for (let j = 0; j < bulkData.length; j++) {
                const elem = bulkData[j];
                elem.miraxFile = targetElem.fileInfo.name;
                elem.index = j;
                if (!monitorOnly) {
                    bulk.jobList.push(this.formSubmit.bind(this, "uploadFile", elem));
                }
            }

            //copy main element and perform file exists check that skips the bulk job if

            //todo copies change will not be reflected :/
            const copy = this.copyBulkItem(targetElem);
            copy.handler = (success, json) => {
                if (!success) {
                    json.status = "error";
                    json.message = "Target skipped: unknown error.";
                    this.updateBulkElementError(this._bi, json.message);
                    return false;
                }

                const data = json.payload;
                if (data && typeof data === "object") {
                    switch (data["status"]) {
                        case "finished":
                            this.updateBulkElementFinished(this._bi);
                            return false;
                        case "uploaded":
                        case "converting":
                        case "processing-failed":
                        case "ready":
                            //if (!data.created_delta || data.created_delta < this.jobTimeout) { //do not overwrite if still within timeout
                                const msg = this.monitorOnly ? "File is " : "Uploading not initiated: file is ";
                                this.updateBulkElementProcessing(this._bi,msg + " already uploaded but not yet processed. This has to be started manually. <br>Biopsy: <b>" + data["biopsy"] + "</b>", false);
                                this.disableBulkElementChecksum(this._bi);
                                this._uploadedFileNames.push(targetElem.fileName); //include to uploaded files
                                return false;
                            // }
                            // return true;
                        case "processing":
                            this.updateBulkElementProcessing(this._bi);
                            return false;
                        default:
                            return true;
                    }
                }
                return false;
            };

            bulk.jobList.unshift(this.formSubmit.bind(this, "checkFileStatus", copy));

            this.createBulkProgressElement(targetElem.fileInfo?.name || "Item " + (i+1), i, bulk);

            if (!monitorOnly) {
                //bulk upload finished, now perform some processing
                const copy2 = this.copyBulkItem(targetElem);
                copy2.handler = () => {
                    this.updateBulkElementProcessing(this._bi, "Finishing the upload process"); //not online - message changed
                    this._uploadedFileNames.push(targetElem.fileName);
                    return true;
                }
                bulk.jobList.push(this.formSubmit.bind(this, "fileUploadBulkFinished", copy2, false));
            }

            bulk.checkRoutine = {
                biopsy: targetElem.biopsy,
                year: targetElem.year,
                fileName: targetElem.fileName,
            };
        }

        this._joblist = bulkList;
        this._bi = -1; //bulk steps increases by 1
        this._uploadBulkStep();
    }

    startUploading(monitorOnly) {
        const fileList = UI.uploadFileList,
            iterator = [],
            hierarchy = {};

        for (const file of fileList) {
            const name = file.name;
            if (name === "." || name === "..") continue;

            const path = file.webkitRelativePath.split("/");
            let ref = hierarchy;
            for (let segment of path) {
                ref[segment] = ref[segment] || {"..": ref};
                ref = ref[segment];
            }

            ref["."] = file;
            if (file_iterator_predicate(file)) {
                iterator.push(ref);
            }
        }

        if (iterator.length < 1) {
            console.error("Cannot upload files: no valid file");
            this.finish("Failed to upload files: no valid files provided. Make sure you upload the correct folder with <i>.mrxs</i> file, not the child folder with the data files only.");
            return;
        }

        const bulkList = [];
        for (let target of iterator) {
            const uploadBulk = [];
            const parseErrors = [];

            upload_iterator(target, (fileInfo, year, biopsy) => {
                uploadBulk.push({
                    fileInfo: fileInfo,
                    biopsy: biopsy,
                    year: year,
                    fileName: fileInfo.name,
                });
            }, (error) => {
                parseErrors.push(error);
                uploadBulk.push({
                    fileInfo: target["."],
                    fileName: target["."].name,
                });
            });

            bulkList.push({
                data: uploadBulk,
                parseErrors: parseErrors
            });
        }

        this._sessionReady = {bulkList, monitorOnly};
        this.middleStepVerifyParsedFiles();
    }

    middleStepVerifyParsedFiles() {
        const data = ["<h1 class='f2-light'>Verification Step</h1><p>Please verify that all file names are valid, i.e. year and biopsy numbers are recognized correctly and files are complete.</p><br>"];
        const _this = this;

        function printTitleMeta(item) {
            return `&emsp;<span style="font-size: 12pt !important;">${item.year || '<span style="color: var(--color-text-danger)">unknown</span>'}  |  ${item.biopsy || '<span style="color: var(--color-text-danger)">unknown</span>'}</span>`;
        }

        function createCheckBulkNode(title, index, bulk, targetElem) {
            if (bulk.parseErrors.length > 0) {
                return `<div class="py-2 px-4 m-2 rounded border pointer"><h3>${title}  ${printTitleMeta(targetElem)}</h3><div 
id="bulk-element-${index}" class="mt-1 mx-1"><div class="error-container">Invalid MRXS File! This file won't 
be uploaded. Biopsy <b>${targetElem.biopsy}</b>. Year <b>${targetElem.year}</b><code>${bulk.parseErrors.join("<br>")}</code></div></div></div>`;
            }
            return `<div class="py-2 px-4 m-2 rounded border pointer"><h3>${title}  ${printTitleMeta(targetElem)}</h3>
<div id="bulk-element-${index}" class="mt-1 mx-1"><span class="m-2">File will be uploaded with <b>biopsy</b> number <b>${targetElem.biopsy}</b>. Year <b>${targetElem.year}</b></span></div>
<code id="bulk-checksum-${index}" class="my-2 pl-3 d-block">MD5 Checksum: ${bulk.checksum || "start manually if required."}</code>
</div>`;
        }

        const {bulkList} = this._sessionReady;
        for (let i = 0; i < bulkList.length; i++) {
            const bulk = bulkList[i];
            bulk.index = i;
            const targetElem = bulk.data.find(x => x.fileInfo.name.endsWith("mrxs"));

            if (!targetElem.year || !targetElem.biopsy) bulk.parseErrors.push("Invalid Year or Biopsy number: this file won't be uploaded!");
            data.push(createCheckBulkNode(targetElem.fileInfo?.name || "Item " + (i + 1), i, bulk, targetElem));
        }
        let btn = document.createElement("button");
        btn.classList.add("btn");
        btn.onclick = this.startBulkUpload.bind(this);
        btn.id = "mid-step-verify-upload";
        btn.innerText = "Start Uploading";
        $(UI.progress).html(data.join(""));
        UI.progress.appendChild(btn);

        btn = document.createElement("button");
        btn.classList.add("btn", "ml-2");
        btn.onclick = this.middleStepRequireCheckSumCheck.bind(this);
        btn.id = "mid-step-checksum-verify-upload";
        btn.innerText = "Upload + auto Checksum Verification (slower)";
        UI.progress.appendChild(btn);
        this.checksumFinished = (bulkList) => true; //rewritten if started
    }

    middleStepRequireCheckSumCheck() {
        const {bulkList} = this._sessionReady;
        for (let i = 0; i < bulkList.length; i++) {
            const bulk = bulkList[i];
            this.computeBulkMD5(bulk);
            this.updateBulkElementChecksum(bulk.index, "computing...");
        }
        $("#mid-step-checksum-verify-upload").attr("disabled", true);
        $("#mid-step-verify-upload").attr("disabled", true);

    }

    computeBulkMD5(bulk) {
        //from https://stackoverflow.com/questions/39112096/calculate-md5-hash-of-a-large-file-using-javascript
        function readChunked(file, chunkCallback, endCallback) {
            var fileSize   = file.size;
            var chunkSize  = 4 * 1024 * 1024; // 4MB
            var offset     = 0;

            var reader = new FileReader();
            reader.onload = function() {
                if (reader.error) {
                    endCallback(reader.error || {});
                    return;
                }
                offset += reader.result.length;
                // callback for handling read chunk
                // TODO: handle errors
                chunkCallback(reader.result, offset, fileSize);
                if (offset >= fileSize) {
                    endCallback(null);
                    return;
                }
                readNext();
            };

            reader.onerror = function(err) {
                endCallback(err || {});
            };

            function readNext() {
                var fileSlice = file.slice(offset, offset + chunkSize);
                reader.readAsBinaryString(fileSlice);
            }
            readNext();
        }

        function getMD5(blob, cbProgress) {
            return new Promise((resolve, reject) => {
                var md5 = CryptoJS.algo.MD5.create();
                readChunked(blob, (chunk, offs, total) => {
                    md5.update(CryptoJS.enc.Latin1.parse(chunk));
                    if (cbProgress) {
                        cbProgress(offs / total);
                    }
                }, err => {
                    if (err) {
                        reject(err);
                    } else {
                        // TODO: Handle errors
                        var hash = md5.finalize();
                        var hashHex = hash.toString(CryptoJS.enc.Hex);
                        resolve(hashHex);
                    }
                });
            });
        }

        const _this = this;
        const doneMessage = "verified automatically.";
        // let chunks = [];
        this.checksumFinished = (bulkList) => bulkList.every(b => b.checksum === doneMessage); //rewritten

        Promise.all(
            bulk.data.filter(data => data.fileInfo).map(data => {
                console.log(data);
                return getMD5(data.fileInfo, p => console.log(p)).then(chunk => {
                    //chunks.push(chunk);
                    data.checksum = chunk;
                });
            })
        ).then(() => {
            //just compare file-wise
            //bulk.checksum = CryptoJS.MD5(chunks.join("")).toString(CryptoJS.enc.Hex);
            bulk.checksum = doneMessage;
            _this.updateBulkElementChecksum(bulk.index, bulk.checksum);
            const {bulkList} = _this._sessionReady;
            if (_this.checksumFinished(bulkList)) {
                _this.startBulkUpload(false);
            }
        });

    }
}
