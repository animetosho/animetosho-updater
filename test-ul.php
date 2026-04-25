<?php

define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

define('DEFAULT_ERROR_HANDLER', 1);

require ROOT_DIR.'init.php';
if($argc < 2) die("Syntax: php test-ul.php [host]\n");

//loadDb();

define('DEBUG_MODE', 1);
require ROOT_DIR.'uploaders/'.$argv[1].'.php';
$cls = 'uploader_'.$argv[1];
$u = new $cls;
if(isset($db)) $u->setDb($db);

$u->return_linkpage = true;
$u->upload_sockets_retries = 0;
$u->upload_sockets_break_on_failure = true;

if(method_exists($u, 'setServices')) {
	$u->setServices(array(
		// TODO: set appropriately
		'putlocker','crocko','filejungle','mediafire','megashare','twoshared','ziddu','freackshare','uploadstation','rapidshare','sendmyway','jumbofiles',
	));
}

var_dump($u->upload(array(
	'timebase.txt' => ''
)));
