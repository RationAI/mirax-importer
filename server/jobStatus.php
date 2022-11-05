#!/usr/bin/env php
<?php

if (count($argv) < 3) {
    throw new Exception("Incorrect use: not enough arguments. Requires 'file', 'session_id' and 'status'.");
    exit;
}


function process($file, $request_id, $session_id) {
    global $upload_root, $server_root;
    //executes shell script as a background task, copies to output to the log file and stores it
    return shell_exec("$server_root/analysis_job.sh 2>&1 '$file' '$request_id' '$session_id' | tee -a '$upload_root/analysis_log.txt'");
}

require_once "Sessions.php";
require_once "config.php";

global $database_file;
$sql = new Sessions($database_file);

$file = $argv[1];
$session_id = $argv[2];
$status = $argv[3];
$sql->setProgress($file, $session_id, $status);

$result = $sql->getProgress($file, $session_id);
$row = $result->fetchArray(SQLITE3_ASSOC);

$data = $row["session"];
if ($data !== $status) {
    throw new Exception("Database update failed for file $file with session id $session_id, the session status stayed as '$data', requested '$status'.");
}

?>