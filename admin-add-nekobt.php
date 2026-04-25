<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("No nekobt id supplied\n");

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
require ROOT_DIR.'releasesrc/nekobt.php';


$transmission = get_transmission_rpc();
if(!$transmission) {
	die("failed to connect to transmission\n");
}


$ids = [];
foreach($argv as $id) {
	$id = (int)$id;
	if(!$id) {
		echo "Skipping an entry because it isn't a valid ID\n";
		continue;
	}
	$ids[] = $id;
}

$totos = $db->selectGetAll('toto', 'nekobt_id', 'nekobt_id IN('.implode(',', $ids).')', 'nekobt_id');
$new = $db->selectGetAll('arcscrape.nekobt_torrents', 'id', 'id IN('.implode(',', $ids).')');
foreach($ids as $id) {
	if(isset($totos[$id])) {
		echo "ID $id already exists - moving along...\n";
		continue;
	}
	if(!isset($new[$id])) {
		echo "ID $id not found - moving along...\n";
		continue;
	}
	
	$item = $new[$id];
	if($item['imported']) {
		$rows = $db->update('toto', ['nekobt_id' => $id], 'nyaa_id='.$item['imported']);
		echo "ID $id imported from Nyaa $item[imported] - updated ID on $rows rows...\n";
	} else {
		echo "Adding $id...";
		$rel = nekobt_to_rowinfo($item);
		$item['source'] = 'nekobt';
		releasesrc_add($rel, 'toto_', $noskip, $item);
		echo "done\n";
	}
}
