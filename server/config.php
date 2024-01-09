<?php

const XO_DB_ROOT = "../../xo_db/";
const ALLOWED_EXTENSIONS = ["mrxs", "svs"];
$upload_root = "/var/www/data/";
$safe_mode = false; //e.g. deleting all for testing purposes
$log_file = "{$upload_root}log.txt";
$analysis_log_file = "{$upload_root}analysis_log.txt";
$server_root = "/var/www/html/importer/server/";
//event name used for the importer instance (do not change at runtime)
$importer_own_event = "mirax-importer";
//the server URL where it is deployed ($server_api_url/config.php is this file)
$server_api_url = "http://localhost:8081/importer/server";
$basic_auth = true;
$run_conversion_as_job = true;
