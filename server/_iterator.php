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
                    $max_recursion, $recursion,
                    $fname_append === '' ? $file : "$fname_append/$file");
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
    db_scan_inspector(false, $root, $fname_pattern, $iterator, $predicate, $err);
}
function db_dir_scan_inspector(string $root, string $fname_pattern, callable $iterator,
                                callable $predicate=null, callable $err=null) {
    db_scan_inspector(true, $root, $fname_pattern, $iterator, $predicate, $err);
}

function db_scan_inspector(bool $for_directories, string $root, string $fname_pattern, callable $iterator,
                               callable $predicate=null, callable $err=null) {
    echo "Scanning $root...\n";
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
    $pred = $for_directories ?
        fn($f, $path, $is_dir) => $is_dir && $predicate($f, $path) :
        fn($f, $path, $is_dir) => !$is_dir && $predicate($f, $path);

    file_scan($root, "",  $clbck, $pred, false, 9999);
}

/**
 * Erases empty folders in the biobank
 * @param string $root
 * @return void
 */
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

function print_moves($x, $y) {
    echo $x . " --> " . $y . "\n";
}

/**
 * Renames files in correct recursion order - first children, then parent folder if matches the
 * biopsy structure.
 * @param string $root
 * @param $actually_perform
 * @return void
 */
function file_name_fixer(string $root, $actually_perform=false) {
    $clbck = function ($is_file, $item_name, $rel_path, $start_path) use ($root, $actually_perform) {

        try {
            if (preg_match("/^(.*?)([0-9]{1,4})([_-])([0-9]+)(.*)$/i", $item_name, $matches, PREG_UNMATCHED_AS_NULL)) {
                echo "MATCH $item_name\n";
                $biopsy = $matches[4];
                if (is_string($biopsy)) $biopsy = intval(trim($biopsy));
                $biopsy = str_pad($biopsy, 5, '0', STR_PAD_LEFT);

                $correct_name = $matches[1] . $matches[2] . $matches[3] . $biopsy . $matches[5];

                $real_path = $root . "/" . $rel_path . "/" . $item_name;
                $target_path = $root . "/" .  $rel_path . "/" . $correct_name;

                   if ($real_path != $target_path) {
                    $is_file = file_exists($real_path);

                    if ($is_file) {
                        print_moves($real_path, $target_path);
                        if ($actually_perform && !rename($real_path, $target_path)) {
                            exit("Failed to move file $real_path. Exit.");
                        }
                        echo " Renamed.\n";
                    } else {
                        echo "ERR: Invalid $root/$rel_path/$item_name - missing mirax meta file or its directory!\n";
                    }
                }
            }
        } catch (Exception $e) {
            print_r("File $item_name processing exception!");
            print_r($e);
        }
    };
    $pred = fn($f, $path, $is_dir) => $is_dir || str_ends_with($f, ".tif") || str_ends_with($f, ".tiff") || str_ends_with($f, ".mrxs") || str_ends_with($f, ".xml");
    file_scan($root, "",  $clbck, $pred, true, 6);
}

function mrxs_inspector(string $root) {
    global $mirax_pattern;

    db_file_scan_inspector($root, $mirax_pattern,
        function ($is_file, $item_name, $rel_path, $start_path, $matches) {
            global $upload_root;
            $fname = tiff_fname_from_raw_filename($item_name);
            $biopsy = $matches[3];
            $root = file_path_year($matches[2]);

            $path = mirax_path_from_db_record(["name"=>$fname, "root" => $root, "biopsy" => $biopsy]);

            $real_path = $upload_root . $rel_path . "/";
            $target_path = $upload_root . $path;

            if ($real_path != $target_path) {
                echo "Invalid file, $real_path$item_name skípping!\n";
                return;
                  //moves files from one folder to another
//                if (!file_exists("$target_path/$item_name")) {
//                    //bit dirty moving files, but we know only one mirax per folder exists
//                    // so the predicate will not fire for more files in this dir
//                    echo "File should be in $target_path, but stored in $real_path, moving...\n";
//
//                    ensure_accessible($target_path, fn()=>exit("File stored in invalid folder, correct folder not writeable!"));
//                    $objects = scandir($real_path);
//                    foreach($objects as $tmp) {
//                        if ($tmp != '.' && $tmp != '..') {
//                            echo "$tmp | ";
//                            if (!rename($real_path."/".$tmp, $target_path.$tmp)) {
//                                exit("Failed to move file $target_path.$tmp. Exit.");
//                            }
//                        }
//                    }
//                    echo "\n";
//                }
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
                        xo_file_name_event($fname, "prostate-prediction", "{\"status\":\"processing-finished\",\"__glados\":true}");
                        echo "Vis recorded\n";
                    }
                } else echo "NO Vis \n";

                sleep(1);

            } else {
                echo "Skipped $item_name.\n";
            }
        },
        fn($file, $path) => str_ends_with($file, ".mrxs"),
        fn($d, $e) => print_r(["e"=>$e, "d"=>$d])
    );
}

function load_raw_files_prepare_upload(string $root, bool $actually_perform=false) {
    global $mirax_pattern;

    db_file_scan_inspector($root, $mirax_pattern,
        function ($is_file, $item_name, $rel_path, $start_path, $matches) use ($root, $actually_perform) {
            global $upload_root;
            $biopsy = $matches[3];
            $year = $matches[2];
            $target_temp_path = absolute_path_from_records($item_name, $year, $biopsy, $item_name, true);
            $fname_nosuffix =  pathinfo($item_name, PATHINFO_FILENAME);

            $raw_mirax_file = "$root/$rel_path/$item_name";
            $raw_mirax_dir = "$root/$rel_path/$fname_nosuffix";

            $target_temp_mirax_file = "$target_temp_path/$item_name";
            $target_temp_mirax_dir = "$target_temp_path/$fname_nosuffix";

            $is_file = file_exists($raw_mirax_file);
            $has_dir = file_exists($raw_mirax_dir);

            if (!$actually_perform) {
                if ($is_file) echo "FILE $raw_mirax_file will be moved to $target_temp_mirax_file \n";
                else echo "ERR mirax!";
                if ($has_dir) echo "DIR $raw_mirax_dir will be moved to $target_temp_mirax_dir\n";
                else echo "ERR dir!";
            } else {
                echo "Moving to $target_temp_mirax_file ...";

                if ($is_file && $has_dir) {

                    ensure_accessible($target_temp_path,
                        fn()=>exit("File stored in invalid folder, correct folder not writeable!"));
                    if (!rename($raw_mirax_file, $target_temp_mirax_file)) {
                        exit("Failed to move file $raw_mirax_file. Exit.");
                    }
                    if (!rename($raw_mirax_dir, $target_temp_mirax_dir)) {
                        exit("Failed to move file $raw_mirax_dir. Exit.");
                    }
                    echo " Moved.\n";

                } else {
                    echo "ERR: Invalid $root/$rel_path/$item_name - missing mirax meta file or its directory!\n";
                }
            }
        },
        fn($file, $path) => str_ends_with($file, ".mrxs"),
        fn($d, $e) => print_r(["e"=>$e, "d"=>$d])
    );
}

function raw_files_finish_upload(string $root, bool $actually_perform=false) {

    db_dir_scan_inspector($root, "/^(.*?([0-9]{4})[_-]([0-9]+).*)$/i",
        function ($is_file, $item_name, $rel_path, $start_path, $matches) use ($root, $actually_perform) {
            $biopsy = $matches[3];
            $year = $matches[2];

            $year = clean_path($year);
            $name = clean_path($item_name);
            $tiff_name = tiff_fname_from_raw_filename($name);
            $filepath = absolute_path_from_records($name, $year, $biopsy, $name, false);
            $upload_filepath = absolute_path_from_records($name, $year, $biopsy, $name, true);

            $exists = is_dir($upload_filepath);

            echo "Access $rel_path/$item_name ";

            if (!$actually_perform) {
                echo "File $upload_filepath would be moved to $filepath (biopsy $biopsy, year $year) and converted!\n";
            } else {
                echo "Moving $upload_filepath to $filepath...";

                if ($exists) {
                    if (is_dir($filepath)) {
                        echo " skipping - already exists!\n";
                        return;
                    }

                    try {
                        $err = move_item_get_err($upload_filepath, $filepath, 0755);
                        if ($err != "") {
                            exit("Uploaded file cannot be moved to the final destination! " . $err);
                        }
                        $file = xo_insert_or_ignore_file($tiff_name, "uploaded", file_path_year($year), $biopsy);
                        if ($file != null) exit("Uploaded file present in the database: '$name'!");
                    } catch (Exception $e) {
                        exit("File uploaded but the system failed to create an upload record: '$name'! " . $e->getMessage());
                    }
                    file_uploaded($name, $tiff_name, $filepath);

                    sleep(20);
                    exit("TEST!");

                } else {
                    echo "ERR: Invalid file $upload_filepath - nothing to upload!\n";
                }
            }
        },
        function($file, $path) {
            //accept folders that contain mirax file
            if (!is_dir("$path/$file")) return false;
            $objects = is_readable($path) ? scandir($path) : array();
            foreach ($objects as $file) {
                if (str_ends_with($file, ".mrxs")) return true;
            }
            return false;
        },
        fn($d, $e) => print_r(["e"=>$e, "d"=>$d])
    );
}

global $safe_mode, $upload_root;
if (false && $safe_mode) {
    echo "Not allowed in safe mode!";
    exit;
}
require_once "functions.php";
require_once XO_DB_ROOT . "include.php";

//mrxs_inspector($upload_root);
//empty_folder_inspector($upload_root);
//file_name_fixer($upload_root);
file_name_fixer("$upload_root/2023/01/007", true);
file_name_fixer("$upload_root/2023/01/007", true);
file_name_fixer("$upload_root/2023/01/007", true);

//load_raw_files_prepare_upload("$upload_root/.temp/2023-02-11-checked-ok", false);
//raw_files_finish_upload("$upload_root/.uploads", true);
