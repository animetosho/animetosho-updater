<?php
if(PHP_SAPI != 'cli') die;
if($argc<3) die("Syntax: [script] tracker-announce hash1 hash2 ...\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'funcs.php';
require ROOT_DIR.'includes/scrape.php';

array_shift($argv);
$tracker = array_shift($argv);

function SCRAPE_DBG($msg, $data=null) {
	echo $msg, "\n";
	if(isset($data)) var_dump($data);
}

var_dump(scrape($tracker, array_map('hex2bin', $argv)));
