<?php

define('MAGNET_TRY_TIME', 150);

// function no longer used
function toto_item_exists($id, $key, $cache=array(), $where=null) {
	if(isset($cache[$id])) return true;
	if(!empty($cache)) {
		reset($cache);
		if(key($cache) < $id) return false; // newer than what we have
		end($cache);
		if(key($cache) < $id) return false; // ID within range of cache, but not found
	}
	global $db;
	return (bool)$db->selectGetField('toto', $key, $where ?: $key.'='.$id);
}

function releasesrc_store_torrent($btih, $data) {
	$btih = bin2hex($btih);
	$tpath = TOTO_STORAGE_PATH.'torrents/'.substr($btih, 0, 3).'/';
	@mkdir($tpath);
	@chmod($tpath, 0777);
	$tpath .= substr($btih, 3).'.torrent';
	if(!file_exists($tpath)) {
		file_put_contents($tpath, $data);
		@chmod($tpath, 0666);
	} elseif(filesize($tpath) != strlen($data)) {
		// it IS possible to change the torrent and not change the BTIH, but mark as different for now
		return 2;
	}
	return 1;
}

function releasesrc_add($rowinfo, $dirpref, $disable_skipping=false, $extra_srcInfo=[]) {
	$time = time();
	global $db;
	// to prevent unnecessary remote fetching, skip files early if we aren't gonna add them anyway
	if(!$disable_skipping) {
		if($reason = releasesrc_ignore_file($rowinfo, $extra_srcInfo)) {
			if(is_string($reason))
				info($reason.'; '.$rowinfo['name'], 'releasesrc-skip');
			return;
		}
	}
	
	$error = '';
	$total_size = 0;
	$torrent_name = '';
	$ulcomplete = 0;
	$torrent_filelist = array();
	if(empty($rowinfo['torrentfile']) || !($tinfo = releasesrc_get_torrent('file', $rowinfo['torrentfile'], $error))) {
		if(empty($rowinfo['link']) || !($tinfo = releasesrc_get_torrent('link', $rowinfo['link'], $error))) {
			// fetch failed, try magnet
			if(empty($rowinfo['magnetlink']) || !($tinfo = releasesrc_get_torrent('magnet', $rowinfo['magnetlink'], $error_magnet))) {
				if(@$error_magnet) $error .= ($error?"\n":'').$error_magnet;
				
				if(!$error) $error = 'No valid torrent or magnet link available! '.json_encode($rowinfo);
				warning($error, 'torrent');
				$ulcomplete = -3;
				$tinfo = null;
			} elseif(!empty($rowinfo['link']))
				info('[Torrent] Fetching '.$rowinfo['link'].' failed, but successfully resolved torrent from magnet', 'releasesrc');
		}
	}
	
	$torrentOpts = [];
	if(!$ulcomplete) {
		$torrent_filelist = releasesrc_torrent_filelist($tinfo, $total_size);
		if(empty($torrent_filelist)) {
			warning('[Torrent] Could not determine filelist! link='.$rowinfo['link']);
			$ulcomplete = -3;
		}
		else {
			$skipReason = '';
			if(!$disable_skipping) {
				$skipReason = releasesrc_skip_file($total_size, $tinfo, $rowinfo, $extra_srcInfo, $still_add);
				if(!$skipReason) {
					$unwantedFiles = releasesrc_unwanted_files($tinfo, $rowinfo, $extra_srcInfo);
					if($unwantedFiles === true)
						$skipReason = 'No files wanted';
					elseif($unwantedFiles) {
						$torrentOpts['files-unwanted'] = $unwantedFiles;
						info('Skipping '.count($unwantedFiles).' unwanted files from torrent '.$rowinfo['name'], 'releasesrc-skip');
					}
				}
			}
			
			if($skipReason) {
				$skipLog = $skipReason.'; '.$rowinfo['name'].' (';
				if(@$rowinfo['tosho_id'])
					$skipLog .= 't'.$rowinfo['tosho_id'];
				elseif(@$rowinfo['nyaa_id'])
					$skipLog .= (@$rowinfo['nyaa_subdom'] ? substr($rowinfo['nyaa_subdom'], 0, 1):'n').$rowinfo['nyaa_id'];
				elseif(@$rowinfo['anidex_id'])
					$skipLog .= 'd'.$rowinfo['anidex_id'];
				elseif(@$rowinfo['nekobt_id'])
					$skipLog .= 'k'.$rowinfo['nekobt_id'];
				$skipLog .= ')';
				info($skipLog, 'releasesrc-skip');
				if(!$still_add) {
					$db->insert('skip_fetch', array('link' => $rowinfo['link'], 'dateline' => $time), true);
					return;
				}
				$ulcomplete = -1;
			}
			else {
				$torrent_name = fix_utf8_encoding($tinfo['info']['name']);
				$torrent_name = strtr($torrent_name, ["\r"=>'', "\n"=>' ']);
			}
		}
	}
	
	// insert into DB
	if(!isset($rowinfo['nyaa_class'])) $rowinfo['nyaa_class'] = 0;
	if(!isset($rowinfo['nekobt_hide'])) $rowinfo['nekobt_hide'] = 0;
	if(!isset($rowinfo['website'])) $rowinfo['website'] = '';
	if(!isset($rowinfo['comment'])) $rowinfo['comment'] = '';
	if(!@$rowinfo['name']) $rowinfo['name'] = $torrent_name;
	$now = time();
	$dbrecord = array(
		'tosho_id' => @$rowinfo['tosho_id'] ?: 0,
		'nyaa_id' => @$rowinfo['nyaa_id'] ?: 0,
		'nyaa_subdom' => @$rowinfo['nyaa_subdom'] ?: '',
		'name' => $rowinfo['name'],
		'link' => @$rowinfo['link'] ?: '',
		'cat' => $rowinfo['cat'],
		'nyaa_cat' => @$rowinfo['nyaa_cat'] ?: '',
		'nyaa_class' => $rowinfo['nyaa_class'],
		'website' => (string)$rowinfo['website'],
		'dateline' => @$rowinfo['dateline'] ?: $now,
		'comment' => (string)$rowinfo['comment'],
		'added_date' => $now,
		
		// these will be updated a second time in releasesrc_save_track_torrent
		'torrentname' => $torrent_name,
		'torrentfiles' => count($torrent_filelist),
		'totalsize' => $total_size,
		
		'ulcomplete' => $ulcomplete,
		'srccontent' => '',
		'anidex_id' => (int)@$rowinfo['anidex_id'],
		'anidex_cat' => @$rowinfo['anidex_cat'],
		'anidex_labels' => @$rowinfo['anidex_labels'],
		'nekobt_id' => (int)@$rowinfo['nekobt_id'],
	);
	if(!@$rowinfo['dateline'] && isset($tinfo) && (int)@$tinfo['creation date']) // if source (HorribleSubs) doesn't supply a time, pull from torrent instead
		$dbrecord['dateline'] = (int)$tinfo['creation date'];
	if(isset($tinfo['magnetlink'])) {
		$dbrecord['magnet'] = $tinfo['magnetlink'];
		if(!empty($tinfo['btih'])) {
			$dbrecord['btih'] = $tinfo['btih'];
			// check dupe
			$duperecord = $db->selectGetArray('toto', 'btih='.$db->escape($tinfo['btih']).' AND isdupe=0', 'id,ulcomplete,deleted,sigfid,stored_nzb,tosho_id,nyaa_id,anidex_id,nekobt_id');
			if(!$duperecord) {
				// try filesize+name+date match for specific releases (at this time, SallySubs only)
				if(stripos($rowinfo['name'], '[SallySubs]') === 0) {
					$duperecord = $db->selectGetArray('toto', 'name='.$db->escape($rowinfo['name']).' AND totalsize='.$total_size.' AND dateline > '.($rowinfo['dateline'] - 86400*3).' AND isdupe=0 AND added_date>'.($now-86400*30), 'id,ulcomplete,deleted,sigfid');
				}
			}
			if($duperecord) {
				if(!$duperecord['tosho_id'] && !$duperecord['nyaa_id'] && !$duperecord['anidex_id'] && !$duperecord['nekobt_id']) {
					// the existing entry is "orphaned" (i.e. pulled from HS), we'll just overwrite the existing entry instead of adding a new one
					info('Replacing orphaned id='.$duperecord['id'].' with "'.$dbrecord['name'].'".', 'releasesrc-dupe');
					unset($dbrecord['dateline'],
					      $dbrecord['added_date'],
					      $dbrecord['torrentname'],
					      $dbrecord['torrentfiles'],
					      $dbrecord['ulcomplete'],
					      $dbrecord['srccontent']
					     );
					$db->update('toto', $dbrecord, 'id='.$duperecord['id']);
					return;
				} else {
					$dupeinfomsg = 'Adding "'.$dbrecord['name'].'" is duplicate of id='.$duperecord['id'].'. ';
					if($duperecord['ulcomplete'] < 0 && $ulcomplete >= 0) {
						// previous failed, but this one didn't -> continue and mark previous as dupe
						$db->update('toto', array('isdupe'=>1), 'id='.$duperecord['id']);
						info($dupeinfomsg.'Adding regardless because previous failed', 'releasesrc-dupe');
					} elseif($duperecord['deleted']) {
						$db->update('toto', array('isdupe'=>1), 'id='.$duperecord['id']);
						if($duperecord['ulcomplete'] >= 0) {
							// shift everything over
							$shift_attach_from = $duperecord['id'];
							// careful with this divergence! skip pulling this file, but mark it same as dupe
							$dbrecord['ulcomplete'] = $duperecord['ulcomplete'];
							$dbrecord['stored_nzb'] = $duperecord['stored_nzb'];
							$dbrecord['sigfid'] = $duperecord['sigfid'];
							$ulcomplete = -1;
							info($dupeinfomsg.'Shifting stuff over to this one because previous was deleted', 'releasesrc-dupe');
						} else {
							info($dupeinfomsg.'Adding regardless because previous was deleted', 'releasesrc-dupe');
						}
					} else {
						$dbrecord['isdupe'] = 1;
						if($ulcomplete >= 0)
							$dbrecord['ulcomplete'] = $ulcomplete = -1; // skip this, because we have a previous success
						info($dupeinfomsg.'Marking this as dupe', 'releasesrc-dupe');
					}
				}
			}
		}
	}
	if(isset($tinfo['btih_sha256']))
		$dbrecord['btih_sha256'] = $tinfo['btih_sha256'];
	if(!$db->insert('toto', $dbrecord)) {
		warning('Failed to insert record! name = '.$dbrecord['name']);
		return;
	}
	$toto_update = array();
	$newid = $db->insertId();
	
	require_once ROOT_DIR.'anifuncs.php';
	$toto_update = resolve_name($newid, $rowinfo['name'], time(), array());
	
	if(!$ulcomplete && !releasesrc_add_torrent($tinfo['torrentdata'], $newid, $dirpref, $total_size, $torrentOpts)) {
		$toto_update['ulcomplete'] = -3;
	}
	if(isset($tinfo))
		releasesrc_save_track_torrent($toto_update, $newid, $tinfo);
	if(!empty($toto_update))
		$db->update('toto', $toto_update, 'id='.$newid);
	if(isset($shift_attach_from))
		reassign_toto_attachments($shift_attach_from, $newid);
}

// check for errors sent back by pages
function releasesrc_torrent_data_error($url, $torrent, $will_retry=false) {
	if(!function_exists('nyaa_data_tor_deleted')) {
		require_once ROOT_DIR.'releasesrc/nyaa.php';
	}
	$from_nyaa = preg_match('~^https?\://(www\.|sukebei\.)?nyaa\.(se|eu)/~', $url);
	if($from_nyaa && nyaa_data_tor_deleted($torrent))
		return 'Nyaa link dead (URL='.$url.')';
	elseif(!$will_retry && $from_nyaa && strpos($torrent, '<img src="http://files.nyaa.se/www-download.png" alt="Download">'))
		return 'Nyaa weirdly returning the torrent info page (URL='.$url.')';
	elseif(preg_match('~^https?\://(www\.)?bakabt\.me/download/~', $url) && (strpos($torrent, '<td>Invalid download link. Hash is invalid.') || strpos($torrent, '<td>Download link has expired. Links are only valid for ')))
		return 'Invalid BakaBT download (URL='.$url.')';
	elseif(preg_match('~^http\://(www\.)?torrentcaching\.com/torrent/[0-9a-fA-F]{40}\.torrent$~', $url) && (($GLOBALS['CURL_ERRNO'] == 22 /*CURLE_HTTP_RETURNED_ERROR*/ && $GLOBALS['CURL_INFO']['http_code'] == 404) || strpos($torrent, '<title>404 Not Found</title>')))
		return 'TorrentCaching 404';
	return false;
}

function get_torrent_from_magnet($url, &$error='') {
	if(MAGNET_TRY_TIME) {
		// fetch torrent from magnet
		$tmpdir = make_temp_dir();
		$rd = '';
		$rc = timeout_exec('aria2c --follow-torrent=false --bt-metadata-only=true --bt-save-metadata=true --enable-dht=true --dht-listen-port='.(11243+mt_rand(0,50)).' --bt-stop-timeout='.MAGNET_TRY_TIME.' --bt-tracker-connect-timeout='.(MAGNET_TRY_TIME/2).' --bt-tracker="http://tracker.minglong.org:8080/announce,udp://tracker.opentrackr.org:1337/announce,udp://tracker.coppersurfer.tk:6969/announce,http://anidex.moe:6969/announce,http://tracker.anirena.com:80/announce,udp://tracker.pirateparty.gr:6969/announce,http://nyaa.tracker.wf:7777/announce,udp://tracker.openbittorrent.com:6969/announce" --dir='.escapeshellarg($tmpdir).' '.escapeshellarg($url), MAGNET_TRY_TIME+10, $rd);
		$torrent = '';
		$tfile = glob($tmpdir.'*.torrent');
		if(!empty($tfile)) {
			$tfile = $tmpdir . basename(reset($tfile));
			$torrent = @file_get_contents($tfile);
			@unlink($tfile);
		}
		@rmdir($tmpdir);
		if(!$torrent) {
			$error = 'Failed to fetch torrent file from magnet link (retcode='.$rc.', URL='.$url.')'.log_dump_data($rd, 'aria2_magnet');
			return false;
		}
		return $torrent;
	} else
		return false;
}

function releasesrc_create_magnet($tinfo) {
	// 'dn' parameter not needed (added in front-end)
	// (then again, arguably the front-end could add BTIH as well, but we have to maintain the magnet link field)
	$magnet = 'magnet:?xt=urn:btih:'.base32_encode($tinfo['btih']);
	if(isset($tinfo['btih_sha256'])) {
		$magnet .= '&xt=urn:btmh:1220'.bin2hex($tinfo['btih_sha256']);
	}
	
	$added_trackers = [];
	if(isset($tinfo['announce']) && is_string($tinfo['announce'])) {
		$magnet .= '&tr='.rawurlencode($tinfo['announce']);
		$added_trackers[strtolower($tinfo['announce'])] = 1;
	}
	// add a few additional trackers
	if(!empty($tinfo['announce-list']) && is_array($tinfo['announce-list'])) {
		foreach($tinfo['announce-list'] as $tier => $annList) {
			if(empty($annList) || !is_array($annList)) continue;
			foreach($annList as $t) {
				$t = (string)$t;
				if(!isset($added_trackers[strtolower($t)])) {
					$magnet .= '&tr='.rawurlencode($t);
					$added_trackers[strtolower($t)] = 1;
					if(count($added_trackers) >= 5) break;
				}
			}
			if(count($added_trackers) >= 5) break;
		}
	}
	// xl? uTorrent doesn't put it in, so we won't bother either	
	return $magnet;
}

function releasesrc_get_torrent($srctype, $src, &$error='', $do_retry=true) {
	require_once ROOT_DIR.'3rdparty/BDecode.php';
	if($srctype == 'magnet') {
		if(!($torrent = get_torrent_from_magnet($src, $error)))
			return false;
		$tinfo = BDecode($torrent);
	} elseif($srctype == 'link') {
		// ??? the following sometimes fails?
		for($i=0; $i<5; ++$i) {
			$torrent = send_request($src, $null, ['conntimeout' => 60, 'timeout' => 150]);
			if($torrent) {
				$tinfo = BDecode($torrent);
				if(!empty($tinfo['info']) && (!empty($tinfo['info']['name']) || $tinfo['info']['name'] === '')) break;
			}
			if(!$do_retry || releasesrc_torrent_data_error($src, $torrent, true)) break;
			// if the data returned indicates that the torrent is bad, don't bother retrying
			if(substr($GLOBALS['CURL_INFO']['http_code'], 0, 1) == '4') break; // HTTP 4xx error
			sleep(($i+1)*10);
		}
		if(!$torrent) {
			if(stripos($src, '%26amp%3b') !== false) {
				info('Failed to retrieve torrent at specified URL ('.$src.'), but will retry with altered URL', 'releasesrc');
				return releasesrc_get_torrent($srctype, str_ireplace('%26amp%3b', '%26', $src), $error, $do_retry);
			}
			$error = 'No torrent data received (URL='.$src.', tries='.$i.', code='.$GLOBALS['CURL_INFO']['http_code'].')';
			return false;
		}
	} elseif($srctype == 'file') {
		// grab from filesystem
		// TODO: do some basic level of protection?
		if(!file_exists($src)) {
			// have seen this happen on a deleted torrent (e.g. probably fetched DB entry, but failed to fetch torrent before deletion)
			$error = 'File missing: '.$src;
			return false;
		}
		$torrent = file_get_contents($src);
		$tinfo = BDecode($torrent);
	} else {
		$error = 'Unknown source type: '.$srctype;
		return false;
	}
	if(empty($tinfo['info']) || (empty($tinfo['info']['name']) && $tinfo['info']['name'] !== '')) {
		if($srctype == 'link' && ($err = releasesrc_torrent_data_error($src, $torrent)))
			$error = $err;
		else
			$error = 'Bad torrent file ('.$srctype.': '.$src.')'.log_dump_data($torrent, 'releasesrc_torrent');
		return false;
	}
	if(@$i) {
		info('Successfully got torrent/data after '.$i.' retry. ('.$srctype.': '.$src.')', 'releasesrc');
	}
	$tinfo['torrentdata'] = &$torrent;
	if($info_str = torrent_info_str($torrent)) {
		$tinfo['btih'] = sha1($info_str, true);
		if(isset($tinfo['info']['meta version']) && $tinfo['info']['meta version'] == 2)
			$tinfo['btih_sha256'] = hash('sha256', $info_str, true);
		$tinfo['magnetlink'] = releasesrc_create_magnet($tinfo);
	}
	return $tinfo;
}

function releasesrc_torrent_filelist(&$tinfo, &$totsize=null) {
	if(!isset($tinfo['info'])) return false;
	$info =& $tinfo['info'];
	if(empty($info['files'])) {
		if(!isset($info['name']) || !isset($info['length'])) return false;
		if(isset($totsize))
			$totsize = $info['length'];
		return array(str_replace("\0", '', fix_utf8_encoding($info['name'])));
	}
	
	if(!is_array($info['files'])) return false;
	
	$ret = array();
	$size = '0';
	foreach($info['files'] as &$f) {
		if(!isset($f['path']) || !is_array($f['path']) || !isset($f['length']) || !is_numeric($f['length'])) return false;
		$ret[] = str_replace("\0", '', fix_utf8_encoding(implode('/', $f['path'])));
		$size = bcadd($size, $f['length']);
	}
	if(isset($totsize))
		$totsize = $size;
	return $ret;
}

function releasesrc_save_track_torrent(&$update, $toto_id, $tinfo) {
	$update['torrentname'] = strtr(fix_utf8_encoding($tinfo['info']['name']), ["\r"=>'', "\n"=>' ']);
	$total_size = 0;
	$torrent_filelist = releasesrc_torrent_filelist($tinfo, $total_size);
	if(!empty($torrent_filelist)) {
		$update['torrentfiles'] = count($torrent_filelist);
		$update['totalsize'] = $total_size;
	}
	
	if(!empty($tinfo['btih'])) {
		if(isset($tinfo['torrentdata']))
			$update['stored_torrent'] = releasesrc_store_torrent($tinfo['btih'], $tinfo['torrentdata']);
		
		// insert tracker stat tracking
		global $db;
		$trackers = array();
		if(!empty($tinfo['announce-list']) && is_array($tinfo['announce-list'])) {
			foreach($tinfo['announce-list'] as $tier => $annList) {
				if(empty($annList) || !is_array($annList)) continue;
				if($tier > 100) continue;
				foreach($annList as $t) {
					$t = (string)$t;
					if(strlen($t) > 250) continue;
					if(!isset($trackers[$t]))
						$trackers[$t] = $tier+1;
				}
			}
		}
		if(is_string(@$tinfo['announce']) && strlen($tinfo['announce']) <= 250)
			$trackers[$tinfo['announce']] = 0;
		
		if(!empty($trackers)) {
			// grab tracker IDs
			// note that we'll assume that no tracker is stupid enough to have multiple casings in their URL (e.g. example.com/announce and example.com/Announce)
			$tids = $db->selectGetAll('trackers', 'url', 'url IN ('.implode(',', array_map(array($db, 'escape'), array_keys($trackers))).')');
			$tids = strtolower_keys($tids);
			
			if(count($tids) != count($trackers)) {
				// missing stuff, create them
				$missing = array();
				foreach($trackers as $t => $junk) {
					$t = strtolower($t);
					if(!isset($tids[$t]))
						$missing[$t] = $t;
				}
				$db->insertMulti('trackers', array_map(function($t) {
					return array('url' => $t);
				}, $missing), false, true);
				// we could build the IDs from the inserted ID, but be lazy instead and re-query them
				// (this also gets around any possible race conditions, although there should be none)
				$tids2 = $db->selectGetAll('trackers', 'url', 'url IN ('.implode(',', array_map(array($db, 'escape'), $missing)).')');
				if(count($tids2) != count($missing)) {
					// wtf??
					error('Failed to resolve tracker IDs, list: '.implode(',', array_keys($trackers)));
				}
				$tids2 = strtolower_keys($tids2);
				$tids = array_merge($tids, $tids2);
			}
			
			// insert into tracker stats
			$db->insertMulti('tracker_stats', array_map(function($tracker, $tier) use($tids, $toto_id) {
				return array(
					'id' => $toto_id,
					'tracker_id' => $tids[strtolower($tracker)]['id'],
					'tier' => $tier,
					'last_queried' => 0,
					'updated' => 0,
				);
			}, array_keys($trackers), array_values($trackers)), false, true);
			
			if(is_string(@$tinfo['announce']) && isset($tids[strtolower($tinfo['announce'])]))
				$update['main_tracker_id'] = $tids[strtolower($tinfo['announce'])]['id'];
		}
	}
}

function releasesrc_add_torrent(&$data, $id, $dirpref, $totalsize, $opts=[]) {
	global $transmission;
	$path = TORRENT_DIR.$dirpref.$id; // use default path
	
	@mkdir($path);
	@chmod($path, 0777);
	$addopts = array_merge(array('paused' => false), $opts);
	try {
		if(substr($data, 0, 8) == 'magnet:?')
			$addedtorrent = $transmission->add_file($data, $path, $addopts);
		else
			$addedtorrent = $transmission->add_metainfo($data, $path, $addopts);
	} catch(TransmissionRPCException $e) {
		if(preg_match('~^Unable to connect to http\://~', $e->getMessage())) {
			// server is likely under heavy load  so pause for a little while, then search for what we need
			sleep(120);
			if($addedtorrent = find_torrent_by_path($transmission, $path)) {
				$trans_torrent =& $addedtorrent;
				info('Failed to connect to Transmission when adding '.$id.', but torrent added fine anyway', 'releasesrc');
			} else {
				error('[Torrent] Failed to connect to transmission to add torrent '.$id);
				return false;
			}
		} else {
			warning('[Torrent] Exception thrown when attempting to add to transmission, dir='.$dirpref.$id.' ('.$e->getMessage().')'.log_dump_data($data, 'torrent-data'));
			return false;
		}
	}
	
	global $db;
	if(!isset($trans_torrent)) {
		if(isset($addedtorrent->arguments->torrent_added))
			$trans_torrent =& $addedtorrent->arguments->torrent_added;
		elseif(isset($addedtorrent->arguments->torrent_duplicate) && @$addedtorrent->result == 'success') {
			// urgh, duplicate!  maybe this has occurred due to the automatic retry?
			// double check that it doesn't have a DB entry
			$trans_torrent =& $addedtorrent->arguments->torrent_duplicate;
			if($db->selectGetArray('torrents', 'hashString='.$db->escape(pack('H*', $trans_torrent->hashString)))) {
				warning('[Torrent] Cannot add due to duplicate transmission torrent, dir='.$dirpref.$id);
				return false;
			}
			info('[Torrent] Add resulted in a duplicate response: '.json_encode($trans_torrent), 'releasesrc');
		}
	}
	
	if(isset($trans_torrent)) {
		// magnets won't have a torrent name
		$name = @$trans_torrent->name;
		if(!isset($name)) $name = '';
        $time = time();
		$db->insert('torrents', array(
			'id' => $trans_torrent->id,
			'name' => $name,
			'hashString' => pack('H*', $trans_torrent->hashString),
			'folder' => $dirpref.$id,
			'toto_id' => $id,
			'dateline' => $time,
			'lastreannounce' => $time,
			'totalsize' => $totalsize,
		));
		// check for weird pause issue
		try {
			$added_status = $transmission->get($trans_torrent->id, array('status'));
			if(!empty($added_status->arguments->torrents)) {
				if(count($added_status->arguments->torrents) == 1) {
					$tor = reset($added_status->arguments->torrents);
					if(@$tor->status == TransmissionRPC::TR_STATUS_STOPPED) {
						// have observed this if things are too slow, so wait and recheck
						sleep(10);
						try {
							$added_status = $transmission->get($trans_torrent->id, array('status'));
							if(!empty($added_status->arguments->torrents) && count($added_status->arguments->torrents) == 1) {
								$tor = reset($added_status->arguments->torrents);
								if(@$tor->status == TransmissionRPC::TR_STATUS_STOPPED) {
									// TODO: consider starting torrent
									warning('[Torrent] Added torrent is paused! dir='.$dirpref.$id);
								}
							} else {
								warning('[Torrent] Added torrent was paused, but now missing! dir='.$dirpref.$id);
							}
						} catch(TransmissionRPCException $e) {
							warning('[Torrent] Added torrent is paused, but failed to requery for second check! dir='.$dirpref.$id);
						}
					}
				} else {
					warning('[Torrent] Unexpected response to Transmission query for added torrent! dir='.$dirpref.$id.'; response='.json_encode($added_status));
				}
			} else {
				warning('[Torrent] Could not query Transmission for added torrent! dir='.$dirpref.$id.'; response='.json_encode($added_status));
			}
		} catch(TransmissionRPCException $e) {
			warning('[Torrent] Could not query Transmission for added torrent! dir='.$dirpref.$id);
		}
		return true;
	} elseif(@$addedtorrent->result == 'duplicate torrent') {
		// TODO: try to match the dupe torrent
		
		warning('[Torrent] Duplicate transmission torrent, dir='.$dirpref.$id);
		return false;
	} elseif(@$addedtorrent->result == 'invalid or corrupt torrent file') {
		warning('[Torrent] Transmission rejected torrent as corrupt, dir='.$dirpref.$id.log_dump_data($data, 'torrent-data'));
		return false;
	}
	warning('[Torrent] Transmission didn\'t return expected response, dir='.$dirpref.$id.log_dump_data($addedtorrent, 'transmission_response'));
	return false;
}

function find_torrent_by_path(&$transmission, $path) {
	$torrents = $transmission->get(array(), array('id', 'name', 'status', 'hashString', 'downloadDir'));
	if(empty($torrents->arguments->torrents)) return false;
	$ret = false;
	foreach($torrents->arguments->torrents as &$torrent) {
		if($torrent->downloadDir == $path) {
			if($ret)
				return false; // too many matches
			else
				$ret = $torrent;
		}
	}
	return $ret;
}

require ROOT_DIR.'includes/releasesrc_skip.php';


// for duplicate handling, reassign attached stuff to new id
function reassign_toto_attachments($oldid, $newid) {
	global $db;
	// TODO: race conditions with these?
	$db->update('files', array('toto_id' => $newid), 'toto_id='.$oldid);
	$db->update('torrents', array('toto_id' => $newid), 'toto_id='.$oldid);
	$db->update('ulqueue', array('toto_id' => $newid), 'toto_id='.$oldid);
	// don't swap adb_resolve_queue because we resolve both
	
	// toarchive, newsqueue: we don't do these here - the relevant scripts will do a scan at the end and deal with them
	
	// we always update stored_nzb, so just make a copy of the NZB
	$nzbPath = TOTO_STORAGE_PATH.'nzb/';
	$oldidHash = id2hash($oldid);
	$nzbSrc = 
		$nzbPath
		.substr($oldidHash, 0, 3).'/'.substr($oldidHash, 3, 3).'/'.substr($oldidHash, 6)
		.'.nzb.gz';
	
	if(@file_exists($nzbSrc)) {
		include_once ROOT_DIR.'includes/finfo.php'; // for make_id_dirs
		$nzbDest = 
			$nzbPath.
			implode('', make_id_dirs($newid, $nzbPath)).
			'.nzb.gz';
		if(!file_exists($nzbDest))
			link_or_copy($nzbSrc, $nzbDest);
	}
}


// given a BDecoded torrent, compute the SHA1 of the full pieces of the first file
// used as a crude file hash of the first file
function torrent_compute_torpc_sha1($tinfo) {
	$info =& $tinfo['info'];
	
	if(empty($info['files'])) {
		if(!isset($info['name']) || !isset($info['length'])) return false;
		$files = [['length' => $info['length'], 'path' => [$info['name']]]];
	} else
		$files = $info['files'];
	
	$blocksz = $info['piece length'];
	$ret = ['piece_size' => $blocksz];
	$offset = 0;
	foreach($files as &$f) {
		if(!isset($f['path']) || !is_array($f['path']) || !isset($f['length']) || !is_numeric($f['length'])) return false;
		if($offset % $blocksz == 0 && $f['length'] >= $blocksz) {
			$piece_num = (int)($offset / $blocksz);
			$num_pieces = (int)floor($f['length'] / $blocksz);
			
			return [
				'piece_size' => $blocksz,
				'name' => str_replace("\0", '', fix_utf8_encoding(implode('/', $f['path']))),
				'torpc_sha1' => sha1(substr($info['pieces'], $piece_num*20, $num_pieces*20), true),
				'filesize' => $f['length'],
				'hash_coverage' => $num_pieces*$blocksz
			];
		}
		$offset += $f['length'];
	}
	// first file is too small, and couldn't find a piece aligned file
	return false;
}

// Basic bdecode parser for info hash
function torrent_info_str($t) {
	// basic torrent parser...
	if($t[0] != 'd') return false;
	$pos = 1;
	$is_info = false;
	while(1) {
		if(!isset($t[$pos]) || $t[$pos] == 'e') break; // end
		// key
		$r = torrent_entlen($t, $pos);
		if($r===false) return false;
		if(substr($t, $pos, $r) == '4:info')
			$is_info = true;
		$pos += $r;
		// val
		$r = torrent_entlen($t, $pos);
		if($r===false) return false;
		if($is_info) {
			return substr($t, $pos, $r);
		}
		$pos += $r;
	}
	return false;
}
function torrent_entlen($t, $pos) {
	if($t[$pos] == 'i') // integer
		return strpos($t, 'e', $pos) - $pos +1;
	if($t[$pos] == 'l' || $t[$pos] == 'd') { // list/dictionary
		$tpos = $pos+1;
		while(isset($t[$tpos]) && $t[$tpos] != 'e') {
			$r = torrent_entlen($t, $tpos);
			if($r === false) return false;
			$tpos += $r;
		}
		if(!isset($t[$tpos])) return false;
		return $tpos - $pos +1;
	}
	if(ctype_digit($t[$pos])) { // string
		$len = (int)substr($t, $pos, 10);
		return strlen($len) + 1 + $len;
	}
	return false; // invalid
}

function extractBtihFromMagnet($link) {
	if(!preg_match('~^magnet\:\?(?:.+?&)?xt\=urn\:btih\:([0-9a-fA-F]{40}|[a-zA-Z2-7]{32}|[0-9a-zA-Z+/]{27}\=)(?:$|&)~is', $link, $m)) return false;
	switch(strlen($m[1])) {
		case 40: return pack('H*', $m[1]);
		case 32: return base32_decode($m[1]);
		case 28: return base64_decode($m[1]);
	}
}

function base32_encode($s) {
	$charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$len = strlen($s) * 8;
	$buf = 0; $inpos = -1;
	$result = '';
	for($i=0; $i<$len; $i+=5) {
		// feed next byte into buffer if necessary
		$bitsfed = $i % 8;
		if($bitsfed < 1 || $bitsfed > 3) {
			$buf |= ord(@$s[ ++$inpos ]) << ($i + 8 - ceil($i/8)*8);
		}
		
		// shift out 5 bits
		$result .= $charset[ ($buf & 0xF800) >> 11 ];
		$buf <<= 5;
	}
	// append padding
	switch($len % 40) {
		case  8: $result .= '==';
		case 16: $result .= '=';
		case 24: $result .= '==';
		case 32: $result .= '=';
	}
	return $result;
}

function base32_decode($s) {
	$charset = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
	$buf = 0;
	$bits = 0;
	$result = '';
	$i = -1;
	
	$s = strtoupper($s);
	while(isset($s[++$i]) && $s[$i] != '=') {
		$val =& $charset[$s[$i]];
		if(!isset($val)) return false;
		
		$buf |= $val << (11-$bits);
		$bits += 5;
		if($bits >= 8) {
			$result .= chr(($buf & 0xFF00) >> 8);
			$buf <<= 8;
			$bits -= 8;
		}
	}
	return $result;
}
