<?php

$upload_root = "/mnt/data/visualization/importer/data";
function file_uploaded($filename, $filepath, $request_id) {
    //shell_exec("vips tiffsave --tile --pyramid --compression=jpeg --Q=80 --tile-width 512 --tile-height 512 --bigtiff $filepath");

    global $upload_root;
    return shell_exec("$upload_root/my_script.sh 2>&1 | tee -a $upload_root/log.txt 2>/dev/null >/dev/null &");
}

///////////////////////////////
///  UTILS
///////////////////////////////

function erase_dirs() {
    global $upload_root;
    if(file_exists($upload_root)){
        $di = new RecursiveDirectoryIterator($upload_root, FilesystemIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ( $ri as $file ) {
            $file->isDir() ?  rmdir($file) : unlink($file);
        }
    }
}

function upload_file($temp, $target_file_name, $relative_directory) {
    $target_directory = target_upload_dir($relative_directory);

    global $upload_root;
    if (!is_writable($upload_root)) error("Missing permissions for the root directory!");

    if (!make_dir($target_directory)) error("Target directory '$target_directory' is not create-able!");
    if (!is_writable($target_directory)) error("Missing permissions for the target upload directory!");

    $target = "$target_directory/$target_file_name";

    //if (file_exists($target)) error("File failed to upload '$relative_directory/$target_file_name' - already exists!");

    if (move_uploaded_file($temp, $target)) {
        @chmod($target, 0640);
        return true;
    }
    return false;
}

function make_dir($path) {
    return is_dir($path) || mkdir($path, 0777, true);
}

///////////////////////////////
///  CORE
///////////////////////////////

function exception_handler(Throwable $exception) {
    set_error($exception->getMessage());
}

set_exception_handler('exception_handler');

function set_error($title, ...$args) { //todo
    echo "ERROR: $title  " . implode(" ", $args)  . "<br>";
}

function show_log(...$args) { //todo
    echo implode(" ", $args)  . "<br>";
}

function send_response($payload=null) {
    echo json_encode((object)array("status" => "success", "payload" => $payload));
    exit();
}

function error($title, $payload=null) {
    echo json_encode((object)array(
        "status" => "error",
        "message" => $title,
        "payload" => $payload ?? print_r($_POST, true),
    ));
    exit();
}

function target_upload_dir($relative_path) {
    //todo sanitize
    global $upload_root;
    return "$upload_root/$relative_path";
}

function target_upload_path($filename, $relative_path) {
    return  target_upload_dir($relative_path) . "/" . $filename;
}

if (!isset($_POST['command'])) {
    error("Invalid command: no-op.");
}

switch ($_POST["command"]) {
    case "uploadFile": {
        if (!isset($_FILES["uploadedFile"])) {
            error("Cannot upload files - upload failed: missing file data.");
        }

        $file_data = $_FILES["uploadedFile"];
        $request_id = $_POST["requestId"];
        $target_path = $_POST["relativePath"];
        $metadata = $_POST["meta"];

        if (!$request_id || !$file_data || !$target_path) {
            error("Cannot upload files - upload failed: missing metadata.");
        }

        $name = $file_data["name"];
        if (!upload_file($file_data["tmp_name"], $name, $target_path)) {
            error("File failed to upload '$target_path/$name'!", array(
                "errorCode" => $file_data["errors"]
            ));
        }

        send_response();
    }

    case "checkFileExists": {
        $request_id = $_POST["requestId"];
        $target_path = $_POST["relativePath"];
        $name = $_POST["fileName"];

        if (!$request_id || !$target_path || !$name) {
            error("Cannot upload files - upload failed: missing metadata.");
        }
        send_response(file_exists(target_upload_path($name, $target_path)));
    }

    case "computeBulkCheckSum": {
        //todo
    }

    case "fileUploadBulkFinished": {
        $request_id = $_POST["requestId"];
        $target_path = $_POST["relativePath"];
        $name = $_POST["fileName"];
        if (!$request_id || !$target_path || !$name) {
            error("Cannot upload files - upload failed: missing metadata.");
        }
        $result = file_uploaded($name, target_upload_path($name, $target_path), $request_id);
        send_response(array("Processing initiated for $name", $result));
    }

    case "clean": {
        erase_dirs();
        send_response();
    }

    default: error("Invalid command: no-op.");
}

