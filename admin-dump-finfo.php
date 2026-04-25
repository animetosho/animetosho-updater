<?php
if(PHP_SAPI != 'cli') die;
if($argc!=2 && $argc!=3) die("Syntax: [script] fid type\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

loadDb();
unset($config);

$fid = intval($argv[1]);

require_once ROOT_DIR.'includes/finfo-compress.php';
if($argc < 3) {
	$infos = $db->selectGetAll('fileinfo', 'type', 'fid='.$fid, 'type');
	if(empty($infos)) die("No info available\n");
	if(count($infos) > 1) {
		die("Available infos: ".implode(' ', array_keys($infos))."\n");
	}
	$type = key($infos);
} else
	$type = strtolower($argv[2]);

if($type == 'filelinks')
	$info = $db->selectGetField('filelinks', 'links', 'fid='.$fid);
elseif($type == 'audioextract')
	$info = $db->selectGetField('files', 'audioextract', 'id='.$fid);
elseif($type == 'attach')
	$info = $db->selectGetField('attachments', 'attachments', 'fid='.$fid);
elseif($type == 'vidframes_info')
	$info = $db->selectGetField('files_extra', 'vidframes_info', 'fid='.$fid);
else
	$info = $db->selectGetField('fileinfo', 'info', 'fid='.$fid.' AND type='.$db->escape($type));
if(!$info) die("Couldn't retrieve specified info\n");

if($type == 'filelinks' || $type == 'audioextract' || $type == 'attach' || $type == 'ffprobe' || $type == 'mediainfoj')
	var_dump(FileInfoCompressor::decompress_unpack($type, $info));
elseif($type == 'vidframes_info')
	var_dump(FileInfoCompressor::munpack($info));
else
	echo FileInfoCompressor::decompress($type, $info), "\n";
