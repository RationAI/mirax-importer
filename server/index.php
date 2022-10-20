<?php

$upload_root = "/mnt/data/IIPImage/horak";
$self_url = "";
$exec_shell = "";
$exec_convert_tiff = "";
$errors_list = array(
    "no_valid_files" => "Imported files are missing a .mrxs file - no file was uploaded!"
);

/**
 * Evaluator which files are invoked the upload iterator
 * @param $info
 * @return false|mixed any identifier you would like
 */
function file_iterator_predicate($info) {
    $ext = pathinfo($info->name, PATHINFO_EXTENSION);
    $info->extension = $ext;
    return $ext === "mrxs";
}

/**
 * Evaluator which files are invoked the upload iterator
 * the received file contains default properties for the file such as "name" (see $_FILES array desc)
 * moreover, two properties are present: ".." enters file hierarchy, "." enters file description (if available)
 *
 * This function must
 *  - upload all necessary data for each file file_iterator_predicate returned true
 *  - call file_uploaded on successfull upload, once per execution
 *  - call upload_file to upload each file
 *
 * @param $info
 * @return boolean true on success
 */
function upload_iterator($info) {
    global $upload_root;
    //upload the mrxs folder

    //file names: identifier(- / _)year(- / _)reqest_id(- / _)
    if (!preg_match('/^((\w+)[-_](\w+)[-_](\w+).*)\..*$/', $info->name, $matches)) {
        set_error("File failed to upload: the filename does not match pattern [XXX]-[YEAR]-[ID]-...mrxs :", $info->name);
        return false;
    }
    $name = $matches[1];
    $request_id = $matches[4];

    if ($request_id === "") {
        set_error("File failed to upload: no request ID", $info->name);
        return false;
    }

    $bin_root = $info->{".."}->{".."}->{$name} ?? false;
    $uploaded_mrxs = "$upload_root/{$request_id}/{$name}/{$info->name}";
    show_log("Upload file", $uploaded_mrxs);

    if (file_exists($uploaded_mrxs)) {
        set_error("File failed to upload - already exists!", $info->name);
        return false;
    }
    if (!$bin_root) {
        set_error("MRXS data folder missing! Searching for folder: ", $name);
        return false;
    }

    $uploads = true;
    foreach ($bin_root as $bin_file) {
        $bin_file = $bin_file->{"."} ?? false;
        if ($bin_file) {
            //todo fail on failure of any file part

            show_log("  Upload bin", $bin_file->name);

            $uploads = $uploads && upload_file($bin_file->tmp_name,
                    "$upload_root/{$request_id}/{$name}/{$name}/{$bin_file->name}");
        } // todo else fail?
    }
    $uploads = $uploads && upload_file($info->tmp_name, $uploaded_mrxs);

    if ($uploads) {
        show_log("  DONE");
        return file_uploaded($name, $uploaded_mrxs);
    }
    set_error("File failed to upload - partially.", $info->name);
    return false;
}

function file_uploaded($filename, $filepath) {
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

function error($title, ...$args) {
    echo "ERROR: $title  ";
    echo implode(" ", $args)  . "<br>";
    exit();
}

function _debug_hierarchy_dfs($h, $off="") {
    if ($h->__visited__) return;
    $h->__visited__ = true;

    if (is_object($h)) {
        foreach ($h as $k=>$v) {
            if ($k !== "." && $k !== "__visited__") {
                show_log($off, $k);
                _debug_hierarchy_dfs($v, $off . "--");
            }
        }
    } else {
        show_log($off, $h);
    }
}

if (isset($_POST['upload_file_event'])) {

    if (!isset($_FILES["target"])) {
        error("Cannot upload files - upload");
    }
    $file_data = $_FILES["dir_upload"];
    $hierarchy = (object)array();
    $iterator = array();
    $paths = json_decode($_POST["requestId"]);




    for ($i = 0; $i < count($file_data["name"]); $i++) {
        $name = $file_data["name"][$i];
        if ($name === "." || $name === "..") continue;

        $path = explode("/", $paths[$i]); //sent manually via POST form
        //$path = explode("/", $file_data["full_path"][$i]);  8.1

        $ref = $hierarchy;
        foreach ($path as $el) {
            if (!isset($ref->{$el})) {
                $ref->{$el} = (object)array(".." => $ref);
            }

            $ref = $ref->{$el};
        }

        $ref->{"."} = (object)array(
            "name" => $name,
        );
        $file_info = $ref->{"."};

        if ($file_data["error"][$i]) {
            $file_info->size = -1;
            continue;
        }

        $file_info->type = $file_data["type"][$i];
        $file_info->size = $file_data["size"][$i];
        $file_info->tmp_name = $file_data["tmp_name"][$i];

        $includes = file_iterator_predicate($file_info);
        if ($includes) {
            $file_info->predicate_result = $includes;
            $iterator[] = $file_info;
        }
        $file_info->{".."} = $ref;
    }
    _debug_hierarchy_dfs($hierarchy);

    if (count($iterator) < 1) {
        error($errors_list["no_valid_files"]);
    }

    foreach ($iterator as $context) {
        upload_iterator($context);
    }
} else {
    show_page();
}

