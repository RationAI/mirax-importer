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














const preventExit = () => "Files are still uploading. Are you sure?";

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
    showError(title, ...args) {

    }
}

class Uploader {
    constructor() {
        const self = this;
        this.running = false;

        $(UI.submitButton).on("click", () => self.start());
        $("#remove-upload").on("click", () => self.formSubmit("clean", {}, false))
        $(UI.form).ajaxForm({
            beforeSubmit: this.uploadBeforeSubmit.bind(this),
            uploadProgress: this.uploadProgress.bind(this),
            success: this.uploadSuccess.bind(this),
            complete: this.uploadFinish.bind(this)
        });
    }

    start() {
        if (this.running) {
            UI.showError("Upload is running!");
            return;
        }
        this.running = true;
        window.addEventListener("beforeunload", preventExit);

        this.startUploading();
        UI.visibleUploadPanel = false;
    }

    finish(error="") {
        if (!this.running) {
            return;
        }
        this.running = false;
        window.removeEventListener("beforeunload", preventExit);

        if (error) {
            UI.showError(error);
        }
        //todo cleanup
        UI.visibleUploadPanel = true;
    }

///////////////////////////////
///  Form Helpers
///////////////////////////////

    formFill(opts, withFileData=true) {
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

///////////////////////////////
///  Events
///////////////////////////////

    uploadBeforeSubmit() {
        document.getElementById("progress_div").style.display="block";
        const percentVal = '0%';
        $('#bar').width(percentVal)
        $('#percent').html(percentVal);
        console.log(percentVal);
    }

    uploadProgress(event, position, total, percentComplete) {
        const percentVal = percentComplete + '%';
        $('#bar').width(percentVal);
        console.log(percentVal);

        $('#percent').html(percentVal);
    }

    uploadSuccess() {
        const percentVal = '100%';
        $('#bar').width(percentVal);
        console.log(percentVal);
        $('#percent').html(percentVal);
    }

    uploadFinish(xhr) {
        let success = false, data;
        try {
            data = JSON.parse(xhr.responseText);
            success = data.status === "success";
            console.log(data);
        } catch (e) {
            console.error(e, xhr.responseText);
        }

        if (success) {
            $("#code").append(xhr.responseText + "\n\n");
            // document.getElementById("progress_div").style.display="none";

            //todo success...
            this._uploadStep();
        } else {
            $("#code").append(xhr.responseText + "\n\n Failure: skipping bulk job! \n\n");
            this._uploadBulkStep();
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
        jobs[this._i++]();
    }

    startBulkUpload(bulkJobList) {
        this._joblist = [];

        for (const bulk of bulkJobList) {
            //todo render html using
            bulk.jobList = [];

            if (bulk.parseErrors.length > 0) {
                //todo rendeer also errors from bulk.parseErrors
                continue;
            }

            const bulkData = bulk.data;

            let targetElem = null;
            for (const elem of bulkData) {
                if (elem.fileInfo.name.endsWith("mrxs")) {
                    targetElem = elem;
                }

                bulk.jobList.push(this.formSubmit.bind(this, "uploadFile", elem));
            }

            //bulk upload finished, now perform some processing
            bulk.jobList.push(this.formSubmit.bind(this, "fileUploadBulkFinished", targetElem, false));
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
                    relativePath: relativePath
                });
            }, (error) => {
                parseErrors.push(error);
            });

            bulkList.push({
                data: uploadBulk,
                parseErrors: parseErrors,
                runtimeErrors: [],
                runtimeWarnings: []
            });
        }
        this.startBulkUpload(bulkList);
    }
}