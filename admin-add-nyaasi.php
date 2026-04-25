<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("No nyaa id supplied\n");

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
$argv = array_unique($argv);



require ROOT_DIR.'includes/releasesrc.php';
require ROOT_DIR.'releasesrc/nyaasi.php';


$transmission = get_transmission_rpc();
if(!$transmission) {
	die("failed to connect to transmission\n");
}



foreach($argv as $id) {
	$subdom = '';
	if($id[0] == 's') {
		$subdom = 'sukebei';
		$id = (int)substr($id, 1);
	} else
		$id = (int)$id;
	if(!$id) {
		echo "Skipping an entry because it isn't a valid ID\n";
		continue;
	}
	$toto = $db->selectGetArray('toto', 'nyaa_id='.$id.' AND nyaa_subdom='.$db->escape($subdom));
	if(!empty($toto)) {
		echo "ID $id already exists - moving along...\n";
		continue;
	}
	
	echo "Adding $id...";
	nyaasi_add($id, $subdom, $noskip);
	echo "done\n";
}
