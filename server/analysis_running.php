<?php
require_once "config.php";
require_once "Sessions.php";

global $database_file;
$db = new Sessions($database_file);

if ($db->locked()) {
    echo '{"status":"running"}';
} else {
    echo '{"status":"ready"}';
}
