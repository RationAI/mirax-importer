#!/usr/bin/env php
<?php

if (count($argv) < 3) {
    throw new Exception("Incorrect use: not enough arguments. Requires 'file', 'session_id' and 'status'.");
    exit;
}

require_once "Sessions.php";
require_once "config.php";

global $database_file;
$sql = new Sessions($database_file);

$file = $argv[0];
$session_id = $argv[1];
$status = $argv[2];
$sql->setProgress($file, $session_id, $status);

$result = $sql->getProgress($file, $session_id);
$row = $result->fetchArray(SQLITE3_ASSOC);

$data = $row["session"];
if ($data !== $status) {
    throw new Exception("Database update failed for file $file with session id $session_id, the session status stayed as '$data', requested '$status'.");
}

?>