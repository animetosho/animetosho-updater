<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("No toto id supplied\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';
require_once ROOT_DIR.'includes/admin-funcs.php';
loadDb();
unset($config);


array_shift($argv);
$set = true;
$force = false;
if($argv[0] == '-t') {
	$set = false;
	array_shift($argv);
}
elseif($argv[0] == '-f') {
	$force = true;
	array_shift($argv);
}


$totos = id_resolve_input($argv, 'id,sigfid,name,resolveapproved');

$fids = [];
$hasErrors = false;
$needFids = [];
foreach($totos as $id => $toto) {
	$err = '';
	if($toto['resolveapproved'] && !$force)
		$err = 'resolve approved, skipping';
	
	if($err) {
		echo "$id ($toto[name]): $err\n";
		unset($totos[$id]);
		$hasErrors = true;
	} else {
		if($toto['sigfid'])
			$fids[] = $toto['sigfid'];
		else
			$needFids[] = $id;
	}
}
if(!empty($needFids)) {
	// grab the largest file for each batch
	$batchFids = $db->selectGetAll('files', 'mid', '', 'MIN(id) AS mid, toto_id', [
		'joins' => ['JOIN (
			SELECT MAX(filesize) AS filesize, toto_id AS tid
			FROM '.$db->table_name('files').'
			WHERE toto_id IN('.implode(',', $needFids).')
			GROUP BY toto_id
		) mf ON files.filesize=mf.filesize AND files.toto_id=mf.tid'],
		'group' => 'toto_id'
	]);
	if(!empty($batchFids)) {
		$fids = array_merge($fids, array_keys($batchFids));
		foreach($batchFids as $bfid)
			echo "$bfid[toto_id] ({$totos[$bfid['toto_id']]['name']}): batch, using fid $bfid[mid]\n";
		echo "\n";
	}
}
if(empty($fids))
	die("No sigfids found\n");

$files = $db->selectGetAll('files', 'ed2k', 'id IN ('.implode(',', $fids).')', 'id,toto_id,ed2k');
if(empty($files)) die("No valid files found\n");

if($hasErrors) echo "\n";

// query for ADB hashes
$afiles = $db->selectGetAll('anidb.file', 'ed2k', 'ed2k IN (UNHEX("'.implode('"),UNHEX("', array_map(function($e) {
	return bin2hex($e['ed2k']);
}, $files)).'"))', '`anidb.file`.*, animetitle.name AS aname, ep.epno, ep.type AS eptype, group.name AS gname', [
	'joins' => [
 		'left join anidb.animetitle ON `anidb.file`.aid=animetitle.aid AND animetitle.type=1',
 		'left join anidb.ep ON `anidb.file`.eid=ep.id',
 		'left join anidb.group ON `anidb.file`.gid=group.id',
	]
]);

$etype_map = ['', '', 'S', 'C', 'T', 'P', 'O'];
foreach($afiles as $af) {
	$tid = $files[$af['ed2k']]['toto_id'];
	$toto =& $totos[$tid];
	
	if(!$toto['sigfid'])
		$af['eid'] = $af['id'] = 0;
	
	if($set) {
		$db->update('toto', [
			'aid' => $af['aid'],
			'eid' => $af['eid'],
			'gids' => $af['gid'],
			'fid' => $af['id'],
			'resolveapproved' => 1
		], 'id='.$tid);
		echo "$toto[id] => $af[id] ($af[aid]/$af[eid]/$af[gid])\n";
	} else {
		if($af['eid'])
			echo "$toto[id] ($toto[name]) => $af[id] ($af[aid]/$af[eid]/$af[gid]: $af[aname] / {$etype_map[$af['eptype']]}$af[epno] / $af[gname])\n";
		else
			echo "$toto[id] ($toto[name]) => ($af[aid]/$af[gid]: $af[aname] / $af[gname])\n";
	}
	
	unset($totos[$tid]);
} unset($toto);

if(!empty($totos)) {
	echo "\nUnmatched:\n";
	foreach($totos as $toto) {
		echo "$toto[id] ($toto[name])\n";
	}
}
