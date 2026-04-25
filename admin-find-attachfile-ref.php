<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("Syntax: [script] attachfile-id [attachfile-id] [...]\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

loadDb();
unset($config);

array_shift($argv);
$afids = array_map('intval', $argv);

require_once ROOT_DIR.'includes/admin-funcs.php';
require_once ROOT_DIR.'includes/finfo-compress.php';
require_once ROOT_DIR.'includes/attach-info.php';

$refs = find_attachfile_refs($afids);
if(empty($refs))
	echo "Not found\n";
elseif(count($afids) == 1)
	echo implode(',', reset($refs)), "\n";
else foreach($refs as $afid => $r) {
	echo $afid, ': ', implode(',', $r), "\n";
}
