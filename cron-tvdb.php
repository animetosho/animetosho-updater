<?php

define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

make_lock_file(substr(THIS_SCRIPT, 5, -4));

$time = time();
@set_time_limit(900);


// update list from Git first
chdir(ROOT_DIR.'anidb_mapping');
system('git pull');
chdir(ROOT_DIR);

// now process XML
$xml = xml_parse_data(file_get_contents(ROOT_DIR.'anidb_mapping/anime-list.xml'));

$ins = [];
$adbids = [];
foreach($xml[0]['children'] as $anime) {
	$aatr =& $anime['attributes'];
	// because sometimes the XML has spurious spaces...
	if(isset($aatr['ANIDBID'])) $aatr['ANIDBID'] = trim($aatr['ANIDBID']);
	if(isset($aatr['TVDBID'])) $aatr['TVDBID'] = trim($aatr['TVDBID']);
	
	if(!is_numeric($aatr['ANIDBID']))
		warning('AniDB ID not numeric: '.$aatr['ANIDBID'], 'tvdb');
	if(isset($aatr['TVDBID']) && !is_numeric($aatr['TVDBID']) && !in_array(strtolower($aatr['TVDBID']), ['unknown','hentai','movie','ova','web','music video','tv special','tvspecial','other']))
		warning('TVDB ID not numeric: '.$aatr['TVDBID'].' for '.$aatr['ANIDBID'], 'tvdb');
	if(isset($aatr['EPISODEOFFSET']) && !is_numeric($aatr['EPISODEOFFSET']))
		warning('Episode offset not numeric: '.$aatr['EPISODEOFFSET'].' for '.$aatr['ANIDBID'], 'tvdb');
	if(isset($aatr['TMDBID']) && !preg_match('~^(\d+|unknown)(,(\d+|unknown))*$~i', $aatr['TMDBID']))
		warning('TMDB ID not numeric: '.$aatr['TMDBID'].' for '.$aatr['ANIDBID'], 'tvdb');
	$row = [
		'anidbid' => (int)$aatr['ANIDBID'],
		'tvdbid' => (int)@$aatr['TVDBID'],
		'defaulttvdbseason' => null,
		'episodeoffset' => (int)@$aatr['EPISODEOFFSET'] ?: 0,
		'tmdbids' => preg_replace('~[^0-9,]~', '', @$aatr['TMDBID'] ?: ''),
		'imdbids' => str_replace('unknown', '', str_replace('tt', '', @$aatr['IMDBID'])),
		'mapping-list' => '',
		'before' => '',
	];
	if(isset($aatr['DEFAULTTVDBSEASON'])) {
		if(is_numeric($aatr['DEFAULTTVDBSEASON']) && (int)$aatr['DEFAULTTVDBSEASON'] < 120)
			$row['defaulttvdbseason'] = (int)$aatr['DEFAULTTVDBSEASON'];
		elseif($aatr['DEFAULTTVDBSEASON'] != 'a')
			warning('Unknown value ('.$aatr['DEFAULTTVDBSEASON'].') for defaulttvdbseason for '.$row['anidbid'], 'tvdb');
	}
	if(isset($aatr['IMDBID']) && !preg_match('~^(tt\d+|unknown)(,(tt\d+|unknown))*$~', $aatr['IMDBID']))
		warning('Unexpected IMDB IDs: '.$aatr['IMDBID'].' for '.$aatr['ANIDBID'], 'tvdb');
	
	foreach($anime['children'] as $child) {
		if($child['tag'] == 'MAPPING-LIST') {
			// trim stuff
			if(!empty($child['children'])) {
				$row['mapping-list'] = jencode(array_map(function($e) {
					if(!empty($e['value']))
						$e['attributes']['value'] = trim($e['value'], ';');
					return array_combine(array_map('strtolower', array_keys($e['attributes'])), array_values($e['attributes']));
				}, $child['children']));
			}
		} elseif($child['tag'] == 'BEFORE') {
			$row['before'] = trim(@$child['value'], ';');
		}
	}
	
	// check for empty entry
	$isEmpty = true;
	foreach($row as $k => $v) {
		if($v && $k != 'anidbid') {
			$isEmpty = false;
			break;
		}
	}
	if(!$isEmpty) {
		$ins[] = $row;
		$adbids[$row['anidbid']] = 1;
	}
}

// execute delete + insert
loadDb();
unset($config);

if(!empty($ins)) {
	$db->delete('anidb_tvdb', 'anidbid NOT IN ('.implode(',', array_keys($adbids)).')');
	$db->insertMulti('anidb_tvdb', $ins, true);
} else {
	warning('No items defined for TVDB mapping!', 'tvdb');
}
