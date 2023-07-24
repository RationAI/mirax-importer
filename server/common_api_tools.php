<?php

require_once "config.php";
require_once "functions.php";

if (!isset($_POST) || count($_POST) < 1) {
    $_POST = json_decode(file_get_contents('php://input'), true);
}
if (!$_POST || count($_POST) < 1) {
    $_POST = $_GET;
}

set_exception_handler(function(Throwable $exception) {
    error("Unknown error!", $exception->getMessage());
});

global $renders_page;
$renders_page = !isset($_POST['ajax']) || !boolval($_POST['ajax']);

function error($msg, $details=null) {
    global $renders_page;
    if ($renders_page) {
        echo "<p>$msg</p>";
        echo "</body></html>";
        die();
    }
    echo json_encode((object)array(
        "status" => "error",
        "message" => $msg,
        "payload" => $details
    ));
    die();
}

function send_response($payload=null) {
    echo json_encode((object)array("status" => "success", "payload" => $payload));
}

//todo provide end connection that writes </html</body> if $renders_page=true

if ($renders_page) {
    echo <<<EOF
<html>
<head>
  <link rel="stylesheet" type="text/css" href="../style.css">
  <script type="text/javascript" src="../jquery.min.js"></script>
  <script type="text/javascript" src="../jquery.form.min.js"></script>
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

  <link rel="stylesheet" href="../primer_css.css">
  <title>WSI Analysis</title>
  <meta charset="UTF-8">
</head>

<body data-color-mode="auto" data-light-theme="light" data-dark-theme="dark_dimmed" >
EOF;
}
