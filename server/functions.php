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
    if (preg_match("/\.tiff?$/i", $mirax)) return $mirax;
    return "$mirax.tiff";
}

function mirax_fname_from_tiff($tiff) {
    if (preg_match("/^(.*)\.tiff?$/i", $tiff, $match)) {
        return $match[1];
    }
    if (str_ends_with($tiff, ".mrxs")) {
        return $tiff;
    }
    throw new Exception("File is not a mirax file! $tiff");
}

function file_path_year($year) {
    if (str_ends_with($year, "/")) return $year;
    return "$year/";
}

function file_path_biopsy($biopsy) {
    if (is_string($biopsy)) $biopsy = intval(trim($biopsy));
    $biopsy = str_pad($biopsy, 4, '0', STR_PAD_LEFT);

    $suffix_len = 2; //suffix is last two digits
    $prefix = substr($biopsy, 0, strlen($biopsy)-$suffix_len);
    $suffix = substr($biopsy, -$suffix_len);
    return "$prefix/$suffix/";
}

function file_path_from_year_biopsy($filename_no_suffix, $year, $biopsy, $is_mirax) {
    $yp = file_path_year($year);
    $bp = file_path_biopsy($biopsy);

    if ($is_mirax) return "$yp$bp$filename_no_suffix/";
    else return "$yp$bp$filename_no_suffix/$filename_no_suffix/";
}

function mirax_path_from_db_record($record) {
    $file = mirax_fname_from_tiff($record["name"]);
    return file_path_from_year_biopsy(
        pathinfo($file, PATHINFO_FILENAME), $record["root"], $record["biopsy"], true);
}

function get_upload_path($name, $year, $biopsy, $is_mirax) {
    $name_only = pathinfo($name, PATHINFO_FILENAME);
    $target_path = file_path_from_year_biopsy($name_only, $year, $biopsy, $is_mirax);
    return target_upload_path($name, $target_path);
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

function _xo_get_err_last() {
    $err = error_get_last();
    if (is_array($err)) {
        return $err["message"] ?? implode(" | ", $err);
    }
    return "Unknown error: get_err_last.";
}

function make_dir($path) {
    if (is_dir($path)) return "";

    if (!@mkdir($path, 0775, true)) return _xo_get_err_last();
    if (!@chmod($path, 0775)) return _xo_get_err_last(); //todo necessary?
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
    ensure_accessible($directory, $_die);
    $target = "$directory/$target_file_name";

    //non-empty
    if (mb_strlen($temp,"UTF-8") > 225) $_die("File name too big! $target_file_name!");
    if (mb_strlen($target,"UTF-8") > 225) $_die("File name destination too big! $target!");

    if (move_uploaded_file($temp, $target)) {
        @chmod($target, 0640);
        return true;
    }
    return false;
}

function move_file($temp, $target_file_name, $directory, callable $_die) {
    ensure_accessible($directory, $_die);
    $target = "$directory/$target_file_name";
    if (rename($temp, $target)) {
        @chmod($target, 0640);
        return true;
    }
    return false;
}

function ensure_accessible($directory, callable $_die) {
    global $upload_root;
    if (!is_writable($upload_root)) {
        if (!is_dir($upload_root) && !mkdir($upload_root, 0775, true)) {
            $_die("Missing permissions for the root directory $upload_root!");
        }
    }

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

function file_uploaded($log_file, $server_root,
                       $mrxs_name, $tiff_name, $directory,
                       $biopsy, $year, $session_id) {
    //make sure .skip file exists
    $parent_dir = dirname($directory);
    if (!file_exists("$parent_dir/.pull")) {
        file_put_contents("$parent_dir/.pull", 'tiff');
    }
    //executes shell script as a background task, copies to output to the log file and stores it
    return shell_exec("{$server_root}conversion_job.sh 2>&1 '$mrxs_name' '$tiff_name' '$directory' '$biopsy' '$year' '$session_id' | tee -a '$log_file' 2>/dev/null >/dev/null &");
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
