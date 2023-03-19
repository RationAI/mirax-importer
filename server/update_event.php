#!/usr/bin/env php
<?php

/**
 * Shell To PHP Database File Status Update
 *
 * TODO record status as algorithm status and type as histopipe_[algorithm]
 *
 * Empty Algorithm Input means the file status is to be updated.
 */
if (count($argv) < 3) {
    throw new Exception("Incorrect use: not enough arguments. Requires at least 'file' (name only, tiff) and 'status'. Can also specify 'algorith name'.");
}

$file = basename(trim($argv[1]));
$status = trim($argv[2]);
if (count($argv) < 4) {
    $algo = "";
} else {
    $algo = trim($argv[3]);
}

require_once "config.php";
require_once XO_DB_ROOT . "include.php";

if ($algo === "") {
    xo_update_file_by_name($file, $status);
} else { //auto prefix
    global $analysis_event_name;
    xo_file_name_event($file, $analysis_event_name($algo), $status);
}

?>
