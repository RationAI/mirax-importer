<?php

const XO_DB_ROOT = "../../txo_db/";
$upload_root = "/var/www/data/";
$log_file = "$upload_root/log.txt";
$server_root = "/var/www/html/timporter/server/";
$analysis_event_name=function ($event) {
    if (!$event) throw new Exception("Event name must be defined! Got '$event'.");
    if ($event === "mirax-importer") return $event;
    return "histopipe_$event";
};
