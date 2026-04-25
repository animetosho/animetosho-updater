<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("Syntax: [script] torrent_id\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

loadDb();
unset($config);

require ROOT_DIR.'releasesrc/anidex.php';
require ROOT_DIR.'includes/arcscrape.php';

array_shift($argv);

$adex_urlOpts = ['ipresolve' => CURL_IPRESOLVE_V4, 'cookie' => anidex_ddos_cookie()];

// TODO: check existence of IDs
$ids = array_unique($argv);

foreach($ids as $id) {
	$table = 'arcscrape.anidex';
	
	if($id[0] == 'u') {
		$id = substr($id, 1);
		$info = anidex_get_user($id);
		echo "Anidex-user update: $id";
		
		// TODO:
	}
	elseif($id[0] == 'g') {
		$id = substr($id, 1);
		$info = anidex_get_group($id);
		echo "Anidex-group update: $id";
		
		if(!empty($info)) {
			$bufGM = $bufC = $grpClear = [];
			$info = fmt_anidex_group($info, $bufC, $bufGM, $grpClear);
			if($db->selectGetField($table.'_groups', 'id', 'id='.$id)) {
				echo " - updated\n";
				$db->update($table.'_groups', $info, 'id='.$id);
			} else {
				echo " - added\n";
				$db->insert($table.'_groups', $info, 'id='.$id);
			}
			
			if(!empty($grpClear)) {
				$db->delete($table.'_group_members', 'group_id IN ('.implode(',', $grpClear).')');
				$grpClear = [];
			}
			if(!empty($bufGM)) $db->insertMulti($table.'_group_members', $bufGM);
			if(!empty($bufC)) $db->insertMulti($table.'_group_comments', $bufC, true);
			
		} elseif($info === null) {
			echo " - deleted\n";
			// mark as deleted
			$db->update($table.'_groups', ['updated'=>(int)gmdate('U'), 'deleted'=>1], 'id='.$id);
		} else {
			echo " - ERROR\n";
			return false;
		}
	}
	else {
		$info = anidex_get_detail($id);
		echo "Anidex update: $id";
		
		// TODO: mark comments for deletion?
		if(!empty($info['comments'])) foreach($info['comments'] as $cmt) {
			$cmt['torrent_id'] = $id;
			$db->insert($table.'_torrent_comments', [
				'torrent_id' => $id,
				'user_id' => $cmt['user_id'],
				'message' => $cmt['message'],
				'date' => $cmt['date'],
			], true);
		} unset($info['comments']);
		
		$info = fmt_anidex_info($info);
		
		if(!empty($info)) {
			// grab torrent if non existent
			$p = make_id_dirs2($id, TOTO_STORAGE_PATH.'anidex_archive/');
			$tpath = TOTO_STORAGE_PATH.'anidex_archive/'.$p[0].$p[1].'.torrent';
			if(!file_exists($tpath)) {
				echo " - getting torrent...";
				$t = save_torrent('https://anidex.info/dl/'.$id, $tpath, $adex_urlOpts);
				if(isset($t['totalsize']))
					$info['filesize'] = $t['totalsize'];
				else
					$info['filesize'] = 0; // otherwise insert fails with column count mismatches
			}
			
			if($db->selectGetField($table.'_torrents', 'id', 'id='.$id)) {
				echo " - updated\n";
				$db->update($table.'_torrents', $info, 'id='.$id);
			} else {
				echo " - added\n";
				if(!@$info['filesize'] && file_exists($tpath)) {
					// need to grab filesize from existing torrent
					$tinfo = BDecode(@file_get_contents($tpath));
					if(empty($tinfo['info']) || (empty($tinfo['info']['name']) && $tinfo['info']['name'] !== ''))
						echo "! Invalid torrent file: $tpath\n";
					else {
						$info['filesize'] = 0;
						releasesrc_torrent_filelist($tinfo, $info['filesize']);
					}
				}
				$db->insert($table.'_torrents', $info);
			}
		} elseif($info === null) {
			echo " - deleted\n";
			// mark as deleted
			$db->update($table.'_torrents', ['updated'=>(int)gmdate('U'), 'deleted'=>1], 'id='.$id);
		} else {
			echo " - ERROR\n";
			return false;
		}
	}
	sleep(10);
}
