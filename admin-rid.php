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
$argv = array_unique(array_map('intval', $argv));

$data = $db->selectGetAll('toto', 'id', 'id IN ('.implode(',', $argv).')', 'id,tosho_id,nyaa_id,nyaa_subdom,anidex_id,nekobt_id');
foreach($argv as $input_id) {
	echo "$input_id = ";
	if(isset($data[$input_id])) {
		if($data[$input_id]['tosho_id'])
			echo "t", $data[$input_id]['tosho_id'];
		elseif($data[$input_id]['nyaa_id'])
			echo "n", $data[$input_id]['nyaa_id']; // TODO: doesn't check subdom!
		elseif($data[$input_id]['anidex_id'])
			echo "d", $data[$input_id]['anidex_id'];
		elseif($data[$input_id]['nekobt_id'])
			echo "k", $data[$input_id]['nekobt_id'];
		else
			echo "a", $data[$input_id]['id'];
	}
	else
		echo "?";
	echo "\n";
}
