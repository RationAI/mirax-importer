<?php

/**
 * Temporary detached analysis from processing
 */

require_once "config.php";
require_once "functions.php";

///////////////////////////////
///  UTILS
///////////////////////////////

function process($file_id_list, $algorithm, $session_id) {
    global $upload_root, $server_root;
    //executes shell script as a background task, copies to output to the log file and stores it
    $file_id_list = json_encode($file_id_list);
    $algorithm = json_encode($algorithm);
    return shell_exec("$server_root/analysis_job.php 2>&1 '$file_id_list' '$algorithm' '$session_id' | tee -a '$upload_root/analysis_log.txt' 2>/dev/null >/dev/null &");
}

///////////////////////////////
///  CORE
///////////////////////////////

if (!isset($_POST) || count($_POST) < 1) {
    $_POST = json_decode(file_get_contents('php://input'), true);
}

set_exception_handler(function(Throwable $exception) {
    error("Unknown error!", $exception->getMessage());
});

global $renders_page;
$renders_page = !isset($_POST['ajax']) || !boolval($_POST['ajax']);

function error($msg, $details=null) {
    global $renders_page;
    if ($renders_page) {
        echo "<p>$msg</p>";
        echo "</body></html>";
        die();
    }
    echo json_encode((object)array(
        "status" => "error",
        "message" => $msg,
        "payload" => $details
    ));
    die();
}

function send_response($payload=null) {
    echo json_encode((object)array("status" => "success", "payload" => $payload));
}

if (!isset($_POST['algorithm']) || !isset($_POST['algorithm']["name"])) {
    error("Invalid algorithm: provided no valid value: JSON object required.");
}
$algorithm = $_POST['algorithm'];

require_once XO_DB_ROOT . "include.php";
if ($renders_page) {
    echo <<<EOF
<html>
<head>
  <link rel="stylesheet" type="text/css" href="../style.css">
  <script type="text/javascript" src="../jquery.min.js"></script>
  <script type="text/javascript" src="../jquery.form.min.js"></script>
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

  <link rel="stylesheet" href="../primer_css.css">
  <title>WSI Analysis</title>
  <meta charset="UTF-8">
</head>

<body data-color-mode="auto" data-light-theme="light" data-dark-theme="dark_dimmed" ><h1 class="f1-light">Synchronous Analysis</h1>
EOF;
}

global $analysis_event_name;
$out = array();
$param = "";
$event_name = $analysis_event_name($algorithm["name"]);
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
    if (isset($out["id"])) $out = [$out];
    else $out = [];
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

if ($renders_page) echo "Listing unprocessed files by $param<br>";

$file_id_list = array_map(function ($x) { return $x["id"]; }, $out);

$no_files = count($out) < 1;
if ($renders_page) {
    if ($no_files && $out) {
        echo "<p>No files available for processing. Below you can find a list of files available for the given request ID.</p>";
    } else {
        echo "<p>The analysis has been initiated. These files will be processed.</p>";
    }
    if ($no_files) {
        $out[] = array("name" => "No unprocessed files found.", "year" => "-", "biopsy" => "-");
    }
}

if ($renders_page) {
    echo "<br><table class='width-full m-2 pb-2'><tr class='text-bold'><th>File</th><th>Year><th>Biopsy</th></tr>";
    foreach ($out as $row) {

        echo <<<EOF
<tr>
 <td>{$row["name"]}</td>
 <td>{$row["year"]}</td>
 <td>{$row["biopsy"]}</td>
</tr>
EOF;
    }
    echo "</table><br>";
    echo "<p>The online process can be observed interactively using the upload form 'Monitor' function.</p>";
}

if (count($out) < 1) {
    if ($renders_page) {
        echo "<p>No files available for $param.</p>";
    } else {
        error("No files available for $param. Analysis not started.");
    }
    exit();
}

process($file_id_list, $algorithm, time());
if (!$renders_page) send_response("Processing initiated for $param");
