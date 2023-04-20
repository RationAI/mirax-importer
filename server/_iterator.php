#!/usr/bin/env php
<?php
require_once "config.php";

function file_scan(string   $path,
                   string   $rel_start,
                   callable $callback,
                   callable $filename_predicate,
                   int      $max_recursion=-1,
                   //args used in recurring calls
                   int      $recursion_count=0,
                   string   $fname_append='') {

    if ($recursion_count >= $max_recursion) return; //prevent recursion depth
    $objects = is_readable($path) ? scandir($path) : array();

    if (is_array($objects)) {
        foreach ($objects as $file) {
            $recursion = $recursion_count;

            if ($file == '.' || $file == '..') {
                continue;
            }

            $new_path = $path . '/' . $file;
            $valid_dir = is_dir($new_path) && !is_link($new_path);
            $valid_file = true;
            $recursion++;

            if ($filename_predicate($file, $new_path)) {
                if ($valid_file && is_file($new_path)) {
                    $callback(true, $file, $fname_append, $rel_start);
                } else if ($valid_dir) {
                    $callback(false, $file, $fname_append, $rel_start);
                }
            }

            if ($valid_dir) {
                file_scan($new_path,
                    $rel_start === '' ? $file : $rel_start . '/' . $file,
                    $callback, $filename_predicate,
                    $max_recursion, $recursion, "$fname_append/$file");
            }
        }
    }
}

/**
 * Runs through existing files and creates necessary DB records / fixes errors
 *
 * @param $root string root of scan
 * @param $fname_pattern string extract / derive biopsy and root path from filename
 * @param $err callable on error
 * @return void
 */
function db_file_scan_inspector(string $root, string $fname_pattern, callable $iterator,
                                callable $predicate=null, callable $err=null) {
    $clbck = function ($is_file, $item_name, $rel_path, $start_path) use ($iterator, $err, $fname_pattern) {
        try {
            if (preg_match($fname_pattern, $item_name, $matches, PREG_UNMATCHED_AS_NULL)) {
                $iterator($is_file, $item_name, $rel_path, $start_path, $matches);
            } else if ($err) {
                $err("File not matched by the pattern!", [$is_file, $item_name, $rel_path, $start_path]);
            }
        } catch (Exception $e) {
            if ($err) {
                $err("File $item_name processing exception!", $e);
            }
        }
    };
    if ($predicate === null) $predicate = fn($a, $b) => true;
    file_scan($root, "",  $clbck, $predicate, 9999);
}

function mrxs_inspector(string $root) {
    require_once "functions.php";
    require_once XO_DB_ROOT . "include.php";
    global $mirax_pattern;

    db_file_scan_inspector($root, $mirax_pattern,
        function ($is_file, $item_name, $rel_path, $start_path, $matches) {
            global $upload_root;
            $fname = tiff_fname_from_mirax($item_name);
            $file = xo_insert_or_ignore_file($fname, -1, "uploaded", $matches[1], $matches[2]);

            if (!$file) {
                //inserted because if exists we return the record
                global $log_file, $server_root;
                echo "Processing $item_name...\n";
                file_uploaded(
                    $log_file,
                    $server_root,
                    $item_name,
                    $fname,
                    $upload_root . $rel_path,
                    $matches[2],
                    $matches[1],
                    -1
                );
            } else {
                echo "Skipped $item_name.\n";
            }
        },
        fn($file, $dir) => str_ends_with($file, ".mrxs"),
        fn($d, $e) => print_r(["e"=>$e, "d"=>$d])
    );
}

global $safe_mode, $upload_root;
if ($safe_mode) {
    error("Not allowed in safe mode!");
    exit;
}
mrxs_inspector($upload_root);
