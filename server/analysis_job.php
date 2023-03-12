#!/usr/bin/env php
<?php
require_once "config.php";
require_once "functions.php";

if (count($argv) < 2) {
    throw new Exception("Incorrect use: not enough arguments. Requires 'request_id' and 'session_id'.");
}

global $identifier, $session_id;
$identifier = trim($argv[1]);
$session_id = trim($argv[2]);
$is_file = trim($argv[3]) == "true"; //identifier if file can be a '#' separated file list string

function output($msg) {
    global $identifier, $session_id;
    echo "$identifier:$session_id: $msg\n";
}

function process($file, $identifier, $session_id) {
    global $upload_root, $server_root;
    //executes shell script as a background task, copies to output to the log file and stores it

    //echo removed too many lines
    return shell_exec("$server_root/analysis_job.sh 2>&1 '$file' '$identifier' '$session_id'");
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

    //todo update also stuff that has status processing X days before

    $out = array();

    if ($is_file) {
        foreach (explode("#", $identifier) as $filename) {
            $stmt = $db->ensure($db->prepare("SELECT * FROM logs WHERE file = ? AND (session = 'ready' OR session = 'processing-failed') ORDER BY tstamp DESC LIMIT 1"));
            $stmt->bindValue(1, $filename, SQLITE3_TEXT);
            $result = $db->ensure($stmt->execute());
            while (($row = $result->fetchArray(SQLITE3_ASSOC))) {
                $out[] = $row;
            }
        }

    } else {
        $stmt = $db->ensure($db->prepare("SELECT * FROM logs WHERE request_id = ? AND (session = 'ready' OR session = 'processing-failed') ORDER BY tstamp ASC"));
        $stmt->bindValue(1, $identifier, SQLITE3_TEXT);
        $result = $db->ensure($stmt->execute());

        while (($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $out[] = $row;
        }
    }


    foreach ($out as $row) {
        try {
            output("File " . $row["file"]);
            output(">>" . process($row["file"], $row["request_id"], $session_id));
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
