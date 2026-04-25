<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("Syntax: [script] ID_or_timestamp\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

loadDb();
unset($config);

require ROOT_DIR.'releasesrc/nekobt.php';

$num = (int)$argv[1];
if($num > 0xffffffff) {
	// assume ID
	$ts = nekobt_id_to_timestamp($num);
	var_dump($ts);
	echo date('r', $ts), "\n";
}
else {
	var_dump(nekobt_timestamp_to_id($num));
}