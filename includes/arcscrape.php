<?php
require_once ROOT_DIR.'3rdparty/BDecode.php';
require_once ROOT_DIR.'includes/releasesrc.php';

define('SLEEP_DELAY', 10);

// TODO: merge into releasesrc.php
function trackers_from_torrent($tinfo) {
	$main_tracker = null;
	$trackers = array();
	if(!empty($tinfo['announce-list']) && is_array($tinfo['announce-list'])) {
		$trackers = array_map(function($t) {
			if(empty($t) || !is_array($t)) return false;
			return (string)$t[0];
		}, $tinfo['announce-list']);
	}
	if(is_string(@$tinfo['announce'])) {
		$trackers[] = $tinfo['announce'];
		$main_tracker = $tinfo['announce'];
	}
	
	if(!empty($trackers))
		return [$main_tracker, array_unique(array_filter($trackers))];
	return false;
}

function save_torrent($url, $path, $urlOpts=[]) {
	$ret = [];
	
	$tinfo = null;
	if(file_exists($path)) {
		$torrent = file_get_contents($path);
	}
	else {
		if(substr($url, 0, 8) == 'magnet:?') {
			$torrent = get_torrent_from_magnet($url, $error);
		} else {
			$headers = [];
			for($i=0; $i<3; ++$i) {
				$torrent = send_request($url, $headers, $urlOpts);
				if($torrent) {
					$tinfo = BDecode($torrent);
					if(!empty($tinfo['info']) && (!empty($tinfo['info']['name']) || $tinfo['info']['name'] === '')) {
						
						if(isset($headers['content-disposition'])) {
							// ugly hack/fix for nyaa.si
							if(preg_match('~";\s+filename\*=[a-z0-9\-]+\'\'(["\']?)(.*)\\1$~i', $headers['content-disposition'], $m))
								$ret['filename'] = fix_utf8_encoding(rawurldecode($m[2]));
							elseif(preg_match('~^(?:[a-z]+;\s*)?filename(?:=|\*=[a-z0-9\-]+\'\')(["\']?)(.*)\\1$~i', $headers['content-disposition'], $m))
								$ret['filename'] = fix_utf8_encoding(rawurldecode($m[2]));
						}
						break;
					}
				}
				// if the data returned indicates that the torrent is bad, don't bother retrying
				if(substr($GLOBALS['CURL_INFO']['http_code'], 0, 1) == '4' && empty($urlOpts['ignore_4xx'])) break; // HTTP 4xx error
				sleep(($i+1)*10);
			}
			if(!$torrent)
				warning('Failed to save torrent from '.$url.' (tries='.$i.', code='.$GLOBALS['CURL_INFO']['http_code'].')', 'torrent');
		}
	}
	if(!$torrent) return false;
	
	// parse torrent
	if(!isset($tinfo)) $tinfo = BDecode($torrent);
	if(empty($tinfo['info']) || (empty($tinfo['info']['name']) && $tinfo['info']['name'] !== '')) {
		if($err = releasesrc_torrent_data_error($url, $torrent))
			warning($err, 'torrent');
		else
			warning('Bad torrent file (URL='.$url.')'.log_dump_data($torrent, 'releasesrc_torrent'), 'torrent');
		return false;
	}
	
	$ret['totalsize'] = 0;
	$ret['files'] = releasesrc_torrent_filelist($tinfo, $ret['totalsize']);
	$ret['torrent_name'] = fix_utf8_encoding($tinfo['info']['name']);
	
	if($trak = trackers_from_torrent($tinfo)) {
		if(isset($trak[0]))
			$ret['main_tracker'] = $trak[0];
		$ret['trackers'] = $trak[1];
	}
	
	if(!file_exists($path)) {
		file_put_contents($path, $torrent);
		@chmod($path, 0666);
	}
	return $ret;
}

function make_id_dirs2($id, $path, $len=8, $pref=5) {
	$hash = id2hash($id, $len);
	$storefile = ltrim(substr($hash, 0, $pref), '0').'/';
	if($storefile === '/') $storefile = '0/'; // fix for trimming everything
	@mkdir($path.$storefile);
	@chmod($path.$storefile, 0777);
	return [$storefile, substr($hash, $pref)];
}


function fmt_anidex_info($info) {
	if(!$info) return $info;
	static $labels = ['batch','raw','hentai','reencode','private'];
	$lbl = 0;
	foreach($labels as $i => $l)
		if(@$info['tag_'.$l]) $lbl |= 1<<$i;
	
	return [
		'id' => $info['anidex_id'],
		'filename' => fix_utf8_encoding($info['title']),
		'category' => @$info['cat_id'] ?: 0,
		'language' => @$info['lang_id'] ?: 0,
		'labels' => $lbl,
		'uploader_id' => @$info['uploader_id'] ?: 0,
		'group_id' => @$info['group_id'] ?: 0,
		'date' => $info['date'],
		//'filesize' => 0,
		'info_hash' => hex2bin($info['btih']),
		'xdcc' => @$info['link_xdcc'] ?: '', // TODO: check
		'torrent_info' => @$info['torrent_info'] ?: '',
		'description' => fix_utf8_encoding(@$info['desc'] ?: ''),
		'likes' => @$info['likes'] ?: 0,
		'updated' => $info['updated']
	];
}

function fmt_anidex_group($info, &$bufC, &$bufGM, &$grpClear) {
	
	if(isset($info['members'])) {
		$grpClear[$info['id']] = $info['id'];
		foreach(array_keys($info['members']) as $uid) {
			$bufGM[] = ['group_id' => $info['id'], 'user_id' => $uid];
		}
	}
	if(isset($info['comments'])) {
		foreach($info['comments'] as $cmt) {
			$bufC[] = [
				'group_id' => $info['id'],
				'user_id' => $cmt['user_id'],
				'message' => $cmt['message'],
				'date' => $cmt['date'],
			];
		}
	}
	
	return [
		'id' => $info['id'],
		'group_name' => $info['group_name'],
		'tags' => $info['tags'] ?? '',
		'founded' => $info['founded'] ?? 0,
		'language' => $info['lang_id'],
		'banner' => $info['banner'] ?? '',
		'website' => @$info['link_website'],
		'irc' => @$info['link_irc'],
		'email' => @$info['link_email'],
		'discord' => @$info['link_discord'],
		'leader_id' => $info['leader_id'] ?? 0,
		'likes' => $info['likes'] ?? 0,
		'updated' => $info['updated']
	];
}


function nyaasi_merge_userinfo($info, &$bufU, &$bufUlvl) {
	// comments have full info, so can do a full replace with these
	if(!empty($info['comments'])) foreach($info['comments'] as $cmt) {
		$ava_stamp = null;
		if($cmt['ava_slug']) {
			$ava = unpack('N', base64_decode(strtr($cmt['ava_slug'], ['-'=>'+','_'=>'/'])));
			$ava_stamp = reset($ava);
		}
		$bufU[$cmt['username']] = [
			'name' => $cmt['username'],
			'level' => $cmt['level'] < 0 ? -1 : $cmt['level'],
			'ava_stamp' => $ava_stamp,
			'updated_time' => $info['updated_time']
		];
	}
	// submitter info lacks the ava_stamp, so merge it differently
	if(isset($info['uploader_level'])) // if not Anonymous
		$bufUlvl[$info['uploader_name']] = $info['uploader_level'] < 0 ? -1 : $info['uploader_level'];
}
function nyaasi_commit_userinfo(&$bufU, &$bufUlvl) {
	// remove submitter info that's covered by comments
	foreach($bufUlvl as $name => $level) {
		if(isset($bufU[$name])) unset($bufUlvl[$name]);
	}
	
	global $db;
	if(!empty($bufU)) {
		$db->insertMulti('arcscrape.nyaasi_users', array_values($bufU), true);
		$bufU = [];
	}
	if(!empty($bufUlvl)) {
		$valStr = ''; $d = '';
		foreach($bufUlvl as $name => $level) {
			$valStr .= $d.'('.$db->escape($name).','.$level.',0)';
			$d = ', ';
		}
		$db->write_query('arcscrape', '
			INSERT INTO nyaasi_users(name,level,updated_time)
			VALUES '.$valStr.'
			ON DUPLICATE KEY UPDATE level=VALUES(level)
		');
		$bufUlvl = [];
	}
}

@ini_set('memory_limit', '384M'); // for BDecode inefficiencies
