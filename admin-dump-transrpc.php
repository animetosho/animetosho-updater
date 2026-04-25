<?php
if(PHP_SAPI != 'cli') die;

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';
$transmission = get_transmission_rpc();
@ini_set('memory_limit', '384M');
if(!$transmission) {
	die("failed to connect to transmission\n");
}
$torrents = $transmission->get(array(), array(
	'activityDate',
	'addedDate',
	'bandwidthPriority',
	'comment',
	'corruptEver',
	'creator',
	'dateCreated',
	'desiredAvailable',
	'doneDate',
	'downloadDir',
	'downloadedEver',
	'downloadLimit',
	'downloadLimited',
	'error',
	'errorString',
	'eta',
	'etaIdle', // 2.80+
	'hashString',
	'haveUnchecked',
	'haveValid',
	'honorsSessionLimits',
	'id',
	'isFinished',
	'isPrivate',
	'isStalled',
	'leftUntilDone',
	'magnetLink',
	'manualAnnounceTime',
	'maxConnectedPeers',
	'metadataPercentComplete',
	'name',
	'peer-limit',
	'peersConnected',
	'peersGettingFromUs',
	'peersSendingToUs',
	'percentDone',
	'pieceCount',
	'pieceSize',
	'queuePosition',
	'rateDownload (B/s)',
	'rateUpload (B/s)',
	'recheckProgress',
	'secondsDownloading',
	'secondsSeeding',
	'seedIdleLimit',
	'seedIdleMode',
	'seedRatioLimit',
	'seedRatioMode',
	'sizeWhenDone',
	'startDate',
	'status',
	'totalSize',
	'torrentFile',
	'uploadedEver',
	'uploadLimit',
	'uploadLimited',
	'uploadRatio',
	'webseedsSendingToUs',
	
	// arrays
	'files',
	'fileStats',
	'peers',
	'peersFrom',
	'pieces',
	'priorities',
	'trackers',
	'trackerStats',
	'wanted',
	'webseeds',
	
	// deprecated
	'peersKnown', // 2.30
	
	//'id', 'name', 'status', 'doneDate', 'percentDone', 'hashString', 'rateDownload', 'rateUpload', 'eta', 'addedDate', 'startDate'
));
print_r($torrents);
