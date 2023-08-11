<?php
require_once "../common_api_tools.php";
global $renders_page;

$simple_query = $_GET["ajax"] ?? $_POST["ajax"];
if ($simple_query) {
    $tissue = $_GET["tissue"] ?? $_POST["tissue"];
    switch ($simple_query) {
        case "imageCoordinatesOffset":
            if (!$tissue) die("Invalid usage!");
            global $server_root, $upload_root;
            $tissue = mirax_fname_from_tiff($tissue);
            send_response(shell_exec("python3 {$server_root}mirax_extract_meta/offset_extractor.py '$upload_root$tissue' 2>&1"));
            exit();
        default:
            error("Invalid command");
            break;
    }
} else if ($renders_page) {
    echo "Nothing here... </body></html>";
}
