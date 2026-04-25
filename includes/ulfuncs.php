<?php

define('TOTO_ULQUEUE_PATH', '/atdata/ulqueue/');

// returns array of failed files
// our definition of failed is if all hosts have at least one failed part => file failed
function send_to_uploader(&$uploader, $upfiles, &$links, $resolved=false, $svc_map=array()) {
	
	$urls = $uploader->upload($upfiles); // if all uploads fail, gives back empty arrays, which, this function will also generate empty arrays, and the world is happy
	$time = time();
	
	if(empty($urls)) return array_keys($upfiles);
	$failed = array();
	// grab links
	foreach($urls as $fn => &$url) {
		$urlstore =& $links[$fn];
		if(empty($urlstore)) $urlstore = array();
		if(empty($url)) {
			// entire upload thing failed - dummy entries (make it look like a single part)
			foreach($svc_map as $s => &$svc)
				$url[$s] = null;
		}
		$file_success = false;
		foreach($url as $s => &$u) {
			if(isset($svc_map[$s])) $s = $svc_map[$s];
			if(!is_array($u)) $u = array(0 => $u);
			$svc_failed = false;
			foreach($u as $part => $parturl) {
				if(!isset($parturl)) { // uploading of this part failed
					$urlstore[] = array(
						'site' => $s,
						'part' => $part,
						'url' => '',
						'status' => 3,
						'added' => $time,
					);
					$svc_failed = true;
				} else {
					if(is_string($resolved))
						$status = ($s != $resolved);
					else
						$status = ($resolved ? 0:1);
					$urlstore[] = array(
						'site' => $s,
						'part' => $part,
						'url' => trim($parturl),
						'status' => $status,
						'added' => $time,
					);
				}
			}
			if(!$svc_failed) // if at least one service was successful, we've done our job
				$file_success = true;
		}
		
		if(!$file_success)
			$failed[] = $fn;
	}
	return $failed;
}

