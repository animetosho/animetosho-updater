<?php
if(PHP_SAPI != 'cli') die;

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';
loadDb();
unset($config);

require ROOT_DIR.'anifuncs.php';


array_shift($argv);

// syntax: [-e|-n] [-f] name id
// -e: noep=0, -n: noep=1, otherwise both
// -f: force
// syntax: [-e|-n] -d name

$noep = [0,1];
$force = false;
$delete = false;
while(isset($argv[0])) {
	if($argv[0] == '-e')
		$noep = [0];
	elseif($argv[0] == '-n')
		$noep = [1];
	elseif($argv[0] == '-f')
		$force = true;
	elseif($argv[0] == '-d')
		$delete = true;
	else break;
	array_shift($argv);
}

if($delete) {
	if(count($argv) != 1)
		die("Syntax: [-e|-n] -d name\n");
	
	$name = $argv[0];
	$where = 'nameid='.$db->escape($name).' and noep IN('.implode(',', $noep).')';
	$exists = $db->selectGetAll('adb_aniname_map', 'noep', $where);
	if(!$exists) die("Not found\n");
	$db->delete('adb_aniname_map', $where);
	foreach($exists as $entry)
		echo "Removed $name -> $entry[aid] (noep=$entry[noep])\n";
	return;
}

if(count($argv) != 2)
	die("Syntax: [-e|-n] [-f] name id  |  [-e|-n] -d name\n");

[$name, $id] = $argv;
$name = filename_id($name);
$id = (int)$id;

if(!$force) {
	// check if exists before replacing
	$exists = $db->selectGetArray('adb_aniname_map', 'nameid='.$db->escape($name).' and noep IN('.implode(',', $noep).')');
	if($exists) {
		if($exists['autoadd'])
			echo "Replacing existing entry $exists[nameid] ($exists[noep]) -> $exists[aid]\n";
		else
			die("Entry already exists: $exists[nameid] ($exists[noep]) -> $exists[aid]\n");
	}
}

$db->insertMulti('adb_aniname_map', array_map(function($ne) use($name, $id) {
	return [
		'nameid' => $name,
		'noep' => $ne,
		'aid' => $id,
		'autoadd' => 0
	];
}, $noep), true);

echo "Added $name -> $id\n";
