<?php

if(!defined('TOTO_FINFO_PATH')) {
	require_once ROOT_DIR.'includes/finfo.php';
}
if(!defined('TOTO_ULQUEUE_PATH')) {
	require_once ROOT_DIR.'includes/ulfuncs.php';
}
define('TOTO_NEWS_PATH', '/atdata/news/');

// used in archive/news queue to deal with the possibility that the currently processed item's ID changes
// is still vulnerable to race condition, but this significantly reduces the likelihood
// probably best not to supply $toto, as the cached value may be old
function find_nondupe_item_id($id, $toto=null) {
	if(!$toto) {
		global $db;
		$toto = $db->selectGetArray('toto', 'id='.$id, 'isdupe,btih');
	}
	
	if($toto['isdupe']) {
		// ID likely changed!
		$real_id = $db->selectGetField('toto', 'id', 'btih='.$db->escape($toto['btih']).' AND isdupe=0 AND id!='.$id);
		if($real_id) return $real_id;
		else {
			// WTF??
			error('Could not find non-dupe item for '.$id);
		}
	}
	
	return $id; // not a dupe now? return original ID
}

// $file is the full file path, $filename is the displayed filename
function add_file_entries($file, $filename, $toto_id, $priority=0, $is_archive=false) {
	global $db;
	$dbentry = array(
		'filesize' => filesize_big($file),
		// deferred hashing = can't check for dupes before uploading
		'filename' => $filename,
		'filethumbs' => '',
		'filestore' => '',
		'audioextract' => '',
		
		'toto_id' => $toto_id,
		'type' => ($is_archive ? 1:0)
	);
	$db->insert('files', $dbentry);
	$fid = $dbentry['id'] = $db->insertId();
	
	$fileinfo = array(
		'fid' => $fid,
		'filename' => mb_basename($file),
		'dateline' => time(),
		'priority' => $priority,
	);
	
	link_to_dir(TOTO_FINFO_PATH.'file_'.$fid.'/', $file);
	$db->insert('finfo', $fileinfo);
	
	link_to_dir(TOTO_ULQUEUE_PATH.'file_'.$fid.'/', $file);
	$fileinfo['toto_id'] = $toto_id;
	$db->insert('ulqueue', $fileinfo);
	
	return $dbentry;
}
function link_to_dir($dir, $fn) {
	mkdir($dir);
	$fnbase = mb_basename($fn);
	link_or_copy($fn, $dir.$fnbase);
}



// retrieve file type thresholds
// return: small (always include below), medium (indecisive), large (always exclude above), Vlarge (exclusion compare point)
function _get_archive_files_thresh($ext) {
	switch($ext) {
		case 'avi':
		case 'mkv':
		case 'vob':
		case 'm2ts':
		case 'ts':
		case 'webm':
		case 'flv':
		case 'f4v':
		case 'mpg':
		case 'mpeg':
		case 'mp4':
		case 'm4v':
		case 'mov':
		case 'ogm':
		case 'ogv':
		case 'wmv':
		case 'rm':
		case 'rmvb':
			// videos: lower point
			return array(8192, 16384, 24576, 49152);
		case 'mka':
		case 'mp2':
		case 'mp3':
		case 'm4a':
		case 'aac':
		case 'ogg':
		case 'flac':
		case 'tak':
		case 'ape':
		case 'wma':
		case 'wav':
			// audio: slightly bigger (to handle FLACs better)
			return array(12288, 24576, 40960, 49152);
			break;
			
		case 'jp2':
		case 'jpg':
		case 'jpeg':
		case 'jpe':
		case 'png':
		case 'gif':
		case 'tif':
		case 'tiff':
		case 'bmp':
			// image: higher
			return array(16384, 32768, 40960, 49152);
		case 'zip':
		case '7z':
		case 'rar':
			// archives: lower
			return array(8192, 16384, 24576, 49152);
			
		case 'txt':
		case 'nfo':
		case 'sfv':
		case 'srt':
		case 'idx':
		case 'sub':
		case 'ass':
		case 'ssa':
		case 'otf':
		case 'ttc':
		case 'ttf':
		case 'cue':
		case 'ccd':
		case 'img':
		case 'bin':
			// leave default
	}
	return array(12288, 24576, 32768, 49152);
}
class FileCmpHeap extends SplMinHeap {
	protected function compare ( $value1 , $value2 ) {
		return bccomp($value1['fs'], $value2['fs']);
	}
}
function get_archive_files($filelist) {
	// firstly, sort files in order of filesize
	$sorted_files = new FileCmpHeap;
	$incl_assoc_ext = $excl_assoc_ext = array();
	$incl_assoc_dir = $excl_assoc_dir = array();
	$incl_assoc_extdir = $excl_assoc_extdir = array();
	$arcfiles = array();
	foreach($filelist as $file) {
		if(is_array($file)) {
			list($fn, $fs) = $file;
		} else {
			$fn = $file;
			$fs = filesize_big($fn);
		}
		$fs = (float)bcdiv($fs, 1024);
		// ***NOTE*** file sizes are now in KB
		
		$fileext = get_extension($fn);
		$filedir = dirname($fn); // TODO: be able to determine top level dir & exclude
		
		$thresh = _get_archive_files_thresh($fileext);
		
		if($fs > $thresh[2] && $fs < $thresh[3]) {
			if($fileext) @++$excl_assoc_ext[$fileext];
			if($filedir) @++$excl_assoc_dir[$filedir];
			if($fileext && $filedir) @++$excl_assoc_extdir[$fileext.'.'.$filedir];
		} elseif($fs < $thresh[0]) {
			if($fileext) @++$incl_assoc_ext[$fileext];
			if($filedir) @++$incl_assoc_dir[$filedir];
			if($fileext && $filedir) @++$incl_assoc_extdir[$fileext.'.'.$filedir];
		}
		
		// file too big
		if($fs > $thresh[2]) continue;
		
		$sorted_files->insert(array(
			'fn' => $fn,
			'fs' => $fs,
			'ext' => $fileext,
			'dir' => $filedir,
			'thresh' => $thresh
		));
	}
	
	// next, go thru heap
	$cnt = $sorted_files->count();
	while($cnt--) {
		$file = $sorted_files->extract();
		$fileext = $file['ext'];
		$filedir = $file['dir'];
		$thresh = $file['thresh'];
		
		
		if($file['fs'] >= $thresh[0]) {
			// largeish file, start being more critical
			// currently only consider extensions, ignoring directories
			
			$ex_ext = isset($excl_assoc_ext[$fileext]);
			$in_ext = isset($incl_assoc_ext[$fileext]);
			$ex_dir = isset($excl_assoc_extdir[$fileext.'.'.$filedir]);
			$in_dir = isset($incl_assoc_extdir[$fileext.'.'.$filedir]);
			if(!$in_ext) continue; // if no include association, consider skipping
			
			$include_anyway = $file['fs'] < $thresh[1];
			if($in_dir != $ex_dir)
				$include_anyway = $in_dir;
			
			// TODO: maybe look at counts as well?
			if(!$ex_ext || $include_anyway) {
				$arcfiles[] = $file['fn'];
			}
		} else {
			$arcfiles[] = $file['fn'];
			// TODO: perhaps include checks on how big the archive actually is???
			// TODO: perhaps consider skipping if there's an overwhelming number of skipped files like this
		}
	}
	
	if(count($arcfiles) < 5) return false;
	return $arcfiles;
}

function get_archive_meth($files) {
	// now determine archive settings to use
	$uncomp_files = $comp_files = 0;
	//$uncomp_size = $comp_size = 0;
	foreach($files as $file) {
		// TODO: consider using filesizes
		switch(get_extension($file)) {
			case 'avi':
			case 'mkv':
			case 'mka':
			case 'vob':
			case 'm2ts':
			case 'ts':
			case 'webm':
			case 'flv':
			case 'f4v':
			case 'mpg':
			case 'mpeg':
			case 'mp2':
			case 'mp3':
			case 'mp4':
			case 'mov':
			case 'm4v':
			case 'm4a':
			case 'aac':
			case 'ogg':
			case 'flac':
			case 'tak':
			case 'ape':
			case 'ogm':
			case 'ogv':
			case 'wma':
			case 'wmv':
			case 'rm':
			case 'rmvb':
			case 'jp2':
			case 'jpg':
			case 'jpeg':
			case 'jpe':
			case 'png':
			case 'gif':
			case 'tif':
			case 'tiff':
			case 'zip':
			case '7z':
			case 'rar':
			case 'r00':
			case 'r01':
			case 'r02':
			case 'r03':
			case 'r04':
			case 'r05':
			case 'r06':
			case 'r07':
			case 'r08':
			case 'r09':
			case 'r10':
			case 'r11':
			case 'r12':
			case 'r13':
			case 'r14':
			case 'r15':
			case 'r16':
			case 'r17':
			case 'r18':
			case 'r19':
			case 'r20':
			case 'r21':
			case 'r22':
			case 'r23':
			case 'r24':
			case 'r25':
			case 'r26':
			case 'r27':
			case 'r28':
			case 'r29':
			case 'r30':
			case 'r31':
			case 'r32':
			case 'r33':
			case 'r34':
			case 'r35':
			case 'r36':
			case 'r37':
			case 'r38':
			case 'r39':
				//$comp_size += $fs;
				++$comp_files;
				break;
			case 'wav':
			case 'bmp':
			case 'txt':
			case 'nfo':
			case 'sfv':
			case 'srt':
			case 'idx':
			case 'sub':
			case 'ass':
			case 'ssa':
			case 'otf':
			case 'ttc':
			case 'ttf':
			case 'cue':
			case 'ccd':
			case 'img':
			case 'bin':
				//$uncomp_size += $fs;
				++$uncomp_files;
		}
	}
	$known_files = $comp_files + $uncomp_files;
	if($known_files) {
		$cratio = $comp_files/$known_files;
		if($cratio > 0.5) // >50% compressed files
			$cmpmeth = '-m0=copy ';
		else
			$cmpmeth = '-m0=lzma2 -mx=2 -md=24m -ms=on ';
	} else //unknown files, use some random setting
		$cmpmeth = '-m0=lzma2 -mx=2 -md=24m -ms=on ';
	
	return $cmpmeth;
}

