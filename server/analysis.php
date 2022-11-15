<?php

/**
 * Temporary detached analysis from processing
 */

require_once "config.php";

///////////////////////////////
///  UTILS
///////////////////////////////

function process($request_id, $session_id) {
    global $upload_root, $server_root;
    //executes shell script as a background task, copies to output to the log file and stores it
    return shell_exec("$server_root/analysis_job.php 2>&1 '$request_id' '$session_id' | tee -a '$upload_root/analysis_log.txt' 2>/dev/null >/dev/null &");
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

if (!isset($_POST['request'])) {
    error("Invalid request ID: provided no value.");
}

$_POST['request'] = trim($_POST['request']);
if (strlen($_POST['request']) < 1) {
    error("Invalid request ID: provided no value.");
}


$stmt = $db->ensure($db->prepare("SELECT * FROM logs WHERE request_id = ? AND (session = 'ready' OR session = 'processing-failed') ORDER BY tstamp DESC"));
$stmt->bindValue(1, $_POST['request'], SQLITE3_TEXT);
$result = $db->ensure($stmt->execute());

$out = array();
while (($row = $result->fetchArray(SQLITE3_ASSOC))) {
    $out[] = $row;
}


$no_files = count($out) < 1;
if ($outputs) {
    if ($no_files) {
        $locked = true;
        while (($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $out[] = $row;
        }
    }

    if ($locked && $out) {
        if ($no_files) echo "<p>No files available for processing. Below you can find a list of files available for the given request ID.</p>";
        else echo "<p>Some analysis job is currently running. Below is a list of files for the provided request ID and their status:</p>";
    } else {
        echo "<p>The analysis has been initiated. These files will be processed.</p>";
    }
    if (count($out) < 1) {
        $out[] = array("file" => "No files found.", "tstamp" => "-", "session" => "-");
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
        print_response("No files available for request ID " . $_POST['request']);
        exit();
    }
    print_response("Processing initiated for request ID " . $_POST['request']);
}

if (!$locked) {
    process($_POST['request'], time());
}



