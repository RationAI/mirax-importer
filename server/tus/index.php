<?php

require("../vendor/autoload.php");
require_once "../config.php";

function set_error($title, ...$args) { //todo
    error($title, $args);
}
function error($title, $payload=null) {
    echo json_encode((object)array(
        "status" => "error",
        "message" => $title,
        "payload" => $payload ?? print_r($_POST, true),
    ));
    exit();
}

$tmp_dir = "$upload_root/temp/";
$err = make_dir($tmp_dir);

if ($err) {
    //todo dies with error
}


$server   = new \TusPhp\Tus\Server('file');
$server->setUploadDir($tmp_dir);

$server->event()->addListener('tus-server.upload.merged', function (\TusPhp\Events\TusEvent $event) {
//    $fileMeta = $event->getFile()->details();
//    $request  = $event->getRequest();
//    $response = $event->getResponse();
//
//    var_dump($fileMeta);
//    echo "<br><br>";
//    var_dump($response);


//    $target_path = $_POST["relativePath"];
//    $name = $_POST["fileName"];
//
//    if (!$name || !$target_path) {
//        global $uploader;
//        $uploader->_set_error("Failed to position the uploaded file! File name or desired path not provided.", 41);
//    }
//
//    $target_path = target_upload_dir($target_path);
//    $name = clean_path($name);
//
//    $error_callback = function () {
//        global $uploader, $response;
//        $response["error"] = true;
//        $uploader->_die( $response );
//    };
//
//    if (!move_file($full_path, $name, $target_path, $error_callback)) {
//        global $uploader;
//        $uploader->_set_error("Failed to position the uploaded file! The upload failed.", 43);
//    }

});

$response = $server->serve();
//var_dump($response);
//return;
$response->send();

exit(0);
