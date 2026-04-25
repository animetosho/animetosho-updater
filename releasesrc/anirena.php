<?php

// TODO: better error logging
function anirena_get_list($q='') {
	$data = send_request('http://www.anirena.com/index.php?'.$q);
	if(!$data) return false;
	
	if(stripos($data, '<p>No torrents could be shown !</p>'))
		return array();
	
	preg_match_all('~\<div class\="full_spacer"\>\</div\>\s*\<table(?: [^>]*)?\>\s*\<tr\>\s*(.+?)\s*\</tr\>\s*\</table\>~si', $data, $m);
	if(@empty($m[1])) {
		info("Can't find any items; q=$q".log_dump_data($data, 'anirena'), 'anirena');
		return false;
	}
	
	$catMap = array_flip(array(
		'',
		'raw', //'RAW',
		'anime', //'Anime',
		'hentai', //'Hentai',
		'drama', //'Drama',
		'dvd', //'DVD/ISO',
		'hgame2', //'Hentai-Game',
		'manga', //'Manga',
		'music', //'Music',
		'???', //'AMV',
		'noneng', //'Non-English',
		'other', //'Other',
	));
	
	$ret = array();
	foreach($m[1] as $item) {
		$e = array();
		if(preg_match('~\<td class\="torrents_small_type_data1"[^>]*\>\<img[^>]*? src\="[^"]+/cat_([a-z0-9]+)_small\.png"~si', $item, $m)) {
			if(isset($catMap[$m[1]]))
				$e['cat'] = $catMap[$m[1]];
			else {
				$e['cat'] = '0:'.$m[1];
				warning('Unknown Anirena category: '.$m[1]);
			}
		}
		
		if(preg_match('~\<div class\="torrents_small_info_data1"[^>]*\>(.+?)\</div\>~si', $item, $m)) {
			$t = preg_replace('~^\<b\>.+?\</b\>\s*~', '', trim($m[1]));
			if(preg_match('~\<a (?:[^>]*? )?title\="([^"]+)" (?:[^>]*? )?onClick\="get_details\(\'details\d+\', (\d+)\);?"~i', $t, $m)) {
				$e['id'] = (int)$m[2];
				$e['title'] = unhtmlspecialchars($m[1]);
			}
		}
		
		if(preg_match('~\<a href\="(magnet\:[^"]+)"~si', $item, $m))
			$e['magnet'] = $m[1];
		
		if(preg_match('~\<td class\="torrents_small_size_data1"[^>]*\>([^<]+)\</td\>~si', $item, $m))
			$e['size'] = $m[1];
		
		if(preg_match('~\<td class\="torrents_small_seeders_data1"[^>]*\>(.+?)\</td\>~si', $item, $m)) {
			if(preg_match('~\d+~', strip_tags($m[1]), $m2))
				$e['seeds'] = (int)$m2[0];
		}
		if(preg_match('~\<td class\="torrents_small_leechers_data1"[^>]*\>(.+?)\</td\>~si', $item, $m)) {
			if(preg_match('~\d+~', strip_tags($m[1]), $m2))
				$e['leechers'] = (int)$m2[0];
		}
		if(preg_match('~\<td class\="torrents_small_downloads_data1"[^>]*\>(.+?)\</td\>~si', $item, $m)) {
			if(preg_match('~\d+~', strip_tags($m[1]), $m2))
				$e['downloads'] = (int)$m2[0];
		}
		
		if(@$e['id'])
			$ret[] = $e;
		else {
			info("No ID found for item; q=$q".log_dump_data($item, 'anirena-item'), 'anirena');
		}
	}
	return $ret;
}

// attempt to determine the category by doing a search-lookup
function anirena_get_item_info($id, $name) {
	$items = anirena_get_list('s='.urlencode($name));
	if(empty($items)) return null;
	foreach($items as $item) {
		if($item['id'] == $id)
			return $item;
	}
	return null;
}

function anirena_get_detail($id) {
	for($i=0; $i<3; ++$i) {
		$data = send_request('http://www.anirena.com/torrent_details.php?id='.$id);
		if(stripos($data, '<p>Torrent Not Found</p>'))
			return null; // deleted
		
		$dat = @json_decode($data);
		if(!empty($dat)) break;
		info('[Anirena/det] Invalid data returned (id='.$id.')'.log_dump_data($data, 'anirena-det'), 'anirena-det');
		sleep(10); // retry after 10 secs
	}
	if(empty($dat)) {
		info('[Anirena/det] No data returned (id='.$id.')', 'anirena-det');
		return false;
	}
	
	// TODO:
	// keys: UPLOADED, CREATED_BY, TORRENT_CREATED, OWNER_NAME, COMMENT, INFO_HASH, CRC, FILE_LIST, ANNOUNCE_LIST
}
