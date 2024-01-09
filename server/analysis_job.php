#!/usr/bin/env php
<?php
/**
 * This file is meant to be executed from shell.
 * It separates the user session request and the detached processing.
 */
require_once "config.php";
require_once "functions.php";

if (count($argv) < 2) {
    throw new Exception("Incorrect use: not enough arguments. Requires 'file id list' and 'algorithm configuration'.");
}

global $file_id_list, $event_name;
$file_id_list = json_decode(trim($argv[1]), true);
$algorithm_serialized = trim($argv[2]);
$algorithm = json_decode($algorithm_serialized, true);
$event_name = trim($algorithm["name"]);

$time = gmdate("Y-m-d H:i:s");

function output($prefix, $msg) {
    global $time;
    echo $prefix ? "$time $prefix: $msg\n" : "$time $msg\n";
}

require_once XO_DB_ROOT . "include.php";
$out = xo_files_by_id($file_id_list);
foreach ($out as $row) {
    try {
        $file_name = raw_filename_from_tiff($row["name"]);
        $file_path = mirax_path_from_db_record($row);
        $algorithm_name = $algorithm["name"];
        global $upload_root, $server_root, $server_api_url, $basic_auth;

        if (!file_exists("$upload_root$file_path$file_name")) {
            output("$event_name:$file_name", "Failed to call the job! File $file_path$file_name does not exist!");
            return;
        }
        //for python: basic auth dict
        if ($basic_auth) $auth=' \'{"Authorization": "Basic '.base64_encode($basic_auth).'"}\'';
        else $auth = "";

        $cmd = "$server_root/kubernetes/analysis_job_api.py run '$file_path$file_name' '$algorithm_serialized' '$server_api_url/index.php'$auth";
        output(false, run_kubernetes_job("$event_name:$file_name", $cmd, true)); //log prefixed via run_kubernetes_job id
    } catch (Exception $e) {
        output("$event_name:$event_name:{$row['name']}", "Processing failed!");
    }
}
?>
