<?php

function anidex_ddos_cookie() {
	static $ddos_guard_cookie = '';
	if(empty($ddos_guard_cookie)) {
		$data = send_request('https://check.ddos-guard.net/check.js');
		if(preg_match('~\'/\.well-known/ddos-guard/id/(\w{16})\'~', $data, $m)) {
			$ddos_guard_cookie = '__ddg2_='.$m[1].'&';
		} else {
			// couldn't retrieve cookie - but it seems like any value works too
			$ddos_guard_cookie = '__ddg2_=&';
		}
	}
	return $ddos_guard_cookie;
}

// $hentai_toggle: 0=off, 1=hide, 2=only
function anidex_req($page, $args, $hentai_toggle=0) {
	$data = send_request('https://anidex.info/'.$page.($args ? '/'.$args : ''), $null, [
		// anidex having problems at time of writing, so try larger timeouts
		'timeout' => 150,
		'conntimeout' => 60,
		'cookie' => anidex_ddos_cookie().'anidex_h_toggle='.$hentai_toggle,
		//'ignoreerror' => true, // have gotten HTTP 500 for https://anidex.info/torrent/67582 (probably some bug in the PHP), but it otherwise works (HTTP response is cut off)
		'ipresolve' => CURL_IPRESOLVE_V4, // blocked in IPv6, so use IPv4 as a workaround (previously was the other way around)
		'autoretry' => 5
	]);
	
	// undo CloudFlare email protection
	$data = fix_cf_emails($data);
	
	return $data;
}

function anidex_run_from_scrape($timebase) {
	// query DB for items
	global $db;
	$table = 'arcscrape.anidex_torrents';
	foreach($db->selectGetAll($table, 'id',
		'category IN(1,3) AND `'.$table.'`.language=1 AND (labels&6 = 0) AND `'.$table.'`.deleted=0 AND date >= '.$timebase.' AND anidex_id IS NULL',
		'`'.$table.'`.*, `arcscrape.anidex_groups`.website', ['order' => 'date ASC', 'joins' => [
			['left', 'toto', 'id', 'anidex_id'],
			['left', 'arcscrape.anidex_groups', 'group_id', 'id']
		]]
	) as $item) {
		$rel = anidex_fmt_info($item);
		$item['source'] = 'anidex';
		releasesrc_add($rel, 'toto_', false, $item);
	}
	return true;
}

function anidex_fmt_desc($desc) {
	require_once ROOT_DIR.'3rdparty/jBBCode/Parser.php';
	$parser = new JBBCode\Parser();
	$parser->addCodeDefinitionSet(new JBBCode\DefaultCodeDefinitionSet());
	$parser->parse(str_replace("\r", '', $desc));
	$ret = $parser->getAsHtml(); // getAsText doesn't seem to handle image tags properly, so we'll manually strip tags
	$ret = preg_replace("~\n?\<h[1-6]\>~", "\n$0", $ret); // headings are on new lines
	$ret = trim(strip_tags($ret));
	// grab just the first line
	if($p = strpos($ret, "\n"))
		$ret = substr($ret, 0, $p);
	// limit to 150 chars max
	if(isset($ret[150]))
		return '';
	return $ret;
}

function anidex_torrent_file_loc($id) {
	$hash = id2hash($id);
	return TOTO_STORAGE_PATH.'anidex_archive/'.(ltrim(substr($hash, 0, 5), '0') ?: '0').'/'.substr($hash, 5).'.torrent';
}

function anidex_fmt_info($det) {
	$labels = ['batch','raw','hentai','reencode','private'];
	// use description as comment if small
	$desc = '';
	if(isset($det['description'])) // from DB
		$det['desc'] = $det['description'];
	if(@$det['desc'])
		$desc = anidex_fmt_desc($det['desc']);
	
	if(isset($det['id']) && isset($det['filename'])) {
		// from database, format differently
		$tags = [];
		foreach($labels as $i => $l) {
			$tags[$l] = ($det['labels'] & (1<<$i));
		}
		return [
			'anidex_id' => $det['id'],
			'name' => $det['filename'],
			'cat' => anidex_translate_cat($det['category'], $det['language'], $tags['batch'], $tags['raw'], $tags['hentai']),
			'dateline' => $det['date'],
			'totalsize' => $det['filesize'],
			'comment' => $desc,
			'website' => @$det['website'] ?: '',
			//'anidex_lang' => $det['language'],
			'anidex_cat' => $det['category'],
			'anidex_labels' => $det['labels'],
			//'anidex_uid' => $det['uploader_id'],
			'link' => 'https://anidex.info/dl/'.$det['id'],
			'torrentfile' => anidex_torrent_file_loc($det['id']),
			'nyaa_class' => $tags['reencode'] ? 1:0,
		];
	}
	if(empty($det) || !isset($det['dlink_torrent']) || !isset($det['title']) || !isset($det['cat_id']) || !isset($det['lang_id']))
		return false;
	
	$lbl = 0;
	foreach($labels as $i => $l)
		if(@$det['tag_'.$l]) $lbl |= 1<<$i;
	
	return [
		'anidex_id' => $det['anidex_id'],
		'name' => $det['title'],
		'cat' => anidex_translate_cat($det['cat_id'], $det['lang_id'], @$det['tag_batch'], @$det['tag_raw'], @$det['tag_hentai']),
		'dateline' => @$det['date'] ?: 0,
		'comment' => $desc,
		'website' => @$det['link_website'] ?: '',
		//'anidex_lang' => $det['lang_id'],
		'anidex_cat' => $det['cat_id'],
		'anidex_labels' => $lbl,
		//'anidex_uid' => @$det['uploader_id'] ?: 0,
		'link' => 'https://anidex.info'.$det['dlink_torrent'],
		'magnetlink' => @$det['dlink_magnet'] ?: '',
		'nyaa_class' => @$det['tag_reencode'] ? 1:0,
		'anidex_info' => jencode([
			'name' => $det['title'],
			'uploader_name' => @$det['uploader_name'],
			'group_id' => @$det['group_id'],
			'irc' => @$det['link_irc'],
			'email' => @$det['link_email'],
			'website' => @$det['link_website'],
			'description' => @$det['desc']
		])
	];
}

function anidex_add($id, $disable_skipping=false) {
	$info = anidex_get_item_raw($id);
	if($info === null) {
		// file was deleted
		return;
	}
	
	$rowinfo = anidex_fmt_info($info);
	
	// filter out hentai etc that we're not fetching
	// TODO: should be done better somehow
	if(!in_array($rowinfo['cat'], $GLOBALS['toto_cats'])) {
		return;
	}
	
	$rowinfo['tosho_id'] = 0;
	$info['source'] = 'anidex';
	releasesrc_add($rowinfo, 'toto_', $disable_skipping, $info);
}


function anidex_translate_cat($cat, $langId, $isBatch, $isRaw, $isHentai) {
	// $isRaw is now never set
	switch($cat) {
		case 1: // Anime - Sub
		case 3: // Anime - Dub
			if($isHentai) return 12; // hentai anime
			if($langId == 1) return 1; // English anime
			return 10; // non-Eng anime
		case 2: // Anime - Raw
			if($isHentai) return 12; // hentai anime
			return 7; // Raws
		case 4: // Drama - Sub
		case 5: // Drama - Raw
			return 8; // drama
		case 6: // Light Novel
			if($isHentai) return 4; // hentai
			return 5; // other
		case 7: // Manga - TLed
		case 8: // Manga - Raw
			if($isHentai) return 13; // hentai manga
			return 3; // manga
		case 9: // Music - Lossy
		case 10: // Music - Lossless
			return 2; // music
		case 11: // Music - Video
			return 9; // Music Video
		case 12: // Games
			return 14; // hentai games
		case 13: // Applications
		case 14: // Pictures
			if($isHentai) return 4; // hentai
			return 5; // other
		case 15: // Adult Video
			return 15; // JAV
		case 16: // Other
			return 5; // other
	}
}

function anidex_lang_id($code) {
	static $mapCode = [
		'gb' => 1,
		'jp' => 2,
		'pl' => 3,
		'rs' => 4,
		'nl' => 5,
		'it' => 6,
		'ru' => 7,
		'de' => 8,
		'hu' => 9,
		'fr' => 10,
		'fi' => 11,
		'vn' => 12,
		'gr' => 13,
		'bg' => 14,
		'es' => 15,
		'br' => 16,
		'pt' => 17,
		'se' => 18,
		'sa' => 19,
		'dk' => 20,
		'cn' => 21,
		'bd' => 22,
		'ro' => 23,
		'cz' => 24,
		'mn' => 25,
		'tr' => 26,
		'id' => 27,
		'kr' => 28,
		'mx' => 29,
		'ir' => 30,
	];
	static $mapName = [
		'Arabic' => 19,
		'Bengali' => 22,
		'Bulgarian' => 14,
		'Chinese (Simplified)' => 21,
		'Czech' => 24,
		'Danish' => 20,
		'Dutch' => 5,
		'English' => 1,
		'Finnish' => 11,
		'French' => 10,
		'German' => 8,
		'Greek' => 13,
		'Hungarian' => 9,
		'Indonesian' => 27,
		'Italian' => 6,
		'Japanese' => 2,
		'Korean' => 28,
		'Mongolian' => 25,
		'Persian' => 30,
		'Polish' => 3,
		'Portuguese (Brazil)' => 16,
		'Portuguese (Portugal)' => 17,
		'Romanian' => 23,
		'Russian' => 7,
		'Serbo-Croatian' => 4,
		'Spanish (LATAM)' => 29,
		'Spanish (Spain)' => 15,
		'Swedish' => 18,
		'Turkish' => 26,
		'Vietnamese' => 12,
	];
	return $mapCode[$code];
}

function anidex_get_detail($id) {
	$ret = array('anidex_id' => $id, 'likes' => 0, 'updated' => (int)gmdate('U'));
	for($i=0; $i<5; ++$i) {
		$data = anidex_req('torrent',$id);
		if(!$data) {
			sleep(10);
			continue;
		}
		if(!preg_match('~\<h3 class\="panel-title"\>.*?\</span\>([^<>"]+)\<~si', $data, $m)) {
			if(preg_match('~Torrent \d+ does not exist~i', $data)) // deleted / invalid
				return null;
			if(strpos($data, 'Your IP address has accessed AniDex too frequently.') !== false) {
				info('IP banned');
				return false;
			} elseif(substr(trim($data), -18) == '<div id="content">' || substr(trim($data), -6) == '">Mail') {
				info('Anidex served incomplete details page for '.$id, 'anidex');
			} elseif(strpos($data, '<title>504 Gateway Time-out</title>')) {
				// derp on Anidex's side
			} else
				info("Can't find title (id:$id)".log_dump_data($data, 'anidex-det'), 'anidex');
			$data = false;
			sleep(10);
			continue;
		}
		if(!stripos($data, '</html>')) {
			info("Missing end tag (id:$id)".log_dump_data($data, 'anidex-det'), 'anidex');
			$data = false;
			sleep(10);
			continue;
		}
		break;
	}
	if(!$data) {
		info('Failed to get details page for '.$id, 'anidex');
		return false;
	}
	$ret['title'] = unhtmlspecialchars(trim($m[1]));
	
	if(preg_match('~\<span class=\'label label-success\'\>\+(\d+)\</span\>~', $data, $m)) {
		$ret['likes'] = (int)$m[1];
	}
	
	
	foreach(['Torrent info','Edit torrent info'] as $n) if(preg_match('~\>\s*'.$n.'\s*\</.{5,1000}?\<tbody\>(.*?)\</tbody\>~si', $data, $m)) {
		$sect = $m[1];
		if(preg_match_all('~\<th[^>]*?\>([^<]+)\:\s*\</th\>\s*\<td\>(.+?)\</td\>~is', $sect, $matches, PREG_SET_ORDER)) {
			foreach($matches as $m) {
				switch($m[1]) {
					case 'Uploader':
						if(preg_match('~\<a (?:style=\'[^\']+\'\s*)?class\=\'uploader\' id\=\'(\d+)\' [^<]*href\=\'[^\']+\'\>([^<]+)\</a\>~i', $m[2], $m2)) {
							$ret['uploader_name'] = unhtmlspecialchars($m2[2]);
							$ret['uploader_id'] = (int)$m2[1];
						}
					break;
					case 'Language':
						if(preg_match('~\<option selected value\=\'(\d+)\'[^>]*\>([^<]+)\</option\>~i', $m[2], $m2)) {
							$ret['lang_name'] = $m2[2];
							$ret['lang_id'] = (int)$m2[1];
						}
					break;
					case 'Category':
						if(preg_match('~\<option selected value\=\'(\d+)\'~i', $m[2], $m2)) {
							$ret['cat_id'] = (int)$m2[1];
						}
					break;
					case 'Labels':
						if(preg_match_all('~\<input type\="checkbox" name\="([^"]+)" id\="\\1" value\="1" checked\>~i', $m[2], $tags, PREG_SET_ORDER)) {
							foreach($tags as $tag) {
								$ret['tag_'.strtolower($tag[1])] = true;
							}
						}
					break;
					case 'Group':
						if(preg_match('~\<a [^>]*id\=\'(\d+)\' [^>]*href\=\'(?:\?page=|/)group[^\']+\'[^>]*\>([^<]+)\</a\>~i', $m[2], $m2)) {
							$ret['group_name'] = unhtmlspecialchars($m2[2]);
							$ret['group_id'] = (int)$m2[1];
						} elseif(preg_match('~Individual\s*$~', $m[2])) {
							$ret['group_id'] = 0;
						}
					break;
					case 'Links':
						if(preg_match_all('~\<a [^>]*href\="([^"]+)"\>\<span [^>]*title\=\'([^\']+)\'\>\</span\>\</a\>~i', $m[2], $links, PREG_SET_ORDER)) {
							foreach($links as $link) {
								$ret['link_'.strtolower($link[2])] = unhtmlspecialchars($link[1]);
							}
						}
					break;
					case 'Download':
						if(preg_match_all('~\<a [^>]*href\="([^"]+)"\>\<span [^>]*title\=\'([^\']+)\'\>\</span\>~i', $m[2], $dlinks, PREG_SET_ORDER)) {
							foreach($dlinks as $link) {
								$ret['dlink_'.strtolower($link[2])] = unhtmlspecialchars($link[1]);
							}
						}
					break;
				}
			}
		}
	}
	if(preg_match('~\>\s*Scrape info\s*\</.{5,1000}?\<tbody\>(.*?)\</tbody\>~si', $data, $m)) {
		$sect = $m[1];
		if(preg_match_all('~\<th[^>]*\>([^<]+)\:\s*\</th\>\s*\<td\>(.*?)\</td\>~si', $sect, $matches, PREG_SET_ORDER)) {
			foreach($matches as $m) {
				switch($m[1]) {
					case 'Date':
						if(preg_match('~20\d\d-\d\d-\d\d \d\d\:\d\d\:\d\d( UTC)?~', $m[2], $m2)) {
							$ret['date'] = strtotime($m2[0]);
							if(!$m2[1]) $ret['date'] -= 7200;
						}
					break;
					case 'File size':
						if(preg_match('~\>\s*([0-9.]+ \w+)\s*$~i', $m[2], $m2)) {
							$ret['size'] = $m2[1]; // TODO: convert to bytes??
						}
					break;
					case 'Info Hash':
						if(preg_match('~\>\s*([a-f0-9]{40})\s*($|\<)~i', $m[2], $m2)) {
							$ret['btih'] = $m2[1];
						}
					break;
					case 'Torrent Info':
						$ret['torrent_info'] = trim($m[2]);
						if($ret['torrent_info'] == 'None')
							$ret['torrent_info'] = '';
					break;
				}
			}
		}
	}
	
	if(preg_match('~\<div class\="[^"]+"\>\s*\<h3 class\="[^"]+"\>.*?\</span\>\s*(?:Edit description)\</h3\>\s*\</div\>\s*\<div class\="panel-body"\>(.*?)\</div\>~si', $data, $m)) {
		if(preg_match('~\<textarea [^>]+\>(.*?)\</textarea\>~si', $m[1], $m2)) {
			$ret['desc'] = unhtmlspecialchars(trim($m2[1]));
		}
	}
	
	
	// comments
	if(preg_match('~\<div class\="[^"]+"\>\s*\<h3 class\="[^"]+"\>\<span [^>]+\>\</span\>\s*Comments\</h3\>\s*\</div\>\s*\<div class\="panel-body"\>(.*?)\</div\>~si', $data, $m)) {
		preg_match_all('~\<tr\>\s*\<td[^>]*\>(.*?)\</td\>\s*\<td[^>]*\>(.*?)\</td\>\s*\</tr\>~s', $m[1], $cmts, PREG_SET_ORDER);
		$ret['comments'] = [];
		foreach($cmts as $cmt) {
			$c = [];
			if(preg_match('~\<img src=\'(https?[^\']+|/images/user_logos/default\.png)\' ~', $cmt[1], $m)) {
				$c['user_logo'] = unhtmlspecialchars($m[1]);
				if($c['user_logo'] == '/images/user_logos/default.png')
					$c['user_logo'] = '';
			}
			if(preg_match('~\<a [^>]*id=\'(\d+)\'[^>]*\>([^<]+)\</a\>~', $cmt[2], $m)) {
				$c['user_id'] = (int)$m[1];
				$c['user_name'] = unhtmlspecialchars($m[2]);
			}
			if(preg_match('~\</span\>\s*(\d\d\d\d-\d\d-\d\d \d\d:\d\d:\d\d(?: UTC)?)\s*(?:\</span\>\s*)?\<hr[^>]*\>(.*)$~s', $cmt[2], $m)) {
				$c['date'] = strtotime($m[1]);
				$c['message'] = trim($m[2]);
				$c['message'] = preg_replace('~\s*\</td\>\s*\<td [^>]+\>\s*\<button [^>]*class="report_comment_button[^"]*"[^>]*\>.*?\</button\>\s*$~', '', $c['message']); // strip out report button
			}
			$ret['comments'][] = $c;
		}
	}
	
	
	return $ret;
}


function anidex_get_item_raw($id, $incl_del=false) {
	// first, query DB
	global $db;
	$item = $db->selectGetArray('arcscrape.anidex_torrents', '`arcscrape.anidex_torrents`.id='.$id, '`arcscrape.anidex_torrents`.*, `arcscrape.anidex_groups`.website', ['joins' => [
		['left', 'arcscrape.anidex_groups', 'group_id', 'id']
	]]);
	
	// if not exist, try manual query
	// TODO: remove this
	if(!$item) {
		$item = anidex_get_detail($id);
		if(!$item) return $item;
	}
	
	if(!$incl_del && @$item['deleted'])
		return null;
	
	return $item;
}
function anidex_get_item($id) {
	$ret = anidex_get_item_raw($id);
	if($ret)
		return anidex_fmt_info($ret);
	return $ret;
}

function anidex_get_user($id) {
	$ret = array('id' => $id, 'updated' => (int)gmdate('U'));
	for($i=0; $i<5; ++$i) {
		$data = anidex_req('user',$id);
		if(!$data) {
			sleep(10);
			continue;
		}
		
		if(!preg_match('~\<h3 class\="panel-title"\>.*?\</span\>([^<>"]+)\<~si', $data, $m)) {
			if(preg_match('~User \d+ does not exist~i', $data)) // deleted / invalid
				return null;
			info("Can't find title (id:$id)".log_dump_data($data, 'anidex-user'), 'anidex');
			$data = false;
			sleep(10);
			continue;
		}
		break;
	}
	if(!$data) {
		info('Failed to get user details for '.$id, 'anidex');
		return false;
	}
	$ret['username'] = unhtmlspecialchars(trim(strip_tags($m[1])));
	if(preg_match('~src=\'/images/flags/([a-z]{2})\.png\' alt=\'([^\']+)\'~', $data, $m2)) {
		$ret['lang'] = $m2[2];
		$ret['lang_code'] = $m2[1];
		$ret['lang_id'] = anidex_lang_id($m2[1]);
	}
	
	if(preg_match('~<img src=\'([^\']+)\' [^>]*title=\'(?:Default )?User Logo\'~', $data, $m)) {
		$ret['avatar'] = unhtmlspecialchars($m[1]);
		if($ret['avatar'] == '/images/user_logos/default.png')
			$ret['avatar'] = '';
	}
	
	if(preg_match('~\<th[^>]*\>User level:\</th\>\s*\<td\>\<span[^>]*\>\s*\</span\> (?:\<span[^>]*\>)?([^<]+)(?:\</span\>)?\</td\>~', $data, $m)) {
		$ret['user_level'] = trim($m[1]);
	}
	
	preg_match_all('~\<tr\>\s*\<th\>([^<]+):\</th\>\s*\<td\>(.*?)\</td\>\s*\</tr\>~s', $data, $infos, PREG_SET_ORDER);
	foreach($infos as $info) {
		switch($info[1]) {
			case 'Joined':
				$date = trim(strip_tags($info[2]));
				if($date = strtotime($date))
					$ret['joined'] = $date;
				break;
			case 'Last online':
				// probably hard to track, don't bother...
				$date = trim(strip_tags($info[2]));
				if(preg_match('~^(\d+)([smhdw])~', $date, $m)) {
				}
				break;
			case 'Group(s)':
				preg_match_all('~\<a [^>]*href=\'(?:\?page=|/)group(?:&id=|/)(\d+)\'[^>]*\>([^<]+)\</a\>~', $info[2], $m, PREG_SET_ORDER);
				$ret['groups'] = [];
				foreach($m as $g) {
					$ret['groups'][(int)$g[1]] = trim(unhtmlspecialchars($g[2]));
				}
				break;
		}
	}
	
	return $ret;
}

function anidex_get_group($id) {
	$ret = array('id' => $id, 'updated' => (int)gmdate('U'));
	for($i=0; $i<5; ++$i) {
		$data = anidex_req('group',$id);
		if(!$data) {
			sleep(10);
			continue;
		}
		
		if(!preg_match('~\<h3 class\="panel-title"\>.*?\</span\>([^<>"]+)\<~si', $data, $m)) {
			if(preg_match('~Group \d+ does not exist~i', $data)) // deleted / invalid
				return null;
			info("Can't find title (id:$id)".log_dump_data($data, 'anidex-group'), 'anidex');
			$data = false;
			sleep(10);
			continue;
		}
		break;
	}
	if(!$data) {
		info('Failed to get group details for '.$id, 'anidex');
		return false;
	}
	
	$title = $m[1];
	if(preg_match('~\<span class=\'label label-success\'\>\+(\d+)\</span\>~', $data, $m2)) {
		$ret['likes'] = (int)$m2[1];
	}
	$ret['group_name'] = unhtmlspecialchars(trim(strip_tags($title)));
	
	if(preg_match('~\<h3 class\="panel-title"\>.*?\</span\>.*?src=\'/images/flags/([a-z]+)\.png\' alt=\'([^\']+)\'.*?\</h3\>~', $data, $m2)) {
		$ret['lang'] = $m2[2];
		$ret['lang_code'] = $m2[1];
		$ret['lang_id'] = anidex_lang_id($m2[1]);
	}
	
	if(preg_match('~\</div\>\s*<img [^>]*src=\'([^\']+)\'[^>]*/\>\s*\</div\>~', $data, $m)) {
		$ret['banner'] = unhtmlspecialchars($m[1]);
	}
	
	
	
	preg_match_all('~\<tr\>\s*\<th(?: width="\d+px")?\>([^<]+):\</th\>\s*\<td\>(.*?)\</td\>\s*\</tr\>~s', $data, $infos, PREG_SET_ORDER);
	foreach($infos as $info) {
		switch($info[1]) {
			case 'Group tag(s)':
				$ret['tags'] = unhtmlspecialchars($info[2]);
				break;
			case 'Language': // no longer used
				if(preg_match('~src=\'/images/flags/([a-z]+)\.png\' alt=\'([^\']+)\'~', $info[2], $m2)) {
					$ret['lang'] = $m2[2];
					$ret['lang_code'] = $m2[1];
					$ret['lang_id'] = anidex_lang_id($m2[1]);
				}
				break;
			case 'Founded':
				$date = trim(strip_tags($info[2]));
				if($date = strtotime($date))
					$ret['founded'] = $date;
				break;
			case 'Links':
				if(preg_match_all('~\<a [^>]*href\="([^"]+)"\>\<span [^>]*title\=\'([^\']+)\'\>\</span\>\</a\>~i', $info[2], $links, PREG_SET_ORDER)) {
					foreach($links as $link) {
						$ret['link_'.strtolower($link[2])] = unhtmlspecialchars($link[1]);
					}
				}
				break;
			case 'Leader':
				if(preg_match('~\<a [^>]*href\=\'(?:\?page=|/)user(?:&id=|/)(\d+)\'\>([^<]+)\</a\>~i', $info[2], $m2)) {
					$ret['leader_name'] = unhtmlspecialchars($m2[2]);
					$ret['leader_id'] = (int)$m2[1];
				}
				break;
			case 'Members':
				preg_match_all('~\<a [^>]*href=\'(?:\?page=|/)user(?:&id=|/)(\d+)\'[^>]*\>([^<]+)\</a\>~', $info[2], $m, PREG_SET_ORDER);
				$ret['members'] = [];
				foreach($m as $g) {
					$ret['members'][(int)$g[1]] = trim(unhtmlspecialchars($g[2]));
				}
				break;
		}
	}
	
	// comments
	if(preg_match('~\<div [^>]*id="comments"[^>]*\>(.*?)\</div\>~si', $data, $m)) {
		preg_match_all('~\<tr\>\s*\<td[^>]*\>(.*?)\</td\>\s*\<td[^>]*\>(.*?)\</td\>\s*\</tr\>~s', $m[1], $cmts, PREG_SET_ORDER);
		$ret['comments'] = [];
		foreach($cmts as $cmt) {
			$c = [];
			if(preg_match('~\<img src=\'(https?[^\']+|/images/user_logos/default\.png)\' ~', $cmt[1], $m)) {
				$c['user_logo'] = unhtmlspecialchars($m[1]);
				if($c['user_logo'] == '/images/user_logos/default.png')
					$c['user_logo'] = '';
			}
			if(preg_match('~\<a [^>]*id=\'(\d+)\'[^>]*\>([^<]+)\</a\>~', $cmt[2], $m)) {
				$c['user_id'] = (int)$m[1];
				$c['user_name'] = unhtmlspecialchars($m[2]);
			}
			if(preg_match('~\</span\>\s*(\d\d\d\d-\d\d-\d\d \d\d:\d\d:\d\d(?: UTC)?)\s*(?:\</span\>\s*)?\<hr[^>]*\>(.*)$~s', $cmt[2], $m)) {
				$c['date'] = strtotime($m[1]);
				$c['message'] = trim($m[2]);
				$c['message'] = preg_replace('~\s*\</td\>\s*\<td [^>]+\>\s*\<button [^>]*class="report_comment_button[^"]*"[^>]*\>.*?\</button\>\s*$~', '', $c['message']); // strip out report button
			}
			$ret['comments'][] = $c;
		}
	}
	
	return $ret;
}
