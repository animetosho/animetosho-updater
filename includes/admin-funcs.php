<?php

function find_attachfile_refs($afids) {
	global $db;
	$idrange = $db->selectGetArray('attachments', '', 'MIN(fid) AS min, MAX(fid) AS max');
	$minid = $idrange['min'];
	$maxid = $idrange['max'];
	
	$_afids = [];
	foreach($afids as $afid) $_afids[$afid] = 1;
	
	if(!class_exists('FileInfoCompressor')) {
		require_once ROOT_DIR.'includes/finfo-compress.php';
	}
	if(!defined('ATTACHMENT_OTHER')) {
		require_once ROOT_DIR.'includes/attach-info.php';
	}
	
	$BATCH_SIZE = 2000;
	$refs = [];
	for($i=$minid; $i<=$maxid; $i+=$BATCH_SIZE) {
		echo "Scanning attachments ".$i."...\r";
		
		foreach($db->selectGetAll('attachments', 'fid', 'fid BETWEEN '.$i.' AND '.($i+$BATCH_SIZE-1)) as $row) {
			$attachs = FileInfoCompressor::decompress_unpack('attach', $row['attachments']);
			if(empty($attachs)) die("Failed to unpack attachments for $row[fid]\n");
			
			$found = [];
			foreach([ATTACHMENT_OTHER, ATTACHMENT_SUBTITLE] as $atype) {
				if(!isset($attachs[$atype])) continue;
				foreach($attachs[$atype] as $attach) {
					if(isset($_afids[$attach['_afid']]))
						$found[] = $attach['_afid'];
				}
			}
			foreach([ATTACHMENT_CHAPTERS, ATTACHMENT_TAGS] as $atype) {
				if(isset($attachs[$atype]) && isset($_afids[$attachs[$atype]]))
					$found[] = $attachs[$atype];
			}
			if(!empty($found)) {
				$found = array_unique($found);
				foreach($found as $afid) {
					if(!isset($refs[$afid])) $refs[$afid] = [];
					$refs[$afid][] = (int)$row['fid'];
				}
			}
		}
	}
	echo str_repeat(' ', 80), "\r";
	return $refs;
}

function id_resolve($ids) {
	global $db;
	$result = [];
	foreach($ids as $input_id) {
		$test = '';
		if($input_id[0] == 't') {
			$id = (int)substr($input_id, 1);
			$test = 'tosho_id';
		} elseif($input_id[0] == 'n') {
			$id = (int)substr($input_id, 1);
			$test = 'nyaa_id';
		} elseif($input_id[0] == 'd') {
			$id = (int)substr($input_id, 1);
			$test = 'anidex_id';
		} elseif($input_id[0] == 'k') {
			$id = (int)substr($input_id, 1);
			$test = 'nekobt_id';
		} elseif($input_id[0] == 'a') {
			$id = (int)substr($input_id, 1);
			$test = 'id';
		} else
			$id = $input_id;
		
		if($test)
			$real_id = $db->selectGetField('toto', 'id', $test.'='.$id);
		else {
			// try tosho, then nyaa
			$real_id = $db->selectGetField('toto', 'id', 'tosho_id='.$id);
			if(!$real_id) {
				$real_id = $db->selectGetField('toto', 'id', 'nyaa_id='.$id);
				if(!$real_id) {
					$real_id = $db->selectGetField('toto', 'id', 'anidex_id='.$id);
					if(!$real_id) {
						$real_id = $db->selectGetField('toto', 'id', 'nekobt_id='.$id);
					}
				}
			}
		}
		
		$result[] = $real_id ?: null;
	}
	return $result;
}

function id_resolve_input($argv, $fields='*') {
	$argv = id_resolve(array_unique($argv));
	if(count($argv) != count(array_filter($argv))) {
		die("Invalid IDs supplied\n");
	}
	
	global $db;
	$totos = $db->selectGetAll('toto', 'id', 'id IN ('.implode(',', $argv).')', $fields);
	if(count($totos) != count($argv)) {
		die("Some supplied IDs aren't valid - bailing\n");
	}
	return $totos;
}
