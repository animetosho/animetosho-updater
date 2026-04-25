<?php

define('TOSHO_SERVER', 'https://tokyotosho.info');
require_once ROOT_DIR.'releasesrc/funcs.php';

function toto_replace_existing($existing_toto, $rowinfo, $ov_col) {
	global $db;
	// save new dateline to overwritten info col
	// TODO: this is kinda ugly... -> maybe have tosho_dateline column or something
	if($existing_toto[$ov_col]) {
		$oldinfo = @json_decode($existing_toto[$ov_col], true);
		is_array($oldinfo) or $oldinfo = array();
	} else
		$oldinfo = array();
	$oldinfo['tosho_dateline'] = $rowinfo['dateline'];
	$oldinfo = jencode($oldinfo);
	
	$update = array('name' => $rowinfo['name'], 'tosho_id' => $rowinfo['tosho_id']);
	$update[$ov_col] = $oldinfo;
	if(@$rowinfo['comment'] || @$rowinfo['comment'] === '0')
		$update['comment'] = (string)$rowinfo['comment'];
	if(@$rowinfo['website'])
		$update['website'] = (string)$rowinfo['website'];
	if($rowinfo['cat']) $update['cat'] = $rowinfo['cat']; // TODO: separate Nyaa/Toto cats
	$db->update('toto', $update, 'id='.$existing_toto['id']);
}

function toto_add($item, $disable_skipping=false) {
	global $db;
	// check if we already have this item from Nyaa
	// TODO: can this be used to partially detect dupe submissions?
	if($item['nyaa_id']) {
		return; // don't bother trying to link to both TT & Nyaa; we've stopped doing it for most cases already, with the exception of if it's pulled from TT first
		
		if($existing_toto = $db->selectGetArray('toto', 'nyaa_id='.$item['nyaa_id'].' AND nyaa_subdom='.$db->escape($item['nyaa_subdom']).' AND tosho_id=0', 'id')) {
			//toto_replace_existing($existing_toto, $item, 'nyaa_info');
			return;
		} else {
			if(!function_exists('nyaasi_get_item')) {
				require ROOT_DIR.'releasesrc/nyaasi.php';
			}
			$nyaa_det = nyaasi_get_item($item['nyaa_id'], $item['nyaa_subdom']);
			if(!empty($nyaa_det)) {
				$item['nyaa_cat'] = $nyaa_det['nyaa_cat'];
				$item['nyaa_class'] = $nyaa_det['nyaa_class'];
				if(isset($nyaa_det['torrentfile']))
					$item['torrentfile'] = $nyaa_det['torrentfile'];
			}
		}
	}
	if($item['anidex_id']) {
		return;
		
		if($existing_toto = $db->selectGetArray('toto', 'anidex_id='.$item['anidex_id'].' AND tosho_id=0', 'id')) {
			//toto_replace_existing($existing_toto, $item, 'anidex_info');
			return;
		} else {
			if(!function_exists('anidex_get_item')) {
				require ROOT_DIR.'releasesrc/anidex.php';
			}
			$adex_det = anidex_get_item($item['anidex_id']);
			if(!empty($adex_det)) {
				foreach($adex_det as $k=>$v) {
					if(substr($k, 0, 7) == 'anidex_')
						$item[$k] = $v;
				}
				if(isset($adex_det['torrentfile']) && !isset($item['torrentfile']))
					$item['torrentfile'] = $adex_det['torrentfile'];
			}
		}
	}
	if($item['nekobt_id']) return; // let nekoBT scraper add this if it's missing
	
	
	releasesrc_add($item, 'toto_', $disable_skipping, ['source'=>'tosho']);
}

// convert size strings to guestimate byte value
/* function toto_parse_size_str($str) {
	$str = trim($str);
	$val = floatval($str);
	switch(strtoupper(substr($str, -2))) {
		case 'TB': $val *= 1024;
		case 'GB': $val *= 1024;
		case 'MB': $val *= 1024;
		case 'KB': $val *= 1024;
	}
	return $val;
} */


function toto_get_detail($id, $files=false) {
	$null = null;
	for($i=0; $i<3; ++$i) {
		$data = send_request(TOSHO_SERVER.'/details.php?id='.$id.($files?'&full=':''), $null, array('ignoreerror' => true));
		if(stripos($data, '<p style="text-align: center">Entry deleted.</p>') || stripos($data, '<p style="text-align: center">Entry not found.</p>'))
			return null; // deleted
		if($data && substr_count($data, '<div class="details">') == 1) break;
		sleep(10); // retry after 10 secs
	}
	if(!$data) {
		info('[Toto/det] No data returned (id='.$id.')', 'toto-det');
		return false;
	}
	if(substr_count($data, '<div class="details">') != 1) {
		if($data === '522')
			info('[Toto/det] HTTP 522 error', 'toto-det');
		elseif(preg_match('~<title>(?:www\.)?tokyotosho\.info \| (5\d\d): ~', $data, $m))
			info('[Toto/det] HTTP '.$m[1].' error', 'toto-det');
		elseif(strpos($data, '<title>404 Not Found</title>')) // assume real "not found" doesn't return like this
			info('[Toto/det] HTTP 404 error', 'toto-det');
		else
			warning('[ToTo/det] Identifier doesn\'t appear exactly once on page (id='.$id.')'.log_dump_data($data, 'toto_detail'));
		return false;
	}
	if($i) {
		info('Successfully got toto detail after '.$i.' retry. (ID='.$id.')', 'toto');
	}
	
	$ret = array();
	// strip junk spaces
	$data = str_replace('<span class="s"> </span>', '', $data);
	
	if($files && preg_match('~\>File Listing \(\d+ files\)\</h4\>\<table[^>]+\>(.+?)\</table\>~s', $data, $match)) {
		preg_match_all('~\<td class="llist"\>([^<]+?)\</td\>\<td class="rlist"\>([0-9.]+[a-zA-Z]+)\</td\>~s', $match[1], $matches, PREG_SET_ORDER);
		$ret['files'] = array_map(function($m) {
			return [trim(unhtmlspecialchars($m[1])), trim($m[2])];
		}, $matches);
	}
	
	$data = substr($data, strpos($data, '<div class="details">'));
	if(($p = strpos($data, '<ul>')) === false) {
		warning('[ToTo/det] Cannot find start of list (id='.$id.')');
		return false;
	}
	$data = substr($data, $p+4);
	if(($p = strpos($data, '</ul>')) === false) {
		warning('[ToTo/det] Cannot find end of list (id='.$id.')');
		return false;
	}
	$data = substr($data, 0, $p);
	
	// grab magnet link from somewhere
	if(preg_match('~\<a href="(magnet\:\?.*?)"\>(?:\<span class="sprite_magnet"\>\</span\> )?Magnet Link\</a\>~i', $data, $match)) {
		$ret['magnetlink'] = unhtmlspecialchars($match[1]);
	}
	
	// process list
	$data = explode('<li class="detailsleft">', strtr($data, array(
		'</li>' => '',
		'<li class="detailsleft shade">' => '<li class="detailsleft">',
		'<li class="detailsright shade">' => '<li class="detailsright">',
		'<li class="detailsleft shade" id="detailsleft">' => '<li class="detailsleft">',
	)));
	foreach($data as &$datum) {
		$datum = trim($datum);
		if(!$datum) continue;
		list($left, $right) = array_map('trim', explode('<li class="detailsright">', $datum, 2));
		if(!isset($right)) continue;
		$left = strtolower(strip_tags_ex($left));
		if(substr($left, -1) == ':') $left = substr($left, 0, -1);
		
		$ret['_'.$left] = $right;
	}
	// basic processing
	if(isset($ret['_torrent type'])) {
		if(preg_match('~^\<a href\="https?\://(?:www\.)?tokyotosho\.[a-z]+/index\.php\?cat\=(\d+)~i', $ret['_torrent type'], $m))
			$ret['cat'] = (int)$m[1];
		elseif(substr($ret['_torrent type'], 0, 23) == '<a href="index.php?cat=')
			$ret['cat'] = (int)substr($ret['_torrent type'], 23, 3);
	}
	if(isset($ret['_torrent number']))
		$ret['id'] = (int)$ret['_torrent number'];
	if(isset($ret['_torrent name'])) {
		// strip some utf-8 unicode control chars
		// (?<=[\x20-\x7F])
		$name = preg_replace("~(\xE2\x80[\x80-\x8F\xA8-\xAF])+~", '', $ret['_torrent name']);
		if(preg_match('~^\<a [^>]*href\="([^"]+)"\>([^<]*)\</a\>~i', $name, $m)) {
			$ret['name'] = unhtmlspecialchars($m[2]);
			$ret['link'] = unhtmlspecialchars($m[1]);
		}
	}
	if(isset($ret['_date submitted']))
		$ret['date'] = $ret['_date submitted'];
	if(isset($ret['_filesize']))
		$ret['size'] = $ret['_filesize'];
	if(isset($ret['_website'])) {
		$ret['website'] = unhtmlspecialchars(strip_tags_ex($ret['_website']));
		if(!preg_match('~^https?\://~i', $ret['website']))
			$ret['website'] = '';
	}
	if(isset($ret['_comment'])) {
		$ret['comment'] = unhtmlspecialchars(strip_tags_ex($ret['_comment']));
		if($ret['comment'] == 'N/A') $ret['comment'] = '';
	}
	
	if(isset($ret['_tracker'])) {
		$ret['tracker_extra'] = null;
		if($p = strpos($ret['_tracker'], '<br />Multi-tracker:<br />')) {
			$ret['tracker_main'] = trim(unhtmlspecialchars(substr($ret['_tracker'], 0, $p)));
			$trackers = substr($ret['_tracker'], $p+strlen('<br />Multi-tracker:<br />'));
			if(!preg_match_all('~\>\s*Tier \d+\s*(\<ol\>.*?\</ol\>)~si', $trackers, $tracktiers)) {
				$tracktiers = [0, [$trackers]];
			}
			foreach($tracktiers[1] as $tracktier) {
				// [https://www.tokyotosho.info/details.php?id=1129697] has "My Seeders(Add peer it):195.154.221.89:56296" as a tracker!
				preg_match_all('~(?<=\<li\>)\s*([^<]+)(?=\s*\<(?:/li|/ol|li)\>)~', $tracktier, $mt);
				$tdata = [];
				if(!empty($mt[1])) {
					$tdata = array_map(function($t) {
						return trim(unhtmlspecialchars($t));
					}, $mt[1]);
				}
				$ret['tracker_extra'][] = $tdata;
			}
		} else {
			$ret['tracker_main'] = trim(unhtmlspecialchars($ret['_tracker']));
		}
	}
	
	if(isset($ret['_submitter'])) {
		$ret['tosho_uname'] = trim(unhtmlspecialchars(preg_replace('~\<a href\="[^"]+"\>[^<]+\</a\>~i', '', $ret['_submitter'])));
		// TODO: check for presence of link instead
		if($ret['tosho_uname'] == 'Anonymous') $ret['tosho_uname'] = '';
	}
	if(isset($ret['_authorized'])) {
		if(preg_match('~class="auth_([a-z]+)"~', $ret['_authorized'], $m)) {
			$authmap = array_flip(['', 'bad', 'neutral', 'ok']);
			if(isset($authmap[$m[1]]))
				$ret['tosho_uauth'] = $authmap[$m[1]];
			else
				$ret['tosho_uauth'] = -1;
		}
	}
	if(isset($ret['_submitter hash'])) {
		$hash = str_replace(' ', '', strip_tags($ret['_submitter hash']));
		if(preg_match('~^[a-f0-9]{40}$~i', $hash))
			$ret['tosho_uhash'] = pack('H*', strtolower($hash));
	}
	
	return $ret;
}

function fmt_toto_info($info) {
	$ret = [
		'id' => $info['id'],
		'name' => fix_utf8_encoding($info['name']),
		'type' => $info['cat'],
		'submitted' => strtotime($info['date']),
		'size' => $info['size'],
		'link' => $info['link'],
		'website' => @$info['website'] ?: '',
		'comment' => fix_utf8_encoding(@$info['comment'] ?: ''),
		'tracker_main' => @$info['tracker_main'] ?: '',
		'tracker_extra' => @$info['tracker_extra'] ? jencode($info['tracker_extra']) : '',
		'info_hash' => extractBtihFromMagnet($info['magnetlink']),
		'submitter_hash' => @$info['tosho_uhash'] ?: '',
		'submitter' => @$info['tosho_uname'] ?: '',
		'authorized' => @$info['tosho_uauth'] ?: -1,
		'updated' => (int)gmdate('U'),
		'files' => null,
	];
	if(!empty($info['files'])) {
		$ret['files'] = jencode($info['files']);
	}
	return $ret;
}

function toto_get_cached($id) {
	global $db;
	$toto = $db->selectGetArray('arcscrape.tosho_torrents', 'id='.$id);
	if(!empty($toto) && $toto['deleted']) return null;
	if(!empty($toto)) $toto = toto_fmt_from_db($toto);
	return $toto;
}
function toto_fmt_from_db($item) {
	if(!function_exists('releasesrc_create_magnet')) {
		require ROOT_DIR.'includes/releasesrc.php';
	}
	$ret = [
		'tosho_id' => (int)$item['id'],
		'dateline' => (int)$item['submitted'],
		'nyaa_id' => 0,
		'nyaa_subdom' => '',
		'anidex_id' => 0,
		'nekobt_id' => 0,
		'name' => $item['name'],
		'size' => $item['size'], // string
		'comment' => $item['comment'],
		'website' => $item['website'],
		'cat' => (int)$item['type'],
		'link' => $item['link'],
		'magnetlink' => releasesrc_create_magnet([
			'btih' => $item['info_hash'],
			'announce' => $item['tracker_main'],
			'announce-list' => $item['tracker_extra'] ? json_decode($item['tracker_extra'], true) : null
		]),
		'tosho_uname' => $item['submitter'],
		'tosho_uauth' => $item['authorized'],
		'tosho_uhash' => $item['submitter_hash'],
	];
	
	if(preg_match('~^https?\://(?:(www|sukebei)\.)?nyaa(?:torrents\.org|\.[a-z]{2,4})/(?:\?page=)?(?:torrentinfo|download|view)(?:&tid=|/)(\d+)([&./]|$)~i', $item['link'], $m)) {
		$ret['nyaa_id'] = (int)$m[2];
		if(isset($m[1]) && strtolower($m[1]) != 'www')
			$ret['nyaa_subdom'] = strtolower($m[1]);
	}
	elseif(preg_match('~^https?\://anidex\.(?:moe|info)/(\?page=torrent&id=|dl/)(\d+)(&|$)~i', $item['link'], $m)) {
		$ret['anidex_id'] = (int)$m[2];
	}
	elseif(preg_match('~^https?\://nekobt\.to/(?:api/v1/)?torrents/(\d+)(/|$)~i', $item['link'], $m)) {
		$ret['nekobt_id'] = (int)$m[1];
	}
	
	return $ret;
}

function toto_run_from_scrape($timebase) {
	// query DB for items
	global $db;
	$table = 'arcscrape.tosho_torrents';
	foreach($db->selectGetAll($table, 'id',
		'type IN('.implode(',', $GLOBALS['toto_cats']).') AND `'.$table.'`.deleted=0 AND submitted >= '.$timebase.' AND tosho_id IS NULL',
		'`'.$table.'`.*', ['order' => 'submitted ASC', 'joins' => [
			['left', 'toto', 'id', 'tosho_id']
		]]
	) as $item) {
		toto_add(toto_fmt_from_db($item));
	}
	return true;
}

function toto_changes_from_feed($feed, $idnext) {
	global $db;
	$feed = feed_change_preprocess($feed, $idnext);
	if(empty($feed)) return [];
	
	$earliest = end($feed)['id'];
	$items = $db->selectGetAll('arcscrape.tosho_torrents', 'id', 'id BETWEEN '.$earliest.' AND '.($idnext-1), 'id,name,type,submitted,link,comment,info_hash,submitter,authorized,deleted', ['order' => 'id DESC']);
	
	$catMap = [
		'Anime' => 1,
		'Non-English' => 10,
		'Manga' => 3,
		'Drama' => 8,
		'Music' => 2,
		'Music Video' => 9,
		'Raws' => 7,
		'Hentai' => 4,
		'Hentai (Anime)' => 12,
		'Hentai (Manga)' => 13,
		'Hentai (Games)' => 14,
		'Batch' => 11,
		'JAV' => 15,
		'Other' => 5,
	];
	if(!function_exists('base32_decode')) {
		require ROOT_DIR.'includes/releasesrc.php';
	}
	
	$ret = [];
	foreach($feed as $fitem) {
		$id = $fitem['id'];
		if(!isset($items[$id])) {
			$ret[$id] = 'new';
			continue;
		}
		$ditem = $items[$id];
		
		$cat = null;
		if(isset($catMap[$fitem['category']]))
			$cat = $catMap[$fitem['category']];
		
		$auth = $submitter = null;
		preg_match_all('~(Authorized|Submitter)\: ([^<]+)\<br~i', $fitem['description'], $userInfo, PREG_SET_ORDER);
		if(!empty($userInfo)) foreach($userInfo as $ui) {
			if($ui[1] == 'Authorized')
				$auth = $ui[2];
			else {
				$submitter = $ui[2];
				if($submitter == 'Anonymous')
					$submitter = '';
			}
		}
		$btih = null;
		if(preg_match('~\<a href="magnet\:\?xt=urn\:btih\:([0-9A-Z]{32})(&[^"]+)?"\>Magnet Link\</a\>~i', $fitem['description'], $m))
			$btih = base32_decode($m[1]);
		$comment = '';
		if(preg_match("~/>\s+Comment: (.*)$~i", $fitem['description'], $m))
			$comment = strip_tags_ex($m[1]);
		
		// TODO: is this line still relevant?
		$filtered_title = preg_replace("~(\xE2\x80[\x80-\x8F\xA8-\xAF])+~", '', $fitem['title']);
		if($filtered_title != $ditem['name']) {
			$ret[$id] = 'change:title';
		}
		elseif(isset($submitter) && $submitter != $ditem['submitter']) {
			$ret[$id] = 'change:submitter';
		}
		elseif(isset($cat) && $cat != $ditem['type']) {
			$ret[$id] = 'change:category';
		}
		elseif($fitem['link'] != $ditem['link']) {
			$ret[$id] = 'change:link';
		}
		elseif($comment != $ditem['comment']) {
			$ret[$id] = 'change:comment';
		}
		elseif($ditem['deleted'])
			$ret[$id] = 'change:deleted';
		// TODO: compare authorized
		
		// TT's detail page doesn't show seconds, so strip them out here for comparison purposes
		$pubTime = $fitem['time'] - ($fitem['time'] % 60);
		if($pubTime != $ditem['submitted'])
			warning("Unexpected pubDate mismatch for $id: $fitem[time]<>$ditem[submitted]", 'toto');
		if(isset($btih) && $btih != $ditem['info_hash'])
			warning("Unexpected infoHash mismatch for $id: ".bin2hex($btih)."<>".bin2hex($ditem['info_hash']), 'toto');
		
		unset($items[$id]);
	}
	
	foreach($items as $ditem) {
		if(!$ditem['deleted'])
			$ret[$ditem['id']] = 'deleted';
	}
	return $ret;
}
