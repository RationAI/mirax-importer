#!/usr/bin/env php
<?php
require_once "config.php";

if (count($argv) < 2) {
    throw new Exception("Incorrect use: not enough arguments. Requires 'request_id' and 'session_id'.");
}

global $request_id, $session_id;
$request_id = trim($argv[1]);
$session_id = trim($argv[2]);

function output($msg) {
    global $request_id, $session_id;
    echo "$request_id:$session_id: $msg\n";
}

function process($file, $request_id, $session_id) {
    global $upload_root, $server_root;
    //executes shell script as a background task, copies to output to the log file and stores it

    //echo removed too many lines
    shell_exec("$server_root/analysis_job.sh 2>&1 '$file' '$request_id' '$session_id'");
}

require_once "Sessions.php";

global $database_file;
$db = new Sessions($database_file);

if ($db->locked()) {
    output("Database locked! NO OP!");
    die();
}

try {
    $db->lock();
    output("Locking database...");

    //todo update also stuff that has status processing X days later

    $stmt = $db->ensure($db->prepare("SELECT * FROM logs WHERE request_id = ? AND (session = 'ready' OR session = 'processing-failed') ORDER BY tstamp DESC"));
    $stmt->bindValue(1, $request_id, SQLITE3_TEXT);
    $result = $db->ensure($stmt->execute());

    $out = array();
    while (($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $out[] = $row;
    }

    foreach ($out as $row) {
        try {
            output("File " . $row["file"]);
            process($row["file"], $row["request_id"], $session_id);
        } catch (Exception $e) {
            //todo better by e.g. setting file status to db?
            output("Processing failed for file " . $row["file"]);
        }
    }

} finally {
    output("Releasing lock...");
    $db->unlock();
}

?>
