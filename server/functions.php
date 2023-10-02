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
    $biopsy = str_pad($biopsy, 5, '0', STR_PAD_LEFT);

    $prefix_len = 2; //suffix is last two digits
    $prefix = substr($biopsy, 0, $prefix_len);
    $suffix = substr($biopsy, $prefix_len);
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
    return "Unknown error: using get_err_last().";
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

function absolute_upload_path_to_temp($filename_no_suffix, $is_for_mirax) {
    global $upload_root;
    if (!$is_for_mirax) $filename_no_suffix = "$filename_no_suffix/$filename_no_suffix";
    return "/" . clean_path("$upload_root/.uploads/$filename_no_suffix");
}

function absolute_path_from_records($name, $year, $biopsy, $is_for_mirax, $temp=false) {
    $name_only = pathinfo($name, PATHINFO_FILENAME);
    if ($temp) return absolute_upload_path_to_temp($name_only, $is_for_mirax);
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
    //ok if no message
    return move_item_get_err($target, $target, $perms) == "";
}

function move_item_get_err($temp, $target, $perms=null) {
    if (file_exists($target)) return "File $target already exists!";

    $parent = dirname($target);
    if (!file_exists($parent)) {
        $dir_err = make_dir($parent);
        if ($dir_err != "") return $dir_err;
    }

    if (rename($temp, $target)) {
        @chmod($target, $perms !== null ? $perms : (is_file($target) ? 0644 : 0755));
        return "";
    }
    return "Could not move file $temp to $target!";
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

    global $server_root, $log_file, $run_conversion_as_job, $server_api_url, $basic_auth, $importer_own_event;
    $cmd = "{$server_root}conversion_job.sh";
    $time = gmdate("Y-m-d H:i:s");
    $log_prefix = "$time $mrxs_name";
    $args = [$mrxs_name, $tiff_name, $directory, $importer_own_event, "$server_api_url/index.php"];

    if ($run_conversion_as_job) {
        if ($basic_auth) $args[]=$basic_auth;
        $log = run_importer_job($log_prefix, "convert-$mrxs_name", $cmd, ...$args);
        file_put_contents($log_file, $log, FILE_APPEND);
    } else {
        if ($basic_auth) $args[]=$basic_auth;
        $args = implode(" ", array_map(fn($x) => is_numeric($x) || is_bool($x) ? $x : "'$x'", $args));
        file_put_contents($log_file, "$log_prefix: $cmd $args\n", FILE_APPEND);
        shell_exec_async("$cmd $args", $log_file);
    }
}

function shell_exec_async($command, $log_file) {
    return shell_exec("$command 2>&1 | tee -a '$log_file' 2>/dev/null >/dev/null &");
}

function run_importer_job($log_prefix, $id, $command, ...$args) {
    global $log_file, $server_root;

    //shell escaping of quotes is '\'' -> close, scape, open
    $args = implode(" ", array_map(fn($x) => is_numeric($x) || is_bool($x) ? $x : "'\''$x'\''", $args));
    $args .= " >> '\''$log_file'\'' 2>&1";
    return run_kubernetes_job($log_prefix, "{$server_root}kubernetes/importer_job.py run '$id' '$command $args'");
}

function run_kubernetes_job($log_prefix, $cmd, $print_cmd=false) {
    //job.py run|status <args>
    $execs = exec("$cmd 2>&1", $output, $retVal);
    if ($execs !== false) {
        if ($retVal === 0) {
            $output[]= "Job started...";
        } else {
            $output[]= "Failed to initialize the job! Error '$retVal'.";
        }
    } else {
        $output[]= "Failed to call the job!";
    }

    $prefix = "\n$log_prefix> ";
    if ($print_cmd) {
        return "$log_prefix> " . $cmd . " --> $retVal" . $prefix . implode($prefix, $output) . "\n";
    }
    return "$log_prefix> " . implode($prefix, $output) . "\n";
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
