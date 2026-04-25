<?php

require ROOT_DIR.'init.php';

make_lock_file(substr(THIS_SCRIPT, 5, -4));
@ini_set('memory_limit', '384M'); // for large Transmission data

require ROOT_DIR.'anifuncs.php';
require ROOT_DIR.'includes/complete.php';
loadDb();

@set_time_limit(60);
try {
	$transmission = get_transmission_rpc();
	if(!$transmission) return;
	
	// TODO: try to determine finished files and pre-emptively upload those; also, don't forget to take the potential ZIPing into consideration...
	// find completed torrents
	$torrents = $transmission->get(array(), array('id', 'name', 'status', 'doneDate', 'haveValid', 'sizeWhenDone', 'hashString', 'bandwidthPriority', 'files', 'wanted', 'trackerStats'));
} catch(TransmissionRPCException $e) {
	return;
}

if(!empty($torrents->arguments->torrents)) {
	$done = $torrents_a = array();
	$stats = [];
	foreach($torrents->arguments->torrents as $torrent) {
		$hs = pack('H*', $torrent->hashString);
		
		// problem with $torrent->doneDate: if all files got unselected at any point in time, transmission considers the torrent as "completed" and this sticks
		if((isset($torrent->doneDate) || (@$torrent->sizeWhenDone && $torrent->sizeWhenDone === @$torrent->haveValid)) && (@$torrent->status >= TransmissionRPC::TR_STATUS_SEED_WAIT || @$torrent->status == TransmissionRPC::TR_STATUS_STOPPED)) {
			$done[] = $hs;
			$torrents_a[$hs] = $torrent;
		}
		
		// also collect tracker stats
		if(!empty($torrent->trackerStats)) foreach($torrent->trackerStats as $ts) {
			if(empty($ts->hasScraped)) continue;
			$stat_update = [
				'btih' => $hs,
				'tracker' => $ts->announce,
				'last_queried' => $ts->lastScrapeTime ?? 0,
				'updated' => 0
			];
			if(isset($ts->downloadCount) && $ts->downloadCount > -1)
				$stat_update['downloaded'] = $ts->downloadCount;
			if(isset($ts->seederCount) && $ts->seederCount > -1)
				$stat_update['complete'] = $ts->seederCount;
			if(isset($ts->leecherCount) && $ts->leecherCount > -1)
				$stat_update['incomplete'] = $ts->leecherCount;
			// we'll assume this process runs frequently enough such that the last* fields generally can't refer to more than one update since we last checked
			if(!empty($ts->lastScrapeSucceeded)) {
				$stat_update['updated'] = $ts->lastScrapeTime;
				$stat_update['error'] = 0;
			} else {
				$stat_update['error'] = 1;
				// $ts->lastScrapeTimedOut ignored for now
			}
			$stats[] = $stat_update;
		}
	} unset($torrent);
	$lowerPrio = [];
	if(!empty($done)) {
		// find relevant entries in DB
		while(true) {
			isset($qopts) or $qopts = array();
			$qopts['limit'] = 1;
			$done_t = $db->selectGetArray('torrents', 'hashString IN ('.implode(',', array_map(array(&$db, 'escape'), $done)).') AND status=0', 'hashString,folder,toto_id,name,totalsize,dateline', $qopts);
			if(empty($done_t)) break;
			
			$torrent = @$torrents_a[$done_t['hashString']];
			
			log_event('Processing toto_id '.$done_t['toto_id']);
			
			$qwhere = 'hashString='.$db->escape($done_t['hashString']);
			$db->update('torrents', array('status' => 1), $qwhere);
			
			// get skipped files
			$skipped_files = array();
			foreach($torrent->files as $i => $f) {
				if(!@$torrent->wanted[$i]) {
					if($fn = @$f->name)
						$skipped_files[] = $fn;
				}
			}
			if(count($skipped_files) == count($torrent->files)) {
				// sanity check - have seen this actually occur!
				warning('All files marked as skipped for torrent (toto id: '.$done_t['toto_id'].').  Halting process');
				//$done = array_diff($done, [$done_t['hashString']]);
				$db->update('torrents', array('status' => 0), $qwhere);
				break;
			}
			
			if(!$done_t['totalsize']) $done_t['totalsize'] = 1024*1024*1024; // a glitch which should never occur, but we'll put here just in case
			// give at least 10 minutes or 1s/64KB
			@set_time_limit(max($done_t['totalsize'] / (64*1024), 600));
			$sfinfo = process_dir($done_t['folder'], $done_t['toto_id'], $skipped_files, $done_t['name'], @$torrent->bandwidthPriority*2);
			@set_time_limit(60);
			$sigfid = 0;
			if(!$sfinfo)
				$sfinfo = array();
			elseif(isset($sfinfo['id']) && $sfinfo['id'] && empty($skipped_files))
				$sigfid = $sfinfo['id'];
			$db->update('torrents', array('status' => @$sfinfo['archive'] ? 2:3), $qwhere);
			
			$toto_updates = resolve_name($done_t['toto_id'], $done_t['name'], $done_t['dateline'], $sfinfo);
			$toto_updates['ulcomplete'] = empty($skipped_files) ? 1 : 2;
			$toto_updates['sigfid'] = $sigfid;
			$toto_updates['completed_date'] = time();
			$db->update('toto', $toto_updates, 'id='.$done_t['toto_id']);
			log_event('Completed processing toto_id '.$done_t['toto_id']);
			
			if(!isset($torrent->bandwidthPriority) || $torrent->bandwidthPriority > -1)
				$lowerPrio[] = $torrent->id;
		}
	}
	// reduce priority of seeding torrents; after completion, uploads will soon follow, with Transmissions upload bandwidth being throttled, so prefer bandwidth being allocated to uploads instead of seeding
	if(!empty($lowerPrio)) {
		$transmission->set($lowerPrio, ['bandwidthPriority' => -1]);
	}
	
	// update tracker stats
	if(!empty($stats)) {
		$trackers = []; $hashes = [];
		foreach($stats as $ts) {
			$trackers[$ts['tracker']] = 1;
			$hashes[$ts['btih']] = 1;
		}
		
		// get tracker IDs
		$tids = $db->selectGetAll('trackers', 'url', 'url IN ('.implode(',', array_map(array($db, 'escape'), array_keys($trackers))).')');
		$tids = strtolower_keys($tids);
		
		// get existing stats
		$q = $db->select('toto', 'btih IN ('.implode(',', array_map(array(&$db, 'escape'), array_keys($hashes))).')', 'tracker_stats.*, toto.btih', [
			'joins' => [['inner', 'tracker_stats', 'id']],
			'use_index' => '(btih)'
		]);
		$tracker_stats = [];
		while($r = $db->fetchArray($q)) {
			$grpk = $r['btih'].'_'.$r['tracker_id'];
			unset($r['btih']);
			if(!isset($tracker_stats[$grpk]))
				$tracker_stats[$grpk] = [];
			$tracker_stats[$grpk][] = $r;
		}
		$db->freeResult($q);
		
		// merge updates
		$stat_updates = [];
		foreach($stats as $ts) {
			$tid = @$tids[strtolower($ts['tracker'])];
			if(empty($tid)) { // missing tracker?
				//echo "Missing tracker ID for $ts[tracker]\n";
				continue;
			}
			$tracker_stat_grp =& $tracker_stats[$ts['btih'].'_'.$tid['id']];
			if(empty($tracker_stat_grp)) { // missing original data?
				//echo "Missing original data for '".bin2hex($ts['btih'])."_$tid[id]'\n";
				continue;
			}
			
			foreach($tracker_stat_grp as $tss) {
				$new_updated = (int)$ts['updated'] > (int)$tss['updated'];
				$new_queried = (int)$ts['last_queried'] > (int)$tss['last_queried'];
				if($new_updated || $new_queried) {
					if($new_queried) {
						$tss['last_queried'] = $ts['last_queried'];
						$tss['error'] = $ts['error'];
					}
					if($new_updated) {
						$tss['updated'] = $ts['updated'];
						if(isset($ts['complete']))   $tss['complete']   = $ts['complete'];
						if(isset($ts['downloaded'])) $tss['downloaded'] = $ts['downloaded'];
						if(isset($ts['incomplete'])) $tss['incomplete'] = $ts['incomplete'];
					}
					
					$stat_updates[] = $tss;
				}
			}
			
			unset($tracker_stat_grp);
		}
		
		// submit update
		if(!empty($stat_updates)) {
			$db->insertMulti('tracker_stats', $stat_updates, true);
		}
	}
}


function process_dir($dir, $toto_id, $skipped_files=array(), $toto_title='', $priority=0) {
	log_event('Processing directory '.$dir);
	global $db;
	// grab list of files
	$files = array();
	$srcdir = TORRENT_DIR.$dir.'/';
	build_file_list($srcdir, $files);
	$prefix_cut = strlen($srcdir);
	$archive = false;
	
	if(empty($files)) {
		warning('No files in directory '.$dir.'! (toto id: '.$toto_id.')');
		return;
	}
	
	// filter out skipped
	$skipped_files = array_flip($skipped_files);
	foreach($files as $k => $file) {
		$cfn = substr($file, $prefix_cut);
		if(isset($skipped_files[$cfn]))
			unset($files[$k]);
		elseif(substr($cfn, -5) == '.part' && isset($skipped_files[substr($cfn, 0, -5)]))
			unset($files[$k]);
	}
	
	$numfiles = count($files);
	if(!$numfiles) {
		warning('No files to upload for '.$dir.'! (toto id: '.$toto_id.')  All files skipped?!');
		return;
	}
	
	// delete junk files (padding etc)
	if($numfiles > 1) {
		foreach($files as $k => $file) {
			$delete = 0;
			$bnf = strtolower(basename($file));
			if(preg_match('~(^|/)__MACOSX/~', $file) && substr($bnf, 0, 2) == '._') {
				$test = file_get_contents($file, false, null, 0, 4);
				if($test == "\0\x05\x16\x07")
					$delete = 1024*1024;
			}
			elseif(stripos($file, 'padding') !== false && strpos($file, 'BitComet')) {
				$test = file_get_contents($file, false, null, 0, 1024);
				if($test == str_repeat("\0", strlen($test)))
					$delete = true;
			}
			elseif($bnf == 'thumbs.db') {
				$test = file_get_contents($file, false, null, 0, 4);
				if($test == "\xD0\xCF\x11\xE0")
					$delete = 512*1024;
			}
			elseif($bnf == '.ds_store') {
				$test = file_get_contents($file, false, null, 0, 8);
				if($test == "\0\0\0\x01Bud1")
					$delete = 512*1024;
			}
			elseif(preg_match('~(^|/)\.pad/\d+$~i', $file)) {
				// BEP 47 padding definition
				$test = file_get_contents($file, false, null, 0, 1024);
				if($test == str_repeat("\0", strlen($test)))
					$delete = true;
			}
			
			if($delete && ($delete === true || @filesize($file) < $delete)) {
				info('Removing junk file from toto_id '.$toto_id.': '.$file, 'complete-junk');
				//unlink($file); // probably a bad idea as it forces padding to be re-torrented
				unset($files[$k]);
				--$numfiles;
			}
		}
	}
	
	// if there are >1 file and everything's in the same directory, cut off that directory too
	if($numfiles > 1) {
		$folder = null;
		foreach($files as $file) {
			$file_base = substr($file, $prefix_cut);
			if(($p = mb_strpos($file_base, '/')) !== false) {
				$folder_tmp = mb_substr($file_base, 0, $p+1);
				if(!isset($folder)) {
					$folder = $folder_tmp;
					continue;
				}
				elseif($folder == $folder_tmp)
					continue;
			}
			unset($folder);
			break;
		}
		if(isset($folder)) {
			$prefix_cut += strlen($folder);
			$srcdir .= $folder;
		}
		unset($folder);
	}
	
	// TODO: look for archives and consider whether we should extract them
	// this includes split archives
	
	// if we have a lot of files, consider ZIPing it all up
	if($numfiles > 5) {
		$arcfiles = get_archive_files($files);
		if($arcfiles) {
			log_event('Skipping '.count($arcfiles).' files for archiving later');
			$unarcfiles = array_diff($files, $arcfiles);
			
			$pref_filter = function($a) use($prefix_cut) {
				return array_map(function($s) use($prefix_cut) {
					return substr($s, $prefix_cut);
				}, $a);
			};
			
			$db->insert('toarchive', array(
				'toto_id' => $toto_id,
				'torrentname' => $db->selectGetField('toto', 'name', 'id='.$toto_id), // TODO: have seen this query fail
				'files' => serialize($pref_filter($arcfiles)),
				'xfiles' => serialize($pref_filter($unarcfiles)),
				'basedir' => $srcdir,
				'opts' => get_archive_meth($arcfiles),
				'dateline' => time(),
				'priority' => $priority,
			));
			
			$files = $unarcfiles;
			$archive = true;
		}
	}
	
	// auto adjust priority, reducing that for large batches
	if($priority == 0 && count($files) > 5) {
		// if we find multiple large files, consider it a big batch (as opposed to just a BD batch or similar)
		$bigfiles = $hugefiles = $tsz = 0;
		foreach($files as $file) {
			$sz = @filesize($file);
			$tsz += $sz;
			if($sz > 50*1048576)
				++$bigfiles;
			if($sz > 300*1048576)
				++$hugefiles;
			
			if($tsz > 1536*1048576 && ($hugefiles > 5 || $bigfiles > 10)) {
				log_event('Batch detected, reducing priority for '.$dir);
				--$priority;
				break;
			}
		}
	}
	// reduce priority of Deadfish/Bakedfish batches, since they seem to come all at the same time
	if($priority == 0 && count($files) > 1 && preg_match('~^\[(baked|dead|space)fish\]~i', basename(reset($files)))) {
		log_event('Fish batch detected, reducing priority for '.$dir);
		--$priority;
	}
	
	$dbentries = array();
	foreach($files as $file) {
		$dbentries[$file] = add_file_entries($file, substr($file, $prefix_cut), $toto_id, $priority);
	}
	
	add_to_news_queue($srcdir, $toto_id, $files, $archive, !empty($skipped_files), $priority);
	
	log_event('End processing directory '.$dir);
	
	// now that we're mostly done with the details...
	// ...eliminate insignificant files
	if($numfiles > 1 && !$archive) {
		foreach($dbentries as $fn => $entry) {
			if((float)$entry['filesize'] < 1048576 && in_array(get_extension($entry['filename']), array('txt','url','sfv','md5','sha1','bat','jpg','jpe','jpeg','gif','png','bmp','nfo','srt','ass','ssa','sub','idx','pdf','ttf','ttc','otf'))) {
				--$numfiles;
				unset($dbentries[$fn]);
			}
		}
	}
	
	$ret = $numfiles == 1 ? reset($dbentries) : [];
	$ret['archive'] = $archive;
	return $ret;
}

// recursively grabs a list of all files in a dir
function build_file_list($dir, &$list) {
	if(!($d = opendir($dir))) return;
	if(substr($dir, -1) != '/') $dir .= '/';
	
	while(false !== ($f = readdir($d))) {
		if($f == '.' || $f == '..') continue;
		$ff = $dir.$f;
		if(is_dir($ff)) {
			build_file_list($ff, $list);
		}
		else {
			$list[] = $ff;
		}
	}
	
	closedir($d);
}

function mkdirp_helper($path, &$dirs_made) {
	$pathn = rtrim($path, '/');
	if(isset($dirs_made[$pathn]) || $pathn=='') return; // latter condition should never be true
	mkdirp_helper(dirname($pathn), $dirs_made);
	@mkdir($pathn);
	$dirs_made[$pathn] = 1;
}

function add_to_news_queue($srcdir, $toto_id, $files, $has_archive, $is_partial=false, $priority=0) {
	$newsdir = TOTO_NEWS_PATH.'toto_'.$toto_id.'/';
	mkdir($newsdir);
	
	$prefix_cut = strlen($srcdir);
	
	// link files across
	$allow_link = true;
	$dirs_made = [rtrim($newsdir, '/') => 1];
	foreach($files as $file) {
		$target = $newsdir.substr($file, $prefix_cut);
		mkdirp_helper(dirname($target), $dirs_made);
		
		if($allow_link && !@link($file, $target))
			$allow_link = false;
		if(!$allow_link)
			copy($file, $target);
	}
	
	global $db;
	$db->insert('newsqueue', array(
		'id' => $toto_id,
		'status' => $has_archive ? 2:0,
		'dateline' => time(),
		'is_partial' => $is_partial ? 1:0,
		'priority' => $priority
	));
}
