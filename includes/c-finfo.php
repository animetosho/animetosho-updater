<?php

require ROOT_DIR.'init.php';

make_lock_file(substr(THIS_SCRIPT, 5, -4));
@ini_set('memory_limit', '256M'); // for large archives and jazz

loadDb();
unset($config);

require ROOT_DIR.'includes/finfo.php';

@set_time_limit(1800);

if(!isset($order) || !$order) $order = 'priority DESC, dateline ASC';

for($i=0; $i<10; ++$i) { // we'll limit to processing 10 files per process...
	
	// get next thing to do
	$time = time();
	$task = $db->selectGetArray('finfo', 'finfo.status=0 AND finfo.dateline<='.$time, 'finfo.*, files.toto_id', array('order' => $order, 'joins' => array(
		array('inner', 'files', 'fid', 'id')
	)));
	if(empty($task)) break; // end of queue
	
	$path = TOTO_FINFO_PATH.'file_'.$task['fid'].'/';
	$file = $path.$task['filename'];
	if($db->update('finfo', array('status' => 1), 'fid='.$task['fid'].' AND status=0') != 1)
		continue; // race condition conflict, continue
	
	if(!file_exists($file)) {
		error('File for '.$task['fid'].' does not exist!');
		continue; // intentionally leave it stuck in the queue
	}
	
	// ideally pull this information via a JOIN, but our DB class sucks
	$toto = $db->selectGetArray('toto', 'id='.$task['toto_id']);
	
	if($toto['deleted']) {
		if($time - $toto['completed_date'] > 86400) {
			log_event('Skipping deleted file '.$file);
			info('Skipping '.$file.' (id:'.$task['fid'].')', 'finfo-skip');
		} else {
			log_event('Deferring deleted file '.$file);
			$db->update('finfo', array(
				'dateline' => time() + 3600,
				'status' => 0
			), 'fid='.$task['fid']);
			continue;
		}
	} else {
		log_event('Retrieving file info for '.$file);
		
		$fileupdate = fileinfo_run($task['fid'], $file);
		if(empty($fileupdate)) {
			$fileupdate = array(); // TODO: signal error here?
		} else {
			if(isset($fileupdate['video_duration'])) {
				if($toto['sigfid'] == $task['fid'])
					$db->update('adb_resolve_queue', array('video_duration' => $fileupdate['video_duration']), 'toto_id='.$task['toto_id']);
				unset($fileupdate['video_duration']);
			}
			// TODO: do we still need the following?
			foreach($fileupdate as $k => &$v)
				if(!isset($v)) unset($fileupdate[$k]);
		}
		if(!empty($fileupdate)) {
			log_event('Updating file info');
			$db->update('files', $fileupdate, 'id='.$task['fid']);
		} else
			log_event('No file info to update with');
	}
	
	
	// clean up
	$db->delete('finfo', 'fid='.$task['fid']);
	unlink($file);
	rmdir($path);
	
	log_event('Processing completed successfully');
}

