<?php

if(!defined('TOTO_ULQUEUE_PATH')) {
	require ROOT_DIR.'includes/ulfuncs.php'; // for TOTO_ULQUEUE_PATH
}

require_once ROOT_DIR.'includes/finfo-compress.php';
require_once ROOT_DIR.'includes/attach-info.php';

define('TOTO_FINFO_PATH', '/atdata/finfo/');

// although named 'sshots', it's only used for images extracted from archives now
define('STORE_ORIG_SSHOTS', false);
$store_sshot_array = array('thumb' => array());
if(STORE_ORIG_SSHOTS)
	$store_sshot_array = array('thumb' => array(), 'original' => array());

// $idx param is a dodgy hack to grab multiple infos
define('INFO_MAX_LENGTH', 1048576);
function get_file_info($file, &$type=null, $idx=0, &$extra=null, $force_ext=null) {
	$fi = '';
	$type = '';
	$extra = false;
	$ext = $force_ext ?: get_extension($file);
	if(!$ext) {
		$type = '';
		return '';
	}
	if($ext[0] == '0' && ctype_digit($ext)) return ''; // numerical extension, such as .001 etc
	$fn_preg = strtr(mb_basename($file), array('\\' => '\\\\', '$' => '\\$'));
	
	// TODO: check file magic and overwrite extension if necessary
	
	
	if($idx == 0) {
		switch($ext) {
			case 'zip':
			case '7z':
			case 'iso':
			case 'rar':
				$rtn_val = timeout_exec('nice -n5 7z l -p '.escapeshellfile($file), 240, $fi, ['retries' => 1]);
				if(!$rtn_val) {
					if(preg_match('~^\s*7-Zip .+? (Copyright .+? Igor Pavlov) .+?p7zip .*?'."\r?\n".'--'."\r?\n".'Path .+?'."\r?\n".'Type ~s', $fi, $m)) {
						$fi = str_replace("\r", '', '7-Zip '.$m[1].'

Listing archive: '.mb_basename($file).'

--
Type ').fix_utf8_encoding(substr($fi, strlen($m[0])));
					}
					$extra = parse_7z_filelist($fi);
					$type = '7z';
				} else {
					warning('7z info failed; file: '.$file.', return code: '.$rtn_val.log_dump_data($fi, 'finfo_7z'), 'finfo');
					$fi = '';
				}
				break;
			/* case 'rar':
				$rtn_val = timeout_exec('nice -n5 unrar l '.escapeshellfile($file), 30, $fi);
				if(!$rtn_val && preg_match('~'."\r?\n".'Archive ~', $fi)) {
					if(preg_match('~^\s*UNRAR .+?(Copyright .*? Alexander Roshal).+?'."\r?\n".'Archive .+?'."\r?\n".'~s', $fi, $m)) {
						$fi = str_replace("\r", '', 'UNRAR  '.$m[1].'

Archive '.mb_basename($file).'
').fix_utf8_encoding(substr($fi, strlen($m[0])));
					}
					$type = 'unrar';
				} else {
					warning('unrar info failed; return code: '.$rtn_val.log_dump_data($fi, 'finfo_unrar'));
					$fi = '';
				}
				break; */
			// text
			case 'txt':
			case 'nfo':
			case 'sfv':
			case 'md5':
			case 'sha1':
			case 'bat':
			case 'srt':
			case 'vtt':
			case 'ass':
			case 'ssa':
			case 'cue':
				if($fp = fopen($file, 'rb')) {
					$fi = fix_utf8_encoding(fread($fp, INFO_MAX_LENGTH));
					$type = 'content';
					fclose($fp);
				}
				break;
			// ignored
			case 'doc':
			case 'docx':
			case 'pdf':
			case 'html':
			case 'htm':
			case 'rtf':
			case 'exe':
			case 'xdelta':
			case 'idx':
			// rar
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
			// fonts
			case 'otf':
			case 'ttc':
			case 'ttf':
			case 'ccd':
			case 'img':
			case 'bin':
			case '':
				break;
			
			case 'avi':
			case 'rm':
			case 'rmvb':
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
			case 'mov':
			case 'm4v':
			case 'ogm':
			case 'ogv':
			case 'wmv':
				$extra = true;
			case 'asf':
			case 'mka':
			case 'mks':
			case 'mp2':
			case 'mp3':
			case 'm4a':
			case 'aac':
			case 'ac3':
			case 'dts':
			case 'ogg':
			case 'flac':
			case 'tak':
			case 'ape':
			case 'wav':
			case 'wma':
			case 'jp2':
			case 'jpg':
			case 'jpeg':
			case 'jpe':
			case 'bmp':
			case 'png':
			case 'gif':
			case 'tif':
			case 'tiff':
			case 'sub':
				if(strpos($file, '?') !== false || strpos($file, '*') !== false) { // mediainfo doesn't like filenames with these chars, so handle these specially
					$midest = dirname($file).'/mediainfo_tmp'.mt_rand();
					log_event('Using Mediainfo workaround for filename with special characters: linking to '.$midest);
					if(symlink($file, $midest)) {
						$rtn_val = timeout_exec('nice -n5 mediainfo '.escapeshellfile($midest), 90, $fi, ['retries' => 1]);
						unlink($midest);
					} else {
						warning('[mediainfo] Symlink creation failed for mediainfo workaround!  File='.$file.' dest='.$midest, 'finfo');
						$rtn_val = 1;
					}
				} else
					$rtn_val = timeout_exec('nice -n5 mediainfo '.escapeshellfile($file), 90, $fi, ['retries' => 1]);
				if(!$rtn_val) {
					$fi = preg_replace('~(?<='."\n".')(Complete name\s*\:) '.".+?\r?\n".'~', '$1 '.$fn_preg."\n", $fi);
					$fi = preg_replace_callback('~(?<='."\n".')(CompleteName_Last\s*\: )'."(.+?)\r?\n".'~', function($m) {
						return $m[1].mb_basename($m[2])."\n";
					}, $fi);
					
					if(preg_match("~^\s*General\r?\nComplete Name\s+\: [^\r\n]+\r?\nFile size\s+\: [0-9,. \-a-zA-Z]+\s*$~", $fi)) {
						// bad media file
						$fi = '';
					}
					if($fi) $type = 'mediainfo';
				} else {
					$fi = trim($fi);
					warning('mediainfo failed; file: '.$file.', return code: '.$rtn_val.log_dump_data($fi, 'finfo_mediainfo'), 'finfo');
					$fi = '';
				}
				break;
			case 'url':
				$data = @file_get_contents($file, true, null, 0, 16384);
				if(preg_match("~^\s*\[InternetShortcut\]\s*\r?\n~i", $data) && preg_match("~\r?\n\s*URL\s*=\s*(https?\://[^\r\n]+)~i", $data, $match)) {
					$fi = $match[1];
					$type = 'url';
				}
				break;
			default:
				// do nothing
				info('Unknown extension \''.$ext.'\' for file '.$file, 'finfo');
		}
	}
	elseif($idx == 1) {
		switch($ext) {
			case 'zip':
			case '7z':
			case 'rar':
				$rtn_val = timeout_exec('nice -n5 7z l -slt -p '.escapeshellfile($file), 600, $fi, ['retries' => 1]);
				if(!$rtn_val) {
					if(preg_match('~^\s*7-Zip .+? (Copyright .+? Igor Pavlov) .+?p7zip .*?'."\r?\n".'--'."\r?\n".'Path .+?'."\r?\n".'Type ~s', $fi, $m)) {
						$fi = str_replace("\r", '', '7-Zip '.$m[1].'

Listing archive: '.mb_basename($file).'

--
Type ').fix_utf8_encoding(substr($fi, strlen($m[0])));
					}
					$extra = parse_7z_filelist($fi);
					$type = '7z-slt';
				} else {
					warning('7z info failed; file: '.$file.', return code: '.$rtn_val.log_dump_data($fi, 'finfo_7zslt'), 'finfo');
					$fi = '';
				}
				break;
			case 'mkv':
			case 'mka':
			case 'mks':
				$rtn_val = timeout_exec('nice -n5 mkvinfo '.escapeshellfile($file), 90, $fi, ['retries' => 1]);
				if(!$rtn_val) {
					if(substr($fi, 0, 11) != '+ EBML head') {
						// bad file
						$fi = '';
					}
					if($fi) {
						$type = 'mkvinfo';
						$fi = preg_replace("~\n".preg_quote($file, '~').'(: Error in the Matroska file structure at position \d+\.)~', "\n".$fn_preg.'$1', $fi);
					}
				} else {
					$warninfo = 'mkvinfo failed; file: '.$file.', return code: '.$rtn_val;
					if(substr(trim($fi), 0, 29) == '(MKVInfo) No EBML head found.')
						info($warninfo.' '.$fi, 'finfo-mkvinfo');
					elseif(strpos($fi, '(MKVInfo) No segment/level 0 element found.') !== false)
						info($warninfo.' (MKVInfo) No segment/level 0 element found.', 'finfo-mkvinfo');
					else {
						warning($warninfo.log_dump_data($fi, 'finfo_mkvinfo'), 'finfo');
					}
					$fi = '';
				}
				break;
			case 'mp4':
			case 'm4a':
			case 'm4v':
				$rtn_val = timeout_exec('nice -n5 MP4Box -quiet -info '.escapeshellfile($file), 90, $null, ['retries' => 1, 'stderr' => &$fi]);
				if(!$rtn_val) {
					if(strpos($fi, '* Movie Info *') === false) {
						// bad file
						$fi = '';
					}
					if($fi) $type = 'mp4boxinfo';
				} else {
					$warninfo = 'mp4boxinfo failed; file: '.$file.', return code: '.$rtn_val;
					if(strpos($fi, 'Unknown input file type') !== false)
						info($warninfo.' Not an MP4 file', 'finfo-mp4boxinfo');
					elseif(strpos($fi, 'IsoMedia File is truncated') !== false)
						info($warninfo.' MP4 file truncated', 'finfo-mp4boxinfo');
					else {
						warning($warninfo.log_dump_data($fi, 'finfo_mp4box'), 'finfo');
					}
					$fi = '';
				}
				break;
		}
	}
	$fi = trim(str_replace("\r", '', $fi));
	return $fi;
}

function get_ffprobe($file, $args='-show_format -show_streams', $no_warn = false) {
	static $ffBin = null;
	if(!isset($ffBin)) {
		$ffBin = (file_exists(ROOT_DIR.'3rdparty/ffprobe') ? ROOT_DIR.'3rdparty/ffprobe' : 'ffprobe');
	}
	// change CWD so that format.filename doesn't show the path
	$rtn_val = timeout_exec($ffBin.' -print_format json '.$args.' '.escapeshellarg(basename($file)), 180, $output, ['retries' => 1, 'cwd' => dirname($file)]);
	if($rtn_val) {
		if(!$no_warn) info('ffprobe failed for '.$file.' with return code '.$rtn_val, 'finfo');
		return false;
	}
	$data = @json_decode($output);
	if(empty($data))
		return false;
	if(isset($data->format->probe_score) && $data->format->probe_score < 50) {
		if(!$no_warn) info('Ignored low ffprobe probe score '.$file.'; score: '.@$data->format->probe_score, 'finfo');
		return false;
	}
	return $data;
}

function get_ffprobe_dims($info) {
	$ret = [];
	$sar = 0;
	if(is_array(@$info->streams)) foreach($info->streams as $stream) {
		if(isset($stream->width) && isset($stream->height)) {
			$ret['ow'] = $w = (int)$stream->width;
			$ret['oh'] = $h = (int)$stream->height;
			if(isset($stream->sample_aspect_ratio)) {
				$_sar = $stream->sample_aspect_ratio;
				if(strpos($_sar, ':')) {
					list($rw, $rh) = array_map('floatval', explode(':', $_sar));
					if($rw && $rh) $sar = $rw/$rh;
				} elseif(preg_match('~^\d+(\.\d+)?$~', $_sar))
					$sar = floatval($_sar);
				
				if(!$sar) $sar = 1;
				if($sar != 1) {
					if($sar > 1)
						$w = (int)round($w*$sar);
					else
						$h = (int)round($h/$sar);
				}
			}
			$ret['w'] = $w;
			$ret['h'] = $h;
			if(isset($stream->index))
				$ret['id'] = (int)$stream->index;
			if(isset($stream->avg_frame_rate)) {
				list($nom, $den) = explode('/', $stream->avg_frame_rate);
				if($den != 0)
					$ret['avg_fps'] = $nom/$den;
			}
			break;
		}
	}
	return $ret;
}

// very basic preg_match to get mediainfo value
function get_mi_value($val, $data, $section='') {
	if($section) {
		if(preg_match("~(?:^|\r?\n\r?\n)$section(\r?\n.*?)(?:$|\r?\n\r?\n)~si", trim($data), $m)) {
			$data = $m[1];
		} else
			return null;
	}
	if(preg_match("~\n$val\s+\: (.*?)($|\r?\n)~", $data, $m)) {
		return trim($m[1]);
	}
	return null;
}

function calc_thumb_dims($w, $h) {
	if($w > 128 || $h > 96) {
		$wr = $w/128;
		$hr = $h/96;
		if($wr > $hr) {
			return '128x'.max(round($h/$wr),1);
		} else {
			return max(1,round($w/$hr)).'x96';
		}
	} else {
		// extremely small image? don't need to generate thumb
		return '';
	}
}

// processes a bunch of input images in parallel
// this process will compress the input images, and generate some thumbnails
function process_images($fid, $script, $images, $thumbdims, $thumbext='', $tmpdir='', &$storage=null) {
	$screenurls = array();
	if(empty($images)) return $screenurls;
	
	log_event('Processing images');
	
	// parallel process files
	$exec = '';
	foreach($images as $k => &$img) {
		$khash = substr(sha1($k), 0, 16);
		$exec .= ($exec?"\n":'') . escapeshellarg(ROOT_DIR.$script)
			.' '. escapeshellfile($img)
			.' '. escapeshellfile(dirname($img).'/proc_'.$khash);
		$thumbs = '';
		if(is_array($thumbdims)) {
			if(isset($thumbdims[$k])) $thumbs = $thumbdims[$k];
		}
		elseif($thumbdims)
			$thumbs = $thumbdims;
		if($thumbs)
			$exec .= ' '.$thumbs.' '.escapeshellfile(dirname($img).'/th_'.$khash.$thumbext);
		else
			$exec .= ' 0';
	}
	exec('echo '.escapeshellarg($exec).' | parallel --nice 10 --max-procs 50%');
	$screenfiles = array();
	foreach($images as $k => &$img) {
		$khash = substr(sha1($k), 0, 16);
		$ssfilebase = 'proc_'.$khash;
		$ssfile = dirname($img).'/'.$ssfilebase;
		$imgext = '.'.get_extension($img);
		if(!file_exists($ssfile)) {
			info('Screenshot compression failed for key \''.$k.'\' for file '.$img, 'finfo-ss');
			continue;
		}
		
		// send image to fiqueue storage
		if(!STORE_ORIG_SSHOTS) {
			if(!isset($finfodir))
				$finfodir = make_finfo_dir($fid);
			$dest = $finfodir.$ssfilebase.$imgext;
			link_or_copy($ssfile, $dest);
			$screenfiles[$k] = $dest;
		}
		
		if(isset($storage['original'])) {
			$storage['original'][$k] = store_sshot($ssfile, $fid, 'o'.$khash.$imgext, $tmpdir);
		} else
			@unlink($ssfile);
		
		$thumbfile = dirname($img).'/th_'.$khash.$thumbext;
		if($thumbdims && file_exists($thumbfile)) {
			if(isset($storage['thumb'])) {
				$storage['thumb'][$k] = store_sshot($thumbfile, $fid, 't'.$khash.'.'.get_extension($thumbfile), $tmpdir);
			} else
				@unlink($thumbfile);
		}
	}
	if(!empty($screenfiles))
		delay_finfo($fid, 'sshot', $screenfiles);
	
	return $screenurls;
}

function parse_7z_filelist($data) {
	if(!preg_match("~Listing archive.*?\n\s*Date\s+Time\s+Attr\s+Size\s+Compressed\s+Name\s*?\n[\- ]{20,}\r?\n(.*)\n[\- ]{20,}\r?\n~s", $data, $m)) return false;
	// we'll assume \r's don't exist, since we run Linux servers
	$files = array();
	foreach(explode("\n", $m[1]) as $file) {
		if(!preg_match('~^[12][90]\d\d-\d\d-\d\d \d\d\:\d\d\:\d\d ([A-Z.]+)\s+(\d+)\s+\d+\s+(.*)$~', trim($file), $fm))
			return false;
		if(strpos($fm[1], 'D') !== false) continue; // this is a directory
		$files[trim($fm[3])] = $fm[2]; // noting that this could be >4GB
	}
	return $files;
}


// file info + screenshots
function fileinfo_run($fid, $file) {
	$tmpsdir = '';
	$ret = array();
	
	$fileinfo_db = [];
	$add_fileinfo_db = function($data, $type) use($file, $fid, &$fileinfo_db) {
		if(is_object($data) || is_array($data))
			$data_cmp = FileInfoCompressor::compress_pack($type, $data);
		else
			$data_cmp = FileInfoCompressor::compress($type, $data);
		if(strlen($data_cmp) > INFO_MAX_LENGTH) {
			// too large, can this
			warning('[finfo] Compressed file info ['.$type.'] for '.$file.' ('.$fid.') is too large - '.strlen($data_cmp).' from '.strlen($data).' bytes');
		} else {
			$fileinfo_db[] = [
				'fid' => $fid, 'type' => $type, 'info' => $data_cmp
			];
		}
	};
	log_event('Getting file info');
	
	$filetype = $finf_extra = null;
	$fileinfo = get_file_info($file, $filetype, 0, $finf_extra);
	if($filetype && isset($fileinfo[0]))
		$add_fileinfo_db($fileinfo, $filetype);
	
	log_event('Getting file info (2)');
	// detect bad file extensions for media types
	$force_ext = null;
	if($filetype == 'mediainfo') {
		switch(get_mi_value('Format', $fileinfo, 'General')) {
			case 'Matroska':
				$force_ext = 'mkv'; break;
			case 'MPEG-4':
				$force_ext = 'mp4'; break;
			case 'MPEG-PS':
				$force_ext = 'vob'; break; // ??
			case 'AVI':
				$force_ext = 'avi'; break;
			case 'Ogg':
				$force_ext = 'ogm'; break;
			case 'WebM':
				$force_ext = 'webm'; break;
			case 'RealMedia':
				$force_ext = 'rm'; break;
			// otherwise, no special handling
		}
	}
	
	$filetype2 = null;
	$fileinfo2 = get_file_info($file, $filetype2, 1, $null, $force_ext);
	if($filetype2 && isset($fileinfo2[0]))
		$add_fileinfo_db($fileinfo2, $filetype2);
	
	if($filetype == 'mediainfo') {
		log_event('Dumping ffprobe/mediainfo-json');
		if($ffprobe_info = get_ffprobe($file, '-show_programs -show_streams -show_format -show_chapters'))
			$add_fileinfo_db($ffprobe_info, 'ffprobe');
		
		// dump mediainfo-json
		if(strpos($file, '?') !== false || strpos($file, '*') !== false) {
			$midest = dirname($file).'/mediainfo_tmp'.mt_rand();
			if(symlink($file, $midest)) {
				$rtn_val = timeout_exec('nice -n5 mediainfo --Output=JSON '.escapeshellfile($midest), 90, $mij, ['retries' => 1]);
				unlink($midest);
			} else {
				$rtn_val = 1;
			}
		} else
			$rtn_val = timeout_exec('nice -n5 mediainfo --Output=JSON '.escapeshellfile($file), 90, $mij, ['retries' => 1]);
		if(!$rtn_val) {
			$midata = json_decode($mij);
			
			if(empty($midata) || empty($midata->media->{'@ref'})) {
				$mij = trim($mij);
				warning('mediainfo-json could not get JSON; file: '.$file.log_dump_data($mij, 'finfo_mediainfo_json'), 'finfo');
			} else {
				$midata->media->{'@ref'} = basename($file);
				// TODO: detect bad file?
				$add_fileinfo_db($midata, 'mediainfoj');
			}
		} else {
			$mij = trim($mij);
			warning('mediainfo-json failed; file: '.$file.', return code: '.$rtn_val.log_dump_data($mij, 'finfo_mediainfo_json'), 'finfo');
		}
	}
	
	if(!empty($fileinfo_db)) {
		$GLOBALS['db']->insertMulti('fileinfo', $fileinfo_db);
		unset($fileinfo_db);
	}
	
	log_event('Getting CRC32k');
	
	// CRC32 of first 1KB
	if($fp = fopen($file, 'rb')) {
		$ret['crc32k'] = pack('N', crc32(fread($fp, 1024)));
		fclose($fp);
	}
	
	unset($dur);
	$ssInfo_for_subs = [];
	
	// screenshot if necessary
	if($filetype == 'mediainfo' && $finf_extra /*is_video*/) {
		log_event('Getting screenshots');
		
		if(!$tmpsdir) $tmpsdir = make_temp_dir(); // create temp folder
		$ssret = fileinfo_run_screenshots($tmpsdir, $fid, $file, null, $ssInfo_for_subs);
		foreach($ssret as $k => $v)
			$ret[$k] = $v;
		
		// only bother extracting audio if it exists, and a video track is present (to make it worthwhile)
		// mkvmerge doesn't support WMV [https://gitlab.com/mbunkus/mkvtoolnix/-/issues/1127]
		if(get_mi_value('ID', $fileinfo, 'Video') && get_mi_value('ID', $fileinfo, 'Audio(?: #1)?') && get_mi_value('Format', $fileinfo, 'General') != 'Windows Media') {
			log_event('Dumping audio');
			// TODO: if PCM audio, consider encoding to FLAC (zlib compression has minimal effect)
			// TODO: estimate stream size from mediainfo and skip if > 2GB
			$finfodir = make_finfo_dir($fid);
			
			$mkatarget = $finfodir.preg_replace('~\.[^.]+$~', '', mb_basename($file)).'.mka';
			$sem = sema_lock('fileproc', true);
			$rtn_val = timeout_exec('nice -n10 mkvmerge -o '.escapeshellfile($mkatarget).' --no-cues --no-date --no-video --no-subtitles --no-buttons --no-attachments --no-track-tags --no-global-tags --no-chapters '.escapeshellfile($file), 900, $mkaoutput);
			sema_unlock($sem);
			if(!$rtn_val) {
				$mkasize = @filesize($mkatarget);
				if($mkasize < 1) {
					warning('[finfo-mkaextract] mkvmerge returned empty file for '.$file.log_dump_data($mkaoutput, 'mkaextract'), 'finfo');
					@unlink($mkatarget);
					@rmdir($finfodir);
				} elseif($mkasize >= 2048*1048576) {
					info('Audio extract for '.$file.' exceeds 2GB (size='.$mkasize.')', 'finfo');
					@unlink($mkatarget);
					@rmdir($finfodir);
				} else
					delay_finfo($fid, 'mkaextract');
			} else {
				@rmdir($finfodir); // make this fail if file is partially written
				warning('[finfo-mkaextract] mkvmerge '.$rtn_val.' for '.$file.log_dump_data($mkaoutput, 'mkaextract'), 'finfo');
			}
		}
	}
	elseif($filetype == '7z') { // try to grab images from archive
		log_event('Checking archive for images');
		
		// create temp folder
		if(!$tmpsdir) $tmpsdir = make_temp_dir();
		
		$arcfiles =& $finf_extra;
		
		// go thru files and find all images
		$arcimgs = array();
		$num_arcimgs = 0;
		if(!empty($arcfiles)) foreach($arcfiles as $arcfile => $arcfilesize) {
			$arcfilesize = (float)$arcfilesize;
			if($arcfilesize > 48 && $arcfilesize < 20*1024*1024) {
				$arcext = get_extension($arcfile);
				if($arcext != 'bmp' && $arcfilesize > 4*1024*1024) continue;
				// we allow BMPs up to 20MB, but anything else up to 4MB
				switch($arcext) {
					case 'bmp': case 'png':
					case 'jpg': case 'jpeg': case 'jpe':
					case 'gif': case 'tif': case 'tiff':
						$arcimgs[] = $arcfile;
						++$num_arcimgs;
				}
			}
		}
		unset($arcfiles, $finf_extra);
		if(!empty($arcimgs)) {
			// only select a few images
			if($num_arcimgs > 5) {
				$arcimgs = array( // we select the first two, the last, and two from the middle
					$arcimgs[0],
					$arcimgs[1],
					$arcimgs[ floor(($num_arcimgs-4)*1/3) + 2 ],
					$arcimgs[  ceil(($num_arcimgs-4)*2/3) + 2 ],
					$arcimgs[$num_arcimgs-1]
				);
			}
			
			// extract images from archive
			$process_imgs = $imgthumbs = array();
			$rtn_val = timeout_exec('nice -n10 7z x -p '.escapeshellarg('-o'.$tmpsdir).' '.escapeshellfile($file).' '.implode(' ', array_map('escapeshellfile', $arcimgs)), 450);
			if(!$rtn_val) {
				// check if files exist
				foreach($arcimgs as &$arcimg) {
					if(file_exists($tmpsdir.$arcimg) && filesize($tmpsdir.$arcimg)) {
						@chmod($tmpsdir.$arcimg, 0666);
						
						$imginfo = get_ffprobe($tmpsdir.$arcimg);
						if(!in_array(@$imginfo->format->format_name, ['image2','webp_pipe','png_pipe','jpg_pipe','jpeg_pipe','gif','jpg','jpeg','png','webp','bmp_pipe','bmp','tiff','tiff_pipe'])) {
							warning('[finfo-archive-img] File '.$arcimg.' (fid='.$fid.') isn\'t an image (or ffprobe failed); format='.@$imginfo->format->format_name);
							continue;
						}
						if(@count($imginfo->streams) != 1) {
							warning('[finfo-archive-img] File '.$arcimg.' has != 1 stream');
							continue;
						}
						if(isset($imginfo->streams[0]->width) && isset($imginfo->streams[0]->height)) {
							$imgthumbs[$arcimg] = calc_thumb_dims(
								(int)$imginfo->streams[0]->width, (int)$imginfo->streams[0]->height
							);
							$process_imgs[$arcimg] = $tmpsdir.$arcimg;
						} else
							warning('[finfo-archive-img] FFprobe failed to return image dimensions of '.$arcimg);
						unset($imginfo);
					} else {
						warning('[finfo-archive-img] File '.$arcimg.' doesn\'t exist!');
					}
				}
				
				// process images
				$storess = $GLOBALS['store_sshot_array'];
				$screenurls = process_images($fid, 'includes/proc_img.sh', $process_imgs, $imgthumbs, '.jpg', $tmpsdir, $storess);
				if(!empty($screenurls))
					$ret['filethumbs'] = serialize($screenurls);
				if(!empty($storess))
					$ret['filestore'] = serialize($storess);
			} else
				warning('[finfo-archive-img] 7z returned '.$rtn_val.' for '.$file);
			
			// kill everything in this folder to remove directories created by 7z
			exec('rm -rf '.escapeshellfile($tmpsdir).'*');
		}
	}
	
	$attachment_store = [null, null, null, null];
	// try grabbing subtitles
	$mkvinfo = null;
	if($filetype2 == 'mkvinfo') {
		log_event('Running MKV identify');
		
		// TODO: some files have massive ASS files, which seem to be returned by mkv identify?  consider filtering out "codec_private_data" lines
		// - problematic example fids: 601427, 601436, 853889
		$rtn_val = timeout_exec('nice -n5 mkvmerge --identify --identification-format json '.escapeshellfile($file), 300, $mkvinfo, ['retries' => 1]);
		if($rtn_val == 0 || $rtn_val == 1) {
			$mkvinfo = @json_decode($mkvinfo);
		}
		if($rtn_val) {
			warning('[finfo-mkvidentify] mkvmerge returned '.$rtn_val.' for file '.$file.log_dump_data($mkvinfo, 'mkvidentify'));
		}
	}
	if(!empty($mkvinfo) && !empty($mkvinfo->tracks)) {
		log_event('Grabbing subtitles');
		
		$subtracks_all = array();
		$attachments = array();
		// parse info
		foreach($mkvinfo->tracks as $track) {
			if($track->type == 'subtitles' && !empty($track->properties)) {
				$track_prop =& $track->properties;
				switch(@$track_prop->codec_id) {
					case 'S_TEXT/UTF8':
					case 'S_TEXT/ASS':
					case 'S_TEXT/SSA':
					case 'S_TEXT/WEBVTT':
					case 'S_VOBSUB':
					case 'S_HDMV/PGS':
					case 'D_WEBVTT/SUBTITLES': // alternative flavour: https://trac.ffmpeg.org/ticket/5641
						$codec = preg_replace('~^S_(TEXT|HDMV)/~', '', $track_prop->codec_id);
						if($codec == 'S_VOBSUB') $codec = 'VOB';
						if($codec == 'UTF8') $codec = 'SRT';
						if($codec == 'WEBVTT' || $codec == 'D_WEBVTT/SUBTITLES') $codec = 'VTT';
						$subtracks_all[$track->id] = [
							'number' => @$track_prop->number,
							'codec' => $codec,
						];
						if(isset($track_prop->language))
							$subtracks_all[$track->id]['lang'] = str_replace('/', '', $track_prop->language);
						if(isset($track_prop->track_name))
							$subtracks_all[$track->id]['name'] = $track_prop->track_name;
						foreach(['default_track' => 'default', 'enabled_track' => 'enabled', 'forced_track' => 'forced'] as $k => $v) {
							if(isset($track_prop->$k))
								$subtracks_all[$track->id][$v] = $track_prop->$k ? 1:0; // int takes less space than bool in JSON
						}
						break;
					default:
						warning('[sub-extract] Unknown codec '.(@$track->properties->codec_id).' for '.$file);
				}
			}
		}
		if(!empty($mkvinfo->attachments)) foreach($mkvinfo->attachments as $attachment) {
			if(isset($attachment->file_name) && !isset($attachment->name))
				$attachment->name = $attachment->file_name;
			if(empty($attachment->name)) continue;
			$attach_name = preg_replace("~[/\\x00-\\x1f]~", '', $attachment->name);
			$store_data = ['mime' => @$attachment->content_type, 'name' => $attach_name];
			if(mime_from_filename($attach_name) == $store_data['mime'])
				unset($store_data['mime']); // MIME can be inferred from name
			elseif($mime_packed = mime_pack($store_data['mime'])) {
				unset($store_data['mime']);
				$store_data['m'] = $mime_packed;
			}
			if(!empty($attachment->description))
				$store_data['desc'] = $attachment->description;
			$attachments[$attachment->id] = [
				'name' => $attach_name,
				'info' => $store_data
			];
		}
		
		if(!empty($subtracks_all) || !empty($attachments)) {
			// create temp folder
			if(!$tmpsdir) $tmpsdir = make_temp_dir();
			
			// extract attachments
			$cmd = '';
			foreach($attachments as $aid=>&$ainfo) {
				$ainfo['fn'] = $tmpsdir.'attachments/'.$ainfo['name'];
				$cmd .= ' '.escapeshellarg($aid.':'.$ainfo['fn']);
			} unset($ainfo);
			if($cmd) {
				log_event('Dumping attachments');
				@mkdir($tmpsdir.'attachments/');
				$rtn_val = timeout_exec('nice -n10 mkvextract attachments '.escapeshellfile($file).' -q'.$cmd, 420, $mkvxData, ['retries' => 1]);
				if($rtn_val) warning('[finfo-attachextract] mkvextract returned '.$rtn_val.' for file '.$file.log_dump_data($mkvxData, 'mkvextract'));
				if($rtn_val == 0 || $rtn_val == 1) {
					log_event('Packing attachments');
					$atstore_other = [];
					foreach($attachments as $ainfo) {
						if(file_exists($ainfo['fn'])) {
							$atstore_other[] = store_attachment($ainfo['fn'], $fid, ATTACHMENT_OTHER, $ainfo['info']);
						} else
							warning('[finfo-attachextract] Extracted file '.$ainfo['fn'].' is missing, for file '.$file);
					}
					$attachment_store[ATTACHMENT_OTHER] = $atstore_other;
				} else {
					foreach($attachments as $ainfo) {
						@unlink($ainfo['fn']);
					}
				}
			}
			
			// extract subs
			$cmd = '';
			foreach($subtracks_all as $trackid=>&$subtrack) {
				// determine filename
				$fn = 'track'.$subtrack['number'].(@$subtrack['lang'] ? '.'.$subtrack['lang'] : '').'.';
				$subtrack['fnbase'] = $tmpsdir.$fn;
				switch($subtrack['codec']) {
					case 'VOB':
						$fn .= 'idx'; break; // doesn't actually matter because mkvextract will choose the correct filename in this case
					case 'PGS':
						$fn .= 'sup'; break;
					default:
						$fn .= strtolower($subtrack['codec']);
				}
				$cmd .= ' '.escapeshellarg($trackid.':'.$tmpsdir.$fn);
				$subtrack['fn'] = $tmpsdir.$fn;
			} unset($subtrack);
			if($cmd) {
				log_event('Dumping subtitles');
				$rtn_val = timeout_exec('nice -n10 mkvextract tracks '.escapeshellfile($file).' -q'.$cmd, 3600, $mkvxData, ['retries' => 1]);
				if($rtn_val) warning('[finfo-subextract] mkvextract returned '.$rtn_val.' for file '.$file.log_dump_data($mkvxData, 'mkvextract'), 'finfo');
				if($rtn_val == 0 || $rtn_val == 1) {
					log_event('Packing/rendering subtitles');
					$atstore_sub = [];
					foreach($subtracks_all as $trackid=>$subtrack) {
						$subinfo = $subtrack;
						unset($subinfo['fn'], $subinfo['fnbase'], $subinfo['number']);
						$subinfo['trackid'] = $trackid; // note that older stored attachments may not have this key
						$subinfo['tracknum'] = $subtrack['number'];
						$subfiles = glob($subtrack['fnbase'].'*');
						foreach($subfiles as $subfile) { // to handle VOB subs (also conveniently checks existence for us)
							if($subtrack['codec'] == 'VOB') {
								if(strtolower(substr($subfile, -4)) == '.idx')
									$subinfo['vobidx'] = 1;
								else
									unset($subinfo['vobidx']);
							}
							$atstore_sub[] = store_attachment($subfile, $fid, ATTACHMENT_SUBTITLE, $subinfo);
						}
						
						if(!empty($ssInfo_for_subs)) {
							// for VOB subtitles, select .idx
							if(count($subfiles) > 1) {
								if($subtrack['codec'] == 'VOB') {
									$subfile = $subtrack['fnbase'].'idx';
									if(!file_exists($subfile)) {
										warning('Missing index file for VOB subtitle: '.array_map('basename', $subfiles).' (fid: '.$fid.', trackinfo: '.json_encode($subinfo).')');
										continue;
									} elseif(count($subfiles) != 2)
										warning('Unexpected additional files for VOB subtitle: '.array_map('basename', $subfiles).' (fid: '.$fid.', trackinfo: '.json_encode($subinfo).')');
								} else {
									warning('Cannot decide which subtitle file is valid: '.array_map('basename', $subfiles).' (fid: '.$fid.', trackinfo: '.json_encode($subinfo).')');
									continue;
								}
							}
							dump_subtitle_images($ssInfo_for_subs['width'], $ssInfo_for_subs['height'], $subtrack['codec'], $subfile, $tmpsdir.'attachments/', $ssInfo_for_subs['ts'], $ssInfo_for_subs['basename'].'_'.$subtrack['number']);
						}
					}
					$attachment_store[ATTACHMENT_SUBTITLE] = $atstore_sub;
				} else {
					// clean up
					foreach($subtracks_all as $trackid=>$subtrack) {
						foreach(glob($subtrack['fnbase'].'*') as $subfile) {
							@unlink($subfile);
						}
					}
				}
			}
			
			
			// extract other stuff
			log_event('Dumping tags/chapters');
			foreach(array('tags'=>ATTACHMENT_TAGS, 'chapters'=>ATTACHMENT_CHAPTERS) as $meta=>$mtype) {
				timeout_exec('nice -n10 mkvextract '.$meta.' -q '.escapeshellfile($file).' >'.escapeshellfile($tmpsdir.$meta.'.xml'), 400, $null, ['retries' => 1]);
				if(!@filesize($tmpsdir.$meta.'.xml')) @unlink($tmpsdir.$meta.'.xml');
				else $attachment_store[$mtype] = store_attachment($tmpsdir.$meta.'.xml', $fid, $mtype);
			}
			
			if(count(glob($tmpsdir.'*')) > 0) {
				// clean up
				exec('rm -rf '.escapeshellfile($tmpsdir).'*');
			} else {
				warning('[attach-extract] No files extracted for '.$file);
			}
		}
	}
	
	if($filetype2 == 'mp4boxinfo') {
		// check for subtitles & extract
		// MP4Box -quiet -noprog -raw [trackID]:output=[filename]
		// ...or maybe ... -ttxt [trackID]  (converts to XML - more readable, but maybe not the same?)
		//   -dump-cover, -dump-chap, -dump-xml <filename>[:tk=ID], -dump-item <filename>[:td=ID][:filename=FN]
		// info: https://github.com/gpac/gpac/wiki/Subtitling-with-GPAC
		
		// parse info
		preg_match_all("~(?<=\n\n)Track # \d+ Info - TrackID (\d+) [^\n]+(.*?)\n(?=\n)~s", str_replace("\r", '', $fileinfo2), $tracks,  PREG_SET_ORDER); // not completely accurrate, but should be good enough for us
		$extract_cmd = [];
		$trackInfo = [];
		foreach($tracks as $track) {
			if(!preg_match("~(?<=\n)Media Info: Language \"[^\"]+ \(([a-z]{2,5})\)\" - Type \"(subtl|text)\:([a-z0-9]{3,4})\" ~", $track[2], $trackCodec)) continue;
			
			// this is a subtitle track
			if($trackCodec[3] == 'tx3g')
				$extract_cmd[] = '-ttxt '.$track[1];
			else
				$extract_cmd[] = '-raw '.$track[1];
			// TODO: how to obtain track name?  seems available in udta
			$trackInfo[$track[1]] = ['lang' => $trackCodec[1], 'handler' => $trackCodec[2], 'codec' => $trackCodec[3]];
		}
		
		// create temp dir
		if(!empty($extract_cmd)) {
			
			// TODO: loop through all subtitle tracks & extract & store
		}
		// TODO: dump cover/chapters & store
		// TODO: clean up
	}
	if(isset($dur)) {
		$ret['video_duration'] = $dur;
	}
	
	// write attachments to table
	while(end($attachment_store) === null)
		array_pop($attachment_store);
	if(!empty($attachment_store)) {
		$attachdata = FileInfoCompressor::compress_pack('attach', $attachment_store);
		$GLOBALS['db']->insert('attachments', ['fid' => $fid, 'attachments' => $attachdata]);
	}
	
	if($tmpsdir) {
		@rmdir($tmpsdir);
	}
	log_event('File info done');
	return $ret;
}

function fileinfo_run_screenshots($tmpsdir, $fid, $file, $info=null, &$info_for_subs=null) {
	if(!$info) $info = get_ffprobe($file);
	if(!$info) return array();
	
	$dur = (float)@$info->format->duration;
	$w = $h = 0;
	$dims = get_ffprobe_dims($info);
	if(!empty($dims)) {
		foreach(['ow','oh','w','h'] as $k)
			$$k = $dims[$k];
	}
	
	$ret = array();
	if($dur >= 5 && $w && $h) {
		
		// determine where to scrnshot
		if($dur < 90) {
			// small file - single screenshot in middle
			$ss = array(max((int)($dur/2) - 15, 2));
		} else {
			// start from 15s, leave 15s at end (-1 extra for rounding); segments must be at least 1 min apart; max 5 screenshots
			// update: because the duration can be wrong (too large), we'll pad 45s before the end
			$seg = max(floor(($dur-61) / 4), 60);
			$ss = array();
			for($i=15; $i<$dur; $i+=$seg) {
				$ss[] = $i;
			}
		}
		
		$ss2 = $ss; // copy for later
		
		// IFrame storage
		$ssfbase = make_id_dirs($fid, TOTO_STORAGE_PATH.'sframes/');
		$ssfbase = TOTO_STORAGE_PATH.'sframes/'.$ssfbase[0].$ssfbase[1];
		$ifstore = array(); $ifReqStamp = array();
		$ffopts = '-map_chapters -1 -map_metadata -1 -an -sn -dn -vcodec copy -frames 1 -f matroska';
		foreach($ss2 as $k => $sstime) {
			$ssffile = tempnam(TEMP_DIR, 'fiframe'); // NOTE: creates actual file, so -y flag necessary below
			// TODO: is there any metadata we wish to keep? (exotic rotate or similar?)
			$rc = timeout_exec('nice -n10 ffmpeg -y -fflags +genpts+noparse -noaccurate_seek -ss '.$sstime.' -i '.escapeshellfile($file).' '.$ffopts.' '.escapeshellfile($ssffile), 240, $null, ['stderr' => &$ffmpegOut]);
			if(file_exists($ssffile) && !$rc && @$info->format->format_name == 'mpegts' && @filesize($ssffile) < 1000) {
				// workaround for TS files which don't like the +noparse option
				info('Detected TS file with possibly empty screenshot for '.$fid.'; target: '.$sstime.' - retrying without +noparse', 'finfo');
				$rc = timeout_exec('nice -n10 ffmpeg -y -fflags +genpts -noaccurate_seek -ss '.$sstime.' -i '.escapeshellfile($file).' '.$ffopts.' '.escapeshellfile($ssffile), 240, $null, ['stderr' => &$ffmpegOut]);
			}
			if(!file_exists($ssffile) || !@filesize($ssffile)) {
				warning('Iframe extraction failed at '.$sstime.' for file '.$fid.log_dump_data($ffmpegOut, 'finfo'), 'finfo_ffmpeg');
			} elseif($rc) {
				if(preg_match("~\nCould not write header for output file #\d \(incorrect codec parameters \?\): Invalid data found when processing input\s~", $ffmpegOut)) {
					// sample: fid=926837 with ffmpeg 4.4 - ffmpeg refuses to copy the video (though re-encoding works); 4.1 copies, but gives an invalid MKV output
					$errx = '; ffmpeg could not write a valid header for output';
					
					$fs = @filesize($ssffile);
					if($fs == 0 || ($fs > 285 && $fs < 305)) // empty output
						@unlink($ssffile);
					else
						$errx = ' (output: '.$ssffile.')'.$errx;
				} else
					$errx = ' (output: '.$ssffile.')'.log_dump_data($ffmpegOut, 'finfo_ffmpeg');
				warning('ffmpeg error with iframe extraction at '.$sstime.' for file '.$fid.$errx, 'finfo');
			} else {
				// check if file has any frames - ffmpeg sometimes writes an empty MKV
				$checkProbe = get_ffprobe($ssffile, '-show_frames', true);
				if(empty($checkProbe->frames)) {
					warning('Iframe extraction failed at '.$sstime.' for file '.$fid.' - empty file written'.log_dump_data($ffmpegOut, 'finfo'), 'finfo_ffmpeg');
					@unlink($ssffile);
				}
				else {
					$ts = 0;
					if(preg_match('~ time=(-?)(\d\d)\:(\d\d)\:(\d\d\.\d\d) ~', $ffmpegOut, $m)) {
						// new format
						$tsOffs = 0;
						$tsOffs += (int)$m[2] * 3600;
						$tsOffs += (int)$m[3] * 60;
						$tsOffs += (float)$m[4];
						if($m[1]) $tsOffs = -$tsOffs;
						if(abs($tsOffs) > 40) // have seen this happen, e.g. fid=926876 requested 2955, but got offset -58.79 (probably due to ending credits and long GOP?)
							info('Large time offset when dumping frame from '.$fid.'; target: '.$sstime.', offset: '.$tsOffs, 'finfo');
						$ts = $sstime + $tsOffs;
					}
					elseif(preg_match('~ time=(-?\d+)\:(-?\d+)\:(-?\d+)\.(-?\d+) ~', $ffmpegOut, $m)) {
						// old format
						$ts += (int)$m[1] * 3600;
						$ts += (int)$m[2] * 60;
						$ts += (int)$m[3];
						$ts += (int)$m[4] / 100;
						if(abs($ts) > 40)
							info('Large time offset when dumping frame from '.$fid.'; target: '.$sstime.', offset: '.$ts, 'finfo');
						$ts += $sstime;
					} else {
						if(strpos($ffmpegOut, ' time=N/A '))
							info('ffmpeg not showing timestamp when dumping frame at '.$sstime.' for file '.$fid);
						else
							warning('Could not parse timestamp from ffmpeg output for frame at '.$sstime.' for file '.$fid.log_dump_data($ffmpegOut, 'finfo_ffmpeg'), 'finfo');
						$ts = $sstime;
					}
					if($ts < 0) $ts = 0;
					$ts = (int)($ts * 1000); // store stamps in miliseconds
					if(isset($ifstore[$ts])) {
						// TODO: have seen this happen, example fid=909755 at ts=1419420
						warning('Got duplicate dump frame at '.$ts.' for file '.$fid.'; requested: '.$sstime, 'finfo');
						unlink($ssffile);
					} else {
						$ifstore[$ts] = $ts;
						$ifReqStamp[] = $sstime;
						rename($ssffile, $ssfbase.'_'.$ts.'.mkv');
						@chmod($ssfbase.'_'.$ts.'.mkv', 0666);
					}
				}
			}
		}
		if(count($ifstore) == 1 && count($ss2) > 2) {
			// seems like all screenshots landed on the same frame - ffmpeg issue
			warning('All dumped frames occurred at same location for '.$fid.'; requested: '.implode(',', $ss2).', got: '.implode(',', $ifstore), 'finfo');
			foreach($ifstore as $ts) {
				unlink($ssfbase.'_'.$ts.'.mkv');
			}
			$ifstore = array();
			
		}
		// TODO: consider joining all frames into a single MKV??
		$ret['vidframes'] = implode(',', $ifstore);
		
		// grab timestamps via ffprobe (more accurate than what's given by mplayer)
		if(!empty($info->streams)/*should never be false*/ && isset($dims['id'])) {
			// weird ffprobe bugs we mitigate:
			// - request 0th frame seems to improve reliability
			// - requesting each frame at a time seems to be more reliable than asking all at once
			$frame_info = array();
			foreach($ifReqStamp as $ifReq) {
				$probe_info = get_ffprobe($file, '-show_frames -fflags genpts -select_streams '.$dims['id'].' -read_intervals 0%+#1,'.$ifReq.'%+#1');
				if(!empty($probe_info->frames)) {
					// shift out the dummy frame we inserted
					array_shift($probe_info->frames);
					if(count($probe_info->frames) != 1) {
						// bad...
						if(empty($probe_info->frames)) {
							// it seems like some versions of ffprobe don't properly support the ',' operator in `-read_intervals`, so try with just one frame instead
							$probe_info = get_ffprobe($file, '-show_frames -fflags genpts -select_streams '.$dims['id'].' -read_intervals '.$ifReq.'%+#1');
							if(!empty($probe_info->frames) && count($probe_info->frames) == 1) {
								// check if the frame looks sane
								$frame = $probe_info->frames[0];
								if(!isset($frame->best_effort_timestamp_time)) {
									$probe_info->frames = null;
								} // otherwise, probably got a good frame
							} else {
								$probe_info->frames = null;
							}
						}
						if(empty($probe_info->frames) || count($probe_info->frames) != 1) {
							warning('ffprobe did not return requested frame for '.$file.' at '.$ifReq, 'finfo');
							$frame_info[] = null;
							continue;
						}
					}
					foreach($probe_info->frames as $frame) {
						if(@$frame->media_type != 'video' || (!@$frame->key_frame && @$frame->pict_type != 'I' && !@$frame->interlaced_frame)) { // have seen non-key I-frames dumped, unsure if correct...
							// something's odd
							// TODO: happened on fid=908284 and fid=919917 with every dumped frame
							warning('Got non-keyframe from ffprobe in '.$file.' [frame]: '.json_encode($frame), 'finfo');
						}
						if(!isset($frame->best_effort_timestamp_time)) {
							warning('ffprobe did not return best_effort_timestamp_time for '.$file.' at frame '.$ifReq, 'finfo');
						}
						$frame_inf = [];
						if(isset($frame->pkt_pos))
							$frame_inf['p'] = (int)$frame->pkt_pos;
						if(isset($frame->pkt_size))
							$frame_inf['s'] = (int)$frame->pkt_size;
						if(isset($frame->best_effort_timestamp_time)) {
							if(preg_match('~^\d+\.\d{6}$~', $frame->best_effort_timestamp_time))
								$frame_inf['t'] = (int)str_replace('.', '', $frame->best_effort_timestamp_time);
							else
								$frame_inf['t'] = (int)((double)$frame->best_effort_timestamp_time * 1000000);
						}
						$frame_info[] = $frame_inf;
					}
				}
			}
			
			// count($frame_info) == count($ifstore)   should be true here
			// use more accurate timestamps for rendering subs
			if(!empty($frame_info)) {
				$i = 0;
				foreach($ifstore as &$e) {
					$fi = @$frame_info[$i++];
					if(isset($fi['t']))
						$e = round($fi['t']/1000);
				} unset($e);
			}
			$GLOBALS['db']->upsert('files_extra', ['fid' => $fid], ['vidframes_info' => FileInfoCompressor::mpack($frame_info)]);
		}
		
		if(isset($info_for_subs) && !empty($ifstore)) {
			$info_for_subs = ['width' => $w, 'height' => $h, 'owidth' => $ow, 'oheight' => $oh, 'ts' => $ifstore, 'basename' => $ssfbase];
		}
	}
	return $ret;
}

function make_finfo_dir($fid) {
	$finfodir = TOTO_ULQUEUE_PATH.'fileinfo_'.$fid.'/';
	@mkdir($finfodir);
	return $finfodir;
}
function delay_finfo($fid, $type, $data=null) {
	$GLOBALS['db']->insert('fiqueue', array(
		'fid' => $fid,
		'type' => $type,
		'dateline' => time(),
		'data' => json_encode($data)
	));
}

// store files to static file storage
function make_id_dirs($id, $path) {
	$hash = id2hash($id);
	$storefile = substr($hash, 0, 3).'/';
	@mkdir($path.$storefile);
	@chmod($path.$storefile, 0777);
	$storefile .= substr($hash, 3, 3).'/';
	@mkdir($path.$storefile);
	@chmod($path.$storefile, 0777);
	return [$storefile, substr($hash, 6)];
}
// NOTE: this function MOVEs files (as opposed to copy, like store_attachment does)
function store_sshot($file, $fid, $name, $tmpdir='') {
	$path = TOTO_STORAGE_PATH.'sshots/';
	list($storefile, $fn) = make_id_dirs($fid, $path);
	if(!$tmpdir) $tmpdir = $path.$storefile; // this is the directory that the ZIP will be placed in
	$storefile .= $fn;
	
	// TODO: ideally ZIP all these at once; stuffing 1 by 1 is a little inefficient
	rename($file, $tmpdir.$name);
	$zipfile = $path.$storefile.'.zip';
	$z7out = '';
	chdir($path); // 7z fails if it can't write a .tmp file to the current location (only when updating archives) - fix it by switching to a writable location
	if(timeout_exec('nice -n5 7z a -tzip -mm=copy '.escapeshellarg($zipfile).' '.escapeshellfile($tmpdir.$name), 300, $z7out) || !file_exists($zipfile)) {
		@chdir(ROOT_DIR);
		warning('[finfo-sshot] Failed to add sshot to zip '.$zipfile.' for file '.$tmpdir.$name.log_dump_data($z7out, 'finfo-sshot-7z'));
		return false;
	}
	@chdir(ROOT_DIR);
	@chmod($zipfile, 0666);
	unlink($tmpdir.$name);
	$storefile .= '_'.$name;
	return $storefile;
}
function store_attachment($file, $fid, $type, $info=null) {
	$fs = filesize($file);
	if($fs > 160*1048576) { // TODO: lower this overly generous limit?
		$is_pgs = ($type == ATTACHMENT_SUBTITLE && strtolower(substr($file, -4)) == '.sup');
		if(!$is_pgs || $fs > 512*1048576) { // allow PGS subs up to 512MB
			if($is_pgs) {
				// okay, we know that some large .sup files exist
				info('File '.$file.' (fid:'.$fid.', type:'.$type.') too large ('.round($fs/1048576,1).'MB) - not storing', 'finfo-storage');
			} else
				warning('File '.$file.' (fid:'.$fid.', type:'.$type.') too large ('.round($fs/1048576,1).'MB) - not storing');
			return false;
		}
	}
	
	$dest = tempnam(TEMP_DIR, 'fisattach');
	
	// scale dictionary depending on file size (dunno why this isn't done automatically)
	$dict = $fs + 16384;
	$dict = max($dict, 65536);
	$dict = min($dict, 8*1048576);
	$rtn_val = timeout_exec('nice -n10 '.escapeshellarg(ROOT_DIR.'includes/xzattach.sh').' '.escapeshellfile($file).' '.escapeshellfile($dest).' '.escapeshellarg('--lzma2=preset=6e,dict='.$dict), 5400, $output);
	
	if($rtn_val) {
		error('[store-attachment] Xz process returned '.$rtn_val.' for file '.$file);
		@unlink($dest);
		return false;
	}
	
	if(!file_exists($dest)) { // should never happen, since tempnam always creates a file anyway
		error('[store-attachment] Output file not found, for file '.$file);
		return false;
	}
	// parse hash
	if(preg_match('~(?:SHA1)?\(stdin\)\= ([a-f0-9]{40})~', $output, $m)) {
		$hash = $m[1];
		$test = str_replace($m[0], '', $output);
		if(trim($test)) {
			warning('[store-attachment] Additional output from hash process'.log_dump_data($output, 'finfo_store_hash'));
		}
	} else {
		// !! hash failed ?!
		warning('[store-attachment] Failed to grab hashes for file '.$file.log_dump_data($output, 'finfo_store_hash'));
		// fallback
		$hash = hash_file('sha1', $file);
	}
	
	// check for duplication
	global $db;
	$hash_bin = hex2bin($hash);
	$fileid = $db->selectGetField('attachment_files', 'id', 'hash='.$db->escape($hash_bin));
	if(!$fileid) {
		if(!$db->insert('attachment_files', array(
			'hash' => $hash_bin,
			'filesize' => $fs,
			'packedsize' => filesize($dest)
		), false, 1)) {
			// race encountered!
			$fileid = $db->selectGetField('attachment_files', 'id', 'hash=X\''.$hash.'\'');
			if(!$fileid) {
				// wtf??
				error('Failed to retrieve file ID for attachment hash: '.$hash);
			} else {
				unlink($dest);
			}
		} else {
			$fileid = $db->insertId();
			$storehash = id2hash($fileid);
			
			// move file to appropriate location based on hash
			$storefile = TOTO_STORAGE_PATH.'attachments/'.substr($storehash, 0, 5).'/';
			@mkdir($storefile);
			@chmod($storefile, 0777);
			$storefile .= substr($storehash, 5).'.xz';
			rename($dest, $storefile);
			@chmod($storefile, 0666);
		}
	} else {
		unlink($dest);
	}
	
	// for insertion into table
	$fileid = (int)$fileid;
	if(isset($info)) {
		$info['_afid'] = $fileid;
		return $info;
	} else
		return $fileid;
}

function dump_subtitle_get_vsscript($width, $height, $subtype, $subfile, $fontdir, $ts, $output, $charset='') {
	$length = end($ts) + 1000; // random padding that doesn't have any particular meaning
	$script = <<<EOF
import vapoursynth as vs
import operator
try:
	core = vs.get_core()
except:
	core = vs.core
b = core.std.BlankClip(width=$width, height=$height, format=vs.RGB24, length=$length, fpsnum=1000, fpsden=1)

EOF;
	if($subtype == 'VOB' || $subtype == 'PGS') {
		$script .= 'rgb = core.sub.ImageFile(b, file="'.addslashes($subfile).'", blend=False)';
		$script .= "\n";
		$script .= 'alpha = core.std.PropToClip(rgb)';
	} else {
		$script .= 'rgbAlpha = core.sub.TextFile(b, file="'.addslashes($subfile).'", '.($fontdir ? 'fontdir="'.addslashes($fontdir).'", ' : '').($charset ? 'charset="'.addslashes($charset).'", ' : '').'blend=False)';
		$script .= "\n";
		// older versions would return a rgb/alpha pair, newer ones return the whole clip
		$script .= <<<EOF
if len(rgbAlpha) == 2:
	[rgb, alpha] = rgbAlpha
else:
	rgb = rgbAlpha
	alpha = core.std.PropToClip(rgb)
EOF;
	}
	
	$script .= "\nif rgb.width != $width or rgb.height != $height:";
	$script .= "\n  rgb = core.resize.Spline36(rgb, width=$width, height=$height)";
	$script .= "\n  alpha = core.resize.Spline36(alpha, width=$width, height=$height)";
	
	$times = json_encode(array_values($ts));
	$script .= <<<EOF

# R38 requires inverting alpha, R52 doesn't, unsure about versions inbetween
if core.version_number() < 52:
	alpha = core.std.Invert(alpha)

from functools import reduce
t=$times
Srgb = reduce(operator.add, map(lambda x: rgb[x], t))
Salpha = reduce(operator.add, map(lambda x: alpha[x], t))

core.imwri.Write(Srgb, "PNG", r"$output", alpha=Salpha, compression_type="None").set_output()

EOF;
	return $script;
}

function dump_subtitle_exec($scriptFile) {
	static $vsOpts = null;
	if(!isset($vsOpts)) {
		$vsOpts = ['env+' => ['PYTHONPATH' => trim(`echo /usr/lib/python3.?/site-packages`)], 'retries' => 1];
	}
	$vsOpts['stderr'] =& $info;
	$rtn = timeout_exec('nice -n10 vspipe '.escapeshellfile($scriptFile).' /dev/null', 480, $junk, $vsOpts);
	unset($vsOpts['stderr']);
	return [
		'code' => $rtn,
		'stderr' => $info
	];
}

// for image subs, $fontdir is ignored; for VOBSUB, send in the .idx file!
function dump_subtitle_images($width, $height, $subtype, $subfile, $fontdir, $ts, $outFnBase) {
	switch($subtype) {
		case 'PGS':
		case 'VOB':
		case 'SSA':
		case 'ASS':
		case 'VTT':
			break; // supported format
		case 'SRT':
			//info('Skipped rendering unsupported SRT '.$subfile.' to '.$outFnBase, 'finfo-subrender');
			return; // not supported
		default:
			warning('[finfo-subrender] Unknown subtitle codec '.$subtype.' for '.$subfile.' (output: '.$outFnBase.')');
			return;
	}
	
	$tmpsdir = make_temp_dir();
	$output = $tmpsdir.'%d.png';
	$script = dump_subtitle_get_vsscript($width, $height, $subtype, $subfile, $fontdir, $ts, $output);
	$charset = '';
	$nullStripped = false;
	
	// write script somewhere
	$scriptFile = $tmpsdir.'run.vpy';
	file_put_contents($scriptFile, $script);
	
	// execute vspipe
	$vsTry = 3;
	while($vsTry--) {
		$vs = dump_subtitle_exec($scriptFile);
		if($vs['code'] && $vs['code'] != 130) { // vspipe returns 130 (128+SIGINT) on success?!
			if(strpos($vs['stderr'], 'Python exception: TextFile: unable to parse input file')) {
				// TODO: consider checking if data is UTF-8?
				if($charset) {
					info('Failed to render ASS: bad text subtitle file for '.$scriptFile.' (output: '.$outFnBase.')', 'finfo-subrender');
					unlink($scriptFile);
					rmdir($tmpsdir);
					return false;
				} else {
					info('Bad text subtitle file for '.$scriptFile.' (output: '.$outFnBase.'), attempting to render as ISO-8859-1', 'finfo-subrender');
					$charset = 'ISO-8859-1';
					$script = dump_subtitle_get_vsscript($width, $height, $subtype, $subfile, $fontdir, $ts, $output, $charset);
					file_put_contents($scriptFile, $script);
					++$vsTry;
					continue;
				}
			} elseif(strpos($vs['stderr'], 'Python exception: ImageFile: no streams found')) {
				// check for mkvextract VOB bug
				if($subtype == 'VOB' && !$nullStripped) {
					$nullStripped = true;
					$vob = file_get_contents($subfile);
					if($p = strpos($vob, "\0")) {
						info('Null detected in VOB file '.$scriptFile.' (output: '.$outFnBase.'), attempting to render with null stripped', 'finfo-subrender');
						$vob = substr($vob, 0, $p) . substr($vob, $p+1);
						file_put_contents($subfile, $vob);
						++$vsTry;
						continue;
					}
				}
				info('Streamless image file for '.$scriptFile.' (output: '.$outFnBase.')', 'finfo-subrender');
				unlink($scriptFile);
				rmdir($tmpsdir);
				return false;
			} elseif(strpos($vs['stderr'], 'Python exception: ImageFile: no usable subtitle pictures found')) {
				info('Empty image file for '.$scriptFile.' (output: '.$outFnBase.')', 'finfo-subrender');
				unlink($scriptFile);
				rmdir($tmpsdir);
				return false;
			} elseif(strpos($vs['stderr'], 'Python exception: ImageFile: avformat_find_stream_info failed')) {
				info('avformat_find_stream_info failed for '.$scriptFile.' (output: '.$outFnBase.')', 'finfo-subrender');
				unlink($scriptFile);
				rmdir($tmpsdir);
				return false;
			} elseif($vs['code'] == 139 /*128+SIGSEGV*/ || strpos($vs['stderr'], 'Segmentation fault') !== false) {
				if($vsTry) {
					info('Segfault for '.$scriptFile.' (output: '.$outFnBase.'), retrying...', 'finfo-subrender');
					exec('/bin/rm -f '.escapeshellarg($tmpsdir).'[0-9]*.png');
					continue;
				} else {
					warning('Segfault for '.$scriptFile.' (output: '.$outFnBase.')'.log_dump_data($vs['stderr'], 'finfo_vspipe'));
					unlink($scriptFile);
					rmdir($tmpsdir);
					return false;
				}
			} else {
				warning('[finfo-subrender] vspipe returned error code '.$vs['code'].' for '.$scriptFile.' (output: '.$outFnBase.')'.log_dump_data($vs['stderr'], 'finfo_vspipe'));
				return false;
			}
		}
		break;
	}
	
	$imgs = glob($tmpsdir.'*.png');
	if(count($imgs) != count($ts)) {
		warning('[finfo-subrender] Expected '.count($ts).' images, but found '.count($imgs).' in '.$tmpsdir.' (output: '.$outFnBase.')');
		return false;
	}
	
	// finalize PNGs
	$c = 0;
	foreach($ts as $t => $_ts) {
		$img = $tmpsdir.($c++).'.png';
		if(!file_exists($img)) {
			warning('[finfo-subrender] Could not find expected image '.$img.' (output: '.$outFnBase.')');
			return false;
		}
		
		// check if all transparent
		$rc = timeout_exec(ROOT_DIR.'3rdparty/is_image_transparent '.escapeshellfile($img), 300, $null, ['stderr' => &$info]);
		if($rc == 0) {
			// completely transparent, just skip this image
			unlink($img);
			continue;
		} elseif($rc != 1) {
			// error
			warning('[finfo-subrender] is_image_transparent returned code '.$rc.' for '.$output.' (error: '.$info.')');
		}
		
		// TODO: consider running this under parallel
		$output = $outFnBase.'_'.$t.'.webp';
		// '-z 9' not available in earlier versions of cwebp, but is equivalent to '-lossless -m 6 -q 100' anyway
		$rc = timeout_exec('nice -n10 cwebp -preset text -lossless -m 5 -q 100 '.escapeshellfile($img).' -o '.escapeshellfile($output), 300, $null, ['retries' => 1, 'stderr' => &$info]);
		if($rc) {
			warning('[finfo-subrender] cwebp returned code '.$rc.' for '.$output.log_dump_data($info, 'cwebp'));
		}
		if(!file_exists($output) || !filesize($output)) {
			warning('[finfo-subrender] cwebp failed to generate '.$output);
			return false;
		}
		
		unlink($img);
	}
	
	unlink($scriptFile);
	rmdir($tmpsdir);
}
