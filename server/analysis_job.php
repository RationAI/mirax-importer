#!/usr/bin/env php
<?php
/**
 * This file is meant to be executed from shell.
 * It separates the user session request and the detached processing.
 */
require_once "config.php";
require_once "functions.php";

if (count($argv) < 2) {
    throw new Exception("Incorrect use: not enough arguments. Requires 'request_id' and 'session_id'.");
}

global $file_id_list, $event_name, $session_id, $analysis_event_name;
$file_id_list = json_decode(trim($argv[1]), true);
$algorithm_serialized = trim($argv[2]);
$algorithm = json_decode($algorithm_serialized, true);
$session_id = trim($argv[3]); //identifier if file can be a '#' separated file list string
$event_name = $analysis_event_name($algorithm["name"]);

function output($msg) {
    global $event_name, $session_id;
    echo "$event_name:$session_id: $msg\n";
}

function process($file_name, $file_path, $biopsy, $algorithm_name, $algorithm, $session_id) {
    //todo in future flexible way of sending algo params here
    global $server_root;

    //inspect file path, search for mirax file name
    //exec busy-waiting shell analysis!
    return shell_exec("$server_root/analysis_job.sh 2>&1 '$file_name' '$file_path' '$biopsy' '$algorithm_name' '$algorithm' '$session_id'");
}

output("Running analysis job session: Algorithm configuration {$argv[2]}");
//todo update also stuff that has status processing X days before

require_once XO_DB_ROOT . "include.php";
$out = xo_files_by_id($file_id_list);
foreach ($out as $row) {
    try {
        output("File " . $row["name"]);
        output(process($row["name"], file_path_from_db_record($row), $row["biopsy"],
            $algorithm["name"], $algorithm_serialized, $session_id));
    } catch (Exception $e) {
        output("Processing failed for file " . $row["file"]);
    }
}
?>
