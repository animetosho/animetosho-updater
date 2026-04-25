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
$force = false;
if($argv[0] == '-f') {
	$force = true;
	array_shift($argv);
}


$totos = id_resolve_input($argv, 'id,sigfid,name,added_date');


foreach($totos as $id => $toto) {
	if($db->selectGetField('adb_resolve_queue', 'toto_id', 'toto_id='.$id)) {
		echo "$id already in queue - setting priority to highest\n";
		$db->update('adb_resolve_queue', array('dateline' => 86400, 'priority' => 0), 'toto_id='.$id);
		if($force)
			$db->update('toto', array(
				'aid' => 0, 'eid' => 0, 'gids' => '', 'fid' => 0
			), 'id='.$id);
	} else {
		if($force) {
			// clear resolved details
			$db->update('toto', array(
				'aid' => 0, 'eid' => 0, 'gids' => '', 'fid' => 0,
				'resolveapproved' => 0
			), 'id='.$id);
		}
		// add to resolve queue at highest priority
		$queue_add = array(
			'toto_id' => $id,
			'name' => $toto['name'],
			'added' => $toto['added_date'],
			'dateline' => 86400,
			'priority' => 0,
		);
		if($toto['sigfid']) {
			$file = $db->selectGetArray('files', 'id='.$toto['sigfid'], 'filesize,crc32,ed2k');
			foreach($file as $k => &$v)
				$queue_add[$k] = $v;
		}
		$db->insert('adb_resolve_queue', $queue_add);
	}
}
