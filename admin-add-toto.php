<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("No toto id supplied\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';
loadDb();
unset($config);


array_shift($argv);
$noskip = false;
if($argv[0] == '-f') {
	$noskip = true;
	array_shift($argv);
}
$argv = array_unique(array_map('intval', $argv));



require ROOT_DIR.'includes/releasesrc.php';
require ROOT_DIR.'releasesrc/toto.php';


$transmission = get_transmission_rpc();
if(!$transmission) {
	die("failed to connect to transmission\n");
}



foreach($argv as $id) {
	$toto = $db->selectGetArray('toto', 'tosho_id='.$id);
	if(!empty($toto)) {
		echo "ID $id already exists - moving along...\n";
		continue;
	}
	
	$toto = toto_get_cached($id);
	if(empty($toto)) {
		echo "ID $id can't be found\n";
		continue;
	}
	
	echo "Adding $id...";
	toto_add($toto, $noskip);
	echo "done\n";
}
