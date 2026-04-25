<?php

define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');
define('TIME_SKEW', 3600*36); // define something big to handle potential issues


// grab timebase
$timebase_file = ROOT_DIR.'timebase.txt';
if(!@touch($timebase_file)) exit;
if(!($fp = @fopen($timebase_file, 'r+'))) exit;
if(!@flock($fp, LOCK_EX+LOCK_NB)) exit;

$timebase = intval(fread($fp, 30)) - TIME_SKEW;
// sanity check
if($timebase < 0) $timebase = time() - TIME_SKEW;

require ROOT_DIR.'init.php';

// don't run cron if not much space left
// not the best methodology for preventing disk being full (torrents can hurt), but better than nothing
if(low_disk_space()) {
	fclose($fp);
	exit;
}



loadDb();
unset($config);

@set_time_limit(600);
@ini_set('memory_limit', '384M'); // for BDecode inefficiencies
require ROOT_DIR.'includes/releasesrc.php';

require_once ROOT_DIR.'releasesrc/nyaasi.php';
require_once ROOT_DIR.'releasesrc/toto.php';
require_once ROOT_DIR.'releasesrc/nekobt.php';
$success = true;
try {
	
	foreach($nyaasi_cats as $cat) {
		// refresh transmission on every loop cycle
		$transmission = get_transmission_rpc();
		if(!$transmission || !nyaasi_run_from_scrape($timebase, '', $cat)) { // effectively, failure = retry
			$success = false;
			break;
		}
	}
	foreach($nyaasis_cats as $cat) {
		$transmission = get_transmission_rpc();
		if(!$transmission || !nyaasi_run_from_scrape($timebase, 'sukebei', $cat)) { // effectively, failure = retry
			$success = false;
			break;
		}
	}
	
	// Toto
	{
		$transmission = get_transmission_rpc();
		if(!$transmission || !toto_run_from_scrape($timebase)) { // effectively, failure = retry
			$success = false;
		}
	}
	
	// nekoBT
	{
		$transmission = get_transmission_rpc();
		if(!$transmission || !nekobt_run_from_scrape($timebase))
			$success = false;
	}
	
} catch(TransmissionRPCException $e) {
	// failed, do nothing for now
	$success = false;
}

if($success) {
	// update timebase
	fseek($fp, 0);
	fwrite($fp, $curtime);
}
fclose($fp);

unset($db);
