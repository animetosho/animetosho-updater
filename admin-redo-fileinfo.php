<?php
if(PHP_SAPI != 'cli') die;
if($argc!=3) die("Syntax: [script] fid file\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';
require_once ROOT_DIR.'includes/finfo.php';

if(!file_exists($infile = realpath($argv[2]))) die("Invalid file\n");

loadDb();
unset($config);

$file = $db->selectGetArray('files', 'id='.intval($argv[1]));
if(!$file['id']) die("Invalid fid\n");

if($db->selectGetField('finfo', 'fid='.$file['id']))
	die("Already in queue\n");
if($db->selectGetField('fiqueue', 'fid='.$file['id']))
	die("Entry exists in fiqueue - resolve manually\n");

if(filesize_big($infile) != (string)$file['filesize']) die("Filesize mismatch (".filesize_big($infile)." <-> $file[filesize])!\n");

$dir = TOTO_FINFO_PATH.'file_'.$file['id'].'/';
if(@is_dir($dir)) die("Folder exists on disk: $dir\n");

$fnbase = mb_basename($file['filename']);
mkdir($dir);
link_or_copy($infile, $dir.$fnbase);
system('chmod a+rw -R '.escapeshellarg($dir));

// delete screenshots
$ssfbase = make_id_dirs($file['id'], TOTO_STORAGE_PATH.'sframes/');
$ssfbase = TOTO_STORAGE_PATH.'sframes/'.$ssfbase[0].$ssfbase[1];
$ssframes = glob($ssfbase.'_*');
foreach($ssframes as $fn) {
	echo "Deleting $fn\n";
	unlink($fn);
}
$path = TOTO_STORAGE_PATH.'sshots/';
list($storefile, $fn) = make_id_dirs($file['id'], $path);
$storefile .= $fn;
$zipfile = $path.$storefile.'.zip';
if(file_exists($zipfile)) {
	echo "Deleting $zipfile\n";
	unlink($zipfile);
}

$db->delete('fileinfo', 'fid='.$file['id']);
$db->delete('attachments', 'fid='.$file['id']);

// TODO: delete unref'd attachments

$db->insert('finfo', array(
	'fid' => $file['id'],
	'filename' => $fnbase,
	'dateline' => time(),
	'priority' => 0,
));

