<?php

define('ROOT_DIR', dirname(__FILE__).'/');
define('THIS_SCRIPT', basename(__FILE__));
define('DEFAULT_ERROR_HANDLER', 1);
require ROOT_DIR.'init.php';

loadDb();
unset($config);
require ROOT_DIR.'anifuncs.php';

array_shift($argv);
$batch = false;
if($argv[0] == '-b') {
	$batch = true;
	array_shift($argv);
}

$ans = parse_filename($argv[0], $batch);
if($ans['title'])
	$ans['filename_id'] = filename_id($ans['title']);
var_dump($ans);
