<?php

if(PHP_SAPI != 'cli') die;
if($argc<2) die("No mode supplied\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';
loadDb();
unset($config);


// TODO: dedupe from cron-arcscrape
function get_last_id_from_feed($rss, $tag='guid') {
	if(!empty($rss)) {
		$lastLink = trim(reset($rss)[$tag]);
		if(preg_match('~^https?://[a-z.]+/(?:[a-z]+/|[a-z.]+\?id=)(\d+)(?:$|/)~', $lastLink, $m))
			return (int)$m[1];
		else
			die("Could not get last ID from feed\n");
	} else die("Could not retrieve feed\n");
}


if($argv[1] == 'nyaasi' || $argv[1] == 'nyaasis') {
	require ROOT_DIR.'releasesrc/nyaasi.php';
	$feedData = parse_feed($argv[1] == 'nyaasis' ? 'https://sukebei.nyaa.si/rss' : 'https://nyaa.si/rss');
	$changes = nyaasi_changes_from_feed($feedData, '', get_last_id_from_feed($feedData)+1);
}
elseif($argv[1] == 'tosho') {
	require ROOT_DIR.'releasesrc/toto.php';
	$feedData = parse_feed('https://www.tokyotosho.info/rss.php');
	$changes = toto_changes_from_feed($feedData, get_last_id_from_feed($feedData)+1);
	
}
elseif($argv[1] == 'nekobt') {
	require ROOT_DIR.'releasesrc/nekobt.php';
	$latestItems = nekobt_query_latest();
	if(empty($latestItems)) die("No new items returned\n");
	$changes = nekobt_changes_from_latest($latestItems, reset($latestItems)->id+1);
}
else
	die("Unknown source\n");

var_dump($changes);
