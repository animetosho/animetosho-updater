<?php

require ROOT_DIR.'init.php';

if(!sema_lock('uploader')) return;

define('TOTO_USENET_DUMP_DIR', '/atdata/nntpdump/'); // also edit news/repost.sh !
include ROOT_DIR.'includes/complete.php'; // for TOTO_NEWS_PATH; also includes finfo.php for TOTO_STORAGE_PATH and make_id_dirs()
include ROOT_DIR.'includes/miniwebcore.php'; // for URLs

make_lock_file(substr(THIS_SCRIPT, 5, -4));

loadDb();


@set_time_limit(300);

$groupmap = array(
	 0 => 'alt.binaries.multimedia.anime.highspeed', // default fallback
	 1 => 'alt.binaries.multimedia.anime.highspeed',
	 2 => 'alt.binaries.sounds.anime', // alt.binaries.sounds.jpop
	 3 => 'alt.binaries.pictures.manga',
	 4 => 'alt.binaries.hentai',
	// 5 => '', // other
	 7 => 'alt.binaries.multimedia.anime.raws',
	 8 => 'alt.binaries.multimedia.japanese',
	// 9 => '', // music video
	//10 => '', // non-english
	11 => 'alt.binaries.multimedia.anime.highspeed', // batch
	12 => 'alt.binaries.multimedia.erotica.anime',
	13 => 'alt.binaries.pictures.erotica.anime',
	//14 => '', // H Games
	15 => 'alt.binaries.multimedia.erotica.asian',
);

$articleSize = 716800;
$nyuuOpts = implode(' ', [
	'--user', '',
	'--password', '',
	'--host', '',
	'--port', '119',
	'--from', escapeshellarg('Anime Tosho <usenet.bot@animetosho.org>'),
	'--connections', '8',
	//'--check-tries', '2',
	//'--check-delay', '20',
	'--article-size', $articleSize,
	'--minify',
	//'--nzb-compress', 'gzip', '--nzb-compress-level', '9',
	'--log-time',
	'--group-files',
	'--post-timeout', '30s',
	'--skip-errors', 'post-timeout,check-timeout,check-missing',
	'--on-post-timeout', 'retry,strip-hdr=User-Agent,retry,ignore',
	'--connect-retries', '5',
	'--reconnect-delay', '30s',
	'--check-delay', '2s',
	//'--check-queue-size', '200',
	'--check-queue-cache', '200',
	'--check-tries', '4',
	'--check-post-tries', '5',
	'--check-connections', '2',
	//'--check-group', 'bit.test',
	'--progress', 'http:localhost',
	'--subdirs', 'keep',
	'--disk-req-size', '28000K',
	//'--disk-buf-size', '1',
	'--post-queue-size', '16',
	'--request-retries', '8',
	'--post-retries', '3',
	'--post-retry-delay', '10s',
	'--post-chunk-size', '384k', // TODO: consider setting to 0
	'--post-fail-reconnect',
	'--preload-modules',
	'--dump-failed-posts', TOTO_USENET_DUMP_DIR,
	'-H', escapeshellarg('Organization=Anime Tosho'),
	'-M', escapeshellarg('x-generator=Nyuu [https://animetosho.org/app/nyuu]')
	// TODO: category?
	// group,out,meta[title] will be added
]);




if(!isset($order) || !$order) $order = 'priority DESC, dateline ASC';
if(!isset($where) || !$where) $where = '1=1';
$now = time();
$logfile = ROOT_DIR.'logs/log-'.substr(THIS_SCRIPT, 5, -4).'-nyuu-'.date('Y-m').'.txt';


for($_i=0; $_i<2; ++$_i) {
	$item = $db->selectGetArray('newsqueue', 'status=3 AND newsqueue.dateline < '.$now.' AND '.$where, 'newsqueue.*,toto.completed_date,toto.name,toto.torrentname,toto.deleted,toto.cat,toto.totalsize,toto.tosho_id,toto.nyaa_subdom,toto.nyaa_id,toto.anidex_id,toto.nekobt_id', array('order' => $order, 'joins' => array(
		array('inner', 'toto', 'id')
	)));
	if(empty($item)) break;
	
	if($db->update('newsqueue', array('status' => 4), 'id='.$item['id'].' AND status=3') != 1)
		continue; // race condition conflict
	
	// check to see if the ulqueue is busy - if so, 80% chance to defer usenet posting altogether
	$ulqCount = $db->selectGetField('ulqueue', 'COUNT(*)', 'status=0 AND dateline<='.$now.' AND priority>'.$item['priority']);
	if($ulqCount > 5 && mt_rand(0, 4) != 0) {
		$db->update('newsqueue', array('status' => 3), 'id='.$item['id']);
		break;
	}
	
	$dir = TOTO_NEWS_PATH.'toto_'.$item['id'].'/';
	log_event('Processing toto '.$item['id'].' for news posting...');
	
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
			$db->update('newsqueue', array('status' => 3, 'dateline' => $now+7200), 'id='.$item['id']);
		}
		continue;
	}
	
	// exec upload
	
	$group = @$groupmap[$item['cat']];
	if(!$group) $group = $groupmap[0];
	
	$nzbTitle = $item['name'];
	if($item['is_partial']) $nzbTitle .= ' (partial)';
	$subjComment = '';
	if(count(glob($dir.'*')) > 1) // should never really be false
		$subjComment = ' --comment '.escapeshellarg($nzbTitle);
	$nzbPath = TOTO_STORAGE_PATH.'nzb/';
	$nzbDest = 
		$nzbPath.
		implode('', make_id_dirs($item['id'], $nzbPath)).
		'.nzb.gz';
	if(file_exists($nzbDest)) {
		@unlink($nzbDest);
		warning('Removed destination NZB: '.$nzbDest);
	}
	if($rc = timeout_exec('nodejs '.escapeshellarg(ROOT_DIR.'3rdparty/nyuu/bin/nyuu').' '.$nyuuOpts.' '.escapeshellarg($dir).$subjComment.' -M '.escapeshellarg('title='.$nzbTitle).' -M '.escapeshellarg('x-info-url='.AT::viewUrl($item, array(), true)).' --groups '.escapeshellarg($group).' -o '.escapeshellarg('proc://7z a -tgzip -bd -mx=9 -si '.escapeshellarg($nzbDest)).' 2>>'.escapeshellarg($logfile), 3600*18)) {
		// TODO: enable retries
		// TODO: check return codes?
		if($rc != 32) {
			@unlink($nzbDest);
			
			error('Failed to upload NZB '.$item['id'].' (rc='.$rc.')', 'news');
			continue;
		} else {
			warning('NZB created for '.$item['id'].', but with errors', 'news');
		}
	}
	$nzbSize = @filesize($nzbDest) ?: 0;
	if($nzbSize < 32) { // empty xz is 20 bytes, should be impossible for a valid NZB to be smaller than 32B
		//@unlink($nzbDest);
		error('Failed to create NZB '.$nzbDest.' for '.$item['id'].' (size='.$nzbSize.')');
		continue;
	}
	
	log_event('Upload successful, saving NZB...');
	
	// store NZB
	@chmod($nzbDest, 0666);
	
	log_event('NZB stored as '.$nzbDest.', finalising...');
	$db->update('toto', array('stored_nzb' => 1), 'id='.$item['id']);
	$db->delete('newsqueue', 'id='.$item['id']);
	rmdir_r(substr($dir, 0, -1));
	
	// do a scan for isdupe
	$real_id = find_nondupe_item_id($item['id']);
	if($real_id != $item['id']) {
		// the set ID is no longer "valid" - copy to new one
		log_event('Duplicate redirection detected: '.$item['id'].' -> '.$real_id);
		
		$nzbDest2 = 
			$nzbPath.
			implode('', make_id_dirs($real_id, $nzbPath)).
			'.nzb.gz';
		if(!file_exists($nzbDest2)) {
			link_or_copy($nzbDest, $nzbDest2);
			$db->update('toto', array('stored_nzb' => 1), 'id='.$real_id);
			log_event('NZB also stored to '.$nzbDest2);
		} else {
			log_event('NZB '.$nzbDest2.' already exists!');
		}
	}
	
	
	log_event('Process complete');
}
