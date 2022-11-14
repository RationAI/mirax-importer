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
const regExp = /^((\w+)[-_](\w+)[-_](\w+).*)\..*$/;
function upload_iterator(infoNode, uploader, errorHandler) {
    //upload the mrxs folder

    //file names: identifier(- / _)year(- / _)reqest_id(- / _)
    const fileInfo = infoNode["."];
    const match = regExp.exec(fileInfo.name);
    if (!Array.isArray(match)) {
        errorHandler(`File '${fileInfo.name}' failed to upload: it does not match pattern [XXX]-[YEAR]-[ID]-...mrxs`);
        return false;
    }
    const name = match[1], request_id = match[3];

    if (!request_id) {
        errorHandler(`File '${fileInfo.name}' failed to upload: no request ID match pattern [XXX]-[YEAR]-[REQUEST ID]-...mrxs`);
        return false;
    }

    const bin_root = infoNode[".."]?.[name];

    if (!bin_root) {
        errorHandler(`MRXS data folder missing! Searching for folder: ${name}`);
        return false;
    }

    for (const bin in bin_root) {
        const bin_file = bin_root[bin]?.["."];
        if (bin_file) {
            uploader(bin_file, request_id, `${request_id}/${name}/${name}`);
        } // todo else fail?
    }
    uploader(fileInfo, request_id, `${request_id}/${name}`);
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
    get inputRequestId() {
        return document.getElementById("request-id-uploader");
    },
    get inputFileInfo() {
        return document.getElementById("file-uploader");
    },
    get inputRelativePath() {
        return document.getElementById("relative-path-uploader");
    },
    get inputMetaField() {
        return document.getElementById("meta-uploader");
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
        this.jobCheckRoutinePeriod = 5000; //5 secs per slide;
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
                    console.error(e, xhr.responseText);
                    data = {
                        status: "error",
                        message: "Unknown error.",
                        payload: e
                    };
                }

                const willContinue = self._onUploadFinish(success, data);
                if (willContinue) {
                    self._uploadStep();
                } else {
                    self._uploadBulkStep();
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
        $("#send-one-file-analysis").on('click', function () {
            if (self._analysisRun) {
                $("#analysis-message").removeClass("error-container").html("Still processing please wait...");
            }

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
                    request: $("#request-analysis").val(),
                    ajax: true
                })
            }).then(response => response.json()).then(data => {
                console.log('Analysis', data);
                $("#analysis-message").removeClass("error-container").html(data.payload);
                self._analysisRun = false;
                self.monitorAll();
            }).catch(e => {
                console.log('Analysis Error', e);
                $("#analysis-message").addClass("error-container").html(e.payload || e);
                self._analysisRun = false;
            });
        });
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
            //not an upload job but different request
            this._onBeforeSubmit = () => {};
            this._onUploadProgress = (total, percentComplete) => {};
            this._onUploadSuccess = () => {};
            this._onUploadFinish = onFinish.bind(this);
        } else {
            this._onBeforeSubmit = (arr, form, options) => {
                const file = arr.find(x => x.name === "uploadedFile")?.value;
                const relativePath = arr.find(x => x.name === "relativePath")?.value;

                if (!file) throw "Invalid data! No file data provided!";
                if (!relativePath) throw `Invalid data! Relative path not computed for file ${file.name}!`;

                this.updateBulkElementDownloading(this._bi, bulkItem, this._i-1);

                return true;

                // if (file.size < this.chunkUploadSizeLimit) {
                //     return true;
                // }
                //
                // //parallel? this.chunkUploadParallel
                // const self = this;
                // const upload = new tus.Upload(file, {
                //     endpoint: "server/tus/",
                //     retryDelays: [0, 3000, 5000, 10000, 20000],
                //     chunkSize: this.chunkUploadSizeLimit,
                //     metadata: {
                //         name: file.name,
                //         type: file.type,
                //         fileName: file.name,
                //         relativePath: relativePath,
                //     },
                //     onError: (e) => {
                //         console.error("Chunk error!", e);
                //         const willContinue = self._onUploadFinish(false, {
                //             status: "error",
                //             message: "Unknown error.",
                //         });
                //         if (willContinue) self._uploadStep();
                //         else self._uploadBulkStep();
                //     },
                //     onProgress: (bytesUploaded, bytesTotal) => {
                //         let percentage = Math.round(bytesUploaded / bytesTotal * 100);
                //         self._onUploadProgress(bytesTotal, percentage);
                //     },
                //     onSuccess: () => {
                //         console.log("Download %s from %s", upload.file.name);
                //         const willContinue = self._onUploadFinish(false, {
                //             status: "error",
                //             message: "Unknown error.",
                //         });
                //         if (willContinue) self._uploadStep();
                //         else self._uploadBulkStep();
                //     }
                // });
                //
                // //todo what about resumed stuff?
                // // // Check if there are any previous uploads to continue.
                // // upload.findPreviousUploads().then(function (previousUploads) {
                // //     // Found previous uploads so we select the first one.
                // //     if (previousUploads.length) {
                // //         upload.resumeFromPreviousUpload(previousUploads[0])
                // //     }
                // //
                // //     // Start the upload
                // //     upload.start()
                // // })
                // upload.start();
                //
                // return false; //do not proceed using the default form upload
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
                    this.updateBulkElementError(this._bi, json.message);
                }
                return success; //continue only if successful
            };
        }
    }

    formFill(opts, withFileData=true) {
        if (opts.handler) {
            this.setFormHandlers(undefined, opts.handler);
        } else {
            this.setFormHandlers(opts);
        }

        UI.inputRequestId.value = opts.requestId || "";

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
        UI.inputRelativePath.value = opts.relativePath || "";
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
        $(UI.progress).append(`<div class="py-2 px-4 m-2 rounded border pointer">
<h3>${title}</h3>
<div id="bulk-element-${index}" class="mt-1 mx-1"><span class="m-2">Waiting in queue</span></div>
</div>`);
    }

    updateBulkElementError(index, error) {
        $(`#bulk-element-${index}`).html(`
          <div class="error-container">${error}</div>
        `);
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
            requestId: item.requestId,
            relativePath: item.relativePath,
            fileName: item.fileName,
            handler: undefined,
            index: item.index,
        }
    }

///////////////////////////////
///  CORE
///////////////////////////////

    _monitoring = async (routine, tstamp, timeout, isMonitoringOnly, isProcessing, updateUI, updateUIFinish, updateUIError) => {
        const response = await fetch(UI.form.getAttribute("action"), {
            method: "POST",
            headers: {
                "Content-Type":"application/json",
            },
            body: JSON.stringify({
                command: "checkFileStatus",
                requestId: routine.requestId,
                relativePath: routine.relativePath,
                fileName: routine.fileName
            })
        });
        let data = await response.json(),
            id = routine.intervalId;

        if (data.status !== "success" || typeof data.payload !== "object") {
            updateUIError("Failed to upload file: please, try again.");
            clearInterval(id);
            delete routine.intervalId;
            return;
        }

        data = data.payload;

        console.log("Check file: status", data["session"]);
        //todo check whether necessary to inspect if multiple files had been uploaded - employ session id?
        // if (tstamp - new Date(data.tstamp).getTime() > timeout) {
        //     updateUIError("Failed to upload file: timed out. Please, try again.");
        //     clearInterval(id);
        //     return;
        // }

        switch (data["session"]) {
            case "uploaded":
                break;
            case "converting":
                updateUI("The file is being converted to a pyramidal tiff");
                break;
            case "ready":
                updateUI("File is uploaded but not yet processed. This has to be started manually. It is recommended to wait after all files are loaded.<br>Request ID: <b>" + data["request_id"] + "</b>. Do not close this window to observe the process.", false);
                break;
            case "processing":
                updateUI();
                break;
            case "finished":
                updateUIFinish("The file has been successfully uploaded and processed.");
                clearInterval(id);
                delete routine.intervalId;
                return;
            case "processing-failed":
                updateUIError("The processing of this file failed." + (isProcessing ? " Note: analysis in progress..":""))
                if (!isProcessing) {
                    clearInterval(id);
                    delete routine.intervalId;
                }
                return;
            default:
                updateUIError("Unknown error. Please, try again.");
                console.error(`Invalid server response <code>Unknown file status ${data['session']}</code>`, data);
                clearInterval(id);
                delete routine.intervalId;
                return;
        }

        if (Date.now() - tstamp > timeout) {
            updateUI("Timed out. The session has been running too long.");
            clearInterval(id);
            delete routine.intervalId;
        }
    }

    monitorBulk(bulkIndex, forProcessing) {
        const routine = this._joblist[bulkIndex]?.checkRoutine;

        if (routine) {
            this.monitor(routine, bulkIndex, forProcessing);
        }
    }

    monitor(object, htmlListIndex, forProcessing) {
        if (object.intervalId) {
            return; //running
        }

        const updateUI = this.updateBulkElementProcessing.bind(this, htmlListIndex);
        const updateUIFinish = this.updateBulkElementFinished.bind(this, htmlListIndex);
        const updateUIError = this.updateBulkElementError.bind(this, htmlListIndex);

        const tstamp = Date.now(),
            timeout = this.jobTimeout,
            self = this,
            isMonitoringOnly = this.monitorOnly,
            periodTimeout = this._joblist?.length > 0  //scale with job count
                ? this._joblist.length * this.jobCheckRoutinePeriod : this.jobCheckRoutinePeriod;

        object.intervalId = setInterval(() => {
            self._monitoring(
                object, tstamp, timeout, isMonitoringOnly, forProcessing,
                updateUI, updateUIFinish, updateUIError
            );
        }, periodTimeout);

        //run immediately
        self._monitoring(
            object, tstamp, timeout, isMonitoringOnly, forProcessing,
            updateUI, updateUIFinish, updateUIError
        );
    }

    monitorAll(processing=true) {
        for (let i = 0; i < this._joblist.length; i++) {
            this.monitorBulk(i, processing);
        }
    }

    _uploadBulkStep() {
        if (this._bi >= 0) { //if routine was skipped, do not initiate checking
            this.monitorBulk(this._bi, false);
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
        return this._uploadBulkStep(false);
    }

    startBulkUpload(bulkJobList, monitorOnly) {
        this._joblist = [];

        for (let i = 0; i < bulkJobList.length; i++) {
            const bulk = bulkJobList[i];
            bulk.jobList = [];

            bulk.index = i;

            if (bulk.parseErrors.length > 0) {
                const targetElem = bulk.data.find(x => x.fileInfo.name.endsWith("mrxs"));
                this.createBulkProgressElement(targetElem?.fileInfo?.name || "Item " + i, i, bulk);
                this.updateBulkElementError(i, bulk.parseErrors.join("<br>"));
                continue;
            }

            const bulkData = bulk.data;

            let targetElem = null;
            for (let j = 0; j < bulkData.length; j++) {
                const elem = bulkData[j];
                if (elem.fileInfo.name.endsWith("mrxs")) {
                    targetElem = elem;
                }
                elem.index = j;
                if (!monitorOnly) {
                    bulk.jobList.push(this.formSubmit.bind(this, "uploadFile", elem));
                }
            }

            //copy main element and perform file exists check that skips the bulk job if
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
                    switch (data["session"]) {
                        case "finished":
                            this.updateBulkElementFinished(this._bi);
                            return false;
                        case "uploaded":
                        case "converting":
                        case "processing-failed":
                        case "ready":
                            //if (!data.tstamp_delta || data.tstamp_delta < this.jobTimeout) { //do not overwrite if still within timeout
                                const msg = this.monitorOnly ? "File is " : "Uploading not initiated: file has been";
                                this.updateBulkElementProcessing(this._bi,msg + " uploaded but not yet processed. This has to be started manually. It is recommended to wait after all files are loaded.<br>Request ID: <b>" + data["request_id"] + "</b>", false);
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
                    return true;
                }
                bulk.jobList.push(this.formSubmit.bind(this, "fileUploadBulkFinished", copy2, false));
            }

            bulk.checkRoutine = {
                requestId: targetElem.requestId,
                relativePath: targetElem.relativePath,
                fileName: targetElem.fileName,
            };
        }

        this._joblist = bulkJobList;
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
            this.finish("Failed to upload files: no valid files provided.");
            return;
        }

        const bulkList = [];
        for (let target of iterator) {
            const uploadBulk = [];
            const parseErrors = [];

            upload_iterator(target, (fileInfo, requestId, relativePath) => {
                uploadBulk.push({
                    fileInfo: fileInfo,
                    requestId: requestId,
                    relativePath: relativePath,
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
        this.startBulkUpload(bulkList, monitorOnly);
    }
}