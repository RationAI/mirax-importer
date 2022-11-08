<?php
//time values should be in UTC!
require_once "config.php";

///////////////////////////////
///  UTILS
///////////////////////////////

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
    $data = $result->fetchArray(SQLITE3_ASSOC);

    //should be in UTC
    if (isset($data["tstamp"])) {
        $data["tstamp_delta"] = time() - strtotime($data["tstamp"]);
    }
    return $data;
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
    error($title, $args);
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

        $name = clean_path($file_data["name"]);
        $target_path = target_upload_dir($target_path);
        $error_handler = function ($title) {
            echo json_encode((object)array(
                "status" => "error",
                "message" => $title,
                "payload" => "File failed to upload: incorrect paths or permissions!",
            ));
            exit();
        };

        if (!upload_file($file_data["tmp_name"], $name, $target_path, $error_handler)) {
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

        $target_directory = target_upload_dir($target_path);
        $name = clean_path($name);
        if (!register_file_upload("$target_directory/$name", $request_id)) { //todo define file composition on one place only
            error("File uploaded but the system failed to create an upload record: '$target_path/$name'!");
        }
        $result = file_uploaded($name, $target_directory, $request_id, 0); //todo session id
        send_response(array("Processing initiated for $name", $result));
    }

    case "clean": {
        erase_dirs();
        send_response();
    }

    default: error("Invalid command: no-op.");
}

