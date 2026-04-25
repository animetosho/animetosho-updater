<?php

// DB should already be set up

make_lock_file('cleanup');
@set_time_limit(900);
@ini_set('memory_limit', '384M'); // for large Transmission data

try {
	$transmission = get_transmission_rpc();
	if(!$transmission) return;
	// check for completed torrents and remove
	// Other fields which may be useful: activityDate, dateCreated, error, errorString
	// ref: https://trac.transmissionbt.com/browser/trunk/extras/rpc-spec.txt
	$torrents = $transmission->get(array(), array('id', 'name', 'status', 'doneDate', 'haveValid', 'sizeWhenDone', 'hashString', 'rateDownload', 'rateUpload', 'eta', 'addedDate', 'startDate'));
} catch(TransmissionRPCException $e) {
	return;
}
if(!empty($torrents->arguments->torrents)) {
	$low_space = low_disk_space();
	
	$paused = $broken = array();
	$stop_torrent = $reannounce = array();
    $time = time();
	// statuses, 1=waiting, 2=checking files, 4=downloading, 8=seeding, 16=paused+complete?[or stopped]
	foreach($torrents->arguments->torrents as &$torrent) {
		$torrenthash = pack('H*', $torrent->hashString);
		if(!isset($torrent->status)) $torrent->status = TransmissionRPC::TR_STATUS_STOPPED;
		// treat waiting to seed as seeding
		if($torrent->status == TransmissionRPC::TR_STATUS_SEED_WAIT) $torrent->status = TransmissionRPC::TR_STATUS_SEED;
		if(($torrent->status == TransmissionRPC::TR_STATUS_STOPPED && (isset($torrent->doneDate) || (@$torrent->sizeWhenDone && $torrent->sizeWhenDone === @$torrent->haveValid)))
		|| ($torrent->status == TransmissionRPC::TR_STATUS_SEED && ($time - $torrent->startDate) > 86400*5 && @$torrent->rateUpload < 10240)) {
			$paused[$torrenthash] = $torrent->id;
			// if been seeding for a long time (>1 day) and going fairly slow (<10KB/s), pause this torrent
			if($torrent->status == TransmissionRPC::TR_STATUS_SEED) $stop_torrent[$torrent->id] = $torrent->id;
		} elseif($torrent->status == TransmissionRPC::TR_STATUS_DOWNLOAD && ($time - $torrent->startDate) > 86400*7 && (!isset($torrent->rateDownload) || $torrent->rateDownload < 10240) && (@$torrent->eta > 86400 || @$torrent->eta < 0)) {
			// been downloading for a long time (>7 days) and downloading fairly slowly (<10KB/s)
			// just *hope* we haven't caught an almost complete torrent
			// TODO: problem here is that if the speed happens to ever drop too low whilst we're doing this check, it could get pulled
			$broken[$torrenthash] = $torrent->id;
			$stop_torrent[$torrent->id] = $torrent->id;
		} elseif($torrent->status == TransmissionRPC::TR_STATUS_STOPPED && !isset($torrent->doneDate) /*paranoia*/ && ($time - $torrent->addedDate) > 86400) {
			// paused for a long time (>1day), but not complete - broken?
			$broken[$torrenthash] = $torrent->id;
		} elseif($low_space && ($torrent->status == TransmissionRPC::TR_STATUS_SEED || $torrent->status == TransmissionRPC::TR_STATUS_STOPPED)) {
			// we're low on disk space - prune completed torrents
			$paused[$torrenthash] = $torrent->id;
			if($torrent->status == TransmissionRPC::TR_STATUS_SEED) $stop_torrent[$torrent->id] = $torrent->id;
		}
		
		// try reannounce on slow torrents
		elseif($torrent->status == TransmissionRPC::TR_STATUS_DOWNLOAD && ($time - $torrent->startDate) > 12*3600 && (!isset($torrent->rateDownload) || $torrent->rateDownload < 10240) && (@$torrent->eta > 86400 || @$torrent->eta < 0)) {
			// been downloading for >12hrs and downloading fairly slowly (<10KB/s)
			// try reannouncing
			$reannounce[$torrenthash] = $torrent->id;
		}
	}
	
	$deltorrent = $pausetorrent = $deldirs = $delincomp = array();
	if(!empty($paused)) {
		// find if torrents in DB
		
		$done = $db->selectGetAll('torrents', 'hashString', 'hashString IN ('.implode(',', array_map(array(&$db, 'escape'), array_keys($paused))).') AND status=3', 'id,name,hashString,torrents.folder');
		if(!empty($done)) {
			foreach($done as $hs => &$d) {
				$deltorrent[] = $paused[$hs];
				$delincomp[] = $d['name'];
				if(isset($stop_torrent[$hs])) $pausetorrent[] = $hs;
				$deldirs[] = $d['folder'];
			}
			$db->delete('torrents', 'hashString IN ('.implode(',', array_map(array($db, 'escape'), array_keys($done))).')');
		}
	}
	if(!empty($reannounce)) {
		// find if torrents in DB
		// max reannounce every 4 hours
		$torrents = $db->selectGetAll('torrents', 'hashString', 'hashString IN ('.implode(',', array_map(array(&$db, 'escape'), array_keys($reannounce))).') AND status=0 AND lastreannounce < '.($time - 3600*4), 'id,name,folder,hashString');
		if(!empty($torrents)) {
			$reanntorrent = $updreann = array();
			foreach($torrents as $hs => &$d) {
				$reanntorrent[] = $reannounce[$hs];
				$updreann[] = $d['id'];
				info('Reannouncing '.$d['name'].' ('.$d['folder'].').', 'torrent-reannounce');
			}
			if(!empty($reanntorrent)) {
				try {
					$transmission->reannounce($reanntorrent);
				} catch(TransmissionRPCException $e) {}
				$db->update('torrents', array('lastreannounce' => $time), 'id IN ('.implode(',', $updreann).')');
			}
			unset($reanntorrent, $updreann);
		}
	}
	if(!empty($broken)) {
		// TODO: try to upload any completed files
		$torrents = $db->selectGetAll('torrents', 'hashString', 'hashString IN ('.implode(',', array_map(array(&$db, 'escape'), array_keys($broken))).') AND status=0', 'id,hashString,name,torrents.folder,toto_id,dateline');
		if(!empty($torrents)) {
			$mark_toto = array();
			if(!function_exists('resolve_name')) { // this shouldn't be necessary, but just in case this wasn't included by c-complete...
				require ROOT_DIR.'anifuncs.php';
			}
			foreach($torrents as $hs => &$d) {
				$mark_toto[] = $d['toto_id'];
				info('Torrent '.$d['name'].' ('.$d['folder'].') appears to be dead/broken and is being removed.', 'torrent-cleanup');
				$deltorrent[] = $broken[$hs];
				$delincomp[] = $d['name'];
				if(isset($stop_torrent[$hs])) $pausetorrent[] = $hs;
				$deldirs[] = $d['folder'];
				
				// do resolve still (too lazy to update name here, will eventually get done anyway)
				resolve_name($d['toto_id'], $d['name'], $d['dateline']);
			}
			$db->delete('torrents', 'hashString IN ('.implode(',', array_map(array($db, 'escape'), array_keys($torrents))).')');
			$db->update('toto', array('ulcomplete' => -2), 'id IN ('.implode(',', $mark_toto).')');
			unset($mark_toto);
		}
	}
	
	if(!empty($pausetorrent)) {
		try {
			$transmission->stop($pausetorrent);
		} catch(TransmissionRPCException $e) {}
	}
	if(!empty($deltorrent)) {
		try {
			$transmission->remove($deltorrent, true);
		} catch(TransmissionRPCException $e) {}
	}
	foreach($deldirs as &$d) { // remove empty directories, but do recursive just in case
		delete_dir($d);
		if(@is_dir(TORRENT_DIR.$d))
			warning('Unable to remove directory '.$d);
	}
	// fix weird transmission bug where it doesn't always remove source folder
	foreach($delincomp as &$d) {
		if(strpos($d, '/') !== false || $d == '..' || $d == '.') continue;
		$d2 = TORRENT_DIR.'../incomplete/'.$d;
		if(@is_dir($d2))
			rmdir_r($d2);
	}
}








function delete_dir($dir) {
	rmdir_r(TORRENT_DIR.$dir);
}

