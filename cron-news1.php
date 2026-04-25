<?php
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

$where = 'priority >= 0';
$order = 'priority DESC, dateline ASC';
require ROOT_DIR.'includes/c-news.php';
