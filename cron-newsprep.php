<?php
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

sleep(5); // give time for cron-complete to process

//$order = 'priority DESC, dateline ASC';
require ROOT_DIR.'includes/c-newsprep.php';

unset($db);
