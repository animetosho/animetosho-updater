<?php

define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

loadDb();
unset($config);

@set_time_limit(900);
//define('DEBUG_FIND', 1);

// find ID that corresponds with a value (vcol) that is ordered alongside the ID (e.g. an added date)
// $lt: if true, will find largest value that's <= specified, otherwise will find smallest value that's >= specified
function find_id($table, $idcol, $vcol, $target, $lt=true) {
	$val_isint = is_int($target);
	if(defined('DEBUG_FIND')) echo "Find ".($lt?'<':'>')."= $target\n";
	
	global $db;
	// find MIN/MAX to know our range
	$qpart = 'SELECT '.$idcol.' id, '.$vcol.' val FROM '.$db->tableName($table);
	$query = '('.$qpart.' ORDER BY '.$idcol.' ASC LIMIT 1) UNION ALL ('.$qpart.' ORDER BY '.$idcol.' DESC LIMIT 1)';
	if(defined('DEBUG_FIND')) echo "Min/max query: $query\n";
	$q = $db->query($query);
	$min = $max = null;
	if($r = $db->fetchArray($q)) {
		$min = $r;
		if($r = $db->fetchArray($q))
			$max = $r;
		else {
			$db->freeResult();
			return -1; // shouldn't happen
		}
	} else {
		$db->freeResult();
		return -1;
	}
	$db->freeResult($q);
	if(defined('DEBUG_FIND')) echo "Min/max query result: [$min[id], $min[val]] [$max[id], $max[val]]\n";
	
	$min['id'] = (int)$min['id'];
	$max['id'] = (int)$max['id'];
	if($val_isint) {
		$min['val'] = (int)$min['val'];
		$max['val'] = (int)$max['val'];
	}
	
	$PARTITION = 10; // 1 = binary search
	while($min['id'] != $max['id']) {
		if($lt && $min['val'] > $target) return -1;
		if(!$lt && $max['val'] < $target) return -1;
		if($max['id'] < $min['id']) return -1;
		
		if($min['val'] == $target) return $min['id'];
		if($max['val'] == $target) return $max['id'];
		
		$idDiff = $max['id'] - $min['id'];
		if($idDiff <= $PARTITION) {
			if(defined('DEBUG_FIND')) echo "Small range = direct find\n";
			// directly find it
			return (int)$db->selectGetField($table, $idcol, $vcol.($lt?'<':'>').'='.$target.' AND '.$idcol.' BETWEEN '.$min['id'].' AND '.$max['id'], [
				'order' => $idcol.' '.($lt?'DESC':'ASC'),
				'limit' => 1
			]);
		}
		
		$step = $idDiff / ($PARTITION+1); // must be >= 1
		$qa = [];
		for($i=1; $i<=$PARTITION; ++$i) {
			$_i = $lt ? $i : ($PARTITION-$i+1); // invert ordering of UNIONs if searching for >=
			$qa[] = '('.$qpart.' WHERE '.$idcol.'>='.($min['id'] + round($_i*$step)).' ORDER BY '.$idcol.' ASC LIMIT 1)';
		}
		
		$query = implode(' UNION ALL ', $qa);
		if(defined('DEBUG_FIND')) echo "Search query: $query\n";
		$q = $db->query($query);
		while($r = $db->fetchArray($q)) {
			$r['id'] = (int)$r['id'];
			if($val_isint)
				$r['val'] = (int)$r['val'];
			if($r['val'] == $target) {
				$db->freeResult($q);
				return $r['id'];
			}
			if($lt) {
				if($r['val'] > $target) {
					$max = $r;
					break;
				} else
					$min = $r;
			}
			else {
				if($r['val'] < $target) {
					$min = $r;
					break;
				} else
					$max = $r;
			}
		}
		$db->freeResult($q);
		if(defined('DEBUG_FIND')) echo "Search result: [$min[id], $min[val]] [$max[id], $max[val]]\n";
	}
	// sanity check
	if($lt && $max['val'] <= $target) return $max['id'];
	if(!$lt && $min['val'] >= $target) return $min['id'];
	return -1;
}

///// prune toto.filelinks_active table

// find cutoff ID
$cutoff = find_id('filelinks_active', 'id', 'added', time()-86400*183);
if($cutoff < 0) die("Found no entries to prune\n");

echo "Pruning filelinks_active entries <= $cutoff";
do {
	$deleted = $db->delete('filelinks_active', 'id<'.$cutoff, 50000, 'id ASC');
	echo '.';
	sleep(2);
} while($deleted);
echo "\n";


// TODO:
/*
///// set unresolved filelinks_active to never resolved
echo "Expiring filelinks_active entries...";
$fids = $db->selectGetAll('filelinks_active', 'fid', 'status=1 AND added < '.(time() - 86400*14), 'id,fid,site,part,url,added', 'fid', array('group' => 'fid', 'limit' => 1000));
*/
