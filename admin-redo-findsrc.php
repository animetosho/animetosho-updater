<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("No toto id supplied\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';
require_once ROOT_DIR.'includes/find_src.php';
loadDb();
unset($config);


array_shift($argv);
$argv = array_unique(array_map('intval', $argv));

$totos = $db->selectGetAll('toto', 'id', 'id IN ('.implode(',', $argv).')', 'id,name,website,dateline,link');
if(count($totos) != count($argv)) {
	die("Some supplied IDs aren't valid - bailing\n");
}

foreach($totos as $id => $toto) {
	$update = array('srcurltype' => 'none');
	
	echo "Doing $toto[name] ...";
	
	$files = $db->selectGetAll('files', 'id', 'toto_id='.$id, 'id,filename,filesize');
	$r = find_source_url($toto['website'], $toto['dateline'], $toto['link'], $files);
	if(!empty($r)) {
		if($r[0]) $update['srcurl'] = $r[0];
		$update['srcurltype'] = $r[1];
		isset($r[2]) and $update['srctitle'] = $r[2];
		isset($r[3]) and $update['srccontent'] = $r[3];
		echo " found";
		if(isset($r[2])) echo ": $r[2]";
		if($r[0]) echo " ($r[0])";
		echo "\n";
	} else
		echo " not found\n";
	$db->update('toto', $update, 'id='.$id);
}
