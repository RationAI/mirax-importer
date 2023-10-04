<?php

/**
 * Temporary detached analysis from processing
 */

require_once "common_api_tools.php";

///////////////////////////////
///  UTILS
///////////////////////////////

function process($file_id_list) {
    $out = xo_files_by_id($file_id_list);
    foreach ($out as $row) {
        try {
            $file_name = mirax_fname_from_tiff($row["name"]);
            $file_path = mirax_path_from_db_record($row);
            global $server_root, $upload_root, $log_file, $run_conversion_as_job, $server_api_url, $basic_auth, $importer_own_event;
            $cmd = "{$server_root}conversion_job.sh";
            $time = gmdate("Y-m-d H:i:s");
            $log_prefix = "$time $file_name";
            $args = [$file_name, $row["name"], "$upload_root$file_path", $importer_own_event, "$server_api_url/index.php"];

            if ($run_conversion_as_job) {
                if ($basic_auth) $args[]=$basic_auth;
                else $args[]="";
                $args[]="true";
                $log = run_importer_job($log_prefix, "convert-manual-$file_name", $cmd, ...$args);
                file_put_contents($log_file, $log, FILE_APPEND);
            } else {
                if ($basic_auth) $args[]=$basic_auth;
                $args = implode(" ", array_map(fn($x) => is_numeric($x) || is_bool($x) ? $x : "'$x'", $args));
                file_put_contents($log_file, "$log_prefix: $cmd $args\n", FILE_APPEND);
                shell_exec_async("$cmd $args", $log_file);
            }
        } catch (Exception $e) {
            output("$importer_own_event:{$row['name']}", "Processing failed!");
        }
    }
}

///////////////////////////////
///  CORE
///////////////////////////////

global $renders_page;
if ($renders_page) {
    echo '<h1 class="f1-light">File processing</h1>';
}


require_once XO_DB_ROOT . "include.php";
$out = array();
$param = "";
$event_name = "__undefined__";
if (isset($_POST['biopsy'])) {
    $param = trim($_POST['biopsy']);
    $year = trim($_POST['year']);
    if (strlen($param) < 1 || strlen($year) < 1) {
        error("Invalid biopsy or year: provided no value.");
    }
    //process files with missing event type or where event type not like "processing%"
    $out = xo_file_biopsy_root_get_by_missing_event($param, file_path_year($year), $event_name, "processing%");
    $param = "biopsy number <b>$param</b> and year <b>$year</b>";

} else if (isset($_POST['file'])) {
    $param = trim($_POST['file']);
    if (strlen($param) < 1) {
        error("Invalid file: provided no value.");
    }
    //process files with missing event type or where event type not like "processing%"
    $out = xo_file_name_get_by_missing_event(tiff_fname_from_mirax($param), $event_name, "processing%");
    $param = "file name <b>$param</b>";

} else if (isset($_POST['fileList'])) {
    $files = $_POST['fileList'];
    if (is_string($files)) $files = json_decode($files);
    if (!is_array($files) || count($files) < 1) {
        $out = [];
    } else {
        //process files with missing event type or where event type not like "processing%"
        $out = xo_file_name_list_get_by_missing_event(
            array_map(fn($f)=>tiff_fname_from_mirax($f), $files), $event_name, "processing%");
    }
    $param = "file list <b>" . implode(", ", $files) . "</b>";

} else {
    error("Invalid request ID or file ID: provided no value.");
}
if ($renders_page) echo "Listing desired files by $param<br>";

$file_id_list = array_map(function ($x) { return $x["id"]; }, $out);

$no_files = count($out) < 1;
if ($renders_page) {
    if (!$no_files) {
        echo "<p>The conversion has been initiated. These files will be processed.</p>";
    }

    echo "<br><table class='width-full m-2 pb-2'><tr class='text-bold'><th>File</th><th>Root<th>Biopsy</th></tr>";

    if ($no_files) {
        echo <<<EOF
<tr>
 <td>No files found.</td>
 <td>-</td>
 <td>-</td>
</tr>
EOF;
    } else {
        foreach ($out as $row) {
            echo <<<EOF
<tr>
 <td>{$row["name"]}</td>
 <td>{$row["root"]}</td>
 <td>{$row["biopsy"]}</td>
</tr>
EOF;
        }
        echo "</table><br>";
    }
}


if ($no_files) {
    if ($renders_page) {
        echo "<p>No files available for $param.</p>";
    } else {
        error("No files available for $param. Conversion did not start.");
    }
    exit();
}

process($file_id_list);
if (!$renders_page) send_response("Processing initiated for $param");
