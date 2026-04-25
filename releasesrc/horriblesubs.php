<?php

function hrsubs_run($timebase) {
	global $db;
	
	$known_ids = array(); // to prevent unlikely case that an item appears twice (i.e. across a page boundary)
	
	// grab dates from RSS feed
	$feed_dates = [];
	$rss_items = parse_feed('http://horriblesubs.info/rss.php?res=all');
	if(empty($rss_items)) {
		info('Unable to get feed items', 'horriblesubs');
	} else foreach($rss_items as $item) {
		if(!isset($item['link']) || !isset($item['time'])) continue;
		$btih = extractBtihFromMagnet($item['link']);
		if($btih)
			$feed_dates[$btih] = $item['time'];
	}
	
	for($page=0; $page<=9; ++$page) {
		$data = send_request('http://horriblesubs.info/lib/latest.php?nextid='.$page);
		$data = fix_cf_emails($data);
		
		// currently we just match IDs as we'll need to pull extended info anyway...
		preg_match_all('~\<table class\="release-info".*?\</table\>\s*\<div class\="release-links.*?\</div\>(?=\<table class\="release-info"|$)~s', $data, $items);
		if(empty($items[0])) {
			warning('Unable to find valid entries'.log_dump_data($data, 'horriblesubs'), 'horriblesubs');
			return;
		}
		
		foreach($items[0] as $e) {
			
			$grp = [];
			
			if(preg_match('~\<td class\="rls-label">\((\d\d)/(\d\d)\)~', $e, $m)) {
				$y = (int)gmdate('Y');
				if((int)$m[1] > (int)gmdate('n'))
					// assume year boundary crossed
					$y--;
				
				$ds = $y.'-'.$m[1].'-'.$m[2];
				$day = strtotime($ds);
				
				// check timebase
				if($day && $day < $timebase-86400*2) return true; // entry older than base, bail
				if($day) $grp['day'] = $ds;
			}
			
			if(preg_match('~\<a title\="[^"]+" href\="(/shows/[^"]+)">([^<]+)</a>(?: - ([0-9.]*(?:v\d+)?))?\</td\>~', $e, $m)) {
				$grp['title'] = unhtmlspecialchars(trim($m[2]));
				$grp['showlink'] = unhtmlspecialchars(trim($m[1]));
				$grp['ep'] = unhtmlspecialchars(trim(@$m[3]));
				$grp['name'] = unhtmlspecialchars(strip_tags($m[0]));
			} elseif(preg_match('~\(\d+/\d+\) (([^<]+?)(?: - ([0-9.]*(?:v\d+)?))?)\</td\>~', $e, $m)) {
				$grp['title'] = unhtmlspecialchars(trim($m[2]));
				$grp['showlink'] = '';
				$grp['ep'] = unhtmlspecialchars(trim(@$m[3]));
				$grp['name'] = unhtmlspecialchars($m[1]);
			} else {
				warning('Unable to find title for group'.log_dump_data($e, 'horriblesubs-grp'), 'horriblesubs');
				continue;
			}
			
			// grab files
			preg_match_all('~\<div class\="release-links ([^"]+)">\<table class\="release-table".*?\<i\>([^<]+)\</i\>.*?(\<td .*?\</td\>)\</tr\>~s', $e, $fm, PREG_SET_ORDER);
			if(empty($fm)) {
				warning('Unable to match files for group'.log_dump_data($e, 'horriblesubs-grp'), 'horriblesubs');
				continue;
			}
			
			foreach($fm as $f) {
				// match links
				preg_match_all('~\<span class\="dl-link">\<a title\="([^"]+)" href\="([^"]+)"\>([^<]+)\</a\>\</span\>~', $f[3], $lm, PREG_SET_ORDER);
				$links = [];
				foreach($lm as $l) {
					$links[unhtmlspecialchars(trim($l[3]))] = unhtmlspecialchars($l[2]);
				}
				if(!isset($links['Magnet'])) continue; // can't process this item if there's no torrent/magnet
				
				$file = array_merge($grp, [
					'name' => unhtmlspecialchars($f[2])
				]);
				if(isset($links['Magnet'])) {
					$file['magnet'] = $links['Magnet'];
					$file['btih'] = extractBtihFromMagnet($links['Magnet']);
					unset($links['Magnet']);
				}
				if(isset($links['Torrent'])) {
					$file['torrent'] = $links['Torrent'];
					unset($links['Torrent']);
				}
				if(!empty($links))
					$file['links'] = $links;
				
				if(!isset($file['btih'])) continue; // skip, as we can't run sanity checks on this item
				
				if(isset($feed_dates[$file['btih']]))
					$file['dateline'] = $feed_dates[$file['btih']];
				
				$id = $f[1];
				if(strpos($id, ' ') !== false)
					$id = $file['btih'];
				else
					$file['id'] = $id;
				
				// check existence of this item
				if(isset($known_ids[$id])) continue;
				$known_ids[$id] = 1;
				if($db->selectGetField('toto', 'id', 'btih='.$db->escape($file['btih'])))
					continue;
				
				hrsubs_add($file);
			}
		}
	}
	return true;
}

function hrsubs_add($det, $disable_skipping=false) {
	$comment = '';
	
	// place HS' additional links in a comment
	if(!empty($det['links'])) foreach($det['links'] as $link => $url) {
		$append = ($comment ? ' | ':'').$link.': '.$url;
		$newcomment = $comment.$append;
		if(strlen($newcomment) > 220) break;
		$comment = $newcomment;
	}
	
	// try a pseudo timestamp, which should be somewhat okay in most cases
	$date = 0;
	if(isset($det['dateline']))
		$date = $det['dateline'];
	elseif(isset($det['day']))
		$date = strtotime($det['day'].' '.date('H:i:s'));
	
	releasesrc_add([
		'name' => '[HorribleSubs] '.$det['name'].'.mkv',
		'cat' => 1,
		'dateline' => $date,
		'comment' => $comment,
		'website' => 'http://horriblesubs.info/',
		'magnetlink' => $det['magnet'],
		'link' => @$det['torrent'] ?: '',
	], 'toto_', $disable_skipping);
}

