#!/usr/bin/env php
<?php
require_once "config.php";

function file_scan(string   $path,
                   string   $rel_start,
                   callable $callback,
                   callable $filename_predicate,
                   bool     $exit_recurse=false,
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

            if (!$exit_recurse && $filename_predicate($file, $new_path, $valid_dir)) {
                if ($valid_file && is_file($new_path)) {
                    $callback(true, $file, $fname_append, $rel_start);
                } else if ($valid_dir) {
                    $callback(false, $file, $fname_append, $rel_start);
                }
            }

            if ($valid_dir) {
                file_scan($new_path,
                    $rel_start === '' ? $file : $rel_start . '/' . $file,
                    $callback, $filename_predicate, $exit_recurse,
                    $max_recursion, $recursion, "$fname_append/$file");
            }

            if ($exit_recurse && $filename_predicate($file, $new_path, $valid_dir)) {
                if ($valid_file && is_file($new_path)) {
                    $callback(true, $file, $fname_append, $rel_start);
                } else if ($valid_dir) {
                    $callback(false, $file, $fname_append, $rel_start);
                }
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
    $pred = fn($f, $path, $is_dir) => !$is_dir && $predicate($f, $path);
    file_scan($root, "",  $clbck, $pred, false, 9999);
}

function empty_folder_inspector(string $root) {
    $clbck = function ($is_file, $item_name, $rel_path, $start_path) {
        global $upload_root;
        $dir = $upload_root.$rel_path."/".$item_name;
        echo "Scan $dir";
        $files = scandir($dir);
        if(count($files) == 3 && is_file("$dir/.pull")) {
            unlink("$dir/.pull");
            rmdir($dir);
            echo "Removing empty directory with pull item $dir\n";
        } else if(count($files) == 2) {
            rmdir($dir);
            echo "Removing empty directory $dir\n";
        } else echo " Skipped - has " . count($files) . "\n";
    };
    $pred = fn($f, $path, $is_dir) => $is_dir;
    file_scan($root, "",  $clbck, $pred, true, 9999);
}

function file_name_fixer(string $root) {
    $clbck = function ($is_file, $item_name, $rel_path, $start_path) {
        global $upload_root;

        try {
            if (preg_match("/^(.*?)([0-9]{1,4})([_-])([0-9]+)(.*)$/i", $item_name, $matches, PREG_UNMATCHED_AS_NULL)) {
                echo "MATCH $item_name\n";
                $real_path = $upload_root . $rel_path . "/" . $item_name;
                $biopsy = $matches[4];
                if (is_string($biopsy)) $biopsy = intval(trim($biopsy));
                $biopsy = str_pad($biopsy, 4, '0', STR_PAD_LEFT);
                $target_path = $upload_root . $rel_path . "/" . $matches[1] . $matches[2] . $matches[3] . $biopsy  . $matches[5];

                //throw new Exception("Implement your own iterator logics");
                if (!rename($real_path, $target_path)) {
                    exit("Failed to move file $target_path. Exit.");
                }

            }
        } catch (Exception $e) {
            print_r("File $item_name processing exception!");
            print_r($e);
        }
    };
    $pred = fn($f, $path, $is_dir) => str_ends_with($f, ".tif");
    file_scan($root, "",  $clbck, $pred, true, 6);
}

function mrxs_inspector(string $root) {
    global $mirax_pattern;

    db_file_scan_inspector($root, $mirax_pattern,
        function ($is_file, $item_name, $rel_path, $start_path, $matches) {
            global $upload_root;
            $fname = tiff_fname_from_mirax($item_name);
            $biopsy = $matches[3];
            $root = file_path_year($matches[2]);

            $path = mirax_path_from_db_record(["name"=>$fname, "root" => $root, "biopsy" => $biopsy]);
            $real_path = $upload_root . $rel_path;
            $target_path = $upload_root . $path;

            if (!file_exists("$target_path/$item_name")) {
                //bit dirty moving files, but we know only one mirax per folder exists
                echo "File should be in $target_path, but stored in $real_path, moving...\n";
                ensure_accessible($target_path, fn()=>exit("File stored in invalid folder, correct folder not writeable!"));
                $objects = scandir($real_path);
                foreach($objects as $tmp) {
                    if ($tmp != '.' && $tmp != '..') {
                        echo "$tmp | ";
                        if (!rename($real_path."/".$tmp, $target_path.$tmp)) {
                            exit("Failed to move file $target_path.$tmp. Exit.");
                        }
                    }
                }
                echo "\n";
            }

            $file = xo_insert_or_ignore_file($fname, "uploaded", $root, $biopsy);

            if (!$file) {
                //inserted because if exists we return the record
                echo "Processing $item_name...\n";
                file_uploaded($item_name, $fname, $target_path);

                if (is_dir("$target_path/vis/")) {
                    $viz = scandir("$target_path/vis/");
                    if ($viz && count($viz) > 2) {
                        //generate viz record!
                        require_once XO_DB_ROOT . "include.php";
                        xo_file_name_event($fname, "prostate-prediction", "{\"status\":\"processing-finished\",\"__glados\":true}");
                        echo "Vis recorded\n";
                    }
                } else echo "NO Vis \n";
            } else {
                echo "Skipped $item_name.\n";
            }
        },
        fn($file, $path) => str_ends_with($file, ".mrxs"),
        fn($d, $e) => print_r(["e"=>$e, "d"=>$d])
    );
}

global $safe_mode, $upload_root;
if ($safe_mode) {
    error("Not allowed in safe mode!");
    exit;
}
require_once "functions.php";
require_once XO_DB_ROOT . "include.php";

//mrxs_inspector($upload_root);
//empty_folder_inspector($upload_root);
file_name_fixer($upload_root);
