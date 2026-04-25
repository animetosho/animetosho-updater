<?php
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

$qopts = array('order' => 'dateline ASC');
require ROOT_DIR.'includes/c-complete.php';
require ROOT_DIR.'includes/c-cleanup.php';

unset($db);
