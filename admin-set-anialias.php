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
if(count($argv) != 2)
	die("Syntax: {name id | -d name}\n");

if($argv[0] == '-d') {
	$result = $db->delete('adb_aniname_alias', 'name='.$db->escape($argv[1]));
	echo "$result row(s) deleted\n";
} else {
	[$name, $id] = $argv;
	$db->insert('adb_aniname_alias', ['name' => $name, 'aid' => $id]);
}
