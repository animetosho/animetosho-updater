<?php

define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

define('DEFAULT_ERROR_HANDLER', 1);
require ROOT_DIR.'init.php';

loadDb();
unset($config);


function ANIDB_DBG() {
	$args = func_get_args();
	echo implode(' ', array_map(function($a) {
		if(is_scalar($a))
			return (string)$a;
		return print_r($a, true);
	}, $args)), "\n";
}


require ROOT_DIR.'anifuncs.php';
ini_set('memory_limit', '256M');


array_shift($argv);
$parse = false;
if($argv[0] == '-p') {
	$parse = true;
	array_shift($argv);
}


if($parse) {
	$parsed = parse_filename($argv[0], false);
	if(empty($parsed) || !isset($parsed['title'])) {
		echo 'Could not parse title';
		die(var_dump($parsed));
	}
	$hints = ['noep' => $parsed['noep']];
	if(isset($parsed['title_alt'])) $hints['title_alt'] = $parsed['title_alt'];
	$result = anidb_search_anime($parsed['title'], $hints);
} else
	$result = anidb_search_anime($argv[0]);

// pull anime names
if(is_array($result) && !empty($result)) {
	foreach($db->selectGetAll('anidb.animetitle', 'id', 'aid IN ('.implode(',', array_keys($result)).')') as $at) {
		$aid = $at['aid'];
		if(!isset($result[$aid]['titles'])) $result[$aid]['titles'] = [];
		$result[$aid]['titles'][] = $at['name'];
	}
}

var_dump($result);
