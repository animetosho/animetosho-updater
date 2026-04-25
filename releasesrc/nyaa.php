<?php

function nyaa_run($timebase, $cat='1_37') {
	global $db;
	$subdom = ((int)$cat >= 7 ? 'sukebei':''); // FIXME: better way of handling this voodoo rule?
	
	// cache a copy of the latest tosho_ids
	$latest_toto_cache = $db->selectGetAll('toto', 'nyaa_id', 'nyaa_subdom="'.$subdom.'"', 'nyaa_id', array('limit' => 100, 'order' => 'nyaa_id DESC'));
	$added_ids = array(); // to prevent unlikely case that an item appears twice (i.e. across a page boundary)
	
	for($page=1; $page<=9; ++$page) {
		$items = parse_feed('http://'.($subdom?:'www').'.nyaa.se/?page=rss&cats='.$cat.'&offset='.$page);
		if(empty($items)) {
			info('Unable to grab feed items.', 'nyaa');
			return;
		}
		$paging_leadin = true;
		foreach($items as &$item) {
			// check timebase
			$dateline = @$item['time'];
			if($dateline && $dateline < $timebase) {
				//return true; // problem: have seen a case where Nyaa wedged a really old torrent in the middle
				continue;
			}
			
			// grab item ID
			if(preg_match('~tid\=(\d+)$~', $item['link'], $m)) {
				$id = (int)$m[1];
			}
			else {
				warning('[Nyaa] Bad link format: '.$item['link']);
				continue;
			}
			
			// check existence of this item
			if(isset($added_ids[$id]) || toto_item_exists($id, 'nyaa_id', $latest_toto_cache, 'nyaa_id='.$id.' AND nyaa_subdom="'.$subdom.'"')) {
				continue;
				if($paging_leadin) continue;
				// dupe item! :O
				//error('[Nyaa] Duplicate item id='.$rowinfo['id'].'!');
				//return;
				return true;
				// debugging line
				continue;
			} else
				$paging_leadin = false;
			
			nyaa_add($id, $subdom);
			$added_ids[$id] = 1;
		}
		if($paging_leadin) {
			//error('[Nyaa] Reached end of page but still haven\'t found new entries!');
			return true;
		}
	}
	return true;
}


function nyaa_add($id, $subdom='', $disable_skipping=false) {
	$rowinfo = nyaa_get_detail($id, $subdom);
	if($rowinfo === null) {
		// file was deleted
		return;
	}
	if(empty($rowinfo) || !isset($rowinfo['id']) || !isset($rowinfo['link']) || !isset($rowinfo['name']) || !isset($rowinfo['cat'])) {
		warning('[Nyaa] Could not retrieve info for id '.$id.' ('.$subdom.')');
		return;
	}
	
	$rowinfo['tosho_id'] = 0;
	$rowinfo['nyaa_id'] = $id;
	$rowinfo['nyaa_subdom'] = $subdom;
	//unset($rowinfo['id']);
	$rowinfo['dateline'] = strtotime(@$rowinfo['date']);
	if(!$rowinfo['dateline']) $rowinfo['dateline'] = 0;
	
	// use description as comment if small
	if(@$rowinfo['description'] && !isset($rowinfo['description'][100]) && !strpos($rowinfo['description'], "\n")) {
		$rowinfo['comment'] = $rowinfo['description'];
		// TODO: check multiline descriptions
	}
	
	$rowinfo['nyaa_info'] = array(
		'name' => $rowinfo['name'],
		'cat' => $rowinfo['cat'],
		//'nyaa_cat' => $rowinfo['nyaa_cat'],
		'description' => (string)@$rowinfo['description_html'],
		'website' => (string)@$rowinfo['website'],
	);
	
	releasesrc_add($rowinfo, 'toto_', $disable_skipping);
}

function nyaa_translate_cat($cat) {
	switch($cat) { // temporary Nyaa -> Tosho category translation
		case '1_37':
			return 1; // anime
		case '1_11':
			return 7; // raw
		case '1_38':
			return 10; // non-english
		case '1_32':
			return 9; // music video
		case '2_12':
		case '2_13': // raw - TODO: should we put them here?
		case '2_39': // non-eng
			return 3; // manga
		case '3_14':
		case '3_15':
			return 2; // music
		case '4_17':
		case '4_18':
			return 5; // other
		case '5_19':
		case '5_20':
		case '5_21':
		case '5_22':
			return 8; // drama
		case '6_23':
		case '6_24':
			return 5; // other; TODO: put software elsewhere?
		case '7_25':
			return 12; // hentai (anime)
		case '7_26':
			return 13; // hentai (manga)
		case '7_27':
			return 14; // hentai (games)
		case '7_28':
		case '7_33':
			return 4; // hentai
		case '8_30':
		case '8_31':
			return 15; // jav
		default:
			error('[Nyaa] Need to set category translation for '.$cat.'!');
			return 0;
	}
}

function nyaa_data_tor_deleted($data) {
	if(preg_match('~\<div class\="content"\>&(?:nbsp|#160);The torrent you are looking for does not appear to be in (?:the|our) database\.\</div\>~', $data)) return true;
	if(preg_match('~\<div class\="content"\>&(?:nbsp|#160);The torrent you are looking for has been deleted~', $data)) return true;
	return false;
}

function nyaa_get_detail($id, $subdom) {
	$idstr = '(ID='.$id.', subdom='.$subdom.')';
	if(!$subdom) $subdom = 'www';
	for($i=0; $i<3; ++$i) {
		$data = send_request('http://'.$subdom.'.nyaa.se/?page=view&tid='.$id);
		if($data) {
			if(substr_count($data, '<table class="viewtable">') == 1) break;
			elseif(nyaa_data_tor_deleted($data)) return null;
		}
		sleep(10); // retry after 10 secs
	}
	if(!$data) {
		info('[Nyaa/det] No data returned '.$idstr, 'nyaa-det');
		return false;
	}
	if(substr_count($data, '<table class="viewtable">') != 1) {
		warning('[Nyaa/det] Identifier doesn\'t appear exactly once on page '.$idstr.log_dump_data($data, 'nyaa_detail'));
		return false;
	}
	if($i) {
		info('Successfully got nyaa detail after '.$i.' retry. '.$idstr, 'nyaa');
	}
	
	$ret = array();
	if(preg_match('~\<div class\="viewdescription"\>(.*?)\</div\>\s*\<h3\>~si', $data, $m)) {
		$ret['description_html'] = trim($m[1]);
		if($ret['description_html'] == 'None') $ret['description_html'] = '';
		$ret['description'] = trim(unhtmlspecialchars(strip_tags(strtr($ret['description_html'], array('<br />' => "\n")))));
	}
	if(preg_match('~\<div class\="content(| trusted| remake| aplus| hidden)"\>~', $data, $m)) {
		switch(trim($m[1])) {
			case 'remake':
				$ret['nyaa_class'] = 1; break;
			case '':
				$ret['nyaa_class'] = 2; break;
			case 'trusted':
				$ret['nyaa_class'] = 3; break;
			case 'aplus':
				$ret['nyaa_class'] = 4; break;
			case 'hidden':
				$ret['nyaa_class'] = -1; break;
		}
	}
	
	if(preg_match('~\<td class\="viewcategory"\>(.*?)\</td\>~i', $data, $m))
		if(preg_match('~.*\<a href\="(?:https?\:)?//[a-z0-9.\-]+/\?(?:page\=torrents(?:&amp;|&#38;))?cats=([0-9_]+)"\>.+?\</a\>$~i', $m[1], $m)) {
			$ret['nyaa_cat'] = $m[1];
			$ret['cat'] = nyaa_translate_cat($m[1]);
		}
	if(preg_match('~\<div class\="viewdownloadbutton"\>\<a href\="((?:https?\:)?//[a-z0-9.\-]+/\?page\=download(?:&amp;|&#38;)tid=(\d+))"(?: [^>]*)?\>~i', $data, $m)) {
		$ret['link'] = unhtmlspecialchars($m[1]);
		if(strtolower(substr($ret['link'], 0, 4)) != 'http')
			$ret['link'] = 'http:'.$ret['link'];
		$ret['id'] = $m[2];
	}
	
	$data = substr($data, strpos($data, '<table class="viewtable">') + 25);
	if(($p = strpos($data, '</table>')) === false) {
		warning('[Nyaa/det] Cannot find end of table '.$idstr);
		return false;
	}
	$data = substr($data, 0, $p);
	
	
	// process list
	$data = explode('<tr>', preg_replace(array(
		'~\</td\>\s*\</tr\>~i',
		'~\<td class\="[^"]+"\>~i',
		'~\<td\>~i',
		'~\<tr\>\s*\<th .*?\</tr\>\s*\<tr\>~i',
		'~\<tr\>(<th class\="[^"]+">&#160;\</th\>)+\</tr\>~i'
	), array(
		'',
		'',
		'',
		'',
		''
	), $data));
	foreach($data as &$datum) {
		$datum = trim($datum);
		if(!$datum) continue;
		if(strpos($datum, '<th class="thead">')) continue;
		$stuff = array_map('trim', explode('</td>', $datum));
		if(count($stuff) != 4) {
			warning('[Nyaa/det] Unexpected row format: '.$datum);
			continue;
		}
		$stuff[0] = strtolower(strip_tags($stuff[0]));
		$stuff[2] = strtolower(strip_tags($stuff[2]));
		if(substr($stuff[0], -1) == ':') $stuff[0] = substr($stuff[0], 0, -1);
		if(substr($stuff[2], -1) == ':') $stuff[2] = substr($stuff[2], 0, -1);
		
		
		$ret['_'.$stuff[0]] = $stuff[1];
		$ret['_'.$stuff[2]] = $stuff[3];
	}
	// basic processing
	if(isset($ret['_name']))
		$ret['name'] = unhtmlspecialchars($ret['_name']);
	if(isset($ret['_date']))
		$ret['date'] = unhtmlspecialchars($ret['_date']);
	if(isset($ret['_file size']))
		$ret['size'] = $ret['_file size'];
	if(isset($ret['_information']) && preg_match('~\<a href\="(https?\://[^"]+)"~i', $ret['_information'], $m))
		$ret['website'] = unhtmlspecialchars($m[1]);
	
	return $ret;
}
