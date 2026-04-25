<?php
if(PHP_SAPI != 'cli') die;
if($argc!=3) die("Syntax: [script] fid file\n");

if(!posix_getuid()) die("Don't run this as root\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

if(!file_exists($infile = realpath($argv[2]))) die("Invalid file\n");

loadDb();
unset($config);

$file = $db->selectGetArray('files', 'id='.intval($argv[1]));
if(!$file['id']) die("Invalid fid\n");

if(filesize_big($infile) != (string)$file['filesize']) die("Filesize mismatch (".filesize_big($infile)." <-> $file[filesize])!\n");

require ROOT_DIR.'includes/finfo.php';

// check if there's stored screenshots
$zipfile = implode('', make_id_dirs($file['id'], TOTO_STORAGE_PATH.'sshots/')); // make_id_dirs is somewhat wrong, but okay for here
$zipfileFull = TOTO_STORAGE_PATH.'sshots/'.$zipfile.'.zip';
if(file_exists($zipfileFull)) {
	die("Screenshot ZIP file exists - please delete $zipfileFull\n");
}

// check for frames
$sfglob = glob(
	TOTO_STORAGE_PATH.'sframes/'.implode('', make_id_dirs($file['id'], TOTO_STORAGE_PATH.'sframes/'))
.'*');
if(!empty($sfglob))
	die("Screen frames exist, please delete them\n");

$srcfile = $argv[2];

// get mediainfo
$filetype = $finf_extra = null;
if($filetype != 'mediainfo' || !$finf_extra) die("Not mediainfo type file\n");

// do screenshotting
$tmpsdir = make_temp_dir(); // create temp folder
$update = fileinfo_run_screenshots($tmpsdir, $file['id'], $srcfile);
if(empty($update)) die("Nothing changed\n");

rmdir($tmpsdir);

$db->update('files', $update, 'id='.$file['id']);
