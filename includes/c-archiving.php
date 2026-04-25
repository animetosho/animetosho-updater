<?php

require ROOT_DIR.'includes/complete.php';

if(!sema_lock('fileproc')) return;

for($i=0; $i<2; ++$i) { // we'll limit to processing 2 files per process...
	
	// get next thing to do
	$task = $db->selectGetArray('toarchive', 'status=0 AND dateline<='.time(), '*', array('order' => $order));
	if(empty($task)) break; // end of queue
	
	// TODO: if torrent deleted, pause this perhaps?
	
	if($db->update('toarchive', array('status' => 1), 'toto_id='.$task['toto_id'].' AND status=0') != 1)
		continue; // race condition conflict, continue
	
	$arcfiles = unserialize($task['files']);
	if(empty($arcfiles)) // error!
		continue;
	
	// make folder - otherwise possible filename conflict
	$archive_dir = TEMP_DIR.'c_complete_'.uniqid().'/';
	@mkdir($archive_dir);
	$archive_name = clean_filename($task['torrentname']).'.7z';
	$archive = $archive_dir.$archive_name;
	file_put_contents($filelist = $archive_dir.'filelist.txt', implode(' ', array_map('escapeshellfile', $arcfiles)));
	
	log_event('Creating archive '.$archive.' for toto_id='.$task['toto_id']);
	chdir($task['basedir']);
	timeout_exec('xargs nice -n15 7za a -t7z -mmt=1 -bd '.$task['opts'].' '.escapeshellfile($archive).' <'.escapeshellarg($filelist), 3600*8, $sevenzret);
	chdir(ROOT_DIR);
	unlink($filelist);
	if(file_exists($archive) && filesize_big($archive) != '0') {
		log_event('Archive successfully created');
		
		// add to news queue
		if($db->selectGetArray('newsqueue', 'id='.$task['toto_id'].' AND status=2', 'id')) {
			link_or_copy($archive, TOTO_NEWS_PATH.'toto_'.$task['toto_id'].'/'.$archive_name);
			$db->update('newsqueue', ['status'=>0], 'id='.$task['toto_id']);
		}
		
		$real_id = find_nondupe_item_id($task['toto_id']);
		
		// TODO: consider whether it makes sense to add the file entry during c-complete, then updating the filesize here
		add_file_entries($archive, $archive_name, $real_id, $task['priority'], true);
		unlink($archive);
		
		// mark torrent for deletion
		if($db->update('torrents', array('status' => 3), 'toto_id='.$real_id.' AND status=2') != 1) {
			// have seen this occur... maybe c-complete hasn't finished marking the torrent yet
			sleep(20);
			// try again
			if($db->update('torrents', array('status' => 3), 'toto_id='.$real_id.' AND status=2') != 1) {
				error('Could not update torrent status after archiving, for '.$real_id.'; old status='.$db->selectGetField('torrents', 'status', 'toto_id='.$real_id));
			}
		}
		
	} else {
		warning('Archive creation failed! archive='.$archive.' toto_id='.$task['toto_id'].log_dump_data($sevenzret, '7z'));
		@unlink($archive);
		@rmdir($archive_dir);
		// leave in queue
		continue;
	}
	unset($sevenzret);
	
	
	
	// clean up
	$db->delete('toarchive', 'toto_id='.$task['toto_id']);
	rmdir($archive_dir);
	log_event('Archiving completed successfully');
}

function clean_filename($fn) {
	$fn = strtr($fn, array("\0" => '', '/' => '-'));
	if($fn == '.' || $fn == '..') return 'unnamed';
	//if(isset($fn[200])) return substr($fn, 0, 200);
	$fn = mb_substr($fn, 0, 200);
	// ugly fudge strat
	while(isset($fn[235])) $fn = mb_substr($fn, 0, -1);
	return $fn;
}

