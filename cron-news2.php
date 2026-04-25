<?php
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

$order = 'priority DESC, totalsize ASC';
require ROOT_DIR.'includes/c-news.php';
