<?php

define('NYAASI_FLAG_ANONYMOUS', 1);
define('NYAASI_FLAG_HIDDEN', 2);
define('NYAASI_FLAG_TRUSTED', 4);
define('NYAASI_FLAG_REMAKE', 8);
define('NYAASI_FLAG_COMPLETE', 16);
define('NYAASI_FLAG_DELETED', 32);
define('NYAASI_FLAG_BANNED', 64);
define('NYAASI_FLAG_COMMENT_LOCKED', 128);
require_once ROOT_DIR.'releasesrc/funcs.php';

// this function was intended to allow customization of destination when we get blocked, but isn't used (will pollute 'link' fields)
function nyaasi_rooturl($subdom='') {
	return 'https://'.($subdom ? $subdom.'.':'').'nyaa.si/';
}

function nyaasi_parse_userlevel($html) {
	if(preg_match('~\<a class="text-([^"]*)"([^>]*)\>(.*?)\</a\>~', $html, $m)) {
		$r = [trim(unhtmlspecialchars($m[3])), -1];
		switch($m[1]) {
			case 'default strike': $r[1] = -2; break; // BANNED
			case 'default': $r[1] = 0; break; // REGULAR
			case 'success': $r[1] = 1; break; // TRUSTED
			case 'purple':
				$r[1] = 2; // MODERATOR or SUPERADMIN
				if(strpos($m[2], 'title="Administrator"') !== false)
					$r[1] = 3;
				break;
		}
		return $r;
	}
	return false;
}

function nyaasi_get_detail($id, $subdom='') {
	$idstr = '(ID='.$id.', subdom='.$subdom.')';
	for($i=0; $i<3; ++$i) {
		$data = send_request(nyaasi_rooturl($subdom).'view/'.$id, $null, ['ignoreerror'=>1]);
		if($data) {
			if(strpos($data, '>Category:</div>')) break;
			elseif(strpos($data, '<h1>404 Not Found</h1>')) return null;
		}
		if(strpos($data, '<title>DDOS-GUARD</title>'))
			sleep(40); // wait a bit longer
		sleep(20); // retry after delay
	}
	if(!$data) {
		info('[Nyaa/det] No data returned '.$idstr, 'nyaasi-det');
		return false;
	}
	if(!strpos($data, '>Category:</div>')) {
		if(strpos($data, ' | 502: Bad gateway</title>') || strpos($data, '<title>502 Bad Gateway</title>') || strpos($data, '<title>Error 502</title>'))
			info('[Nyaa/det] HTTP 502 for '.$idstr, 'nyaasi-det');
		elseif(strpos($data, ' | 504: Gateway time-out</title>') || strpos($data, '<title>Error 504</title>') || strpos($data, '<title>504 Gateway Time-out</title>'))
			info('[Nyaa/det] HTTP 504 for '.$idstr, 'nyaasi-det');
		elseif(strpos($data, ' | 521: Web server is down</title>'))
			info('[Nyaa/det] HTTP 521 for '.$idstr, 'nyaasi-det');
		elseif(strpos($data, ' | 522: Connection timed out</title>'))
			info('[Nyaa/det] HTTP 522 for '.$idstr, 'nyaasi-det');
		elseif(strpos($data, ' | 523: Origin is unreachable</title>'))
			info('[Nyaa/det] HTTP 523 for '.$idstr, 'nyaasi-det');
		elseif(strpos($data, ' | 525: SSL handshake failed</title>'))
			info('[Nyaa/det] HTTP 525 for '.$idstr, 'nyaasi-det');
		elseif($data == 'Internal Server Error' || strpos($data, '<title>500 - Internal Server Error</title>') || strpos($data, '<title>500 Internal Server Error</title>'))
			info('[Nyaa/det] HTTP 500 for '.$idstr, 'nyaasi-det');
		elseif(stripos($data, '<title>DDOS-GUARD</title>'))
			info('[Nyaa/det] Blocked by DDOS-GUARD '.$idstr, 'nyaasi-det');
		else
			warning('[NyaaSi/det] Page doesn\'t look valid'.$idstr.log_dump_data($data, 'nyaasi_detail'));
		return false;
	}
	if($i) {
		info('Successfully got nyaa detail after '.$i.' retry. '.$idstr, 'nyaasi');
	}
	
	$ret = array(
		'id' => (int)$id,
		'info_hash' => '',
		'display_name' => '',
		//'torrent_name' => '', // do we want this?
		'information' => '',
		'description' => '',
		//'filesize' => 0, // do we want this?
		'flags' => 0,
		'uploader_name' => '',
		'uploader_level' => null,
		'created_time' => 0,
		'updated_time' => (int)gmdate('U'),
		'main_category_id' => 0,
		'sub_category_id' => 0,
		// redirect => leave as NULL
	);
	
	// TODO: consider more fuzzy matching, as our rules are otherwise quite strict
	
	if(preg_match('~\<div class\="panel panel-(deleted|danger|success|default)"\>\s*\<div class\="panel-heading"( style\="background-color\: darkgray;")?\>\s*\<h3 class\="panel-title"\>(.*?)\</h3\>?~s', $data, $m)) {
		switch($m[1]) {
			case 'deleted': $ret['flags'] |= NYAASI_FLAG_DELETED; break;
			case 'danger':  $ret['flags'] |= NYAASI_FLAG_REMAKE;  break;
			case 'success': $ret['flags'] |= NYAASI_FLAG_TRUSTED; break;
		}
		if($m[2])           $ret['flags'] |= NYAASI_FLAG_HIDDEN;
		
		$ret['display_name'] = unhtmlspecialchars(trim(strip_tags($m[3])));
	} else {
		warning('[Nyaa/det] Cannot retrieve critical info '.$idstr.log_dump_data($data, 'nyaa_detail'));
		return false;
	}
	
	
	$nyaasi_cats = ['' => [
		'Show all'                  => '0_0',
		'Anime'                     => '1_0',
		'Anime - Anime Music Video' => '1_1',
		'Anime - English-translated' => '1_2',
		'Anime - Non-English-translated' => '1_3',
		'Anime - Raw'               => '1_4',
		'Audio'                     => '2_0',
		'Audio - Lossless'          => '2_1',
		'Audio - Lossy'             => '2_2',
		'Literature'                => '3_0',
		'Literature - English-translated' => '3_1',
		'Literature - Non-English-translated' => '3_2',
		'Literature - Raw'          => '3_3',
		'Live Action'               => '4_0',
		'Live Action - English-translated' => '4_1',
		'Live Action - Idol/Promotional Video' => '4_2',
		'Live Action - Non-English-translated' => '4_3',
		'Live Action - Raw'         => '4_4',
		'Pictures'                  => '5_0',
		'Pictures - Graphics'       => '5_1',
		'Pictures - Photos'         => '5_2',
		'Software'                  => '6_0',
		'Software - Applications'   => '6_1',
		'Software - Games'          => '6_2',
	], 'sukebei' => [
		'Show all'                  => '0_0',
		'Art'                       => '1_0',
		'Art - Anime'               => '1_1',
		'Art - Doujinshi'           => '1_2',
		'Art - Games'               => '1_3',
		'Art - Manga'               => '1_4',
		'Art - Pictures'            => '1_5',
		'Real Life'                 => '2_0',
		'Real Life - Photobooks / Pictures' => '2_1',
		'Real Life - Videos'        => '2_2',
	]];
	
	preg_match_all('~\<div class\="col-md-1"\>([^<]+)\</div\>\s*\<div class\="col-md-5"([^>]*)\>\s*(.*?)\s*\</div\>~s', $data, $matches, PREG_SET_ORDER);
	foreach($matches as $m) {
		$m[3] = trim($m[3]);
		switch(strtolower($m[1])) {
			case 'category:':
				// pulling ID from links may be better, but at time of writing, isn't implemented in live site
				$cat = trim(strip_tags($m[3]));
				if(isset($nyaasi_cats[$subdom][$cat])) {
					list($ret['main_category_id'], $ret['sub_category_id']) = array_map('intval', explode('_', $nyaasi_cats[$subdom][$cat]));
				} else {
					warning('[Nyaa/det] Cannot retrieve category '.$idstr.log_dump_data($data, 'nyaa_detail'));
					return false;
				}
			break;
			case 'date:':
				if(preg_match('~data-timestamp\="(\d+)"~', $m[2], $dm)) {
					$ret['created_time'] = (int)$dm[1];
				} else {
					warning('[Nyaa/det] Cannot retrieve date '.$idstr.log_dump_data($data, 'nyaa_detail'));
					return false;
				}
			break;
			case 'submitter:':
				if($m[3] == 'Anonymous') {
					$ret['flags'] |= NYAASI_FLAG_ANONYMOUS;
				} else {
					$userlevel = nyaasi_parse_userlevel($m[3]);
					if($userlevel) {
						$ret['uploader_name'] = $userlevel[0];
						$ret['uploader_level'] = $userlevel[1];
						if($userlevel[1] == -2)
							$ret['flags'] |= NYAASI_FLAG_BANNED;
					} else {
						// fallback - shouldn't happen
						$ret['uploader_name'] = unhtmlspecialchars(trim(strip_tags($m[3])));
						if(stripos($m[3], 'title="BANNED User"'))
							$ret['flags'] |= NYAASI_FLAG_BANNED;
					}
				}
			break;
			case 'information:':
				if($m[3] != 'No information.')
					$ret['information'] = unhtmlspecialchars(trim(strip_tags($m[3])));
			break;
			case 'seeders:': case 'leechers:': case 'downloads:': break; // no-op
			case 'file size:': break; // no-op (grab more accurate value from torrent)
		}
	}
	
	
	// TODO: do we care about 'has_torrent'?
	
	
	if(preg_match('~\<a href\="(magnet:[^"]+)"~', $data, $m)) {
		if(!function_exists('extractBtihFromMagnet')) {
			require ROOT_DIR.'includes/releasesrc.php';
		}
		$ret['info_hash'] = extractBtihFromMagnet($m[1]);
	}
	
	if(preg_match('~\<div [^>]*id\="torrent-description"\>(.*?)\</div\>~s', $data, $m)) {
		$ret['description'] = unhtmlspecialchars(trim($m[1]));
		if($ret['description'] == '#### No description.')
			$ret['description'] = '';
	}
	
	
	if(preg_match('~\<h3 class="[^"]+"\>\s*Comments - (.*?)\<(script|footer)(?: [^>]+)?\>~s', $data, $d)) {
		$ret['comments'] = [];
		// try to get comments
		preg_match_all('~\<div class="panel-body"\>(.*?\<div [^>]*id="torrent-comment\d+"\>.*?\</div\>)~s', $d[1], $comments);
		if(!empty($comments[1])) foreach($comments[1] as $cmt) {
			$c = [
				'id' => 0,
				//'pos' => 0,
				'username' => '',
				'level' => 0,
				'ava_slug' => '',
				'md5' => null,
				'created_time' => 0,
				'edited_time' => 0,
				'text' => ''
			];
			$userlevel = nyaasi_parse_userlevel($cmt);
			if($userlevel) {
				$c['username'] = $userlevel[0];
				$c['level'] = $userlevel[1];
			}
			if(preg_match('~\<img class="avatar" src="([^"]+)"~', $cmt, $m)) {
				if(preg_match('~/avatar/([0-9a-f]{32})~', $m[1], $m2))
					$c['md5'] = hex2bin($m2[1]);
				elseif(preg_match('~/avatar-([0-9a-zA-Z_\\-+=.,]+)\?~', $m[1], $m2))
					$c['ava_slug'] = $m2[1];
			}
			if(preg_match('~\<a href="#com-(\d+)"\>\<small(?: [^<]*data-timestamp="(\d+)"[^<]*)?\>([^<]+)\</small\>~', $cmt, $m)) {
				//$c['pos'] = (int)$m[1];
				if($m[2])
					$c['created_time'] = (int)$m[2];
				else {
					// NOTE: time is relative ('2 hours ago') and not particularly reliable for recent comments
					$c['created_time'] = strtotime(unhtmlspecialchars($m[3]));
				}
			}
			if(preg_match('~<small [^>]*data-timestamp="([0-9.]+)"[^>]*>\(edited\)</small>~', $cmt, $m)) {
				$c['edited_time'] = (int)$m[1];
			}
			if(preg_match('~\<div [^>]*id="torrent-comment(\d+)"\>(.*?)\</div\>~', $cmt, $m)) {
				$c['id'] = (int)$m[1];
				$c['text'] = trim(unhtmlspecialchars($m[2]));
			}
			
			if(!$c['id'] || $c['text'] === '' || !$c['username'])
				continue; // TODO: some warning or something
			
			$ret['comments'][] = $c;
		}
		
		if(preg_match('~<div class="alert alert-warning">\s*<p>\s*<i class="fa fa-lock"[^>]*></i>\s*Comments have been locked\.\s*</p>~i', $d[1])) {
			$ret['flags'] |= NYAASI_FLAG_COMMENT_LOCKED;
		}
	}
	
	return $ret;
}

function nyaasi_torrent_file_loc($id, $subdom) {
	$hash = id2hash($id);
	return TOTO_STORAGE_PATH.'nyaasi'.($subdom?'s':'').'_archive/'.(ltrim(substr($hash, 0, 5), '0') ?: '0').'/'.substr($hash, 5).'.torrent';
}

function nyaasi_fmt_item($item, $subdom) {
	$website = '';
	if(preg_match('~^https?://~', $item['information']))
		$website = $item['information'];
	
	$cat = $nyaa_cat = '';
	if($subdom == 'sukebei') switch($item['main_category_id']*100 + $item['sub_category_id']) {
		case 100:  list($cat, $nyaa_cat) = [ 4, '7']; break;  // Art
		case 101:  list($cat, $nyaa_cat) = [12, '7_25']; break;  // Art - Anime
		case 102:  list($cat, $nyaa_cat) = [ 4, '7_33']; break;  // Art - Doujinshi
		case 103:  list($cat, $nyaa_cat) = [14, '7_27']; break;  // Art - Games
		case 104:  list($cat, $nyaa_cat) = [13, '7_26']; break;  // Art - Manga
		case 105:  list($cat, $nyaa_cat) = [ 4, '7_28']; break;  // Art - Pictures
		case 200:  list($cat, $nyaa_cat) = [15, '8']; break;  // Real Life
		case 201:  list($cat, $nyaa_cat) = [15, '8_31']; break;  // Real Life - Photobooks / Pictures
		case 202:  list($cat, $nyaa_cat) = [15, '8_30']; break;  // Real Life - Videos
	} else switch($item['main_category_id']*100 + $item['sub_category_id']) {
		case 100:  list($cat, $nyaa_cat) = [ 5, '1']; break;  // Anime
		case 101:  list($cat, $nyaa_cat) = [ 9, '1_32']; break;  // Anime - Anime Music Video
		case 102:  list($cat, $nyaa_cat) = [ 1, '1_37']; break;  // Anime - English-translated
		case 103:  list($cat, $nyaa_cat) = [10, '1_38']; break;  // Anime - Non-English-translated
		case 104:  list($cat, $nyaa_cat) = [ 7, '1_11']; break;  // Anime - Raw
		case 200:  list($cat, $nyaa_cat) = [ 2, '3']; break;  // Audio
		case 201:  list($cat, $nyaa_cat) = [ 2, '3_14']; break;  // Audio - Lossless
		case 202:  list($cat, $nyaa_cat) = [ 2, '3_15']; break;  // Audio - Lossy
		case 300:  list($cat, $nyaa_cat) = [ 3, '2']; break;  // Literature
		case 301:  list($cat, $nyaa_cat) = [ 3, '2_12']; break;  // Literature - English-translated
		case 302:  list($cat, $nyaa_cat) = [ 3, '2_39']; break;  // Literature - Non-English-translated
		case 303:  list($cat, $nyaa_cat) = [ 3, '2_13']; break;  // Literature - Raw
		case 400:  list($cat, $nyaa_cat) = [ 8, '5']; break;  // Live Action
		case 401:  list($cat, $nyaa_cat) = [ 8, '5_19']; break;  // Live Action - English-translated
		case 402:  list($cat, $nyaa_cat) = [ 8, '5_22']; break;  // Live Action - Idol/Promotional Video
		case 403:  list($cat, $nyaa_cat) = [ 8, '5_21']; break;  // Live Action - Non-English-translated
		case 404:  list($cat, $nyaa_cat) = [ 8, '5_20']; break;  // Live Action - Raw
		case 500:  list($cat, $nyaa_cat) = [ 5, '4']; break;  // Pictures
		case 501:  list($cat, $nyaa_cat) = [ 5, '4_18']; break;  // Pictures - Graphics
		case 502:  list($cat, $nyaa_cat) = [ 5, '4_17']; break;  // Pictures - Photos
		case 600:  list($cat, $nyaa_cat) = [ 5, '6']; break;  // Software
		case 601:  list($cat, $nyaa_cat) = [ 5, '6_23']; break;  // Software - Applications
		case 602:  list($cat, $nyaa_cat) = [ 5, '6_24']; break;  // Software - Games
	}
	$class = 2;
	if($item['flags'] & NYAASI_FLAG_TRUSTED)
		$class = 3;
	elseif($item['flags'] & NYAASI_FLAG_REMAKE)
		$class = 1;
	elseif($item['flags'] & NYAASI_FLAG_HIDDEN)
		$class = -1;
	
	return [
		'name' => $item['display_name'],
		'cat' => $cat,
		'link' => 'https://'.($subdom?$subdom.'.':'').'nyaa.si/download/'.$item['id'].'.torrent',
		'torrentfile' => nyaasi_torrent_file_loc($item['id'], $subdom),
		'nyaa_id' => (int)$item['id'],
		'nyaa_subdom' => $subdom,
		'dateline' => (int)$item['created_time'],
		'comment' => markdown_to_desc($item['description']),
		'website' => $website,
		'nyaa_class' => $class,
		'nyaa_cat' => $nyaa_cat,
	];
}

function nyaasi_get_item_raw($id, $subdom='', $incl_del=false) {
	// first, query DB
	global $db;
	$item = $db->selectGetArray('arcscrape.nyaasi'.($subdom?'s':'').'_torrents', 'id='.$id);
	
	// if not exist, try manual query
	// TODO: disable this - we always assume the arcscrape is accurate - if not, it should get fixed
	if(!$item) {
		$item = nyaasi_get_detail($id, $subdom);
		if(!$item) return $item;
	}
	
	if(!$incl_del && ($item['flags'] & (NYAASI_FLAG_DELETED|NYAASI_FLAG_HIDDEN)) > 0)
		return null;
	
	return $item;
}
function nyaasi_get_item($id, $subdom='') {
	$ret = nyaasi_get_item_raw($id, $subdom);
	if($ret)
		return nyaasi_fmt_item($ret, $subdom);
	return $ret;
}

function nyaasi_run_from_scrape($timebase, $subdom='', $cat='1_1') {
	// query DB for items
	global $db;
	list($cat1, $cat2) = explode('_', $cat);
	$table = 'arcscrape.nyaasi'.($subdom?'s':'').'_torrents';
	$cat_cond = 'main_category_id = '.$cat1.' AND sub_category_id = '.$cat2;
	foreach($db->selectGetAll($table, 'id',
		/* we select idx_class>=0 (currently 0,1,2 but provision for more); use IN() to hint MySQL optimizer to use index */
		'idx_class IN(0,1,2,3,4,5,6) AND ('.$cat_cond.') AND created_time >= '.$timebase.' AND nyaa_id IS NULL',
		'`'.$table.'`.*', ['order' => 'created_time ASC', 'joins' => [
			['left', 'toto', 'id', 'nyaa_id']
		]]
	) as $item) {
		$rel = nyaasi_fmt_item($item, $subdom);
		
		// if torrent is missing, Nyaa likely returned 404 when trying to fetch it - we'll skip these for now, as the update retries the fetch, and might be successful there
		// TODO: think of some way to log this, but only the first instance?
		if(!file_exists($rel['torrentfile']))
			continue;
		
		$item['source'] = 'nyaasi';
		releasesrc_add($rel, 'toto_', false, $item);
	}
	return true;
}

function nyaasi_add($id, $subdom='', $disable_skipping=false) {
	if(!($item = nyaasi_get_item_raw($id, $subdom)))
		return $item;
	
	$rel = nyaasi_fmt_item($item, $subdom);
	$item['source'] = 'nyaasi';
	releasesrc_add($rel, 'toto_', $disable_skipping, $item);
}

function nyaasi_changes_from_feed($feed, $sukebei, $idnext) {
	global $db;
	$feed = feed_change_preprocess($feed, $idnext);
	if(empty($feed)) return [];
	
	$tablePref = 'arcscrape.nyaasi'.($sukebei?'s':'').'_torrent';
	$earliest = end($feed)['id'];
	$items = $db->selectGetAll($tablePref.'s', 'id', 'id BETWEEN '.$earliest.' AND '.($idnext-1), 'id,display_name,created_time,info_hash,main_category_id,sub_category_id,flags', ['order' => 'id DESC']);
	// TODO: get comment count as well
	// - the count doesn't seem to include deleted comments, and we currently don't track comment deletion status
	
	$ret = [];
	
	// loop through all feed items to see what's changed
	// keep a list of DB items that haven't been observed to detect deletions
	foreach($feed as $fitem) {
		$id = $fitem['id'];
		if(!isset($items[$id])) {
			$ret[$id] = 'new';
			continue;
		}
		$ditem = $items[$id];
		
		$dcat = $ditem['main_category_id'].'_'.$ditem['sub_category_id'];
		$dflags = $ditem['flags'] & (NYAASI_FLAG_TRUSTED | NYAASI_FLAG_REMAKE | NYAASI_FLAG_DELETED);
		$fflags = 0;
		if($fitem['nyaa:trusted'] == 'Yes')
			$fflags |= NYAASI_FLAG_TRUSTED;
		elseif($fitem['nyaa:trusted'] != 'No') {
			$dflags &= ~NYAASI_FLAG_TRUSTED;
			warning("Unexpected trusted value {$fitem['nyaa:trusted']} for $id", 'nyaasi');
		}
		if($fitem['nyaa:remake'] == 'Yes')
			$fflags |= NYAASI_FLAG_REMAKE;
		elseif($fitem['nyaa:remake'] != 'No') {
			$dflags &= ~NYAASI_FLAG_REMAKE;
			warning("Unexpected remake value {$fitem['nyaa:remake']} for $id", 'nyaasi');
		}
		
		if($fitem['title'] != $ditem['display_name']) {
			$ret[$id] = 'change:title';
		}
		elseif($fitem['nyaa:categoryid'] != $dcat) {
			$ret[$id] = 'change:category';
		}
		elseif($dflags != $fflags) {
			$ret[$id] = 'change:flags';
		}
		// have seen a number of instances where the time has changed - maybe this is due to deleting+reposting the torrent?
		// our script doesn't update the created time though, so exclude this as an update
		//elseif($fitem['time'] != $ditem['created_time'])
		//	$ret[$id] = 'change:created';
		
		if(hex2bin($fitem['nyaa:infohash']) != $ditem['info_hash'])
			warning("Unexpected infoHash mismatch for $id: {$fitem['nyaa:infohash']}<>".bin2hex($ditem['info_hash']), 'nyaasi');
		
		unset($items[$id]);
	}
	
	// items still in DB might've been deleted
	foreach($items as $ditem) {
		if(!($ditem['flags'] & (NYAASI_FLAG_DELETED | NYAASI_FLAG_HIDDEN)))
			$ret[$ditem['id']] = 'deleted';
	}
	return $ret;
}
