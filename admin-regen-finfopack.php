<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("Syntax: [script] fid fid...\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

loadDb();
unset($config);

@ini_set('memory_limit', '512M');

array_shift($argv);

$fids = array_unique(array_map('intval', $argv));

require ROOT_DIR.'includes/finfo-compress.php';

$files = $db->selectGetAll('files', 'id', 'id IN ('.implode(',',$fids).')', 'id,audioextract');
if(empty($files))
	die("No files found\n");

foreach($files as $file) {
	$update = [];
	$infos = $db->selectGetAll('fileinfo', 'type', 'fid='.$file['id']);
	foreach($infos as $info) {
		$data = FileInfoCompressor::decompress($info['type'], $info['info']);
		if(!$data) die("Failed to decompress $info[type] $file[id]\n");
		$cdata = FileInfoCompressor::compress($info['type'], $data, true);
		if($cdata != $info['info']) {
			$update[] = [
				'fid' => $file['id'],
				'type' => $info['type'],
				'info' => $cdata
			];
		}
	}
	if(!empty($update)) {
		$db->insertMulti('fileinfo', $update, true);
	}
	
	if(strlen($file['audioextract']) > 0) {
		$data = FileInfoCompressor::decompress_unpack('audioextract', $file['audioextract']);
		if(empty($data)) die("Failed to decompress audioextract $file[id]\n");
		$newdata = FileInfoCompressor::compress_pack('audioextract', $data, true);
		
		if($newdata != $file['audioextract']) {
			$db->update('files', ['audioextract' => $newdata], 'id='.$file['id']);
		}
	}
}

echo "done\n";
