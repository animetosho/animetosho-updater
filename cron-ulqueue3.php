<?php
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require_once ROOT_DIR.'init.php';
loadDb();

$where = 'priority >= 0';
$order = 'priority DESC, (complete+incomplete) DESC';  // use the number of seeders+leechers as a proxy for popularity
$extra_joins = [
	['inner', 'toto', 'toto_id', 'id'],
	'INNER JOIN '.$db->tableName('tracker_stats').' ON ulqueue.toto_id=toto_tracker_stats.id AND toto.main_tracker_id=toto_tracker_stats.tracker_id'
];
require ROOT_DIR.'includes/c-ulqueue.php';

