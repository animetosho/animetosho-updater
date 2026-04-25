<?php
if(PHP_SAPI != 'cli') die;
if($argc!=3) die("Syntax: [script] fid file\n");

if(!posix_getuid()) die("Don't run this as root\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

if(!file_exists($infile = realpath($argv[2]))) die("Invalid file\n");

loadDb();
unset($config);

$file = $db->selectGetArray('files', 'id='.intval($argv[1]));
if(!$file['id']) die("Invalid fid\n");

if(filesize_big($infile) != (string)$file['filesize']) die("Filesize mismatch (".filesize_big($infile)." <-> $file[filesize])!\n");
// TODO: check crc32k?  but that could be broken

require ROOT_DIR.'includes/finfo.php';

// sort out attachments
$attachments = $db->selectGetField('attachments', 'attachments', 'fid='.$file['id'], 'attachments');
if($attachments) {
	$attachments = FileInfoCompressor::decompress_unpack('attach', $attachments);
	$aids = [];
	foreach($attachments as $type => $atItems) {
		if($type == ATTACHMENT_CHAPTERS || $type == ATTACHMENT_TAGS)
			$aids[$atItems] = 1;
		else
			foreach($atItems as $attach)
				$aids[$attach['_afid']] = 1;
	}
	
	// search for other attachments referencing the files
	// TODO: technically we don't need to scan the whole table - if we've found all files ref'd elsewhere, we can stop the scan, but how often does that occur?
	echo "Searching for referenced attachments... ";
	$arefs = find_attachfile_refs(array_keys($aids));
	if(count($arefs) != count($aids)) {
		echo "Find attachfile ID count mismatch - search AFIDs: ".implode(',', $aids)."\nRefs:\n";
		foreach($arefs as $afid => $r)
			echo $afid, ': ', implode(',', $r), "\n";
		die;
	}
	foreach($arefs as $afid => $fids) {
		if(count($fids) > 1) // there should be a ref to the current file - if there's more, we've got another ref
			unset($aids[$afid]);
	}
	
	$db->delete('attachments', 'fid='.$file['id']);
	
	if(!empty($aids)) {
		$aids = array_keys($aids);
		$aidstr = implode(', ', $aids);
		echo "removing $aidstr\n";
		$db->delete('attachment_files', 'id IN('.$aidstr.')');
		foreach($aids as $aid) {
			// delete underlying files
			$storehash = id2hash($aid);
			$storefile = TOTO_STORAGE_PATH.'attachments/'.substr($storehash, 0, 5).'/'.substr($storehash, 5).'.xz';
			unlink($storefile);
		}
	} else {
		echo "nothing to remove\n";
	}
}

// clear out stored screenshots
// clearing files.filestore is ideal, but not really necessary since it'll be overridden
$zipfile = implode('', make_id_dirs($file['id'], TOTO_STORAGE_PATH.'sshots/')); // make_id_dirs is somewhat wrong, but okay for here
@unlink(TOTO_STORAGE_PATH.'sshots/'.$zipfile.'.zip');

// clear out sframes + subs
$ssfbase = implode('', make_id_dirs($file['id'], TOTO_STORAGE_PATH.'sframes/'));
exec('/bin/rm -f '.escapeshellarg(TOTO_STORAGE_PATH.'sframes/'.$ssfbase.'_').'*');

// clear out fileinfo
$db->delete('fileinfo', 'fid='.$file['id']);
// clearing vidframes_info is probably ideal, but likely unnecessary
//$db->update('files_extra', ['vidframes_info' => null], 'fid='.$file['id']);

$out = fileinfo_run($file['id'], $infile);
if(empty($out)) die("No fileinfo returned\n");

unset($out['video_duration']);

$db->update('files', $out, 'id='.$file['id']);

