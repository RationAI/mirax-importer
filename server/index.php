<?php

var_dump($_POST);
var_dump($_FILES);

$upload_root = "/mnt/data/IIPImage/horak";
$self_url = "";
$exec_shell = "";
$exec_convert_tiff = "";
$errors_list = array(
    "no_valid_files" => "Imported files are missing a .mrxs file - no file was uploaded!"
);

function file_uploaded($filename, $filepath, $request_id) {
    //shell_exec("vips tiffsave --tile --pyramid --compression=jpeg --Q=80 --tile-width 512 --tile-height 512 --bigtiff $filepath");
    return true;
}

///////////////////////////////
///  UTILS
///////////////////////////////

function upload_file($temp, $target) {
    return true;
    if (move_uploaded_file($temp, $target)) {
        @chmod($target, 0640);
        return true;
    }
    return false;
}

///////////////////////////////
///  CORE
///////////////////////////////

function exception_handler(Throwable $exception) {
    set_error($exception->getMessage());
}

set_exception_handler('exception_handler');

function set_error($title, ...$args) {
    echo "ERROR: $title  " . implode(" ", $args)  . "<br>";
}

function show_log(...$args) {
    echo implode(" ", $args)  . "<br>";
}

function send_response(...$args) {
    echo json_encode((object)array_merge($args, array("status" => "success")));
    exit();
}

function error($title, ...$args) {
    echo json_encode((object)array_merge($args, array(
        "status" => "error",
        "message" => $title,
    )));
    exit();
}

if (isset($_POST['upload_file_event'])) {

    if (!isset($_FILES["target"])) {
        error("Cannot upload files - upload failed: missing file data.");
    }
    $file_data = $_FILES["dir_upload"];
    $request_id = $_POST["requestId"];
    $target_path = $_POST["relativePath"]; //fixme sanitize for dots etc...
    $metadata = $_POST["meta"];

    if (!$request_id || !$file_data || !$target_path) {
        error("Cannot upload files - upload failed: missing metadata.");
    }

    global $upload_root;
    $name = $file_data["name"][0];

    $uploaded_target = "$upload_root/$target_path/$name";

    if (file_exists($uploaded_target)) {
        error("File failed to upload '$target_path/$name' - already exists!");
    }

    if (upload_file($file_data["tmp_name"][0], $uploaded_target)) {
        error("File failed to upload '$target_path/$name'!");
    }

    send_response();
} else {
    //API todo
}

