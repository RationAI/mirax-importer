<?php

/**
 * Required software for server working with mirax files:
 *  vips (conversion to tiff)
 *  snakemake with histopipe (analysis)
 *  php with sqlite3 (server + db)
 *
 *  todo: python, openslide... in future
 */

////////////////////////////// functions

function tiff_fname_from_mirax($mirax) {
    return "$mirax.tiff";
}

function file_path_year($year) {
    return "$year/";
}

function file_path_biopsy($biopsy) {
    $pos = 2;
    $prefix = substr($biopsy, 0, $pos+1);
    $suffix = substr($biopsy, $pos+1);
    return "$prefix/$suffix/";
}

function file_path_from_year_biopsy($filename_no_suffix, $year, $biopsy, $is_mirax) {
    $yp = file_path_year($year);
    $bp = file_path_biopsy($biopsy);

    if ($is_mirax) return "$yp/$bp/$filename_no_suffix/";
    else return "$yp/$bp/$filename_no_suffix/$filename_no_suffix/";
}

function clean_path($path) {
    $path = trim($path);
    $path = trim($path, '\\/');
    $path = str_replace(array('../', '..\\'), '', $path);
    if ($path == '..') {
        $path = '';
    }
    return str_replace('\\', '/', $path);
}

function make_dir($path) {
    if (is_dir($path)) return "";

    function get_err() {
        $err = error_get_last();
        if (is_array($err)) {
            return $err["message"] ?? implode(" | ", $err);
        }
        return "Unknown error: mkdir.";
    }

    if (!@mkdir($path, 0775, true)) return get_err();
    if (!@chmod($path, 0775)) return get_err(); //todo necessary?
    return "";
}

function target_upload_dir($relative_path, $_processed=false) {
    global $upload_root;
    if ($_processed) {
        return $relative_path; //already processed, return as is
    }
    return "/" . clean_path("$upload_root/$relative_path");
}

function target_upload_path($filename, $relative_path, $path_processed=false) {
    return target_upload_dir($relative_path, $path_processed) . "/" . clean_path($filename);
}

function upload_file($temp, $target_file_name, $directory, callable $_die) {
    ensure_accessible($target_file_name, $directory, $_die);
    $target = "$directory/$target_file_name";

    if (move_uploaded_file($temp, $target)) {
        @chmod($target, 0640);
        return true;
    }
    return false;
}

function move_file($temp, $target_file_name, $directory, callable $_die) {
    ensure_accessible($target_file_name, $directory, $_die);
    $target = "$directory/$target_file_name";
    if (rename($temp, $target)) {
        @chmod($target, 0640);
        return true;
    }
    return false;
}

function ensure_accessible($target_file_name, $directory, callable $_die) {
    global $upload_root;
    if (!is_writable($upload_root)) $_die("Missing permissions for the root directory $upload_root!");

    $dir_err = make_dir($directory);
    if ($dir_err != "") $_die("Target directory '$directory' is not create-able! <code>$dir_err</code>");
    if (!is_writable($directory)) $_die("Missing permissions for the target upload directory!");
}

if (! function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        $needle_len = strlen($needle);
        return ($needle_len === 0 || 0 === substr_compare($haystack, $needle, - $needle_len));
    }
}
