<?php
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

$where = 'priority >= 0';
$order = 'priority DESC, IF(retry_sites="",500,LENGTH(retry_sites)) DESC, files.filesize ASC';
require ROOT_DIR.'includes/c-ulqueue.php';
