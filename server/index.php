<?php
//time values should be in UTC!
require_once "config.php";
require_once "functions.php";

///////////////////////////////
///  UTILS
///////////////////////////////

function file_uploaded($filename, $directory, $biopsy, $year, $session_id) {
    global $log_file, $server_root;

    //make sure .skip file exists
    $parent_dir = dirname($directory);
    if (!file_exists("$parent_dir/.pull")) {
        file_put_contents("$parent_dir/.pull", 'tiff');
    }

    //executes shell script as a background task, copies to output to the log file and stores it
    return shell_exec("$server_root/conversion_job.sh 2>&1 '$filename' '$directory' '$biopsy' '$year' '$session_id' | tee -a '$log_file' 2>/dev/null >/dev/null &");
}


function get_file_status($fname) {
    require_once XO_DB_ROOT . "include.php";

    $data = xo_get_file_by_name($fname);

    //should be in UTC
    if (isset($data["created"])) {
        $data["created_delta"] = time() - strtotime($data["created"]);
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
    require_once XO_DB_ROOT . "include.php";
    xo_files_erase();
}

///////////////////////////////
///  CORE
///////////////////////////////

if (!isset($_POST) || count($_POST) < 1) {
    $_POST = json_decode(file_get_contents('php://input'), true);
}

function exception_handler(Throwable $exception) {
    set_error("Unknown error occurred!", $exception->getMessage());
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

function get_upload_path($name, $year, $biopsy, $is_mirax) {
    $name_only = pathinfo($name, PATHINFO_FILENAME);
    $target_path = file_path_from_year_biopsy($name_only, $year, $biopsy, $is_mirax);
    return target_upload_path($name, $target_path);
}

switch ($_POST["command"]) {
    case "uploadFile": {
        if (!isset($_FILES["uploadedFile"])) {
            error("Cannot upload files - upload failed: missing file data.");
        }

        $file_data = $_FILES["uploadedFile"];
        $mirax_name = $_POST["mirax-name"];
        $biopsy = $_POST["biopsy"];
        $year = $_POST["year"];
        $metadata = $_POST["meta"];

        if (!$biopsy || !$file_data || !$year) {
            error("Cannot upload files - upload failed: missing metadata.");
        }

        $name = clean_path($file_data["name"]);
        $is_mirax = str_ends_with($name, ".mrxs");
        $mirax_name = pathinfo($mirax_name, PATHINFO_FILENAME);

        $target_path = target_upload_dir(file_path_from_year_biopsy($mirax_name, $year, $biopsy, $is_mirax));
        $error_handler = function ($title) {
            echo json_encode((object)array(
                "status" => "error",
                "message" => $title,
                "payload" => "File failed to upload: incorrect paths or permissions!",
            ));
            exit();
        };

        if (!upload_file($file_data["tmp_name"], $name, $target_path, $error_handler)) {
            $err = error_get_last();
            if (is_array($err)) $err = $err["message"] ?? implode(" | ", $err);

            error("File failed to upload '$target_path/$name'!", array(
                "errorCode" => $file_data["error"],
                "payload" => $err
            ));
        }
        send_response();
    }

    case "checkFileExists": {
        $biopsy = $_POST["biopsy"];
        $year = $_POST["year"];
        $name = $_POST["fileName"];
        if (!$biopsy || !$year || !$name) {
            error("Cannot verify file existence - execution failed: missing metadata.");
        }
        send_response(file_exists(get_upload_path($name, $year, $biopsy, true)));
    }

    case "checkFileStatus": {
        $biopsy = $_POST["biopsy"];
        $year = $_POST["year"];
        $name = $_POST["fileName"];
        if (!$biopsy || !$year || !$name) {
            error("Cannot verify file existence - execution failed: missing metadata.");
        }
        if (!file_exists(get_upload_path($name, $year, $biopsy, true))) {
            send_response(array());
        } else {
            send_response(get_file_status(tiff_fname_from_mirax($name)));
        }
    }

    case "computeBulkCheckSum": {
        //todo
    }

    case "fileUploadBulkFinished": {
        $biopsy = $_POST["biopsy"];
        $year = $_POST["year"];
        $name = $_POST["fileName"];
        if (!$biopsy || !$year || !$name) {
            error("Cannot process files - fileUploadBulkFinished failed: missing metadata.");
        }

        $biopsy = (int)$biopsy;
        $year = clean_path($year);
        $name = clean_path($name);
        try {
            require_once XO_DB_ROOT . "include.php";
            //todo request_id not used check its use
            xo_insert_or_get_file(tiff_fname_from_mirax($name), $biopsy, "uploaded", file_path_year($year), $biopsy);
        } catch (Exception $e) {
            error("File uploaded but the system failed to create an upload record: '$name'!");
        }
        $name_only = pathinfo($name, PATHINFO_FILENAME);
        $result = file_uploaded(
            $name,
            target_upload_dir(file_path_from_year_biopsy($name_only, $year, $biopsy, true)),
            $biopsy,
            $year,
            time()
        ); //tstamp as session
        send_response(array("Upload finished for $name", $result));
    }

    case "clean": {
        erase_dirs();
        send_response();
    }

    default: error("Invalid command: no-op.");
}

