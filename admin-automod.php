<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("No id supplied\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';
require_once ROOT_DIR.'includes/admin-funcs.php';
loadDb();
unset($config);



// TODO: don't use copied code!!!

$time = time();
@set_time_limit(900);

array_shift($argv);
$totos = id_resolve_input($argv, 'id,tosho_id,nyaa_id,nyaa_subdom,anidex_id,nekobt_id,sigfid,deleted,btih,ulcomplete,stored_nzb');

require ROOT_DIR.'releasesrc/toto.php';
require ROOT_DIR.'releasesrc/nyaasi.php';
require ROOT_DIR.'releasesrc/anidex.php';
require ROOT_DIR.'releasesrc/nekobt.php';
require ROOT_DIR.'includes/releasesrc.php';

function find_dupe($id, $btih) {
	global $db;
	return $db->selectGetArray('toto', 'btih='.$db->escape($btih).' AND deleted=0 AND id!='.$id, 'id,ulcomplete,sigfid,stored_nzb', array('order' => 'ulcomplete DESC, dateline DESC'));
}

foreach($totos as $id => &$toto) {
	$update = array('lastchecked' => $time);
	$nyaa_r = null;
	echo "#$id: ";
	if($toto['tosho_id']) {
		echo "looking up toto $toto[tosho_id]...";
		$r = toto_get_cached($toto['tosho_id']);
		if($toto['nyaa_id'] && !empty($r)) {
			echo " checking nyaa $toto[nyaa_id]...";
			$nyaa_r = nyaasi_get_item($toto['nyaa_id'], $toto['nyaa_subdom']);
			if(isset($nyaa_r['nyaa_class']))
				$r['nyaa_class'] = $nyaa_r['nyaa_class'];
			if(isset($nyaa_r['nyaa_cat']))
				$r['nyaa_cat'] = $nyaa_r['nyaa_cat'];
		}
		if($toto['anidex_id'] && !empty($r)) {
			echo " checking anidex $toto[anidex_id]...";
			$adex_r = anidex_get_item($toto['anidex_id']);
			if(!empty($adex_r)) foreach($adex_r as $k => $v) {
				if(substr($k, 0, 7) == 'anidex_')
					$r[$k] = $v;
			}
			if(isset($adex_r['nyaa_class']))
				$r['nyaa_class'] = $adex_r['nyaa_class'];
		}
		echo "\n";
	} elseif($toto['nyaa_id']) {
		echo "looking up nyaa $toto[nyaa_id]...";
		$nyaa_r = $r = nyaasi_get_item($toto['nyaa_id'], $toto['nyaa_subdom']);
		// set comment?
		if(@$r['description'] && !isset($r['description'][100]) && !strpos($r['description'], "\n")) {
			$r['comment'] = $r['description'];
		}
		
	} elseif($toto['anidex_id']) {
		echo "looking up anidex $toto[anidex_id]...";
		$adex_r = $r = anidex_get_item($toto['anidex_id']);
	} elseif($toto['nekobt_id']) {
		echo "looking up nekobt $toto[nekobt_id]...";
		$r = nekobt_to_rowinfo(nekobt_get_item_raw($toto['nekobt_id']));
	} else continue;
	
	if($toto['nekobt_id'] && ($toto['nyaa_id'] || $toto['tosho_id']) && !empty($r)) {
		echo " checking nekobt $toto[nekobt_id]...";
		$nbt_r = nekobt_to_rowinfo(nekobt_get_item_raw($toto['nekobt_id'], true));
		if(!empty($nbt_r))
			$r['nekobt_hide'] = $nbt_r['nekobt_hide'];
	}
	
	if($r === null) { // deleted
		echo "Entry deleted!\n";
		$update['deleted'] = 1;
		// is it because we have a dupe?
		// there's a possible (but unlikely) race condition here
		if(!$toto['deleted'] && ($dupe = find_dupe($id, $toto['btih']))) {
			$update['isdupe'] = 1;
			if($toto['ulcomplete'] >= 0 && $dupe['ulcomplete'] < 0) {
				// swap ulcomplete and files
				$update['ulcomplete'] = $dupe['ulcomplete'];
				$dupdate = array('isdupe' => 0, 'ulcomplete' => $toto['ulcomplete']);
				if(!$dupe['stored_nzb'])
					$dupdate['stored_nzb'] = $toto['stored_nzb'];
				if($toto['sigfid'] && !$dupe['sigfid'])
					$dupdate['sigfid'] = $toto['sigfid'];
				$db->update('toto', $dupdate, 'id='.$dupe['id']);
				reassign_toto_attachments($id, $dupe['id']);
				info('Deleted duplicate itemid='.$id.' replacing with id='.$dupe['id'], 'automod-dupe');
			} else {
				$db->update('toto', array('isdupe' => 0), 'id='.$dupe['id']);
				info('Deleted duplicate itemid='.$id.' setting non-dupe id='.$dupe['id'], 'automod-dupe');
			}
		}
	} elseif($r) {
		// the following may be desirable, but it causes a potential issue where attachments have been swaped; we'll consider this unlikely enough, so disable reviving deleted items
		if($toto['deleted']) {
			echo "Entry UNdeleted!\n";
			$update['deleted'] = 0;
			if($dupe = find_dupe($id, $toto['btih'])) {
				// TODO: ensure that there's at least one non-dupe?
				$update['isdupe'] = 1;
			}
		}
		foreach(array('cat','nyaa_cat','name','website','comment','nyaa_class','anidex_cat','anidex_labels','nekobt_hide') as $k)
			if(isset($r[$k]) && $r[$k]) {
				if($k == 'name' || $k == 'website' || $k == 'comment')
					$update[$k] = fix_utf8_encoding($r[$k]);
				else
					$update[$k] = $r[$k];
			}
	} else
		echo "Error\n";
	
	var_dump($update);
	$db->update('toto', $update, 'id='.$id);
}

