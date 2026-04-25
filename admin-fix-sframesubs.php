<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("Syntax: [script] fid...\n");

if(!posix_getuid()) die("Don't run this as root\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

loadDb();
unset($config);

array_shift($argv);

// for convenience, allow hex representation
foreach($argv as &$arg) {
	if(preg_match('~^[0-9a-f]{3}/[0-9a-f]{3}/[0-9a-f]{2}$~i', $arg)) {
		$arg = hexdec(str_replace('/', '', $arg));
	}
} unset($arg);

$files = $db->selectGetAll('files', 'id', 'id IN ('.implode(',', array_map('intval', $argv)).')', 'files.*, files_extra.vidframes_info', [
	'joins' => [
		['left', 'files_extra', 'id', 'fid']
	]
]);
if(empty($files)) die("No valid files\n");

$hasMediainfo = $db->selectGetAll('fileinfo', 'fid', 'fid IN('.implode(',', array_keys($files)).') AND type="mediainfo"', 'fid');

require ROOT_DIR.'includes/finfo.php';

$tmpsdir = make_temp_dir();
foreach($files as $fid=>$file) {
	$ssfbase = make_id_dirs($fid, TOTO_STORAGE_PATH.'sframes/');
	$fi = [
		'ts' => array_map('intval', explode(',', $file['vidframes'])),
		'basename' => TOTO_STORAGE_PATH.'sframes/'.$ssfbase[0].$ssfbase[1]
	];
	if(empty($fi['ts'])) {
		echo "Skipping $fid (no video)\n";
		continue;
	}
	$fi['ts'] = array_combine($fi['ts'], $fi['ts']);
	
	if(!isset($hasMediainfo[$fid])) {
		echo "Skipping $fid (no mediainfo)\n";
		continue;
	}
	// use ffprobe on sframe
	$sframes = glob($fi['basename'].'_*.mkv');
	if(empty($sframes)) {
		echo "Skipping $fid (no sframes found)\n";
		continue;
	}
	$info = get_ffprobe(reset($sframes));
	if($info) $dims = get_ffprobe_dims($info);
	if(empty($dims)) {
		echo "Skipping $fid (couldn't get width/height from ffprobe)\n";
		continue;
	}
	$fi['w'] = $dims['w'];
	$fi['h'] = $dims['h'];
	
	// if better frame info available, use it
	if($file['vidframes_info']) {
		$vi = FileInfoCompressor::munpack($file['vidframes_info']);
		if(empty($vi) || count($vi) != count($fi['ts'])) {
			echo "Invalid vidframes_info ";
		} else {
			$i = 0;
			foreach($fi['ts'] as &$ts) {
				$frame = $vi[$i++];
				if(isset($frame['t']))
					$ts = round($frame['t'] / 1000);
			} unset($ts);
		}
	} else {
		// otherwise, we fall back to the hack of adding a frame
		if(!isset($dims['avg_fps']))
			echo "**FPS UNDETERMINED** ";
		else {
			// add 2 frames to compensate for bad positioning in ffmpeg
			foreach($fi['ts'] as &$ts) {
				$ts += round((1000/$dims['avg_fps'])*2);
			} unset($ts);
		}
	}
	
	$subtracks = array();
	
	$attachments = $db->selectGetField('attachments', 'attachments', 'fid='.$file['id']);
	if(empty($attachments)) {
		echo "Skipping $fid (no attachments)\n";
		continue;
	}
	$attachments = FileInfoCompressor::decompress_unpack('attach', $attachments);
	echo "Extracting attachments for $fid... ";
	$fileCounter = 0;
	foreach([ATTACHMENT_OTHER, ATTACHMENT_SUBTITLE] as $attachType) {
		if(empty($attachments[$attachType])) continue;
		foreach($attachments[$attachType] as $attach) {
			// extract xz(fileid) to filename
			$storehash = id2hash($attach['_afid']);
			$storefile = TOTO_STORAGE_PATH.'attachments/'.substr($storehash, 0, 5).'/'.substr($storehash, 5).'.xz';
			$fn = $tmpsdir.'attachment'.$attach['_afid'].'_'.(++$fileCounter);
			system('/usr/bin/xz -dkc '.escapeshellarg($storefile).' >'.escapeshellarg($fn));
			
			// file #5752 is the blank file
			if(!@filesize($fn) && $attach['_afid'] != 5752) die("Failed to extract $fn\n");
			
			if($attachType == ATTACHMENT_SUBTITLE) {
				if(!isset($attach['trackid'])) die("Missing track ID for $attach[id]\n");
				// for initial import, skip SRTs as they're not supported
				if(true || $attach['codec'] != 'SRT') {
					$attach['fn'] = $fn;
					if(isset($subtracks[$attach['trackid']])) {
						// possible VOB file
						$stPrev =& $subtracks[$attach['trackid']];
						if($attach['codec'] != 'VOB' || $stPrev['codec'] != 'VOB') die("Multi-track file $attach[id] is not a VOB\n");
						if(isset($stPrev['filecount']))
							die("More than 2 files in subtitle\n");
						$stPrev['filecount'] = 2;
						
						// select .idx
						$extCur = strtolower(substr($fn, -4));
						$extPrv = strtolower(substr($stPrev['fn'], -4));
						if($extCur == '.idx' && $extPrv == '.sub')
							$stPrev['fn'] = $fn;
						elseif(!($extCur == '.sub' && $extPrv == '.idx'))
							die("Unrecognised VOB structure\n");
					} else {
						$subtracks[$attach['trackid']] = $attach;
					}
				}
			}
		}
	}
	
	if(empty($subtracks)) {
		exec('/bin/rm -f '.escapeshellarg($tmpsdir).'*');
		echo "No subtitle tracks, skipping\n";
		continue;
	}
	
	if(count(glob($fi['basename'].'_*.webp')) > 0) {
		echo "Deleting existing renders... ";
		exec('/bin/rm -f '.escapeshellarg($fi['basename'].'_').'*.webp');
	}
	
	echo "Rendering subtitles... "; 
	foreach($subtracks as $subtrack) {
		if($subtrack['codec'] == 'VOB' && !isset($subtrack['filecount']))
			die("VOB file doesn't have two parts\n");
		
		dump_subtitle_images($fi['w'], $fi['h'], $subtrack['codec'], $subtrack['fn'], $tmpsdir, $fi['ts'], $fi['basename'].'_'.$subtrack['tracknum']);
	}
	
	exec('/bin/rm -f '.escapeshellarg($tmpsdir).'*');
	echo "Done\n";
}

exec('/bin/rm -rf '.escapeshellarg($tmpsdir));
