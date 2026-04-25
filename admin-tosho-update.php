<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("No id supplied\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';
loadDb();
unset($config);


array_shift($argv);
$argv = array_unique($argv);

require ROOT_DIR.'includes/releasesrc.php';
require ROOT_DIR.'releasesrc/toto.php';


foreach($argv as $id) {
	echo "Tosho update: $id";
	$info = toto_get_detail($id, true);
	if(!empty($info)) {
		if($db->selectGetField('arcscrape.tosho_torrents', 'id', 'id='.$id)) {
			echo " - replaced\n";
		} else {
			echo " - added\n";
		}
		$db->insert('arcscrape.tosho_torrents', fmt_toto_info($info), true);
	} elseif($info === null) {
		echo " - deleted\n";
		$db->update('arcscrape.tosho_torrents', ['deleted' => 1], 'id='.$id);
	} else
		echo " - ERROR\n";
	sleep(5);
}
