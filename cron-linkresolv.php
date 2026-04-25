<?php

define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

make_lock_file(substr(THIS_SCRIPT, 5, -4));

loadDb();
unset($config);


require ROOT_DIR.'includes/filelinks.php';

$links = array();
$time = time();
@set_time_limit(1200);

$db->select('filelinks_active', 'status=1 AND lastchecked < '.($time - 180).' AND url != "" AND NOT site IN ("MultiUp") AND site NOT LIKE "MultiUp|%"', 'id,fid,site,part,url,added', array('order' => 'lastchecked ASC', 'limit' => 250));
// build array of fid->part->site
$ids = array();
while($res = $db->fetchArray()) {
	$res['site'] = strtolower($res['site']);
	isset($links[$res['fid']]) or $links[$res['fid']] = array();
	isset($links[$res['fid']][$res['part']]) or $links[$res['fid']][$res['part']] = array();
	isset($links[$res['fid']][$res['part']][$res['site']]) or $links[$res['fid']][$res['part']][$res['site']] = array();
	$links[$res['fid']][$res['part']][$res['site']] = array(
		'id' => $res['id'],
		'url' => $res['url'],
		'added' => $res['added']
	);
	$ids[$res['id']] = $res['id'];
} unset($res);
$db->freeResult();

if(!empty($ids)) {
	log_event('Resolving '.count($ids).' links');

	$psites = array('MultiUp');
	$psites = array_combine(array_map('strtolower', $psites), $psites);
	$puploaders = array();
	foreach($links as $fid => &$link1)
		foreach($link1 as $part => &$link2) {
			foreach($link2 as $site => &$link) {
				unset($uploader);
				if($p = strpos($site, '|'))
					$psite = substr($site, 0, $p);
				else
					$psite = '';
				
				if($psite && isset($psites[$psite])) {
					if(!isset($puploaders[$psite])) {
						require_once ROOT_DIR.'uploaders/'.$psite.'.php';
						$ulclass = 'uploader_'.$psites[$psite];
						$puploaders[$psite] = new $ulclass;
					}
					$uploader =& $puploaders[$psite];
				}
				if(!$psite) switch($site) {
					/*
					case 'mediafire':
						if(!isset($uploader_mf)) {
							require_once ROOT_DIR.'uploaders/mediafire.php';
							$uploader_mf = new uploader_MediaFire;
						}
						$uploader =& $uploader_mf;
						break;
					*/
					case 'link': // should never happen since "link" will always be resolved
						break;
				}
				if(isset($uploader)) {
					$resolveSite = ($psite ? substr($site, strlen($psite)+1) : $site);
					log_event('Resolving fid='.$fid.', part='.$part.', site='.$resolveSite.', url='.$link['url']);
					// grab ID from URL
					$id = $uploader->id_from_unresolved_url($link['url']);
					if($id) {
						// check status
						$status = $uploader->check_status($id, $resolveSite);
						if($status) { // TODO: if this fails, it'll retry with the other ids!!
							log_event('Got status response');
							$cursitedone = false;
							// from status, perform necessary updates
							foreach($status as $ssite => &$url) {
								$ssite = strtolower($ssite);
								if($psite && !strpos($ssite, '|'))
									$ssite = $psite.'|'.$ssite;
								if($ssite == $site) $cursitedone = true;
								$update = array('lastchecked' => $time, 'lasttouched' => $time);
								if($url) {
									$update['url'] = trim($url);
									$update['status'] = 0;
									$update['resolvedate'] = $time;
									
									log_event('Received URL for '.$ssite);
								} elseif($url === null || ($url === false && $link['added'] < $time-3600*18)) {
									// link is *dead* or hasn't resolved in 18 hours -> mark bad
									$update['status'] = 3;
									
									log_event('Bad link for '.$ssite);
								} else {
									log_event('Still waiting for '.$ssite);
								}
								filelinks_mod($fid, $ssite, $part, $update);
								unset($link2[$ssite]);
							}
							if(!$cursitedone) {
								// resolve status didn't grab status for current link - something's not right... -> mark bad
								log_event("Didn't get response for requested site - marking link bad");
								filelinks_mod($fid, $site, $part, array('lastchecked' => $time, 'lasttouched' => $time, 'status' => 3));
								unset($link2[$site]);
							}
						} else {
							log_event('Didn\'t get status response');
							if($link['added'] < $time-3600*24) {
								// can't resolve after 24hrs -> consider dead
								filelinks_mod($fid, $site, $part, array('lastchecked' => $time, 'status' => 3));
							} else {
								// don't choke us - push a 10 min delay before retrying
								filelinks_mod($fid, $site, $part, array('lastchecked' => $time + 600, 'status' => 1));
							}
						}
					} else
						warning('[linkresolve] Can\'t get ID from URL '.$link['url'].' (ID:'.$link['id'].')'); // should never happen
				} else
					warning('[linkresolve] No uploader found for URL '.$link['url'].' (ID:'.$link['id'].')'); // should never happen
			} // end loops
		}
	
	log_event('Resolving done');
	
	unset($uploader_mf);
}

