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

function file_path_from_year_biopsy($filename_no_suffix, $year, $biopsy, $is_for_mirax) {
    $yp = file_path_year($year);
    $bp = file_path_biopsy($biopsy);

    if ($is_for_mirax) return "$yp$bp$filename_no_suffix/";
    else return "$yp$bp$filename_no_suffix/$filename_no_suffix/";
}

function mirax_path_from_db_record($record) {
    $file = mirax_fname_from_tiff($record["name"]);
    return file_path_from_year_biopsy(
        pathinfo($file, PATHINFO_FILENAME), $record["root"], $record["biopsy"], true);
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

function make_dir($path, $perms=0775) {
    if (is_dir($path)) return "";

    if (!@mkdir($path, $perms, true)) return _xo_get_err_last();
    if (!@chmod($path, $perms)) return _xo_get_err_last(); //todo necessary?
    return "";
}

function relative_upload_path_to_absolute($relative_path) {
    global $upload_root;
    return "/" . clean_path("$upload_root/$relative_path");
}

function relative_upload_path_to_temp($filename_no_suffix, $is_for_mirax) {
    global $upload_root;
    if (!$is_for_mirax) $filename_no_suffix = "$filename_no_suffix/$filename_no_suffix";
    return "/" . clean_path("$upload_root/.uploads/$filename_no_suffix");
}

function absolute_path_from_records($name, $year, $biopsy, $is_for_mirax, $temp=false) {
    $name_only = pathinfo($name, PATHINFO_FILENAME);
    if ($temp) return relative_upload_path_to_temp($name_only, $is_for_mirax);
    $target_path = file_path_from_year_biopsy($name_only, $year, $biopsy, $is_for_mirax);
    return relative_upload_path_to_absolute($target_path);
}

function upload_file($temp, $target_file_name, $directory, callable $_die) {
    ensure_accessible($directory, $_die);
    $target = "$directory/$target_file_name";

    //non-empty
    if (mb_strlen($temp,"UTF-8") > 225) $_die("File name too big! $target_file_name!");
    if (mb_strlen($target,"UTF-8") > 225) $_die("File name destination too big! $target!");

    if (move_uploaded_file($temp, $target)) {
        @chmod($target, 0644);
        return true;
    }
    return false;
}

function move_item($temp, $target, $perms=null) {
    if (file_exists($target)) return false;

    $parent = dirname($target);
    if (!file_exists($parent)) {
        $dir_err = make_dir($parent);
        if ($dir_err != "") return false;
    }

    if (rename($temp, $target)) {
        @chmod($target, $perms !== null ? $perms : (is_file($target) ? 0644 : 0755));
        return true;
    }
    return false;
}

function _is_dir_empty($dir) {
    return (count(scandir($dir)) == 2);
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
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

//finishing touches - mirax pyramid, extract label
function file_uploaded($mrxs_name, $tiff_name, $directory) {
    //make sure .skip file exists
    $parent_dir = dirname($directory);
    if (!file_exists("$parent_dir/.pull")) {
        file_put_contents("$parent_dir/.pull", 'tiff');
    }

    global $server_root, $log_file, $run_conversion_as_job, $server_api_url, $importer_own_event;
    if ($run_conversion_as_job) {
        return run_importer_job("convert-$mrxs_name", "{$server_root}conversion_job.sh",
            $mrxs_name, $tiff_name, $directory, $importer_own_event, $server_api_url);
    }
    return shell_exec_async("{$server_root}conversion_job.sh 2>&1 '$mrxs_name' '$tiff_name' '$directory' '$importer_own_event' '$server_api_url'",
        $log_file);
}

function shell_exec_async($command, $log_file) {
    return shell_exec("$command 2>&1 | tee -a '$log_file' 2>/dev/null >/dev/null &");
}

function run_importer_job($id, $command, ...$args) {
    global $log_file, $server_root;
    $args = implode(" ", array_map(fn($x) => is_numeric($x) || is_bool($x) ? $x : "\'$x\'", $args));
    return run_kubernetes_job("{$server_root}kubernetes/importer_job.py run '$id' '$command $args' '$log_file'");
}

function run_kubernetes_job($cmd) {
    //job.py run|status <args>
    $out = "$cmd\n> ";
    $execs = exec("$cmd 2>&1", $retArr, $retVal);
    $out .= implode("\n> ", $retArr);
    if ($execs !== false) {
        if ($retVal === 0) {
            $out .= "Job started...\n";
        } else {
            $out .= "Failed to initialize the job! Error '$retVal'.\n";
        }
    } else {
        $out .= "Failed to call the job!\n";
    }
    return $out;
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
