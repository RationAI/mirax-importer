<?php

/**
 * Temporary detached analysis from processing
 */

require_once "config.php";

///////////////////////////////
///  UTILS
///////////////////////////////

function process($is_file, $request_id, $session_id) {
    global $upload_root, $server_root;
    //executes shell script as a background task, copies to output to the log file and stores it
    $is_file = $is_file ? "true" : "false";
    return shell_exec("$server_root/analysis_job.php 2>&1 '$request_id' '$session_id' '$is_file' | tee -a '$upload_root/analysis_log.txt' 2>/dev/null >/dev/null &");
}

function get_db_instance() {
    require_once "Sessions.php";
    global $database_file;
    return new Sessions($database_file);
}

///////////////////////////////
///  CORE
///////////////////////////////

if (!isset($_POST) || count($_POST) < 1) {
    $_POST = json_decode(file_get_contents('php://input'), true);
}

function exception_handler(Throwable $exception) {
    set_error($exception->getMessage());
}

set_exception_handler('exception_handler');

function set_error($title, ...$args) { //todo
    echo "<p>ERROR: $title  " . implode(" ", $args)  . "</p>";
}


global $outputs;
$outputs = !isset($_POST['ajax']) || !boolval($_POST['ajax']);

function error($msg) {
    global $outputs;
    if ($outputs) {
        echo "<p>$msg</p>";
        echo "</body></html>";
        die();
    }
    echo json_encode((object)array(
        "status" => "error",
        "message" => $msg,
    ));
    die();
}

function print_response($payload=null) {
    echo json_encode((object)array("status" => "success", "payload" => $payload));
}

require_once "Sessions.php";

$db = get_db_instance();
$locked = boolval($db->locked());

if ($locked && !$outputs) {
    print_response("Analysis not initiated: a different analysis is running!");
    exit();
}


if ($outputs) {
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

$out = array();
$is_file = false;
$param = "";
$will_process_arg = "";
if (isset($_POST['request'])) {
    $param = trim($_POST['request']);
    if (strlen($param) < 1) {
        error("Invalid request ID: provided no value.");
    }

    $stmt = $db->ensure($db->prepare("SELECT * FROM logs WHERE request_id = ? AND (session = 'ready' OR session = 'processing-failed') ORDER BY tstamp DESC"));
    $stmt->bindValue(1, $param, SQLITE3_TEXT);
    $result = $db->ensure($stmt->execute());

    if ($outputs) echo "Fetching request ID by <b>" . $param . "</b><br>";
    while (($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $out[] = $row;
    }
    $will_process_arg = $param;

} else if (isset($_POST['file'])) {
    $param = trim($_POST['file']);
    if (strlen($param) < 1) {
        error("Invalid file: provided no value.");
    }
    $is_file = true;
    $stmt = $db->ensure($db->prepare("SELECT * FROM logs WHERE file = ? AND (session = 'ready' OR session = 'processing-failed') ORDER BY tstamp DESC LIMIT 1"));
    $stmt->bindValue(1, $param, SQLITE3_TEXT);
    $result = $db->ensure($stmt->execute());

    if ($outputs) echo "Fetching file by <b>" . $param . "</b><br>";
    while (($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $out[] = $row;
    }
    $will_process_arg = $param;

} else if (isset($_POST['fileList'])) {
    $files = $_POST['fileList'];
    if (is_string($files)) $file = json_decode($files);

    foreach ($files as $file) {
        $param = trim($file);
        if (strlen($param) < 1) {
            continue;
        }
        $is_file = true;
        //todo might select wrong files
        $stmt = $db->ensure($db->prepare("SELECT * FROM logs WHERE file LIKE ? AND (session = 'ready' OR session = 'processing-failed') ORDER BY tstamp DESC LIMIT 1"));
        $stmt->bindValue(1, "%$param%", SQLITE3_TEXT);
        $result = $db->ensure($stmt->execute());
        while (($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $out[] = $row;
        }
    }

    $param = "list " . implode(", ", $files);
    if ($outputs) echo "Fetching file list by substring matching: <b>" . $param . "</b><br>";
    $will_process_arg = array_map(function ($x) { return $x["file"]; }, $out);
    $will_process_arg = implode("#", $will_process_arg);

} else {
    error("Invalid request ID or file ID: provided no value.");
}

//print DB contents
//$stmt = $db->ensure($db->prepare("SELECT * FROM logs"));
//$rr = $stmt->execute();
//while (($row = $rr->fetchArray(SQLITE3_ASSOC))) {
//    echo $row["file"] . " &emsp; " . $row["request_id"] . " &emsp; " . $row["session"] . "<br>";
//}

$no_files = count($out) < 1;
if ($outputs) {
    if ($no_files) {
        $locked = true;
    }

    if ($locked && $out) {
        if ($no_files) echo "<p>No files available for processing. Below you can find a list of files available for the given request ID.</p>";
        else echo "<p>Some analysis job is currently running. Below is a list of files for the provided request ID and their status:</p>";
    } else if (!$locked) {
        echo "<p>The analysis has been initiated. These files will be processed.</p>";
    }
    if (count($out) < 1) {
        $out[] = array("file" => "No unprocessed files found.", "tstamp" => "-", "session" => "-");
    }
}

if ($outputs) {
    echo "<br><table class='width-full m-2 pb-2'><tr class='text-bold'><th>File</th><th>Modified</th><th>Status</th></tr>";
    foreach ($out as $row) {

        echo <<<EOF
<tr>
 <td>{$row["file"]}</td>
 <td>{$row["tstamp"]}</td>
 <td>{$row["session"]}</td>
</tr>
EOF;
    }
    echo "</table><br>";
    echo "<p>The online process can be observed interactively using the upload form 'Monitor' function.</p>";
} else {
    if (count($out) < 1) {
        print_response("No files available for " . ($is_file ? "file " : "request ID ") . $param);
        exit();
    }
    print_response("Processing initiated for " . ($is_file ? "file " : "request ID ") . $param);
}

if (!$locked) {
    process($is_file, $will_process_arg, time());
} else {
    error("A different analysis is running at the time.");
}



