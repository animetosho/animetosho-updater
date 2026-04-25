<?php

define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

make_lock_file(substr(THIS_SCRIPT, 5, -4));

loadDb();
unset($config);


@set_time_limit(1800);


$now = time();
if(!mt_rand(0, 5)) {
	$db->delete('tracker_scrape_failures', 'dateline < '.($now-86400));
}

require ROOT_DIR.'includes/scrape.php';

// go thru each tracker
$trackers = $db->selectGetAll('trackers', 'id', 'dead=0 AND (hour_failures IS NULL OR hour_failures < 5) AND (day_failures IS NULL OR day_failures < 20)', 'trackers.*', array('joins' => array(
	'LEFT JOIN (SELECT tracker_id, COUNT(*) AS hour_failures FROM '.$db->tableName('tracker_scrape_failures').' WHERE dateline > '.($now-3600).' GROUP BY tracker_id) f ON trackers.id=f.tracker_id',
	'LEFT JOIN (SELECT tracker_id, COUNT(*) AS day_failures FROM '.$db->tableName('tracker_scrape_failures').' WHERE dateline > '.($now-86400).' GROUP BY tracker_id) f2 ON trackers.id=f2.tracker_id'
)));

// note that UDP is probably limited to 1500 byte requests; 50*20=1000
function do_work($qWhere, $limit=25) {
	// nyaa.tracker.wf seems to limit 25 entries max
	global $trackers, $db;
	$now = time();
	foreach($trackers as $tracker) {
		// filter by idx_display to make use of indexes (plus it's not so useful to update deleted stuff)
		$torrents = $db->selectGetAll('tracker_stats', 'id', 'tracker_id='.$tracker['id'].' AND idx_dateline IS NOT NULL AND ('.$qWhere.')', 'tracker_stats.*, toto.btih', array(
			'limit' => $limit,
			'order' => 'last_queried ASC',
			'joins' => array(
				array('inner', 'toto', 'id')
			)
		));
		
		if(empty($torrents)) continue;
		
		$resp = scrape($tracker['url'], array_map(function($t) {
			return $t['btih'];
		}, $torrents));
		
		if($resp !== false) {
			$db->update('trackers', array('last_success' => $now), 'id='.$tracker['id']);
			// delete some failure records
			$db->delete('tracker_scrape_failures', 'tracker_id='.$tracker['id'], 2, 'dateline ASC');
		} else {
			$db->update('trackers', array('last_failure' => $now), 'id='.$tracker['id']);
			$db->insert('tracker_scrape_failures', array(
				'tracker_id' => $tracker['id'],
				'dateline' => $now
			));
		}
		
		// update stats
		$now = time();
		foreach($torrents as &$torrent) {
			$torrent['last_queried'] = $now;
			if(empty($resp))
				$torrent['error'] = 1;
			elseif(isset($resp[$torrent['btih']])) {
				$r =& $resp[$torrent['btih']];
				if(!isset($r['complete']) || !isset($r['incomplete'])) {
					$torrent['error'] = 3;
				} else {
					$torrent['error'] = 0;
					$torrent['updated'] = $now;
					$torrent['complete'] = max(0, min(1000000000, (int)$r['complete']));
					if(isset($r['downloaded']))
						$torrent['downloaded'] = max(0, min(1000000000, (int)$r['downloaded'])); // cap at 1B
					else
						$torrent['downloaded'] = 0;
					$torrent['incomplete'] = max(0, min(1000000000, (int)$r['incomplete']));
				}
			} else {
				$torrent['error'] = 2;
			}
			unset($torrent['btih']);
		}
		$db->insertMulti('tracker_stats', $torrents, true);
	}
}

// scrape latest torrents in the last 7 days, frequently
$now = time();
do_work('idx_dateline < -'.($now-86400*7).' AND last_queried < '.($now-1200));
sleep(10);
$now = time();
do_work('idx_dateline < -'.($now-86400*7).' AND last_queried < '.($now-1200));

// ...less frequently for last 30 days
sleep(10);
$now = time();
do_work('idx_dateline < -'.($now-86400*30).' AND last_queried < '.($now-3600*6));

// ...once a day for last 360 days
sleep(10);
$now = time();
do_work('idx_dateline < -'.($now-86400*360).' AND last_queried < '.($now-86400));

// ...very rarely before that
sleep(10);
$now = time();
do_work('last_queried < '.($now-86400*30));



unset($db);
