<?php

require_once "config.php";

///////////////////////////////
///  UTILS
///////////////////////////////

function clean_path($path) {
    $path = trim($path);
    $path = trim($path, '\\/');
    $path = str_replace(array('../', '..\\'), '', $path);
    if ($path == '..') {
        $path = '';
    }
    return str_replace('\\', '/', $path);
}

function file_uploaded($filename, $filepath, $request_id, $session_id) {
    global $log_file, $server_root;
    //executes shell script as a background task, copies to output to the log file and stores it
    return shell_exec("$server_root/job.sh 2>&1 '$filename' '$filepath' '$request_id' '$session_id' | tee -a '$log_file' 2>/dev/null >/dev/null &");
}

function get_db_instance() {
    require_once "Sessions.php";
    global $database_file;
    return new Sessions($database_file);
}

function register_file_upload($file_real_path, $request_id) {
    try {
        $sql = get_db_instance();
        $sql->saveJobLog($file_real_path, $request_id, "uploaded");

        $row = get_file_status($file_real_path, $request_id, $sql);
        $data = $row["session"] ?? false;
        return $data == "uploaded";
    } catch (Exception $e) {
        return false;
    }
}

function get_file_status($file_real_path, $request_id, $db=null) {
    if ($db == null) {
        $db = get_db_instance();
    }
    $result = $db->getProgress($file_real_path, $request_id);
    return $result->fetchArray(SQLITE3_ASSOC);
}

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

    if (file_exists($target)) error("File failed to upload '$relative_directory/$target_file_name' - already exists!");

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

if (!isset($_POST) || count($_POST) < 1) {
    $_POST = json_decode(file_get_contents('php://input'), true);
}

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

function target_upload_dir($relative_path, $processed=false) {
    global $upload_root;
    if ($processed) {
        return $relative_path; //already processed, return as is
    }
    return clean_path("$upload_root/$relative_path");
}

function target_upload_path($filename, $relative_path, $path_processed=false) {
    return target_upload_dir($relative_path, $path_processed) . "/" . clean_path($filename);
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
            error("Cannot verify file existence - execution failed: missing metadata.");
        }
        send_response(file_exists(target_upload_path($name, $target_path)));
    }

    case "checkFileStatus": {
        $request_id = $_POST["requestId"];
        $target_path = $_POST["relativePath"];
        $name = $_POST["fileName"];
        if (!$request_id || !$target_path || !$name) {
            error("Cannot verify file existence - execution failed: missing metadata.");
        }

        $fp = target_upload_path($name, $target_path);
        if (!file_exists($fp)) {
            send_response(array());
        } else {
            send_response(get_file_status(target_upload_path($name, $target_path), $request_id));
        }
    }

    case "computeBulkCheckSum": {
        //todo
    }

    case "fileUploadBulkFinished": {
        $request_id = $_POST["requestId"];
        $target_path = $_POST["relativePath"];
        $name = $_POST["fileName"];
        if (!$request_id || !$target_path || !$name) {
            error("Cannot process files - fileUploadBulkFinished failed: missing metadata.");
        }
        $result = file_uploaded(clean_path($name), target_upload_dir($target_path), $request_id, 0); //todo session id
        send_response(array("Processing initiated for $name", $result));
    }

    case "clean": {
        erase_dirs();
        send_response();
    }

    default: error("Invalid command: no-op.");
}

