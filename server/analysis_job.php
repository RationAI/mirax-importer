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


output("Running analysis job session: Algorithm configuration {$argv[2]}");
//todo update also stuff that has status processing X days before

require_once XO_DB_ROOT . "include.php";
$out = xo_files_by_id($file_id_list);

function process($file_name, $file_path, $algorithm_name, $algorithm) {
    global $server_root, $analysis_event_name;
    xo_file_name_event("$file_name", $analysis_event_name($algorithm_name), "processing");

    //index.php enpoint
    $protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $api = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . 'index.php';

    //job.py run|status slide algorithm serviceAPI, busy waiting (immediately exits, job is submitted)
    //todo add event only if exit is successful
    return shell_exec("$server_root/analysis_job_api.py run '$file_path/$file_name' '$algorithm' '$api' 2>&1");
}

foreach ($out as $row) {
    try {
        output("File " . $row["name"]);
        output(process($row["name"], file_path_from_db_record($row), $algorithm["name"], $algorithm_serialized));
    } catch (Exception $e) {
        output("Processing failed for file " . $row["file"]);
    }
}
?>
