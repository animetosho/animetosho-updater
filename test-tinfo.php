<?php

define('ROOT_DIR', dirname(__FILE__).'/');
define('THIS_SCRIPT', basename(__FILE__));
define('DEFAULT_ERROR_HANDLER', 1);
require ROOT_DIR.'init.php';

require ROOT_DIR.'includes/releasesrc.php';

if($argc < 3) die("Syntax: php test-tinfo.php {torpc|torpcm|filelist} [torrent filename]\n");

array_shift($argv);
$mode = $argv[0];

$torrent = file_get_contents($argv[1]);
if(!$torrent) die("Couldn't load file\n");

require_once ROOT_DIR.'3rdparty/BDecode.php';
$tinfo = BDecode($torrent);
if(empty($tinfo['info'])) die("Missing torrent info\n");

if($mode == 'torpc' || $mode == 'torpcm') {
	$torpc = torrent_compute_torpc_sha1($tinfo);
	if(!$torpc) die("Couldn't compute torpc_sha1\n");

	$torpc_sha1 = $torpc['torpc_sha1'];
	$torpc['torpc_sha1'] = bin2hex($torpc['torpc_sha1']);
	var_dump($torpc);
	if($mode == 'torpcm') {
		loadDb();
		$calctorpc = [16, 32, 64, 128, 256, 512, 1024, 2048, 4096, 8192, 16384];
		$piece_size_kb = $torpc['piece_size']/1024;
		if(($torpc['piece_size'] % 1024 == 0) && in_array($piece_size_kb, $calctorpc) && ($torpc['hash_coverage']/$torpc['filesize']) > 0.97) {
			$tor_matches = $db->selectGetAll('files_extra', 'fid', 'torpc_sha1_'.$piece_size_kb.'k='.$db->escape($torpc_sha1).' AND filesize='.$torpc['filesize'], 'files_extra.fid, filename, toto_id', [
				'joins' => [['inner', 'files', 'fid', 'id']]
			]);
			var_dump(array_values($tor_matches));
		} else
			echo "Skipped finding matches\n";
	}
}
elseif($mode == 'filelist') {
	$total_size = 0;
	$torrent_filelist = releasesrc_torrent_filelist($tinfo, $total_size);
	var_dump($torrent_filelist, $total_size);
}
else
	die("Unknown mode $mode\n");
