<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("Syntax: [script] torrent_id\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

loadDb();
unset($config);

require ROOT_DIR.'releasesrc/nekobt.php';
require ROOT_DIR.'includes/arcscrape.php';

array_shift($argv);

$ids = array_unique($argv);

$users = []; $groups = [];
$first = true;
$tablePref = 'arcscrape.nekobt_';
foreach($ids as $id) {
	if(!$first) sleep(10);
	$first = false;
	
	$info = nekobt_get_detail($id);
	echo "nekoBT update: $id";
	
	if(!empty($info)) {
		// grab torrent if non existent
		$tpath = nekobt_torrent_file_loc($id, true);
		if(!file_exists($tpath)) {
			echo " - getting torrent...";
			$t = save_torrent($info->torrent, $tpath);
		}
		
		$tInfo = nekobt_detail_to_arcdb((int)gmdate('U'), $info, $users, $groups);
		if($db->selectGetField($tablePref.'torrents', 'id', 'id='.$id)) {
			echo " - updated\n";
			unset($tInfo['id']);
			$db->update($tablePref.'torrents', $tInfo, 'id='.$id);
		} else {
			echo " - added\n";
			$db->insert($tablePref.'torrents', $tInfo);
		}
	} elseif($info === null) {
		echo " - deleted\n";
		// mark as deleted
		$db->update($tablePref.'torrents', ['updated_time'=>(int)gmdate('U'), 'deleted'=>-1], 'id='.$id);
	} else {
		echo " - ERROR\n";
		return false;
	}
}

if(!empty($users)) {
	echo "Updating ", count($users), " users\n";
	$db->insertMulti($tablePref.'users', array_values($users), true);
}
if(!empty($groups)) {
	echo "Updating ", count($groups), " groups\n";
	$db->insertMulti($tablePref.'groups', array_values($groups), true);
}
