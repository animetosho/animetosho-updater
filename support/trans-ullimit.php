<?php
if(PHP_SAPI != 'cli') die;

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(dirname(__FILE__)).'/');

require ROOT_DIR.'init.php';
require ROOT_DIR.'3rdparty/TransmissionRPC.class.php';

make_lock_file(substr(THIS_SCRIPT, 5, -4));



/********* CONFIGURATION *********/
// ratio shaping upload speed limits
// the default config means: if ratio is >1, then limit upload to 100KB/s, if ratio >1.5, limit to 50KB/s etc
// use 0 as speed value for no limit
// (note, keys need to be strings, otherwise PHP interprets it as a numeric index)
$ratioshape = array(
	'1.0' => 200,
	'1.5' => 100,
	'2.0' => 50,
);

// what ratio is denominated against (default is amount uploaded / amount to download)
$ratioStat = 'sizeWhenDone'; // can be sizeWhenDone, totalSize or downloadedEver

$ignoreHighPrio = true; // true to ignore limits on High Priority items
/*********  *********/



// sort ratio levels for convenience later on
krsort($ratioshape, SORT_NUMERIC);

try {
	// grab list of torrents in transmission
	$transmission = get_transmission_rpc();
	if(!isset($transmission)) die('Failed to connect to transmission');
	$torrents = $transmission->get(array(), array('id', 'status', 'bandwidthPriority', 'peer-limit', 'uploadLimit', 'uploadLimited', 'uploadedEver', $ratioStat));
	
	// traverse list of torrents
	if(!empty($torrents->arguments->torrents)) foreach($torrents->arguments->torrents as &$torrent) {
		if(!isset($torrent->status)) $torrent->status = TransmissionRPC::RPC_LT_14_TR_STATUS_STOPPED; // TODO: is this still relevant?
		
		if(($torrent->status == TransmissionRPC::TR_STATUS_SEED || $torrent->status == TransmissionRPC::TR_STATUS_SEED_WAIT) && isset($torrent->uploadLimited)) {
			// this condition used to check `@$torrent->bandwidthPriority < 0`, but since we always lower priority of completed, this doesn't make sense any more
			// if we're managing seed bandwidth by priority, remove the speed limit
			$transmission->set($torrent->id, array('uploadLimited' => false));
		}
		
		if($torrent->status != TransmissionRPC::TR_STATUS_DOWNLOAD) continue; // only consider active, non-seeding, torrents
		if($ignoreHighPrio && @$torrent->bandwidthPriority > 0) continue; // ignore High priority items if desired
		
		if(!isset($torrent->uploadLimited)) $torrent->uploadLimited = false;
		if(!isset($torrent->uploadedEver)) $torrent->uploadedEver = 0;
		
		if(@$torrent->$ratioStat > 0) { // we cannot divide by 0, ever
			$ratio = $torrent->uploadedEver / $torrent->$ratioStat;
			foreach($ratioshape as $level => $action) {
				if($ratio > $level) { // hit a ratio threadhold level
					
					// perform action
					if($action) {
						if(!$torrent->uploadLimited || $torrent->uploadLimit > $action)
							$transmission->set($torrent->id, array('uploadLimited' => true, 'uploadLimit' => $action));
					}
					elseif($torrent->uploadLimited) // action=0 -> no limit
						$transmission->set($torrent->id, array('uploadLimited' => false));
					
					break;
				}
			}
		}
	}
	
	
	// if we're doing a lot of uploads, limit Transmission's global upload
	loadDb();
	$uploads = $db->selectGetField('ulqueue', 'COUNT(*)', 'status=1');
	$transmission->sset(['speed-limit-up-enabled' => ($uploads>3 ? 1:0)]);
	
} catch(Exception $ex) {
	echo "Exception ".$ex->getMessage()." occurred.\n";
}
unset($transmission);

