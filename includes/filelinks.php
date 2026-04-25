<?php

require_once ROOT_DIR.'includes/finfo-compress.php';

function _filelinks_lock_get($fids) {
	global $db;
	// lock table
	$db->query('LOCK TABLE '.$db->tableName('filelinks').' WRITE, '
		.$db->tableName('filelinks').' AS filelinks WRITE, ' // need to put in the alias
		.$db->tableName('filelinks_active').' WRITE'); // LOCK TABLES requires this one too
	// fetch current links
	return filelinks_get($fids);
}
function _filelinks_unlock() {
	$GLOBALS['db']->query('UNLOCK TABLES');
}

function filelinks_get($fids, $ctype='filelinks') {
	$ret = $GLOBALS['db']->selectGetAll('filelinks', 'fid', 'fid IN ('.implode(',', $fids).')');
	foreach($ret as &$row) {
		$row = FileInfoCompressor::decompress_unpack($ctype, $row['links']);
	}
	return $ret;
}

function filelinks_enc($links, $check=false, $ctype='filelinks') {
	return FileInfoCompressor::compress_pack($ctype, $links, $check);
}

/* $links is array(
	fid => array(
		'site' => array(
			array(
				'url' => ...,
				'status' => 0
			),
			'encrypted' => false
		)
	)
)
*/
function filelinks_add($links) {
	global $db;
	$fl = _filelinks_lock_get(array_keys($links));
	
	$fli = array();
	$now = time();
	foreach($links as $fid => $sites) {
		$fl_links = @$fl[$fid] ?: array();
		foreach($sites as $site => $parts) {
			$multipart = count($parts) > 1;
			$enc = @$parts['encrypted'] ? 1:0;
			unset($parts['encrypted']);
			$fl_parts = array(); // @$fl_links[$site] ?: array();
			foreach($parts as $num => $part) {
				if(!$part) {
					$fl_parts[] = 0;
					continue;
				}
				$added = @$part['added'] ?: $now;
				$fli[] = array(
					'fid' => $fid,
					'site' => $site,
					'part' => $multipart ? $num+1 : 0,
					'url' => $part['url'],
					'status' => @$part['status'] ?: 0,
					'encrypted' => $enc,
					'added' => $added,
					'resolvedate' => @$part['status'] ? 0 : $added,
					'lastchecked' => $added,
					'lasttouched' => $added,
					'lastrefreshed' => $added,
				);
				$fl_part = array('url' => $part['url']);
				if(@$part['status']) {
					$fl_part['st'] = (int)$part['status'];
					if($fl_part['st'] == 3)
						unset($fl_part['url']);
				}
				if($enc) {
					$fl_part['added'] = $added;
				}
				$fl_parts[] = $fl_part;
			}
			$fl_links[($enc?'!':'') . $site] = $fl_parts;
		}
		ksort($fl_links);
		$fl[$fid] = array(
			'fid' => $fid,
			'links' => filelinks_enc($fl_links)
		);
	}
	
	$db->insertMulti('filelinks', array_values($fl), true);
	$db->insertMulti('filelinks_active', $fli, true);
	
	_filelinks_unlock();
}

// modify a single link
// only used for linkresolve
// if $only_activate, only modifies if the link status is 1 OR -1 (used for linkresolve)
function filelinks_mod($fid, $site, $part, $update, $only_activate = true) {
	global $db;
	$fl = _filelinks_lock_get(array($fid));
	if(!isset($fl[$fid])) return false;
	$fl = $fl[$fid];
	
	$lsite = strtolower($site);
	$updated = false;
	$pn = ($part ?: 1) -1;
	foreach($fl as $_site => &$parts) {
		$l_site = strtolower($_site);
		if($l_site == $lsite || $l_site == '!'.$lsite) {
			if(!isset($parts[$pn]) || !$parts[$pn]) return false;
			$_part =& $parts[$pn];
			if($only_activate && $_part['st'] != 1 && $_part['st'] != -1) return false;
			foreach($update as $k => $v) {
				switch($k) {
					case 'status':
						if($v)
							$_part['st'] = (int)$v;
						else
							unset($_part['st']);
					break;
					//default:
					case 'url':
						$_part[$k] = $v;
				}
			}
			if(@$_part['st'] == 3) unset($_part['url']);
			$updated = true;
			break;
		}
	}
	
	$db->update('filelinks_active', $update, 'fid='.$fid.' AND part='.$part.' AND site='.$db->escape($site).($only_activate ? ' AND status IN (-1,1)' : ''));
	if($updated)
		$db->update('filelinks', array('links' => filelinks_enc($fl)), 'fid='.$fid);
	
	_filelinks_unlock();
}
