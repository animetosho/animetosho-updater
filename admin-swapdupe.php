<?php
if(PHP_SAPI != 'cli') die;
if($argc!= 3) die("Syntax: admin-swapdupe [id1] [id2]\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';
loadDb();
unset($config);

require ROOT_DIR.'includes/releasesrc.php';

$data = $db->selectGetAll('toto', 'id', 'id IN('.((int)$argv[1]).','.((int)$argv[2]).')');
if(count($data) != 2) die("Invalid IDs specified\n");

$data = array_values($data);
if($data[0]['isdupe'] == $data[1]['isdupe'])
	die("Dupe state not different\n");

if($data[0]['isdupe']) {
	$toto = $data[0];
	$dupe = $data[1];
} else {
	$toto = $data[1];
	$dupe = $data[0];
}
if($toto['deleted']) echo "Note: #$toto[id] is marked as deleted\n";

$upd_toto = array('isdupe' => 0);
$upd_dupe = array('isdupe' => 1);
// swap ulcomplete and files
$upd_toto['ulcomplete'] = $dupe['ulcomplete'];
$upd_dupe['ulcomplete'] = $toto['ulcomplete'];
reassign_toto_attachments($dupe['id'], $toto['id']);
if(!$toto['sigfid']) $upd_toto['sigfid'] = $dupe['sigfid'];
if(!$toto['stored_nzb']) $upd_toto['stored_nzb'] = $dupe['stored_nzb'];

$db->update('toto', $upd_toto, 'id='.$toto['id']);
$db->update('toto', $upd_dupe, 'id='.$dupe['id']);

echo "#$toto[id] no longer is dupe, #$dupe[id] is now dupe\n";

