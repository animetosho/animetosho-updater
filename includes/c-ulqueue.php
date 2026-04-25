<?php

require_once ROOT_DIR.'init.php';
if(!sema_lock('uploader')) return;

$ulQ_processor = substr(THIS_SCRIPT, 5, -4);
make_lock_file($ulQ_processor);

require ROOT_DIR.'includes/filelinks.php';
require ROOT_DIR.'includes/ulfuncs.php';
if(!isset($db)) {
	loadDb();
}
unset($config);


$time = time();
@set_time_limit(60);

require ROOT_DIR.'includes/miniwebcore.php';
require ROOT_DIR.'includes/releasesrc_skip.php';

if(!isset($order) || !$order) $order = 'priority DESC, IF(retry_sites="",500,LENGTH(retry_sites)) DESC, dateline ASC';
if(!isset($where) || !$where) $where = '1=1';

// disabled slow hashes: TTH
$calchashes = array( // crc32 + ed2k must be set
	'crc32', 'md5', 'sha1', 'ed2k', 'sha256', 'bt2',
);
$calctorpc = [16, 32, 64, 128, 256, 512, 1024, 2048, 4096, 8192, 16384];

$reshuffle_skip = '';
$time_offs = 0;
for($i=0; $i<10; ++$i) { // we'll limit to processing 10 files per process...
	// get next thing to do
	$qWhere = $where;
	if($reshuffle_skip) $qWhere .= ' AND ulqueue.id NOT IN ('.$reshuffle_skip.')';
	$file = $db->selectGetArray('ulqueue', 'status=0 AND ulqueue.dateline<='.(time()+$time_offs).' AND '.$qWhere, 'ulqueue.*, files.'.implode(', files.', $calchashes).', files_extra.torpc_sha1_'.reset($calctorpc).'k', array('order' => $order, 'joins' => array_merge(
		[['inner', 'files', 'fid', 'id'], ['left', 'files_extra', 'fid', 'fid']],
		$extra_joins ?? []
	)));
	if(empty($file)) break; // end of queue
	if($db->update('ulqueue', array('status' => 1, 'last_processor' => $ulQ_processor), 'id='.$file['id'].' AND status=0') != 1) {
		--$i;
		continue; // race condition conflict, continue
	}
	
	$toto = $db->selectGetArray('toto', 'id='.$file['toto_id']);
	
	$failed_services = $skipped_services = array();
	$upload_count = 0; // actual number of services we attempted to upload to
	$dir = TOTO_ULQUEUE_PATH.'file_'.$file['fid'].'/';
	$filename = $dir.$file['filename'];
	
	// is this file marked as deleted? if so, defer processing this
	$skip_file = false;
	if($toto['deleted']) {
		if($time - $toto['completed_date'] > 86400) {
			$skip_file = true;
		} else {
			$db->update('ulqueue', array(
				'dateline' => time() + 3600,
				'status' => 0,
				'last_processor' => '~'.$ulQ_processor
			), 'id='.$file['id']);
			continue;
		}
	}
	if(!file_exists($filename)) {
		error('[ulqueue] File does not exist!!! '.$filename);
		$skip_file = true;
	}
	if(!$skip_file) {
		$file_full_url = AT::viewUrl($toto);
		$file_short_url = AT::viewUrl($toto, array(), true);
		$filedesc = 'More info @ '.$file_full_url;
		// limit description to 60 chars
		if(isset($filedesc[60]))
			$filedesc = 'More info @ '.$file_short_url;
		
		
		$upfiles = array($filename => $filedesc);
		
		
		$ffilesize = (float)filesize_big($filename);
		
		$ultype = '';
		switch(get_extension($filename)) {
			//case 'gif':
			case 'jpeg':
			case 'jpg':
			case 'jpe':
			case 'png':
			case 'ico':
			case 'tif':
			case 'tiff':
				if($ffilesize < 5*1024*1024)// only bother uploading if <5MB
					$ultype = 'image';
				$ultype = ''; // disable image upload handling - upload images like normal files
				break;
			case 'url':
				$ultype = 'url';
				$link_url = @file_get_contents($filename, false, null, 0, 2048);
				// handle windows shortcut parsing
				if(stripos($link_url, '[InternetShortcut]') !== false) {
					if(preg_match("~\nURL\=(http[^\r\n]+)~i", $link_url, $m))
						$link_url = $m[1];
					else
						$link_url = '';
				}
				if(preg_match('~^(?:ht|f)tps?\://~', $link_url)) {
					filelinks_add(array(
						$file['fid'] => array(
							'Link' => array(array(
								'url' => substr($link_url, 0, 768)
							))
						)
					));
				}
				break;
		}
		
		
		if($ultype == 'image') {
			// TODO: eventually make image uploaders like regular uploaders
			if(!isset($uploader_img)) {
				require_once ROOT_DIR.'uploaders/image.php';
				$uploader_img = new uploader_Image;
				$uploader_img->ul_someimage = true;
			}
			$imglinks = array();
			$failed = send_to_uploader($uploader_img, $upfiles, $imglinks, true);
			if(empty($failed)) {
				save_links($imglinks, $file['fid']);
			} else {
				// currently no retries
			}
		} elseif(!$ultype) {
			$do_services = array();
			$file['retry_sites'] = strtolower($file['retry_sites']);
			if($file['retry_sites']) {
				foreach(explode(',', $file['retry_sites']) as $svc) {
					@list($service, $failed) = explode(':', $svc, 2);
					$do_services[$service] = (int)$failed;
				}
			}
			
			// do we need to calc hashes?
			$do_hashing = !isset($file['torpc_sha1_'.reset($calctorpc).'k']);
			foreach($calchashes as $hash) {
				if(!isset($file[$hash])) {
					$do_hashing = true;
					break;
				}
			}
			
			// $retry array: [immediate retry #, delayed retry #, # recent failures before delay, failed error log]
			// TODO: merge stuff into $opts
			$do_uploader_send_multi = function($site, $services, $namemap, $linkpage, $resolve, $retry=array(2,4,3,''), $z7wrapper=false, $opts=array()) use($upfiles, &$file, &$failed_services, &$skipped_services, &$upload_count, &$do_services, &$db, &$calchashes, &$calctorpc, &$do_hashing, $file_short_url, $ulQ_processor) {
				$num_retries = (int)@$do_services[strtolower($site)];
				
				// check status of this host
				$hostinfo = $db->selectGetArray('ulhosts', 'site='.$db->escape(strtolower($site)));
				if(empty($hostinfo)) {
					$db->insert('ulhosts', array('site' => $site), false, true);
				} else {
					if($hostinfo['defer'] > time()) {
						// defer uploading to this host
						$failed_services[strtolower($site)] = $num_retries;
						return;
					}
				}
				
				// check num recent failures for this host
				$host_failures = $db->selectGetField('ulqueue_failures', 'COUNT(*)', 'site='.$db->escape(strtolower($site)).' AND dateline>'.(time()-7200));
				if($host_failures >= $retry[2]) {
					// ~3 failures in last 2 hours; don't upload - it'll probably be a waste of bandwidth
					$failed_services[strtolower($site)] = $num_retries;
					return;
				}
				// alternatively, don't overload a host - limit number of parallel procs for a single host to 3
				// - primarily useful for really long queues
				$host_load = $db->selectGetField('ulqueue_status', 'COUNT(*)', 'site='.$db->escape(strtolower($site)));
				if($host_load > (@$opts['maxload'] ?: 3)) {
					// 3 procs currently uploading to this host - delay retry
					$failed_services[strtolower($site)] = $num_retries;
					$skipped_services[strtolower($site)] = 1; // very ugly hack; marking this as failed may cause a delay - our workaround is that if all "failed" services are really skipped, don't add a delay
					return;
				}
				
				$retry_on_failure = ($num_retries < $retry[1]);
				$class = 'uploader_'.$site;
				$uploader = new $class;
				$uploader->setDb($db);
				$uploader->upload_sockets_retries = $retry[0];
				$uploader->upload_sockets_error_delay = ($retry_on_failure ? 'uploader_delayed':$retry[3]);
				$uploader->upload_sockets_break_on_failure = true;
				if(method_exists($uploader, 'setServices')) {
					$uploader->return_servicelinks = true;
					$uploader->return_linkpage = $linkpage;
					$uploader->setServices($services);
				}
				$uploader->upload_sockets_speed_rpt = function($speed, $pos=0) use($db, $ulQ_processor) {
					$db->update('ulqueue_status', array('speed' => $speed/1024, 'uploaded' => $pos), 'proc='.$db->escape($ulQ_processor));
				};
				$file_handler = null;
				$uploader->upload_sockets_file_wrapper = function($fn) use($z7wrapper, $do_hashing, $calchashes, $calctorpc, &$file_handler, $file, $file_short_url) {
					if($z7wrapper === 'pwd') {
						// encrypted upload
						require_once ROOT_DIR.'uploaders/filehandler_7z.php';
						$file_handler = new FileHandler_7z($fn, $file_short_url);
						// generate scrambled filename
						$file_handler->basename = substr(sha1('file_'.$file['fid']), 0, 16).'.7z';
					} elseif($z7wrapper) {
						require_once ROOT_DIR.'uploaders/filehandler_7z.php';
						$file_handler = new FileHandler_7z($fn, true);
						// generate custom filename
						$custom_name = 'file_'.$file['fid'];
						if(preg_match('~\[([a-fA-F0-9]{8})\]~', $fn, $m) || preg_match('~\(([a-fA-F0-9]{8})\)~', $fn, $m))
							// use CRC
							$custom_name .= '_('.strtolower($m[1]).')';
						$file_handler->basename = $custom_name.'.7z';
					} else {
						require_once ROOT_DIR.'uploaders/filehandler.php';
						$file_handler = new FileHandler($fn);
					}
					foreach($calchashes as $hash) { // the 7z handler uses the crc32 hash, otherwise this probably isn't really necessary
						if(isset($file[$hash])) {
							$file_handler->hashes[$hash] = $file[$hash];
						}
					}
					if($do_hashing) {
						$file_handler->init_hashes(array_merge(
							$calchashes, array_map(function($torpc) {
								return 'torpc_sha1_'.$torpc.'k';
							}, $calctorpc)
						));
					}
					return $file_handler;
				};
				$links = array();
				$failed = send_to_uploader($uploader, $upfiles, $links, $resolve, $namemap);
				if($do_hashing && isset($file_handler) && !empty($file_handler->hashes)) {
					$file_hashes = []; $torpc_hashes = [];
					foreach($file_handler->hashes as $hk=>$hv) {
						if(substr($hk, 0, 10) == 'torpc_sha1')
							$torpc_hashes[$hk] = $hv;
						else
							$file_hashes[$hk] = $hv;
						$file[$hk] = $hv;
					}
					// save hashes
					if(!empty($file_hashes))
						$db->update('files', $file_hashes, 'id='.$file['fid']);
					if(!empty($torpc_hashes))
						$db->upsert('files_extra', ['fid' => $file['fid']], $torpc_hashes);
					// may need to update resolve queue too
					if($file['fid'] == $db->selectGetField('toto', 'sigfid', 'id='.$file['toto_id'])) {
						$db->update('adb_resolve_queue', array(
							'crc32' => $file_handler->hashes['crc32'],
							'ed2k' => $file_handler->hashes['ed2k'],
						), 'toto_id='.$file['toto_id']);
					}
					$do_hashing = false;
				}
				unset($file_handler, $uploader);
				if(!empty($failed)) {
					// record failure
					$time = time();
					$db->insert('ulqueue_failures', array(
						'site' => strtolower($site),
						'dateline' => $time,
						'fid' => $file['fid']
					));
					// randomly clear out old entries
					static $clrdone = false;
					if(!$clrdone) {
						$clrdone = true;
						if(!mt_rand(0,20)) {
							$db->delete('ulqueue_failures', 'dateline<'.($time-86400));
						}
					}
				} else {
					// success -> clear out some failures
					// (this may clear some irrelevant entries, that is, those >1 day old, but we'll ignore that)
					$db->delete('ulqueue_failures', 'site='.$db->escape($site), 2, 'dateline ASC');
				}
				if(empty($failed) || !$retry_on_failure) {
					save_links($links, $file['fid'], $z7wrapper==='pwd');
					unset($do_services[strtolower($site)]);
				} else {
					$failed_services[strtolower($site)] = $num_retries+1;
					$do_services[strtolower($site)] = $num_retries+1;
				}
				++$upload_count;
			};
			// 'site' is the main identifier (including uploader class name), whereas 'name' is what's displayed to user
			$do_uploader_send_single = function($site, $shortname, $name, $resolve, $retry=array(2,2,3,''), $z7wrapper=false, $opts=array()) use($do_uploader_send_multi) {
				return $do_uploader_send_multi($site, array(), array($shortname => $name), false, $resolve, $retry, $z7wrapper, $opts);
			};
			
			
			$uploadhosts = include(ROOT_DIR.'includes/uploadhosts.php');
			
			// generate list of retry_sites first
			$do_all_services = empty($do_services);
			foreach($uploadhosts as $uploadhost => $uhinfo) {
				if(isset($uhinfo['maxsize']))
					$fscheck = ($ffilesize < $uhinfo['maxsize']*1024*1024);
				else
					$fscheck = true;
				if(isset($uhinfo['minsize']))
					$fscheck = ($fscheck && ($ffilesize > $uhinfo['minsize']));
				if(isset($uhinfo['minprio']) && $file['priority'] < $uhinfo['minprio'])
					$fscheck = false; // re-use this cause I'm lazy
				
				if(!isset($do_services[$uploadhost]) && (!$do_all_services || !$fscheck)) {
					unset($uploadhosts[$uploadhost]);
				}
				elseif(!isset($do_services[$uploadhost]))
					$do_services[$uploadhost] = 0;
			}
			foreach(shuffle_assoc($uploadhosts) as $uploadhost => $uhinfo) {
				// save current progress to DB (in case script crashes etc)
				$db->update('ulqueue', array('retry_sites' => fmtRetrySites($do_services)), 'id='.$file['id']);
				$db->insert('ulqueue_status', array('proc' => $ulQ_processor, 'ulq_id' => $file['id'], 'site' => strtolower($uploadhost), 'started' => time()), true);
				@include_once ROOT_DIR.'uploaders/'.$uploadhost.'.php';
				
				if(isset($uhinfo['multiargs'])) {
					$args = $uhinfo['multiargs'];
					if(is_callable($args[1])) {
						$hostsFunc = $args[1];
						$args[1] = $hostsFunc($ffilesize);
					}
					call_user_func_array($do_uploader_send_multi, $args);
				}
				if(isset($uhinfo['singleargs']))
					call_user_func_array($do_uploader_send_single, $uhinfo['singleargs']);
			}
		}
	} else {
		info('Skipping '.$file['fid'], 'ulqueue-skip');
	}
	
	if(empty($failed_services)) {
		// delete file, only if nothing else references it
		if(!$db->selectGetField('ulqueue', 'id', 'fid='.$file['fid'].' AND id!='.$file['id'])) { // is this race condition safe? probably doesn't matter...
			unlink($filename);
			rmdir($dir);
		}
		$db->delete('ulqueue', 'id='.$file['id']);
		$time_offs = 60; // if we successfully completed this, be a little more eager in searching for entries; mainly, this is to avoid reshuffling delay
	} else {
		// delayed retry
		$reshuffled = (count($skipped_services) == count($failed_services)) || (!empty($skipped_services) && !$upload_count);
		$db->update('ulqueue', array(
			'dateline' => time() + ($reshuffled ? 60 : 3600),
			'retry_sites' => fmtRetrySites($failed_services),
			'status' => 0,
			'last_processor' => '~'.$ulQ_processor
		), 'id='.$file['id']);
		
		if($reshuffled)
			$reshuffle_skip .= ($reshuffle_skip?',':'') . $file['id'];
		if(!$upload_count)
			--$i; // don't hold up the processing queue if we're just reshuffling this entry
	}
	$db->delete('ulqueue_status', 'proc='.$db->escape($ulQ_processor));
}

function save_links($links, $fid, $encrypted=false) {
	// run some integrity checks
	if(empty($links))
		warning('[ulqueue-savelinks] No links sent (fid='.$fid.')');
	elseif(count($links) != 1)
		warning('[ulqueue-savelinks] Multiple filenames sent! (fid='.$fid.')'.log_dump_data($links, 'ulqueue-savelinks'));
	else {
		$link = reset($links);
		if(empty($link))
			warning('[ulqueue-savelinks] Empty link sent! (fid='.$fid.')'.log_dump_data($links, 'ulqueue-savelinks'));
	}
	
	if(!empty($links)) {
		$links = reset($links); // dereference filename component
		if(!empty($links)) {
			// reformat links to new structure
			$sites = array();
			foreach($links as $linkurl) {
				$site =& $sites[$linkurl['site']];
				if(!isset($site)) $site = array();
				$part = ($linkurl['part'] ?: 1) -1;
				
				$site[$part] = $linkurl;
			} unset($site);
			// need to ensure parts are sorted
			foreach($sites as &$site) {
				ksort($site);
				// sanity check
				end($site);
				$lastpart = key($site);
				if($lastpart != count($site)-1) {
					// this should actually never happen...
					// start padding parts
					for($j=0; $j<$lastpart; ++$j) {
						if(!isset($site[$j]))
							$site[$j] = 0;
					}
					ksort($site);
				}
				$site['encrypted'] = $encrypted;
			} unset($site);
			filelinks_add(array($fid => $sites));
		}
	}
}

function fmtRetrySites($retry_services) {
	ksort($retry_services);
	foreach($retry_services as $svc => &$times)
		$times = $svc.':'.$times;
	return implode(',', $retry_services);
}

// from http://php.net/manual/en/function.shuffle.php#104430
function shuffle_assoc( $array )
{
	$keys = array_keys( $array );
	shuffle( $keys );
	return array_merge( array_flip( $keys ) , $array );
}
