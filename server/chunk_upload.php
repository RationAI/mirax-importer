<?php
/**
 * ################################################################################
 * MyChunkUploader
 *
 * Copyright 2016 Eugen Mihailescu <eugenmihailescux@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE.  See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * ################################################################################
 *
 * Short description:
 * URL: https://github.com/eugenmihailescu/my-chunk-uploader
 *
 * Git revision information:
 *
 * @version : 0.2.3-30 $
 * @commit  : 11b68819d76b3ad1fed1c955cefe675ac23d8def $
 * @author  : eugenmihailescu <eugenmihailescux@gmail.com> $
 * @date    : Fri Mar 18 17:18:30 2016 +0100 $
 * @file    : upload.php $
 *
 * @id      : upload.php | Fri Mar 18 17:18:30 2016 +0100 | eugenmihailescu <eugenmihailescux@gmail.com> $
 */

namespace MyChunkUploader;

require_once 'config.php';
require_once 'MyChunkUploader.php';

$tmp_dir = "$upload_root/temp";
$err = make_dir($tmp_dir);
$uploader = new MyChunkUploader($tmp_dir);

if ($err) {
    //dies with error
    $uploader->_set_error("Chunk uploading cannot proceed: " . $err, 42);
}
$uploader->on_done = function ($filename, $directory, $full_path) {
    //todo does not work for single files :/
    $target_path = $_POST["relativePath"];
    $name = $_POST["fileName"];

    if (!$name || !$target_path) {
        global $uploader;
        $uploader->_set_error("Failed to position the uploaded file! File name or desired path not provided.", 41);
    }

    $target_path = target_upload_dir($target_path);
    $name = clean_path($name);

    $error_callback = function () {
        global $uploader, $response;
        $response["error"] = true;
        $uploader->_die( $response );
    };

    if (!move_file($full_path, $name, $target_path, $error_callback)) {
        global $uploader;
        $uploader->_set_error("Failed to position the uploaded file! The upload failed.", 43);
    }
};
$response = $uploader->run();
$uploader->_die( array( 'success' => true, 'json' => $response ) );
?>