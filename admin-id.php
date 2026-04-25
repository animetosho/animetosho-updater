<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("No toto id supplied\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';
loadDb();
unset($config);

$spcDel = false;
array_shift($argv);
if($argv[0] == '-') {
	$spcDel = true;
	array_shift($argv);
}

$argv = array_unique($argv);

foreach($argv as $input_id) {
	$test = '';
	if($input_id[0] == 't') {
		$id = (int)substr($input_id, 1);
		$test = 'tosho_id';
	} elseif($input_id[0] == 'n') {
		$id = (int)substr($input_id, 1);
		$test = 'nyaa_id';
	} elseif($input_id[0] == 'd') {
		$id = (int)substr($input_id, 1);
		$test = 'anidex_id';
	} elseif($input_id[0] == 'k') {
		$id = (int)substr($input_id, 1);
		$test = 'nekobt_id';
	} elseif($input_id[0] == 'a') {
		$id = (int)substr($input_id, 1);
		$test = 'id';
	} else
		$id = $input_id;
	
	if($test)
		$real_id = $db->selectGetField('toto', 'id', $test.'='.$id);
	else {
		// try tosho, then nyaa
		$real_id = $db->selectGetField('toto', 'id', 'tosho_id='.$id);
		if($real_id) {
			if(!$spcDel) $real_id .= ' (tosho match)';
		} else {
			$real_id = $db->selectGetField('toto', 'id', 'nyaa_id='.$id);
			if($real_id && !$spcDel)
				$real_id .= ' (nyaa match)';
			else {
				$real_id = $db->selectGetField('toto', 'id', 'anidex_id='.$id);
				if($real_id && !$spcDel)
					$real_id .= ' (anidex match)';
				else {
					$real_id = $db->selectGetField('toto', 'id', 'nekobt_id='.$id);
					if($real_id && !$spcDel)
						$real_id .= ' (nekobt match)';
				}
			}
		}
	}
	
	if($spcDel) {
		if(!$real_id) $real_id = "???";
		echo "$real_id ";
	} else {
		if(!$real_id) $real_id = '?';
		echo "$input_id is $real_id\n";
	}
}

if($spcDel) echo "\n";
