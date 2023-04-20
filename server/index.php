<?php
//time values should be in UTC!
require_once "config.php";
require_once "functions.php";

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

function get_file_status($fname, $event) {
    require_once XO_DB_ROOT . "include.php";

    if ($event) {
        global $analysis_event_name;
        $data = xo_file_name_get_latest_event($fname, $analysis_event_name($event));
        //we record status as data record, the latest record tells us event status
        if (isset($data["data"])) {
            //override file.status for front-end
            $data["status"] = $data["data"];
        }
    } else {
        $data = xo_get_file_by_name($fname);
    }

    //should be in UTC
    if (isset($data["created"])) {
        $data["created_delta"] = time() - strtotime($data["created"]);
    }
    return $data;
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

        $checksum = null;
        if (strlen($_POST["checksum"]) > 10 ) {
            $checksum = md5_file($file_data["tmp_name"]);
            $client_checksum = trim($_POST["checksum"]);
            if ($checksum !== $client_checksum) {
                error("Checksum verification failed for '$target_path/$name'!", array(
                    "errorCode" => 42,
                    "payload" => "Server computed '$checksum', client '$client_checksum'."
                ));
            }
        }
        if (!upload_file($file_data["tmp_name"], $name, $target_path, $error_handler)) {
            $err = error_get_last();
            if (is_array($err)) $err = $err["message"] ?? implode(" | ", $err);

            error("File failed to upload '$target_path/$name'!", array(
                "errorCode" => $file_data["error"],
                "payload" => $err
            ));
        }
        send_response($checksum);
    }

    case "checkFileExists": {
        $biopsy = $_POST["biopsy"];
        $year = $_POST["year"];
        $name = $_POST["fileName"];
        if (!$biopsy || !$year || !$name) {
            error("Cannot verify file existence - execution failed: missing metadata.");
        }
        $file = xo_get_file_by_name(tiff_fname_from_mirax($name));
        if (isset($file["id"])) {
            send_response(true);
        }
        send_response(file_exists(get_upload_path($name, $year, $biopsy, true)));
    }

    case "checkFileStatus": {
        $biopsy = $_POST["biopsy"];
        $year = $_POST["year"];
        $name = $_POST["fileName"];
        $event = $_POST["eventName"];
        if (!$biopsy || !$year || !$name) {
            error("Cannot verify file existence - execution failed: missing metadata.");
        }
        send_response(get_file_status(tiff_fname_from_mirax($name), trim($event)));
    }

    case "fileUploadBulkFinished": {
        $biopsy = intval(trim($_POST["biopsy"]));
        $year = trim($_POST["year"]);
        $name = trim($_POST["fileName"]);
        $biopsy = str_pad($biopsy, 4, '0', STR_PAD_LEFT);

        if (!$biopsy || strlen($biopsy) != 4 || !$year || !$name) {
            error("Cannot process files - fileUploadBulkFinished failed: missing or invalid metadata.");
        }

        $year = clean_path($year);
        $name = clean_path($name);
        try {
            require_once XO_DB_ROOT . "include.php";
            if ($biopsy)
            $file = xo_insert_or_ignore_file(tiff_fname_from_mirax($name), "uploaded", file_path_year($year), $biopsy);
            if ($file != null) error("Uploaded file present in the database: '$name'!", $file);
        } catch (Exception $e) {
            error("File uploaded but the system failed to create an upload record: '$name'!", $e);
        }
        $name_only = pathinfo($name, PATHINFO_FILENAME);
        global $log_file, $server_root;
        file_uploaded(
            $log_file,
            $server_root,
            $name,
            tiff_fname_from_mirax($name),
            target_upload_dir(file_path_from_year_biopsy($name_only, $year, $biopsy, true)),
            $biopsy,
            $year,
            time()
        ); //tstamp as session
        send_response(array("Upload finished for $name"));
    }

    case "algorithmEvent": {
        $name = trim($_POST["fileName"]);
        $event = trim($_POST["event"]);
        $status = trim($_POST["status"]);
        if (!$name || !$event) {
            error("Invalid event!");
        }

        $data = strpos($status, "error") === false ? "" : "";
        global $analysis_event_name;
        require_once XO_DB_ROOT . "include.php";
        xo_file_name_event(tiff_fname_from_mirax($name), $event, $data);

        send_response();
    }

    case "clean": {
        global $safe_mode;
        if ($safe_mode) {
            error("Not allowed in safe mode!");
        }
        erase_dirs();
        require_once XO_DB_ROOT . "include.php";
        xo_files_erase();

        send_response();
    }

    default: error("Invalid command: no-op.");
}

