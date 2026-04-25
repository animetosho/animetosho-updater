<?php
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

array_shift($argv);
if(empty($argv)) die("Nothing to process\n");
if($argv == ['-'])
	$do_source = null; // all sources? might break the lock file code
else
	$do_source = array_flip($argv);

// to make the lock file work properly, for now, restrict to processing one source per run
// NOTE: it's not enough to simply make a 'unlock_file' function, as we read the DB state 1x time (to make it work properly, the DB state needs to be re-read after each lock)
if(empty($do_source) || count($do_source) != 1)
	die("Running multiple sources at once currently not supported\n");

make_lock_file(substr(THIS_SCRIPT, 5, -4).'-'.(array_keys($do_source)[0]));

loadDb();
unset($config);

require_once ROOT_DIR.'includes/arcscrape.php';
require ROOT_DIR.'releasesrc/nyaasi.php';
require ROOT_DIR.'releasesrc/anidex.php';
require ROOT_DIR.'releasesrc/toto.php';
require ROOT_DIR.'releasesrc/nekobt.php';

@set_time_limit(0);

$state = [];
foreach($db->selectGetAll('arcscrape._state', 'k') as $r) {
	$state[$r['k']] = $r['v'];
}

$stateUpd = [];

function get_last_id_from_feed($rss, $name, $tag='guid') {
	if(!empty($rss)) {
		$lastLink = trim(reset($rss)[$tag]);
		if(preg_match('~^https?://[a-z.]+/(?:[a-z]+/|[a-z.]+\?id=)(\d+)(?:$|/)~', $lastLink, $m))
			return (int)$m[1];
		else
			warning('Could not get last ID from ['.$name.'] feed'.log_dump_data($rss, 'arcscrape-feed'));
	} else {
		info('Could not retrieve feed from '.$name, 'arcscrape');
	}
	return 0;
}
function buffer_insert($table, &$buf, $row=null, $replace=false) {
	global $db;
	if(isset($row)) {
		$buf[] = $row;
		if(count($buf) > 3) {
			$db->insertMulti($table, $buf, $replace);
			$buf = [];
		}
	} elseif(!empty($buf)) {
		$db->insertMulti($table, $buf, $replace);
		$buf = [];
	}
}
function set_state($k, $v) {
	global $state, $stateUpd;
	if($v != $state[$k])
		$stateUpd[] = ['k'=>$k, 'v'=>$v];
}


$info_upd_limit = 150*86400; // just completely skip entries older than ~5 months
$info_upd_steps = [
	// [threshold, ignore_if_updated_newer_than_this]
	'upd4' => [86400*100, 86400*14], // final long term check
	'upd3' => [86400*10, 86400*2],
	'upd2' => [86400, 7200], // most moderation actions should be within this time
	'upd1' => [7200, 1200], // most immediate changes should be done
];
function run_updates($stateKey, $table, $colId, $colCreat, $colUpdat, $fn, $opts=[]) {
	global $state, $db;
	$delay = SLEEP_DELAY;
	$limit = 50; // limit to 50 items so that we don't choke the main process too much
	if(isset($opts['delay']))
		$delay = $opts['delay'];
	if(isset($opts['limit']))
		$limit = $opts['limit'];
	$lastLimit = (int)gmdate('U') - $GLOBALS['info_upd_limit'];
	if(!empty($opts['creat_expr'])) {
		$colCreatName = '__created_at';
		$colCreatExpr = $colCreat.' AS '.$colCreatName;
	} else {
		$colCreatName = $colCreat;
		$colCreatExpr = $colCreat;
	}
	foreach($GLOBALS['info_upd_steps'] as $u => $t) {
		// select all entries since last scan, within specified time range
		if(isset($opts['creat_where'])) {
			$creatWhere = $opts['creat_where'];
			$timeWhere = $creatWhere($lastLimit);
		} else
			$timeWhere = $colCreatExpr.'>'.$lastLimit;
		$ids = $db->selectGetAll($table, $colId, $colId.'>'.$state[$stateKey.'_'.$u].' AND '.$timeWhere, $colId.','.$colCreatExpr.','.$colUpdat, ['limit' => $limit, 'order' => $colId.' ASC']);
		
		$now = (int)gmdate('U');
		$thresh = $now - $t[0];
		$threshU = $now - $t[1];
		$lastLimit = $thresh;
		if(empty($ids)) continue;
		
		foreach($ids as $row) {
			if($row[$colCreatName] > $thresh) break;
			if($row[$colUpdat] > $threshU) { // recently updated, ignore it for this pass
				set_state($stateKey.'_'.$u, $row[$colId]);
				continue;
			}
			
			if($fn($row) === false) return; // halt process in case of an error
			
			set_state($stateKey.'_'.$u, $row[$colId]);
			if(file_exists(ROOT_DIR.'arcscrape_halt_update')) return; // mechanism to gracefully halt updates
			sleep($delay);
		}
	}
}

/// NYAA.SI
function nyaasi_log_update($sect, $action, $id, $info) {
	if(is_array($info))
		info("$action ID $id, cat={$info['main_category_id']}_{$info['sub_category_id']}, flags={$info['flags']}", 'arcscrape-'.$sect, true);
	else
		info("$action ID $id returned ".gettype($info), 'arcscrape-'.$sect, true);
}
function nyaasi_save_torrent(&$info, $i, $site) {
	$path = make_id_dirs2($i, TOTO_STORAGE_PATH.$site['storedir'].'/');
	$path = TOTO_STORAGE_PATH.$site['storedir'].'/'.$path[0].$path[1].'.torrent';
	if(!file_exists($path)) {
		// Nyaa.si sometimes returns HTTP 404 responses here - maybe the torrent is written async or at a later stage?
		$t = save_torrent($site['url'].'download/'.$i.'.torrent', $path, ['ignore_4xx' => true]);
		if(isset($t['filename']))
			$info['torrent_name'] = preg_replace('~\.torrent$~i', '', $t['filename']);
		if(isset($t['totalsize']))
			$info['filesize'] = $t['totalsize'];
	}
}
function nyaasi_update_item_base(&$info, $i, $site, &$bufC) {
	nyaasi_save_torrent($info, $i, $site);
	
	if(!empty($info['comments'])) foreach($info['comments'] as $cmt) {
		$cmt['torrent_id'] = $i;
		unset($cmt['level'], $cmt['ava_slug']);
		buffer_insert($site['tbl'].'_comments', $bufC, $cmt, true);
	}
	unset($info['comments'], $info['uploader_level']);
}
function nyaasi_add_item($info, $i, $site, &$buf, &$bufC) {
	$info['torrent_name'] = '';
	$info['filesize'] = 0;
	
	nyaasi_update_item_base($info, $i, $site, $bufC);
	buffer_insert($site['tbl'].'s', $buf, $info);
}
function nyaasi_update_item($info, $i, $site, &$bufC) {
	global $db;
	if(empty($info)) {
		if($info === null)
			// mark as deleted
			$db->update($site['tbl'].'s', ['updated_time'=>(int)gmdate('U'), 'flags'=>'flags|32'], 'id='.$i, true);
		else
			return false;
		return true;
	}
	
	nyaasi_update_item_base($info, $i, $site, $bufC);
	unset($info['id'], $info['info_hash'], $info['created_time']);
	$db->update($site['tbl'].'s', $info, 'id='.$i);
	return true;
}
$nyaasi_sites = [];
if(empty($do_source) || isset($do_source['nyaasi']))
	$nyaasi_sites[] = ['url' => 'https://nyaa.si/', 'state_key' => 'nyaasi_upto', 'subdom' => '', 'tbl' => 'arcscrape.nyaasi_torrent', 'storedir' => 'nyaasi_archive', 'delay' => 12, 'skipupd_thresh' => 200, 'update_limit' => 30];
if(empty($do_source) || isset($do_source['nyaasis']))
	$nyaasi_sites[] = ['url' => 'https://sukebei.nyaa.si/', 'state_key' => 'nyaasis_upto', 'subdom' => 'sukebei', 'tbl' => 'arcscrape.nyaasis_torrent', 'storedir' => 'nyaasis_archive', 'delay' => 45, 'skipupd_thresh' => 2400, 'update_limit' => 25];
foreach($nyaasi_sites as $site) {
	$time_start = time();
	
	$buf = $bufC = $bufU = $bufUlvl = [];
	$feedData = parse_feed($site['url'].'rss');
	$last = get_last_id_from_feed($feedData, $site['url'], 'guid');
	$idnext = $state[$site['state_key']]+1;
	if($last) log_event('Retrieving items '.$idnext.' to '.$last.' from '.$site['url']);
	$missing_retry = [];
	for($i=$idnext; $i<=$last; ++$i) {
		echo "Nyaa.si: $i\n";
		$info = nyaasi_get_detail($i, $site['subdom']);
		nyaasi_log_update('nyaasi'.($site['subdom'] ? '-'.$site['subdom']:''), 'Fetch', $i, $info);
		if(!empty($info)) {
			nyaasi_merge_userinfo($info, $bufU, $bufUlvl);
			nyaasi_add_item($info, $i, $site, $buf, $bufC);
		} else
			$missing_retry[] = $i; // Nyaa seems to barf up new entries at times (replies with 404; but we'll also handle other failures) - try again later
		sleep($site['delay']);
	}
	buffer_insert($site['tbl'].'s', $buf); // flush
	buffer_insert($site['tbl'].'_comments', $bufC, null, true);
	nyaasi_commit_userinfo($bufU, $bufUlvl);
	set_state($site['state_key'], $i-1);
	
	// action on detected changes
	foreach(nyaasi_changes_from_feed($feedData, $site['subdom'], $idnext) as $i => $change) {
		echo "Nyaa.si $change: $i\n";
		$info = nyaasi_get_detail($i, $site['subdom']);
		nyaasi_log_update('nyaasi'.($site['subdom'] ? '-'.$site['subdom']:''), ucfirst($change), $i, $info);
		if(!empty($info))
			nyaasi_merge_userinfo($info, $bufU, $bufUlvl);
		
		if($change == 'new') {
			if(!empty($info))
				nyaasi_add_item($info, $i, $site, $buf, $bufC);
			else
				$missing_retry[] = $i;
		} else {
			nyaasi_update_item($info, $i, $site, $bufC);
		}
		sleep($site['delay']);
	}
	buffer_insert($site['tbl'].'s', $buf);
	buffer_insert($site['tbl'].'_comments', $bufC, null, true);
	nyaasi_commit_userinfo($bufU, $bufUlvl);
	
	$time_start_updates = time();
	
	if($time_start_updates - $time_start < $site['skipupd_thresh']) {
		log_event('Looking for updated items from '.$site['url']);
		// update old entries
		run_updates($site['state_key'], $site['tbl'].'s', 'id', 'created_time', 'updated_time', function($row) use(&$site, &$db, &$bufC, &$bufU, &$bufUlvl) {
			// grab info + update
			echo "Nyaa.si update: $row[id]\n";
			$info = nyaasi_get_detail($row['id'], $site['subdom']);
			nyaasi_log_update('nyaasi'.($site['subdom'] ? '-'.$site['subdom']:''), 'Update', $row['id'], $info);
			
			if(!empty($info))
				nyaasi_merge_userinfo($info, $bufU, $bufUlvl);
			if(!nyaasi_update_item($info, $row['id'], $site, $bufC))
				return false;
		}, ['delay' => $site['delay'], 'limit' => $site['update_limit']]);
		buffer_insert($site['tbl'].'_comments', $bufC, null, true);
		nyaasi_commit_userinfo($bufU, $bufUlvl);
	} else {
		log_event('Skipped looking for updated items from '.$site['url']);
	}
	
	if(!empty($missing_retry)) {
		echo "Pausing for retries...\n";
		sleep(max($site['delay'], 60 - (time() - $time_start_updates)));
		log_event('Retrying '.count($missing_retry).' failed new fetch requests to '.$site['url']);
		foreach($missing_retry as $i) {
			echo "Nyaa.si retry: $i\n";
			$info = nyaasi_get_detail($i, $site['subdom']);
			nyaasi_log_update('nyaasi'.($site['subdom'] ? '-'.$site['subdom']:''), 'Fetch', $i, $info);
			if(isset($info))
				info('[Arcscrape] Got data after a failure for '.$site['subdom'].$i, 'nyaasi-det');
			if(!empty($info)) {
				nyaasi_merge_userinfo($info, $bufU, $bufUlvl);
				nyaasi_add_item($info, $i, $site, $buf, $bufC);
			}
			sleep($site['delay']);
		}
		buffer_insert($site['tbl'].'s', $buf); // flush
		buffer_insert($site['tbl'].'_comments', $bufC, null, true);
		nyaasi_commit_userinfo($bufU, $bufUlvl);
	}
}


/// ANIDEX
if(empty($do_source) || isset($do_source['anidex'])) {
	$time_start = time();
	
	$bufT = $bufC = [];
	$users = [];
	$maxGid = 240; // max ID I've found at time of writing
	
	$site = [
		'pref' => 'anidex_',
	];
	
	$tablePref = 'arcscrape.'.$site['pref'];
	$adex_urlOpts = ['ipresolve' => CURL_IPRESOLVE_V4, 'cookie' => anidex_ddos_cookie()];
	$feedData = parse_feed('https://anidex.info/rss/', $adex_urlOpts);
	$last = get_last_id_from_feed($feedData, 'anidex', 'guid');
	if($last) log_event('Retrieving items '.($state[$site['pref'].'upto']+1).' to '.$last.' from anidex.info');
	sleep(SLEEP_DELAY);
	for($i=($state[$site['pref'].'upto']+1); $i<=$last; ++$i) {
		echo "Anidex: $i\n";
		$info = anidex_get_detail($i);
		if(!empty($info)) {
			$tInfo = fmt_anidex_info($info);
			if(@$info['uploader_id'])
				$users[$info['uploader_id']] = $info['uploader_name'];
			if(@$info['group_id'])
				$maxGid = max($maxGid, $info['group_id']);
			
			// grab torrent
			$p = make_id_dirs2($i, TOTO_STORAGE_PATH.'anidex_archive/');
			$tpath = TOTO_STORAGE_PATH.'anidex_archive/'.$p[0].$p[1].'.torrent';
			$t = save_torrent('https://anidex.info/dl/'.$i, $tpath, $adex_urlOpts);
			if(isset($t['totalsize']))
				$tInfo['filesize'] = $t['totalsize'];
			else
				$tInfo['filesize'] = 0; // otherwise insert fails with column count mismatches
			if(mb_strlen($tInfo['torrent_info']) > 10000)
				$tInfo['torrent_info'] = mb_substr($tInfo['torrent_info'], 0, 10000);
			
			buffer_insert($tablePref.'torrents', $bufT, $tInfo);
			if(!empty($info['comments'])) foreach($info['comments'] as $cmt) {
				buffer_insert($tablePref.'torrent_comments', $bufC, [
					'torrent_id' => $i,
					'user_id' => $cmt['user_id'],
					'message' => $cmt['message'],
					'date' => $cmt['date'],
				], true);
				if($cmt['user_id'])
					$users[$cmt['user_id']] = $cmt['user_name'];
			}
		}
		sleep(SLEEP_DELAY);
	}
	buffer_insert($tablePref.'torrents', $bufT); // flush
	buffer_insert($tablePref.'torrent_comments', $bufC, null, true);
	set_state($site['pref'].'upto', $i-1);
	
	function fmt_anidex_user($info) {
		if(!empty($info['groups'])) {
			global $maxGid;
			$maxGid = max($maxGid, max(array_keys($info['groups'])));
		}
		
		$info['language'] = $info['lang_id'];
		unset($info['groups'], $info['lang'], $info['lang_code'], $info['lang_id']);
		$info['username'] = fix_utf8_encoding($info['username']);
		return $info;
	}
	
	// check users
	if(!empty($users)) {
		log_event('Scanning for missing users from anidex');
		$maxUid = 0;
		$userData = $db->selectGetAll($tablePref.'users', 'id', 'id IN ('.implode(',', array_keys($users)).')', 'id,username');
		$bufU = [];
		foreach($users as $uid => $uname) {
			if($uid > $maxUid) $maxUid = $uid;
			if(isset($userData[$uid]) && $userData[$uid]['username'] == $uname) continue;
			
			echo "Anidex user: $uid\n";
			$info = anidex_get_user($uid);
			if(!empty($info)) {
				buffer_insert($tablePref.'users', $bufU, fmt_anidex_user($info), true);
			}
			sleep(SLEEP_DELAY);
		}
		buffer_insert($tablePref.'users', $bufU, null, true);
		
		// check for new/missing users
		if($maxUid > $state[$site['pref'].'user_upto']) {
			$knownUids = $db->selectGetAll($tablePref.'users', 'id', 'id BETWEEN '.($state[$site['pref'].'user_upto']+1).' AND '.$maxUid, 'id');
			
			log_event('Scanning for users '.$state[$site['pref'].'user_upto'].' to '.$maxUid.' from anidex');
			for($i=($state[$site['pref'].'user_upto']+1); $i<=$maxUid; ++$i) {
				if(isset($knownUids[$i])) continue;
				
				echo "Anidex user: $i\n";
				$info = anidex_get_user($i);
				if(!empty($info)) {
					buffer_insert($tablePref.'users', $bufU, fmt_anidex_user($info), true);
				}
				sleep(SLEEP_DELAY);
			}
			buffer_insert($tablePref.'users', $bufU, null, true);
			
			// also scan for new users
			log_event('Scanning for new users from anidex');
			while(1) {
				echo "Anidex user: $i\n";
				$info = anidex_get_user($i);
				if(empty($info)) break;
				buffer_insert($tablePref.'users', $bufU, fmt_anidex_user($info), true);
				++$i;
				if($i - $maxUid > 5000) break; // sanity check
				sleep(SLEEP_DELAY);
			}
			buffer_insert($tablePref.'users', $bufU, null, true);
			
			set_state($site['pref'].'user_upto', $i-1);
		}
		
		
		// only bother updating if something's actually changed
		$bufG = $bufGM = $bufC = $grpClear = [];
		if($maxGid > $state[$site['pref'].'group_upto']) {
			$knownGids = $db->selectGetAll($tablePref.'groups', 'id', 'id BETWEEN '.($state[$site['pref'].'group_upto']+1).' AND '.$maxGid, 'id');
			
			log_event('Scanning for groups '.$state[$site['pref'].'group_upto'].' to '.$maxGid.' from anidex');
			for($i=($state[$site['pref'].'group_upto']+1); $i<=$maxGid; ++$i) {
				if(isset($knownGids[$i])) continue;
				
				echo "Anidex group: $i\n";
				$info = anidex_get_group($i);
				if(!empty($info)) {
					buffer_insert($tablePref.'groups', $bufG, fmt_anidex_group($info, $bufC, $bufGM, $grpClear), true);
				}
				sleep(SLEEP_DELAY);
			}
			buffer_insert($tablePref.'groups', $bufG, null, true);
			if(!empty($grpClear)) {
				$db->delete($tablePref.'group_members', 'group_id IN ('.implode(',', $grpClear).')');
				$grpClear = [];
			}
			buffer_insert($tablePref.'group_members', $bufGM);
			buffer_insert($tablePref.'group_comments', $bufC, null, true);
			
			// also scan for new groups
			log_event('Scanning for new groups from anidex');
			while(1) {
				echo "Anidex user: $i\n";
				$info = anidex_get_group($i);
				if(empty($info)) break;
				buffer_insert($tablePref.'groups', $bufG, fmt_anidex_group($info, $bufC, $bufGM, $grpClear), true);
				++$i;
				if($i - $maxGid > 5000) break; // sanity check
				sleep(SLEEP_DELAY);
			}
			buffer_insert($tablePref.'groups', $bufU, null, true);
			if(!empty($grpClear)) {
				$db->delete($tablePref.'group_members', 'group_id IN ('.implode(',', $grpClear).')');
				$grpClear = [];
			}
			buffer_insert($tablePref.'group_members', $bufGM);
			buffer_insert($tablePref.'group_comments', $bufC, null, true);
			
			set_state($site['pref'].'group_upto', $i-1);
		}
		
	} $users = null;
	
	
	if(time() - $time_start < 300) {
		log_event('Scanning for updated entries from anidex');
		$bufC = [];
		run_updates($site['pref'].'upto', $tablePref.'torrents', 'id', 'date', 'updated', function($row) use(&$site, &$db, $tablePref, &$bufC) {
			// grab info + update
			$id = $row['id'];
			
			echo "Anidex update: $id\n";
			$info = anidex_get_detail($id);
			
			if(!empty($info['comments'])) foreach($info['comments'] as $cmt) {
				$cmt['torrent_id'] = $id;
				$db->insert($tablePref.'torrent_comments', [
					'torrent_id' => $id,
					'user_id' => $cmt['user_id'],
					'message' => $cmt['message'],
					'date' => $cmt['date'],
				], true);
			} unset($info['comments']);
			
			if(!empty($info)) {
				$db->update($tablePref.'torrents', fmt_anidex_info($info), 'id='.$id);
			} elseif($info === null) {
				// mark as deleted
				$db->update($tablePref.'torrents', ['updated'=>(int)gmdate('U'), 'deleted'=>1], 'id='.$id);
			} else {
				// Anidex returns HTTP 500 on some pages, which seem to be permanently broken; instead of halting, just ignore these
				//return false;
			}
		});
		buffer_insert($tablePref.'torrent_comments', $bufC, null, true);
		// TODO: updates for users,groups
	} else {
		log_event('Skipped scanning for updated entries from anidex');
	}
}



/// TOKYOTOSHO
if(empty($do_source) || isset($do_source['tosho'])) {
	$time_start = time();
	
	$buf = [];
	$idnext = $state['tosho_upto']+1;
	$feedData = parse_feed('https://www.tokyotosho.info/rss.php');
	$last = get_last_id_from_feed($feedData, 'tokyotosho', 'guid');
	if($last) log_event('Retrieving items '.$idnext.' to '.$last.' from tosho');
	for($i=$idnext; $i<=$last; ++$i) {
		echo "Tosho: $i\n";
		$info = toto_get_detail($i, true);
		if(!empty($info)) {
			buffer_insert('arcscrape.tosho_torrents', $buf, fmt_toto_info($info));
		}
		sleep(5);
	}
	buffer_insert('arcscrape.tosho_torrents', $buf); // flush
	set_state('tosho_upto', $i-1);
	
	foreach(toto_changes_from_feed($feedData, $idnext) as $i => $change) {
		echo "Tosho $change: $i\n";
		$info = toto_get_detail($i, $change == 'new');
		if(!empty($info)) {
			$tInfo = fmt_toto_info($info);
			if($change == 'new')
				buffer_insert('arcscrape.tosho_torrents', $buf, $tInfo);
			else {
				unset($tInfo['id']);
				$db->update('arcscrape.tosho_torrents', $tInfo, 'id='.$i);
			}
		} elseif($info === null && $change != 'new') {
			$db->update('arcscrape.tosho_torrents', ['updated'=>(int)gmdate('U'), 'deleted'=>1], 'id='.$i);
		}
		
		sleep(5);
	}
	buffer_insert('arcscrape.tosho_torrents', $buf); // flush
	
	if(time() - $time_start < 200) {
		log_event('Looking for updated items from tosho');
		run_updates('tosho_upto', 'arcscrape.tosho_torrents', 'id', 'submitted', 'updated', function($row) use(&$db) {
			// grab info + update
			$info = toto_get_detail($row['id']);
			echo "Tosho update: $row[id]\n";
			
			if(!empty($info)) {
				$tInfo = fmt_toto_info($info);
				unset($tInfo['id']);
				$db->update('arcscrape.tosho_torrents', $tInfo, 'id='.$row['id']);
			} elseif($info === null) {
				// mark as deleted
				$db->update('arcscrape.tosho_torrents', ['updated'=>(int)gmdate('U'), 'deleted'=>1], 'id='.$row['id']);
			} else {
				return false;
			}
		});
	} else {
		log_event('Skipped looking for updated items from tosho');
	}
}




/// NEKOBT
if(empty($do_source) || isset($do_source['nekobt'])) {
	$time_start = time();
	
	$users = []; $groups = [];
	$bufT = [];
	
	$site = [
		'pref' => 'nekobt_',
	];
	
	$tablePref = 'arcscrape.'.$site['pref'];
	$fetch_offset = 0;
	$reached_end = false;
	$nekobt_upto = $state[$site['pref'].'upto'];
	$nekobt_latest = null;
	$nekobt_last_id = null;
	$nekobt_listing_retries = 0;
	$nekobt_old_items = [];
	$nekobt_find_changes_til = $time_start - 86400*2; // scrape the feed back two days to handle moderation
	while(!$reached_end) {
		$items = nekobt_query_latest($fetch_offset);
		if(empty($items)) {
			// for initial fetch, this warning will trigger incorrectly
			// otherwise, if $fetch_offset>0, this is quite problematic as items will be lost
			log_event('Failed to fetch nekoBT items');
			if($fetch_offset) {
				if(++$nekobt_listing_retries < 5) {
					sleep(60);
					continue;
				}
				warning('Failed to fetch nekoBT listing - items '.$nekobt_upto.'-'.$nekobt_last_id.' may be lost', 'nekobt');
			}
			break;
		}
		$nekobt_listing_retries = 0;
		if(!$fetch_offset) {
			$nekobt_latest = $items[0]->id;
			log_event('Retrieving items '.($nekobt_upto).' to '.$nekobt_latest.' from nekoBT');
		}
		$fetch_offset += count($items);
		sleep(SLEEP_DELAY);
		
		foreach($items as $item) {
			// TODO: is nekoBT's ID system always consistently going up?  We'll assume it does
			if($item->id <= $nekobt_upto) {
				// keep track of old items to check for changes in them later
				// first, handle the possibility of dupe items due to them being pushed onto the next page
				$last_id = $nekobt_upto+1;
				if(!empty($nekobt_old_items)) $last_id = end($nekobt_old_items)->id;
				foreach($items as $item2) {
					if($item2->id < $last_id)
						$nekobt_old_items[] = $item2;
				}
				$last_ts = nekobt_id_to_timestamp(end($items)->id);
				$reached_end = ($last_ts <= $nekobt_find_changes_til);
				break;
			}
			if($nekobt_last_id && $item->id >= $nekobt_last_id) continue; // a new item was added, causing a previously processed item to appear in the next page - skip over this
			$nekobt_last_id = $item->id;
			// TODO: do we want to reverse the order of items inserted?
			echo "nekoBT: $item->id\n";
			$info = nekobt_get_detail($item->id);
			if(!empty($info)) {
				// grab torrent
				$tpath = nekobt_torrent_file_loc($info->id, true);
				$t = save_torrent($info->torrent, $tpath);
				
				$tInfo = nekobt_detail_to_arcdb((int)gmdate('U'), $info, $users, $groups);
				buffer_insert($tablePref.'torrents', $bufT, $tInfo);
			} elseif($info === false) {
				// failed fetch; TODO: rescan capability
			}
			sleep(SLEEP_DELAY);
		}
	}
	buffer_insert($tablePref.'torrents', $bufT); // flush
	if($nekobt_latest)
		set_state($site['pref'].'upto', $nekobt_latest);
	
	$nekobt_changes = nekobt_changes_from_latest($nekobt_old_items, $nekobt_upto+1);
	if(!empty($nekobt_changes)) foreach($nekobt_changes as $id => $change) {
		echo "nekoBT $change: $id\n";
		$info = nekobt_get_detail($id);
		if(!empty($info)) {
			$tpath = nekobt_torrent_file_loc($info->id, true);
			if(!file_exists($tpath))
				$t = save_torrent($info->torrent, $tpath);
			
			$tInfo = nekobt_detail_to_arcdb((int)gmdate('U'), $info, $users, $groups);
			if($change == 'new')
				buffer_insert($tablePref.'torrents', $bufT, $tInfo);
			else {
				unset($tInfo['id']);
				$db->update($tablePref.'torrents', $tInfo, 'id='.$id);
			}
		} elseif($info === null && $change != 'new') {
			$db->update($tablePref.'torrents', ['updated_time'=>(int)gmdate('U'), 'deleted'=>-1], 'id='.$id);
		}
		sleep(SLEEP_DELAY);
	}
	buffer_insert($tablePref.'torrents', $bufT); // flush
	
	// flush users/groups
	if(!empty($users))
		$db->insertMulti($tablePref.'users', array_values($users), true);
	if(!empty($groups))
		$db->insertMulti($tablePref.'groups', array_values($groups), true);
	$users = $groups = [];
	
	if(time() - $time_start < 300) {
		log_event('Scanning for updated entries from nekoBT');
		run_updates($site['pref'].'upto', $tablePref.'torrents', 'id', 'floor(id/256000)+1735689600', 'updated_time', function($row) use(&$site, &$db, $tablePref, &$users, &$groups) {
			// grab info + update
			$id = $row['id'];
			
			echo "nekoBT update: $id\n";
			$info = nekobt_get_detail($id);
			
			if(!empty($info)) {
				// if missing torrent, grab it
				$tpath = nekobt_torrent_file_loc($id, true);
				if(!file_exists($tpath))
					$t = save_torrent($info->torrent, $tpath);
				
				$tInfo = nekobt_detail_to_arcdb((int)gmdate('U'), $info, $users, $groups);
				unset($tInfo['id']);
				$db->update($tablePref.'torrents', $tInfo, 'id='.$id);
			} elseif($info === null) {
				// mark as deleted; 1=deleted returned by API, -1=API returning not found error
				$db->update($tablePref.'torrents', ['updated_time'=>(int)gmdate('U'), 'deleted'=>-1], 'id='.$id);
			} else {
				// failed - skip over
			}
		}, ['creat_expr' => true, 'creat_where' => function($ts) {
			return 'id>'.nekobt_timestamp_to_id($ts);
		}]);
		
		// flush users/groups
		if(!empty($users))
			$db->insertMulti($tablePref.'users', array_values($users), true);
		if(!empty($groups))
			$db->insertMulti($tablePref.'groups', array_values($groups), true);
		$users = $groups = [];
	} else {
		log_event('Skipped scanning for updated entries from nekoBT');
	}
	
	// TODO: consider mirroring users, groups, invites, media, comments
}


// update status
if(!empty($stateUpd))
	$db->insertMulti('arcscrape._state', $stateUpd, true);

unset($db);
