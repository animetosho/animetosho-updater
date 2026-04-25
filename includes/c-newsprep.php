<?php

require ROOT_DIR.'init.php';
if(!sema_lock('fileproc')) return;

include ROOT_DIR.'includes/complete.php'; // for TOTO_NEWS_PATH; also includes finfo.php + miniwebcore for URLs
make_lock_file(substr(THIS_SCRIPT, 5, -4));

loadDb();


define('PAR2_OPTS', '-m 1.0G --seq-read-size 32M --seq-read-throttle 1r/3s --chunk-read-throttle 16M/s --read-buffers 2 --read-hash-queue 2 --chunk-read-threads 1');

@set_time_limit(300);

// TODO: parpar min block size

define('NNTP_ARTICLE_SIZE', 716800);
define('PAR2_MINBLOCK', 2*NNTP_ARTICLE_SIZE);
define('PAR2_NUMBLOCKS', 200); // avoid creating more than 200 blocks for speed purposes
define('PAR2_RATIO', 0.10);

if(!isset($order) || !$order) $order = 'priority DESC, dateline ASC';
$now = time();

// TODO: merge with c-archive
function clean_filename($fn) {
	$fn = strtr($fn, array("\0" => '', '/' => '-'));
	if($fn == '.' || $fn == '..') return 'unnamed';
	$fn = mb_substr($fn, 0, 200);
	while(isset($fn[235])) $fn = mb_substr($fn, 0, -1);
	return $fn;
}

function par2_basename($file, $keep_ext=false) {
	$file = strtr(basename($file), array('/'=>''));
	if(!$keep_ext)
		$file = preg_replace('~\.[a-zA-Z0-9]{1,10}$~', '', $file);
	if(strlen($file) > 235) // allocate space for '.vol12345+12345.par2'
		$file = substr($file, 0, 235);
	return $file;
}

for($_i=0; $_i<5; ++$_i) {
	$item = $db->selectGetArray('newsqueue', 'status=0 AND newsqueue.dateline < '.$now, 'newsqueue.*,toto.completed_date,toto.name,toto.torrentname,toto.deleted,toto.cat,toto.totalsize,toto.tosho_id,toto.nyaa_subdom,toto.nyaa_id,toto.anidex_id,toto.nekobt_id', array('order' => $order, 'joins' => array(
		array('inner', 'toto', 'id')
	)));
	if(empty($item)) break;
	
	if($db->update('newsqueue', array('status' => 1), 'id='.$item['id'].' AND status=0') != 1)
		continue; // race condition conflict
	
	$dir = TOTO_NEWS_PATH.'toto_'.$item['id'].'/';
	log_event('Prepping toto '.$item['id'].' for news posting...');
	// TODO: create NFO?
	
	if($item['deleted']) {
		// item deleted? avoid processing it
		if($now - $item['completed_date'] > 86400) {
			// purge item
			log_event('Item marked as deleted, purging...');
			$db->delete('newsqueue', 'id='.$item['id']);
			rmdir_r(substr($dir, 0, -1));
		} else {
			// just defer it
			log_event('Item marked as deleted, deferring...');
			$db->update('newsqueue', array('status' => 0, 'dateline' => $now+7200), 'id='.$item['id']);
		}
		continue;
	}
	
	// archive up subdirectories (it seems like usenet just doesn't support subdirs)
	// TODO: if lots of files, archive everything?  probably not as big of an issue though
	$files = glob($dir.'*');
	$dirs = array_filter($files, 'is_dir');
	if(!empty($dirs)) {
		log_event('Creating archive for subfolders...');
		$name = clean_filename($item['name']);
		$nameSuf = ''; $i = 0;
		while(file_exists($dir.$name.$nameSuf.'.7z')) {
			$nameSuf = ' ('.(++$i).')';
		}
		$name .= $nameSuf.'.7z';
		
		file_put_contents($filelist = TEMP_DIR.'toto_filelist_'.uniqid().'.txt', implode(' ', array_map('escapeshellfile', array_map('basename', $dirs))));
		
		chdir($dir);
		timeout_exec('xargs nice -n15 7za a -t7z -mmt=1 -bd -m0=copy '.escapeshellfile($name).' <'.escapeshellarg($filelist), 3600*8, $sevenzret);
		chdir(ROOT_DIR);
		unlink($filelist);
		
		if(!file_exists($dir.$name) || filesize_big($dir.$name) == '0') {
			error('Usenet archive creation failed! toto_id='.$item['id'].log_dump_data($sevenzret, '7z'));
			continue;
		}
		
		// remove dirs
		foreach($dirs as $_dir)
			rmdir_r($_dir);
	}
	
	// create PAR2s
	$files = glob($dir.'*');
	// first check for files with the same name but different extension
	$fbcount = array(); // zero based counter
	$fbcountX = array();
	foreach($files as $file) {
		$bn = par2_basename($file);
		if(isset($fbcount[$bn]))
			++$fbcount[$bn];
		else
			$fbcount[$bn] = 0;
		
		$bn = par2_basename($file, true);
		if(isset($fbcountX[$bn]))
			++$fbcountX[$bn];
		else
			$fbcountX[$bn] = 0;
	}
	foreach($files as $file) {
		$fs = filesize($file);
		if($fs < NNTP_ARTICLE_SIZE*4) continue; // not so useful to PAR2 a tiny file, since it isn't recoverable if there's a missing article
		// TODO: exclude other file types? the above should filter out .sfv/txt etc
		
		// calc number of recovery slices to have / block size
		$par2size = $fs * PAR2_RATIO;
		if($par2size < PAR2_MINBLOCK) {
			// small file - just have one PAR2 file for the whole thing
			$par2blockDist = '--noindex';
			$par2blockSize = min(NNTP_ARTICLE_SIZE, ceil($par2size));
			$par2blockSize = max(NNTP_ARTICLE_SIZE*0.75, $par2blockSize); // don't make the block size too small, otherwise a missing article makes recovery impossible
			if($par2blockSize % 4) $par2blockSize += 4 - ($par2blockSize % 4);
			$par2blocks = ceil($par2size / $par2blockSize);
		} else {
			$par2blockDist = '-d pow2';
			$par2blockSize = PAR2_MINBLOCK;
			$par2blocks = PAR2_NUMBLOCKS;
			if($par2size > PAR2_NUMBLOCKS*PAR2_MINBLOCK) {
				// >~3GB in size
				// use larger block size
				$par2blockSize = $par2size / $par2blocks;
				// round up to nearest multiple of article size
				$par2blockSize = ceil($par2blockSize / NNTP_ARTICLE_SIZE) * NNTP_ARTICLE_SIZE;
				$par2blocks = ceil($par2size / $par2blockSize);
			} else {
				// use fewer blocks
				$par2blocks = ceil($par2size / $par2blockSize);
			}
		}
		
		$bn = par2_basename($file);
		if($fbcount[$bn]) { // if there's more than one file with the same name, but different extension, we need to retain the extension to avoid the PAR2 overwriting something else
			$bn = par2_basename($file, true);
			if($fbcountX[$bn]) {
				// probably have a long name + duplicate filename, so have to make up a unique name
				$bn = substr($bn, 0, -17).'+'.strtr(base64_encode(random_bytes(12)), ['/'=>'_','+'=>'-']);
			}
		}
		$outFile = $dir.$bn;
		if(file_exists($outFile.'.par2')) {
			warning('[PAR2] '.$outFile.' already exists, not creating PAR2');
			continue;
		}
		if($rc = timeout_exec('nice -n15 nodejs '.escapeshellarg(ROOT_DIR.'3rdparty/parpar/bin/parpar').' -s '.$par2blockSize.'b -r '.$par2blocks.' '.PAR2_OPTS.' '.$par2blockDist.' -o '.escapeshellarg($outFile).' '.escapeshellfile($file), 3600*8, $junk, ['stderr' => &$output])) {
			error('PAR2 creation failed for '.$item['id'].'; exit code: '.$rc.log_dump_data($output, 'par2'));
			// leave the item stuck in the queue
			goto endloop;
		}
	}
	
	log_event('PAR2 files created');
	$db->update('newsqueue', array('status' => 3), 'id='.$item['id']);
	
endloop:
}
