<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("No toto id supplied\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';
loadDb();
unset($config);

$delMode = false;
array_shift($argv);
if($argv[0] == '-d') {
	$delMode = true;
	array_shift($argv);
}
$argv = array_unique($argv);



require ROOT_DIR.'includes/ulfuncs.php'; // for ulqueue path
require_once ROOT_DIR.'includes/releasesrc.php';
require ROOT_DIR.'includes/finfo.php';
require ROOT_DIR.'includes/arcscrape.php';

$transmission = get_transmission_rpc();
if(!$transmission) {
	die("failed to connect to transmission\n");
}



// reset time in adb_* tables?? - not exactly necessary, because redoing it will set it again, but it may be possible that resolves work differently
foreach($argv as $id) {
	if($id[0] == 't')
		$toto = $db->selectGetArray('toto', 'tosho_id='.(int)substr($id,1));
	elseif($id[0] == 'n')
		$toto = $db->selectGetArray('toto', 'nyaa_id='.(int)substr($id,1).' AND nyaa_subdom=""');
	elseif($id[0] == 's')
		$toto = $db->selectGetArray('toto', 'nyaa_id='.(int)substr($id,1).' AND nyaa_subdom="sukebei"');
	elseif($id[0] == 'd')
		$toto = $db->selectGetArray('toto', 'anidex_id='.(int)substr($id,1));
	elseif($id[0] == 'k')
		$toto = $db->selectGetArray('toto', 'nekobt_id='.(int)substr($id,1));
	elseif($id[0] == 'a')
		$toto = $db->selectGetArray('toto', 'id='.(int)substr($id,1));
	else
		$toto = $db->selectGetArray('toto', 'id='.(int)$id);
	if(empty($toto)) {
		echo "Invalid ID $id - moving along...\n";
		continue;
	}
	$id = $toto['id'];
	
	$dispstr = $toto['name'].' ('.$id.')';
	// TODO: handle torrents table later
	if($db->selectGetField('torrents', 'toto_id', 'toto_id='.$id)) {
		echo "Skipping $dispstr: torrent is active\n";
		continue;
	}
	if($db->selectGetField('ulqueue', 'toto_id', 'toto_id='.$id.' AND status=1')) {
		echo "Skipping $dispstr: currently processed by ulqueue\n";
		continue;
	}
	// urgh, ugly hack
	if($db->selectGetField('fiqueue', 'COUNT(*)', 'fid IN (SELECT id FROM toto_repl.toto_files WHERE toto_id='.$id.')')) {
		echo "Skipping $dispstr: entries in fiqueue exist\n";
		continue;
	}
	if($db->selectGetField('finfo', 'COUNT(*)', 'fid IN (SELECT id FROM toto_repl.toto_files WHERE toto_id='.$id.')')) {
		echo "Skipping $dispstr: entries in finfo exist\n";
		continue;
	}
	if($db->selectGetField('toarchive', 'COUNT(*)', 'toto_id='.$id)) { // TODO: handle this better
		echo "Skipping $dispstr: entries in toarchive exist\n";
		continue;
	}
	if($db->selectGetField('newsqueue', 'COUNT(*)', 'id='.$id)) { // TODO: handle this better
		echo "Skipping $dispstr: entries in newsqueue exist\n";
		continue;
	}
	echo "Processing $dispstr... ";
	$ulqueue = $db->selectGetAll('ulqueue', 'fid', 'toto_id='.$id, 'fid,filename');
	$db->delete('ulqueue', 'toto_id='.$id);
	foreach(array_unique($ulqueue) as $fid => $ulq) {
		// delete ulqueue files
		$dir = TOTO_ULQUEUE_PATH.'file_'.$fid.'/';
		unlink($dir.$ulq['filename']);
		rmdir($dir);
	}
	
	// this can be deleted directly (even if race condition with cron-adb, updates in cron-adb will just simply not update anything)
	$db->delete('adb_resolve_queue', 'toto_id='.$id);
	
	// we won't do any fancy multi-table deletes
	$fids = $db->selectGetAll('files', 'id', 'toto_id='.$id);
	if(!empty($fids)) {
		$db->delete('files', 'toto_id='.$id);
		foreach($fids as &$fid) $fid = reset($fid);
		// this can be deleted directly - cron won't mind
		$fid_in = 'fid IN ('.implode(',', $fids).')';
		$db->delete('filelinks', $fid_in);
		$db->delete('filelinks_active', $fid_in);
		
		$db->delete('attachments', $fid_in);
		// TODO: do we need to delete the files too?  probably won't bother - it's not that big of a deal, since they'll be deduped when we successfully process this again anyway
		// clear out stored screenshots
		$ss_base_path = TOTO_STORAGE_PATH.'sshots/';
		foreach($fids as $_fid) {
			$fid_hash = id2hash($_fid);
			@unlink($ss_base_path .
				substr($fid_hash, 0, 3).'/'.substr($fid_hash, 3, 3).'/'.substr($fid_hash, 6)
				.'.zip');
		}
	}
	
	// remove stored NZB
	$idHash = id2hash($id);
	@unlink(TOTO_STORAGE_PATH.'nzb/'
		.substr($idHash, 0, 3).'/'.substr($idHash, 3, 3).'/'.substr($idHash, 6)
		.'.nzb.gz');
	
	
	if($delMode) {
		$db->delete('toto', 'id='.$id);
	} else {
		// we'll take current details in toto table as accurate
		$db->update('toto', array(
			'added_date' => time(),
			'aid' => 0,
			'eid' => 0,
			'fid' => 0,
			'gids' => '',
			'resolveapproved' => 0,
			'sigfid' => 0,
			'stored_nzb' => 0,
			//'ulcomplete' => 0,
		), 'id='.$id);
		
		
		$update = array();
		
		
		// check if we already have the torrent
		$torrentPath = null;
		if($toto['anidex_id']) {
			$p = make_id_dirs2($toto['anidex_id'], TOTO_STORAGE_PATH.'anidex_archive/');
			$torrentPath = TOTO_STORAGE_PATH.'anidex_archive/'.$p[0].$p[1].'.torrent';
		}
		if($toto['nekobt_id']) {
			if(!function_exists('nekobt_torrent_file_loc')) {
				require ROOT_DIR.'releasesrc/nekobt.php';
			}
			$torrentPath = nekobt_torrent_file_loc($toto['nekobt_id'], true);
		}
		if($toto['nyaa_id']) {
			$storeDir = $toto['nyaa_subdom'] ? 'nyaasis_archive' : 'nyaasi_archive';
			$p = make_id_dirs2($toto['nyaa_id'], TOTO_STORAGE_PATH.$storeDir.'/');
			$torrentPath = TOTO_STORAGE_PATH.$storeDir.'/'.$p[0].$p[1];
		}
		if(!isset($torrentPath) || !file_exists($torrentPath)) {
			$btih = bin2hex($toto['btih']);
			$torrentPath = TOTO_STORAGE_PATH.'torrents/'.substr($btih, 0, 3).'/'.substr($btih, 3).'.torrent';
		}
		
		$ulcomplete = 0;
		if(!file_exists($torrentPath) || !($tinfo = releasesrc_get_torrent('file', $torrentPath, $error, false))) {
			echo "grabbing torrent... ";
			// we already have info from table (yay!) - so just use it :P
			if(!($tinfo = releasesrc_get_torrent('link', $toto['link'], $error, false))) {
				echo "fetching failed: $error";
				// try magnet
				if(!empty($toto['magnet'])) {
					echo " trying magnet... ";
					if(!($tinfo = releasesrc_get_torrent('magnet', $toto['magnet'], $error_magnet, false))) {
						echo "failed: $error_magnet";
						$ulcomplete = -3;
					}
				} else
					$ulcomplete = -3;
			} else {
				$update['btih'] = $tinfo['btih'];
				if(isset($tinfo['btih_sha256']))
					$update['btih_sha256'] = $tinfo['btih_sha256'];
			}
		}
		if(!$ulcomplete) {
			$total_size = 0;
			$torrent_filelist = releasesrc_torrent_filelist($tinfo, $total_size);
			if(empty($torrent_filelist)) {
				echo 'Could not determine filelist!';
				$ulcomplete = -3;
			}
			
			if(@$tinfo['magnetlink'])
				$update['magnet'] = $tinfo['magnetlink'];
		}
		if(!$ulcomplete) {
			if(!releasesrc_add_torrent($tinfo['torrentdata'], $id, 'toto_', $toto['totalsize'])) {
				$ulcomplete = -3;
			}
		}
		releasesrc_save_track_torrent($update, $id, $tinfo);
		$update['ulcomplete'] = $ulcomplete;
		$db->update('toto', $update, 'id='.$id);
		
		
		if(!$ulcomplete) echo "done";
	}
	echo "\n";
}
