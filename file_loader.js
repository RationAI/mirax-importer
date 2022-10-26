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
        console.error(title, ...args);
    }
}

const preventExit = () => "Files are still uploading. Are you sure?";

//todo prevent blur not working
const preventBlur = () => "Hiding this tab or window from focus will penalize running session and the uploading might stop. For switching to different tabs, leave this tab in a separate browser window.";


class Uploader {
    constructor() {
        const self = this;
        this.running = false;

        window.onbeforeunload = () => {
            return self.running ? "Files are still uploading. Are you sure?" : null;
        };
        window.onblur = () => {
            return self.running ? "Hiding this tab or window from focus will penalize running session and the uploading might stop. For switching to different tabs, leave this tab in a separate browser window." : null;
        };


        $(UI.submitButton).on("click", () => self.start());

        //todo remove, for debug reasons
        $("#remove-upload").on("click", () => self.customRequest(
            () => self.formSubmit("clean", {handler: ()=>{}}, false))
        );
        $(UI.form).ajaxForm({
            beforeSubmit: () => self._onBeforeSubmit(),
            uploadProgress: (e, p, t, pp) => self._onUploadProgress(e, p, t, pp),
            success: () => self._onUploadSuccess(),
            complete: (xhr) => {
                console.log(xhr);
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
                    this._uploadStep();
                } else {
                    this._uploadBulkStep();
                }
            }
        });

        this.setFormHandlers(undefined);
    }

    start() {
        if (this.running) {
            UI.showError("Upload is running!");
            return;
        }
        this.running = true;

        $(UI.progress).html("<p>Note: uploading does not check for script finish time: submitted for processing means the job has been initiated and will eventually (tens of minutes) finish.</p>" +
            "<p>Hiding this tab or window from focus will penalize running session and the uploading might stop. For switching to different tabs, leave this tab <b>opened and focused</b> in a separate browser window.</p>");
        this.startUploading();
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
        //todo cleanup
        UI.visibleUploadPanel = true;
    }

    customRequest(job) {
        //by default one bulk of one job unit
        this._joblist = [{
            jobList: [job]
        }];
        this._bi = -1; //bulk steps increases by 1
        this._uploadBulkStep();
    }

    // customRequest() {
    //     //todo separate form with custom inputs
    //     if (this.running) {
    //         UI.showError("Upload is running!");
    //         return;
    //     }
    //     this.formSubmit.bind(this, "checkFileExists", copy)
    // }

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
            this._onBeforeSubmit = () => {};
            this._onUploadProgress = (event, position, total, percentComplete) => {};
            this._onUploadSuccess = () => {};
            this._onUploadFinish = onFinish.bind(this);
        } else {
            this._onBeforeSubmit = () => {
                this.updateBulkElementDownloading(this._bi, bulkItem, this._i-1);
            };
            this._onUploadProgress = (event, position, total, percentComplete) => {
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

    updateBulkElementProcessing(index, customMessage="Submitted for processing") {
        $(`#bulk-element-${index}`).html(`
          <div class="Label mt-2 px-2 py-1">
            <span>${customMessage}</span>
            <span class="AnimatedEllipsis"></span>
        </div>
        `);
    }

    updateBulkElementFinished(index) {
        //todo check mark
        $(`#bulk-element-${index}`).html(`
          <div class="Label mt-2 px-2 py-1" 
          style="background-color: var(--color-bg-success) !important; border-color: var(--color-border-success)">
           <span class="material-icons">done</span> <span>Done</span>
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

    _uploadBulkStep() {
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

    startBulkUpload(bulkJobList) {
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
                bulk.jobList.push(this.formSubmit.bind(this, "uploadFile", elem));
            }

            //copy main element and perform file exists check that skips the bulk job if
            const copy = this.copyBulkItem(targetElem);
            copy.handler = (success, json) => {
                const continues = !success || json?.payload !== true;
                if (!continues) {
                    json.status = "info";
                    json.message = "Target skipped: the target already exists.";
                    this.updateBulkElementError(this._bi, json.message); //todo just info
                }
                return continues;
            };
            bulk.jobList.unshift(this.formSubmit.bind(this, "checkFileExists", copy));

            this.createBulkProgressElement(targetElem.fileInfo?.name || "Item " + (i+1), i, bulk);

            //bulk upload finished, now perform some processing
            const copy2 = this.copyBulkItem(targetElem);
            copy2.handler = () => {
                this.updateBulkElementProcessing(this._bi);
                return true;
            }
            bulk.jobList.push(this.formSubmit.bind(this, "fileUploadBulkFinished", copy2, false));
        }

        this._joblist = bulkJobList;
        this._bi = -1; //bulk steps increases by 1
        this._uploadBulkStep();
    }

    startUploading() {
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
        this.startBulkUpload(bulkList);
    }
}