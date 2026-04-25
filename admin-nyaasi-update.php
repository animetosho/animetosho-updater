<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("Syntax: [script] torrent_id\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

loadDb();
unset($config);

require ROOT_DIR.'includes/arcscrape.php';
require ROOT_DIR.'releasesrc/nyaasi.php';

array_shift($argv);

// TODO: check existence of IDs
$ids = array_unique($argv);

foreach($ids as $id) {
	$subdom = '';
	$table = 'arcscrape.nyaasi';
	$storedir = 'nyaasi_archive';
	if($id[0] == 's') {
		$id = substr($id, 1);
		$subdom = 'sukebei';
		$table = 'arcscrape.nyaasis';
		$storedir = 'nyaasis_archive';
	}
	$info = nyaasi_get_detail($id, $subdom);
	echo "Nyaa.si update: $id";
	
	// TODO: mark comments for deletion?
	$bufC = []; $bufU = $bufUlvl = [];
	nyaasi_merge_userinfo($info, $bufU, $bufUlvl);
	if(!empty($info['comments'])) foreach($info['comments'] as $cmt) {
		$cmt['torrent_id'] = $id;
		unset($cmt['level'], $cmt['ava_slug']);
		$bufC[] = $cmt;
	}
	if(!empty($bufC))
		$db->insertMulti($table.'_torrent_comments', $bufC, true);
	nyaasi_commit_userinfo($bufU, $bufUlvl);
	
	
	if(!empty($info)) {
		// grab torrent if non existent
		$path = make_id_dirs2($id, TOTO_STORAGE_PATH.$storedir.'/');
		$path = TOTO_STORAGE_PATH.$storedir.'/'.$path[0].$path[1];
		if(!file_exists($path.'.torrent')) {
			echo " - getting torrent...";
			$t = save_torrent('https://'.($subdom?$subdom.'.':'').'nyaa.si/download/'.$id.'.torrent', $path.'.torrent');
			if(isset($t['filename']))
				$info['torrent_name'] = preg_replace('~\.torrent$~i', '', $t['filename']);
			if(isset($t['totalsize']))
				$info['filesize'] = $t['totalsize'];
		}
		// TODO: if torrent exists, get torrent_name/filesize from it?
		
		unset($info['uploader_level'], $info['comments']);
		if($db->selectGetField($table.'_torrents', 'id', 'id='.$id)) {
			echo " - updated\n";
			unset($info['id'], $info['info_hash'], $info['created_time']);
			$db->update($table.'_torrents', $info, 'id='.$id);
		} else {
			echo " - added\n";
			if(!isset($info['torrent_name'])) $info['torrent_name'] = '';
			if(!isset($info['filesize'])) $info['filesize'] = 0;
			$db->insert($table.'_torrents', $info);
		}
	} elseif($info === null) {
		echo " - deleted\n";
		// mark as deleted
		$db->update($table.'_torrents', ['updated_time'=>(int)gmdate('U'), 'flags'=>'flags|32'], 'id='.$id, true);
	} else {
		echo " - ERROR\n";
		return false;
	}
	sleep(5);
}