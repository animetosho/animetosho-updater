<?php
if(PHP_SAPI != 'cli') die;
if($argc<2) die("Syntax: [script] fid fid...\n");

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

loadDb();
unset($config);

@ini_set('memory_limit', '512M');

array_shift($argv);

$force = false;
if(@$argv[0] == '-f') {
	$force = true;
	array_shift($argv);
}

$fids = array_unique(array_map('intval', $argv));

require ROOT_DIR.'includes/filelinks.php';

$links = $db->selectGetAll('filelinks_active', 'id', 'fid IN ('.implode(',',$fids).')');
if(empty($links) && !$force)
	die("No links found - check if links have been pruned, and use `-f` to ignore\n");

$oldtime = time() - 86400*165; // prune period is 183 days, so this gives > 2 weeks' window
$flinks = [];
foreach($links as $link) {
	$flf =& $flinks[$link['fid']];
	if(!isset($flf)) $flf = array();
	
	$flf[] = $link;
	
	if(!$force && $link['added'] < $oldtime)
		die("Old link detected - ensure this hasn't been pruned, and use `-f` to ignore\n");
} unset($links);

$update = [];
foreach($flinks as $fid => $links) {
	$fl = array();
	foreach($links as $link) {
		$fls =& $fl[$link['site']];
		if(!isset($fls)) $fls = array();
		
		$flp =& $fls[($link['part'] ?: 1) -1];
		$flp = array('url' => $link['url']);
		if($link['status']) {
			$flp['st'] = (int)$link['status'];
			if($flp['st'] == 3) {
				unset($flp['url']);
			}
		}
		if($link['encrypted']) {
			$flp['enc'] = 1;
			$flp['added'] = (int)$link['added'];
		}
	}
	
	// encrypt conversion
	foreach($fl as $sitename => &$_site) {
		$enc = null;
		foreach($_site as $_part) {
			$curenc = (bool)@$_part['enc'];
			if(isset($enc)) {
				if($curenc != $enc) {
					die("Encrypt status mismatch!\n");
				}
			} else
				$enc = $curenc;
		}
		
		if($enc) {
			unset($fl[$sitename]);
			foreach($_site as &$_part)
				unset($_part['enc']);
			unset($_part);
			$fl['!'.$sitename] = $_site;
		}
	} unset($_site);
	
	// sort & check parts
	foreach($fl as &$_site) {
		if(count($_site) == 1 && key($_site) === 0) {
			/*if(count($_site[0]) == 1 && @$_site[0]['url'])
				$_site = $_site[0]['url'];*/
		} else {
			ksort($_site);
			// sanity check
			end($_site);
			$lastpart = key($_site);
			if($lastpart != count($_site)-1) {
				echo "Part count incorrect for fid $fid! $lastpart <> ".(count($_site)-1)."\n";
				// start padding parts
				for($j=0; $j<$lastpart; ++$j) {
					if(!isset($_site[$j]))
						$_site[$j] = 0;
				}
				ksort($_site);
				// or maybe we just delete it altogether?
			}
		}
	} unset($_site);
	ksort($fl);
	
	
	$update[] = [
		'fid' => $fid,
		'links' => filelinks_enc($fl, true)
	];
}

// commit
$db->insertMulti('filelinks', $update, true);


echo "done\n";
