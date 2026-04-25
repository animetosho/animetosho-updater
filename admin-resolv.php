<?php
if(PHP_SAPI != 'cli') die;
if($argc<3) die("Syntax: [script] [-d] [-aID|-eID|-fID] [-gID] [-p] IDs...\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';
require_once ROOT_DIR.'includes/admin-funcs.php';
loadDb();
unset($config);


array_shift($argv);
$aid = $eid = $fid = null;
$gids = null;
$clear = false;
$approv = null;

while($argv[0][0] == '-') {
	$v = substr($argv[0], 2);
	switch($argv[0][1]) {
		case 'a': $aid = (int)$v; break;
		case 'e': $eid = (int)$v; break;
		case 'f': $fid = (int)$v; break;
		case 'g': $gids = preg_replace('~[^0-9,]~', '', $v); break;
		case 'd': $clear = true; break;
		case 'p': $approv = 0; break;
	}
	array_shift($argv);
	if(empty($argv)) break;
}

if(empty($argv)) die("No IDs supplied\n");


$totos = id_resolve_input($argv, 'id');


$update = [];
if($clear) {
	$update = [
		'fid' => 0,
		'eid' => 0,
		'aid' => 0,
		'gids' => '',
	];
}

if(isset($fid)) {
	$file = $db->selectGetArray('anidb.file', 'id='.$fid);
	if(empty($file)) die("Invalid fid\n");
	$update = [
		'fid' => $fid,
		'eid' => $file['eid'],
		'aid' => $file['aid'],
		'gids' => $file['gid'],
	];
} else {
	if(isset($eid)) {
		$ep = $db->selectGetArray('anidb.ep', 'id='.$eid);
		if(empty($ep)) die("Invalid eid\n");
		$update['eid'] = $eid;
		$update['aid'] = $ep['aid'];
		$update['fid'] = 0;
	}
	elseif(isset($aid)) {
		$anime = $db->selectGetArray('anidb.anime', 'id='.$aid);
		if(empty($anime)) die("Invalid aid\n");
		$update['eid'] = 0;
		$update['aid'] = $aid;
		$update['fid'] = 0;
	}
	
	if(isset($gids)) {
		$groups = $db->selectGetAll('anidb.group', 'id', 'id IN ('.$gids.')');
		if(count($groups) != count(explode(',', $gids)))
			die("Invalid gids\n");
		$update['gids'] = $gids;
	}
}

if(empty($update)) die("Nothing to update\n");
if(!isset($approv))
	$approv = 2;
$update['resolveapproved'] = $approv;
$db->update('toto', $update, 'id IN ('.implode(',', array_keys($totos)).')');
