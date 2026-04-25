<?php
if(PHP_SAPI != 'cli') die;

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';
require ROOT_DIR.'includes/releasesrc.php';
require ROOT_DIR.'releasesrc/anidex.php';
require ROOT_DIR.'releasesrc/toto.php';
require ROOT_DIR.'releasesrc/nyaasi.php';
require ROOT_DIR.'releasesrc/nekobt.php';
loadDb();
unset($config);


array_shift($argv);
if(empty($argv)) die("No IDs supplied\n");


$verbose = false;
if($argv[0] == '-v') {
	$verbose = true;
	array_shift($argv);
}

foreach($argv as $input_id) {
	$id = (int)substr($input_id, 1);
	// TODO: this doesn't really look at category filtering
	if($input_id[0] == 't') {
		$item = $db->selectGetArray('arcscrape.tosho_torrents', 'id='.$id);
		if(!$item) {
			echo "Couldn't find Tosho $id\n";
			continue;
		}
		$rowinfo = toto_fmt_from_db($item);
		// TODO: check Nyaa/Anidex dupes?
		$btih = bin2hex($item['info_hash']);
		$rowinfo['torrentfile'] = TOTO_STORAGE_PATH.'torrents/'.substr($btih, 0, 3).'/'.substr($btih, 3).'.torrent';
		if(!file_exists($rowinfo['torrentfile'])) {
			if($rowinfo['nyaa_id'])
				$rowinfo['torrentfile'] = nyaasi_torrent_file_loc($rowinfo['nyaa_id'], $rowinfo['nyaa_subdom']);
			elseif($rowinfo['anidex_id'])
				$rowinfo['torrentfile'] = anidex_torrent_file_loc($rowinfo['anidex_id']);
			elseif($rowinfo['nekobt_id'])
				$rowinfo['torrentfile'] = nekobt_torrent_file_loc($rowinfo['nekobt_id']);
		}
		$item = ['source' => 'tosho'];
		$item['_id'] = $db->selectGetField('toto', 'id', 'tosho_id='.$id);
	} elseif($input_id[0] == 'n') {
		$item = nyaasi_get_item_raw($id, '', true);
		if(!$item) {
			echo "Couldn't find Nyaa $id\n";
			continue;
		}
		$rowinfo = nyaasi_fmt_item($item, '');
		$item['source'] = 'nyaasi';
		$item['_id'] = $db->selectGetField('toto', 'id', 'nyaa_id='.$id);
	} elseif($input_id[0] == 'd') {
		$item = anidex_get_item_raw($id, true);
		if(!$item) {
			echo "Couldn't find Anidex $id\n";
			continue;
		}
		$rowinfo = anidex_fmt_info($item);
		$rowinfo['tosho_id'] = 0;
		$item['source'] = 'anidex';
		$item['_id'] = $db->selectGetField('toto', 'id', 'anidex_id='.$id);
	} elseif($input_id[0] == 'k') {
		$item = nekobt_get_item_raw($id, true);
		if(!$item) {
			echo "Couldn't find nekoBT $id\n";
			continue;
		}
		$rowinfo = nekobt_to_rowinfo($item);
		$rowinfo['tosho_id'] = 0;
		$item['source'] = 'nekobt';
		$item['_id'] = $db->selectGetField('toto', 'id', 'nekobt_id='.$id);
	} else
		die("Unknown ID type $input_id\n");
	
	if(!in_array($rowinfo['cat'], $GLOBALS['toto_cats'])) {
		echo "Not in tosho category\n(but if it wasn't...)\n";
	}
	
	$extra_srcInfo = $item;
	if($reason = releasesrc_ignore_file($rowinfo, $extra_srcInfo)) {
		echo "Ignored: $reason\n(but if it wasn't...)\n";
	}
	
	$total_size = 0;
	$error = '';
	if(empty($rowinfo['torrentfile']) || !($tinfo = releasesrc_get_torrent('file', $rowinfo['torrentfile'], $error))) {
		if(empty($rowinfo['link']) || !($tinfo = releasesrc_get_torrent('link', $rowinfo['link'], $error))) {
			echo "Failed to get torrent: $error\n";
			continue;
		}
	}
	
	$torrent_filelist = releasesrc_torrent_filelist($tinfo, $total_size);
	if(empty($torrent_filelist)) {
		echo "Could not determine filelist\n";
		continue;
	}
	
	if($verbose) {
		$vars = compact('total_size', 'tinfo', 'rowinfo', 'extra_srcInfo');
		unset($vars['tinfo']['info']['pieces'], $vars['tinfo']['torrentdata'], $vars['extra_srcInfo']['info_hash']);
		if(isset($vars['tinfo']['btih']))
			$vars['tinfo']['btih'] = bin2hex($vars['tinfo']['btih']);
		var_dump($vars);
	}
	
	$still_add = true;
	$skipReason = releasesrc_skip_file($total_size, $tinfo, $rowinfo, $extra_srcInfo, $still_add);
	if(!$skipReason) {
		$unwantedFiles = releasesrc_unwanted_files($tinfo, $rowinfo, $extra_srcInfo);
		if($unwantedFiles === true)
			$skipReason = 'No files wanted';
		elseif($unwantedFiles) {
			echo "Will process, but ".count($unwantedFiles)." files will be skipped\n";
			continue;
		}
	}
	
	if($skipReason) {
		if($still_add)
			echo "Skipped: $skipReason\n";
		else
			echo "Skipped+Hide: $skipReason\n";
	} else
		echo "Not skipped\n";
}
