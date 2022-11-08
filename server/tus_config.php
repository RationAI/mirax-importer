<?php

require_once "config.php";

return [
    /**
     * File cache configs.
     */
    'file' => [
        'dir' => $upload_root . DIRECTORY_SEPARATOR . 'temp',
        'name' => 'tus_php.server.cache',
    ],
];
