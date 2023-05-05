<?php

const XO_DB_ROOT = "../../xo_db/";
$upload_root = "/var/www/data/";
$safe_mode = false; //e.g. deleting all for testing purposes
$log_file = "$upload_root/log.txt";
$analysis_log_file = "$upload_root/analysis_log.txt";
$server_root = "/var/www/html/importer/server/";
//event name used for the importer instance (do not change at runtime)
$importer_own_event = "mirax-importer";
//must have three groups: 1) capture file name without extension 2) capture year 3) capture biopsy
$mirax_pattern = "/^(.*?([0-9]{4})[_-]([0-9]+).*)\.mrxs$/i";
//the server URL where it is deployed ($server_api_url/config.php is this file)
$server_api_url = "http://localhost:8081/importer/server";
$basic_auth = false;
$run_conversion_as_job = false;
