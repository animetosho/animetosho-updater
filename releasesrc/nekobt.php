<?php

require_once ROOT_DIR.'releasesrc/funcs.php';

function nekobt_api($target) {
	for($i=0; $i<3; ++$i) {
		$data = send_request('https://nekobt.to/api/v1/'.$target, $dummy, ['ignoreerror' => true]);
		$jdata = @json_decode($data);
		if(!empty($jdata)) break;
		sleep(10); // retry after 10 secs
	}
	if(empty($jdata)) {
		info('No data returned for '.$target, 'nekobt');
		return false;
	}
	if(!empty($jdata->error)) {
		$msg = $jdata->message ?? 'No error message';
		if($msg != 'Torrent not found.')
			info('Error when retrieving '.$target.': '.$msg, 'nekobt');
		return $msg;
	}
	if(empty($jdata->data)) {
		warning('No data returned for request '.$target, 'nekobt');
		return false;
	}
	//if($i) info('Successfully got data after '.$i.' retry. '.$target, 'nekobt');
	return $jdata->data;
}

function nekobt_query_latest($offset=0) {
	// ordering: it seems like 'rss' orders by ID (insertion into nekoBT's DB) whilst 'latest' incorporates the original Nyaa timestamp
	$data = nekobt_api('torrents/search?sort_by=rss&limit=100&offset='.$offset);
	if(!is_object($data)) return false;
	if(!isset($data->results)) {
		warning('Query for latest lacks results', 'nekobt');
		return false;
	}
	
	return $data->results;
	// compared with detail, lacks:
	// - mediainfo (has_mediainfo flag instead)
	// - media_episode_ids
	// - files
	// - groups.members, groups.role
	//   - but has groups.anonymous?
	// - activity
	//   - but has user_is_seeding, user_is_leeching, user_download_count
	// - can_edit, disable_comments, lock_comments, disable_edits
	// - has comment_count
}

function nekobt_id_to_timestamp($id) {
	// the low 8-bits seems to be a randomish number between 0 and 15
	// it doesn't look fully random though, as it seems to generally increase with increasing ID
	return ($id >> 8) / 1000 + 1735689600;
}
function nekobt_timestamp_to_id($ts) {
	return (int)(($ts - 1735689600) * 256000);
}

function nekobt_add_inferred_info(&$data) {
	isset($data->timestamp) or $data->timestamp = nekobt_id_to_timestamp($data->id);
	isset($data->torrent) or $data->torrent = 'https://nekobt.to/api/v1/torrents/'.$data->id.'/download?public=true';
	if(!isset($data->magnet)) {
		$btih = $data->infohash;
		if(!isset($btih[39])) // from database?
			$btih = bin2hex($btih);
		
		$data->magnet = 'magnet:?xt=urn:btih:'.$btih.'&dn='.rawurlencode($data->auto_title ?? $data->title).'&tr=https%3A%2F%2Ftracker.nekobt.to%2Fapi%2Ftracker%2Fpublic%2Fannounce';
	}
}

function nekobt_get_detail($id) {
	if(!($data = nekobt_api('torrents/'.$id)))
		return false;
	if(is_string($data)) {
		if($data == 'Torrent not found.') return null;
		return false;
	}
	if(empty($data->id)) {
		warning('Query for '.$id.' is missing info', 'nekobt');
		return false;
	}
	
	/* Sample data from doco
	
{
  "error": false,
  "data": {
    "id": "1234567890",
    "title": "Torrent Title",
    "auto_title": "Torrent Title {Tags:L1;V9;C1;A-ja;F-en;}",
    "description": "...", // Markdown formatted description, can be null
    "mediainfo": "...", // Can be null
    "category": 1, // 1 = Anime, others unused
    "deleted": null,
    "hidden": false,
    "otl": false, // Original Translation
    "hardsub": false,
    "level": 1, // -1 = no subtitles, 0 = official ... 4 = full batch, can be null
    "mtl": false,
    "filesize": "242587117",
    "media_id": "s1234", // probably linked to TVDB, but not a TVDB URL; https://nekobt.to/media/<media_id>
    "media_episode_ids": [
      "48665" // probably indirectly linked to a TVDB episode
    ],
    "audio_lang": "ja", // All languages are comma separated lists
    "sub_lang": "", // Official subtitle lang
    "fsub_lang": "en", // Fansub lang
    "video_codec": 1, // 0=Other, 1=H.264, 2=H.265, 3=AV1, 4=VP9, 5=MPEG-2, 6=XviD/DivX, 7=WMV, 8=VC-1
    "video_type": 9, // 0=Other, 1=VHS, 2=LaserDisc, 3=TV - Encode, 4=TV - Raw, 5=DVD - Remux, 6=DVD - Encode, 7=WEB - Mini, 8=WEB - Encode, 9=WEB-DL, 10=<unused>, 11=BD - Disc, 12=BD - Mini, 13=BD - Encode, 14=BD - Remux, 15=Hybrid, 16=DVD - Disc
    "files": [
      {
        "path": "[SubsPlus+] Hero Without a Class - Who Even Needs Skills - S01E10 (ADN WEB-DL 1080p AVC AAC) [FAC10B8C].mkv",
        "name": "[SubsPlus+] Hero Without a Class - Who Even Needs Skills - S01E10 (ADN WEB-DL 1080p AVC AAC) [FAC10B8C].mkv",
        "length": 592387117,
        "offset": 0
      }
    ],
    "magnet": "magnet:?xt=urn:btih:bd2fb09...",
    "private_magnet": "magnet:?xt=urn:btih:bd2fb09...", // blank for anonymous querying
    "infohash": "bd2fb09...",
    "anonymous": false,
    "uploader": { // Can be null on anonymous uploads
      "id": "1234567890",
      "username": "example",
      "display_name": "Example",
      "pfp_hash": null // SHA256 of file; https://nekobt.to/cdn/pfp/<pfp_hash>  - can append "/128" to have it scaled down (or some other number)
    },
    "groups": [
      {
        "id": "1234567890",
        "name": "example group",
        "display_name": "Example Group",
        "pfp_hash": null,
        "tagline": "An example group tagline.",
        "members": [
          {
            "id": "1234567890",
            "invite": false,
            "username": "exampleuser",
            "display_name": "ExampleUser",
            "pfp_hash": null,
            "role": "Typesetting",
            "weight": 1 // Order of members, smallest first
          }
        ],
        "uploading_group": true,
        "role": null
      }
    ],
    "imported": null, // If imported from Nyaa, the original Nyaa ID, otherwise null
    "seeders": "52",
    "leechers": "1",
    "completed": "185",
    "activity": "5557", // Estimated peer speeds in bytes/second
    "upgraded": null, // Torrent ID of the new torrent, or null
    "can_edit": false,
    "waiting_approve": false,
    "disable_comments": false,
    "lock_comments": false,
    "disable_edits": false,
    "nyaa_upload_time": null // If imported from Nyaa, the original upload time as a Unix timestamp in milliseconds, otherwise null
  }
}
	
	*/
	
	nekobt_add_inferred_info($data);
	
	return $data;
}

// packed format: [first ID (4 bytes)][offset from previous ID minus 1]...
// offset is one byte, so a value of 0 means the ID is +1 of previous
// if the top bit is set, this is a control byte instead, with format:
// - 11xxxxxx: repeat zeroes xxxxxx+2 times
// - 100000SS: next offset is 1 (SS=00; offset=-128), 2 (SS=01; offset=-384) or 4 bytes (SS=10; offset=0)
function nekobt_pack_episodes($eps) {
	if(empty($eps)) return '';
	sort($eps, SORT_NUMERIC);
	
	$base = array_shift($eps);
	$r = pack('V', $base);
	// convert to offset format
	foreach($eps as &$ep) {
		$new_base = $ep;
		$ep -= $base+1;
		$base = $new_base;
	}
	
	// pack to stream
	$zero_count = 0;
	$flush_zeroes = function() use(&$zero_count, &$r) {
		if(!$zero_count) return;
		while($zero_count) {
			if($zero_count == 1) {
				$r .= pack('C', 0);
				$zero_count = 0;
			} else {
				$max_zc = min(63, $zero_count-2);
				$r .= pack('C', 192+$max_zc);
				$zero_count -= $max_zc+2;
			}
		}
	};
	foreach($eps as $diff) {
		if($diff == 0) {
			++$zero_count;
		} else {
			$flush_zeroes();
			if($diff < 128)
				$r .= pack('C', $diff);
			elseif($diff < 384)
				$r .= pack('CC', 128, $diff-128);
			elseif($diff < 65536+384)
				$r .= pack('Cv', 129, $diff-384);
			else
				$r .= pack('CV', 130, $diff);
		}
	}
	$flush_zeroes();
	return $r;
}
function nekobt_unpack_episodes($bin) {
	if($bin == '') return [];
	
	list(,$base) = unpack('V', $bin);
	$r = [$base];
	$offs = 4;
	
	// unpack to diffs
	$diffs = [];
	while(isset($bin[$offs])) {
		list(,$nb) = unpack('C', $bin, $offs);
		++$offs;
		
		if($nb & 128) {
			if($nb & 64) {
				// push in zeroes
				$zero_count = ($nb & 63) + 2;
				while($zero_count--)
					$r[] = 0;
			} elseif($nb == 128) {
				list(,$next_offs) = unpack('C', $bin, $offs);
				++$offs;
				$r[] = $next_offs + 128;
			} elseif($nb == 129) {
				list(,$next_offs) = unpack('v', $bin, $offs);
				$offs += 2;
				$r[] = $next_offs + 384;
			} elseif($nb == 130) {
				list(,$next_offs) = unpack('V', $bin, $offs);
				$offs += 4;
				$r[] = $next_offs;
			} else {
				return false;
			}
		} else {
			$r[] = $nb;
		}
	}
	
	// undo diffs
	for($i=1; $i<count($r); ++$i)
		$r[$i] += $r[$i-1]+1;
	
	return $r;
}

function nekobt_extract_groupinfo($data) {
	$pmy_group = null;
	$snd_groups = [];
	if(!empty($data->groups)) foreach($data->groups as $grp) {
		if($grp->uploading_group) {
			if(isset($pmy_group)) {
				warning('Multiple upload_group defined for '.$data->id, 'nekobt');
				$snd_groups[] = $grp->id;
			} else
				$pmy_group = $grp->id;
		} else
			$snd_groups[] = $grp->id;
	}
	return [$pmy_group, $snd_groups];
}

function nekobt_detail_to_arcdb($ts, $data, &$users=null, &$groups=null) {
	if(isset($users)) {
		if(!empty($data->uploader)) {
			$users[$data->uploader->id] = [
				'id' => $data->uploader->id,
				'username' => $data->uploader->username,
				'display_name' => $data->uploader->display_name,
				'pfp_hash' => $data->uploader->pfp_hash ? hex2bin($data->uploader->pfp_hash) : null,
				'updated_time' => $ts
			];
		}
		if(!empty($data->groups)) foreach($data->groups as $grp) {
			foreach($grp->members as $mem) {
				$users[$mem->id] = [
					'id' => $mem->id,
					'username' => $mem->username,
					'display_name' => $mem->display_name,
					'pfp_hash' => $mem->pfp_hash ? hex2bin($mem->pfp_hash) : null,
					'updated_time' => $ts
				];
			}
		}
	}
	if(isset($groups)) {
		if(!empty($data->groups)) foreach($data->groups as $grp) {
			$groups[$grp->id] = [
				'id' => $grp->id,
				'name' => $grp->name,
				'display_name' => $grp->display_name,
				'pfp_hash' => $grp->pfp_hash ? hex2bin($grp->pfp_hash) : null,
				'tagline' => $grp->tagline,
				'members' => jencode(array_map(function($mem) { return [
					'id' => $mem->id,
					'invite' => $mem->invite,
					'role' => $mem->role,
					'weight' => $mem->weight,
				];}, $grp->members)),
				'updated_time' => $ts
			];
		}
	}
	list($pmy_group, $snd_groups) = nekobt_extract_groupinfo($data);
	$media_id = 0;
	$media_id_type = '';
	if($data->media_id) {
		if(!preg_match('~^([a-z])(\d+)$~', $data->media_id, $m)) {
			warning('Unknown media_id for '.$data->id.': '.$data->media_id, 'nekobt');
			if(is_numeric($data->media_id))
				$media_id = (int)$data->media_id;
		} else {
			$media_id = (int)$m[2];
			$media_id_type = $m[1];
		}
	}
	return [
		'id' => $data->id,
		'title' => $data->title,
		'description' => $data->description,
		'mediainfo' => $data->mediainfo,
		'category' => $data->category,
		'deleted' => $data->deleted ? 1:0, // TODO: check possible values
		'hidden' => $data->hidden ? 1:0,
		'otl' => $data->otl ? 1:0,
		'hardsub' => $data->hardsub ? 1:0,
		'level' => $data->level,
		'mtl' => $data->mtl ? 1:0,
		'filesize' => $data->filesize,
		'media_id_type' => $media_id_type,
		'media_id' => $media_id,
		'media_episode_ids' => nekobt_pack_episodes($data->media_episode_ids),
		'audio_lang' => $data->audio_lang,
		'sub_lang' => $data->sub_lang,
		'fsub_lang' => $data->fsub_lang,
		'video_codec' => $data->video_codec,
		'video_type' => $data->video_type,
		'infohash' => hex2bin($data->infohash),
		'uploader' => $data->anonymous ? null : $data->uploader->id,
		'uploading_group' => $pmy_group,
		'secondary_groups' => implode(',', $snd_groups),
		'imported' => $data->imported,
		'upgraded' => $data->upgraded,
		'can_edit' => $data->can_edit ? 1:0,
		'waiting_approve' => $data->waiting_approve ? 1:0,
		'disable_comments' => $data->disable_comments ? 1:0,
		'lock_comments' => $data->lock_comments ? 1:0,
		'disable_edits' => $data->disable_edits ? 1:0,
		'nyaa_upload_time' => $data->nyaa_upload_time,
		'updated_time' => $ts
	];
}

function nekobt_torrent_file_loc($id, $makedir=false) {
	if($makedir) {
		$p = make_id_dirs2($id, TOTO_STORAGE_PATH.'nekobt_archive/', 16, 6);
		return TOTO_STORAGE_PATH.'nekobt_archive/'.$p[0].$p[1].'.torrent';
	}
	$hash = id2hash($id, 16);
	return TOTO_STORAGE_PATH.'nekobt_archive/'.(ltrim(substr($hash, 0, 6), '0') ?: '0').'/'.substr($hash, 6).'.torrent';
}

function nekobt_to_rowinfo($data) {
	if(empty($data)) return $data;
	if(is_array($data)) $data = (object)$data;
	nekobt_add_inferred_info($data);
	
	$cat = 5;
	if($data->category == 1) {
		$langs = explode(',', $data->audio_lang);
		$langs = array_merge($langs, explode(',', $data->sub_lang));
		$langs = array_merge($langs, explode(',', $data->fsub_lang));
		$cat = in_array('en', $langs) ? 1 : 10;
	}
	return [
		'nekobt_id' => $data->id,
		'nekobt_hide' => ($cat!=1 || $data->deleted || $data->hidden),
		'nyaa_id' => $data->imported ?: 0,
		'nyaa_subdom' => $data->imported ? '' : null,
		// since we skip imported entries, we won't bother fetching nyaa_class/nyaa_cat here
		'name' => $data->title,
		'cat' => $cat,
		'totalsize' => $data->filesize,
		'dateline' => $data->nyaa_upload_time ? (int)($data->nyaa_upload_time/1000) : (int)$data->timestamp,
		'comment' => markdown_to_desc($data->description),
		'website' => '', // unavailable
		'link' => $data->torrent,
		'magnetlink' => $data->magnet,
		'torrentfile' => nekobt_torrent_file_loc($data->id),
	];
}

function nekobt_get_item_raw($id, $incl_del=false) {
	// first, query DB
	global $db;
	$item = $db->selectGetArray('arcscrape.nekobt_torrents', 'id='.$id);
	if(!$incl_del && @$item['deleted'])
		return null;
	
	return $item;
}

function nekobt_run_from_scrape($timebase) {
	// query DB for items
	global $db;
	$nyaa_updates = [];
	foreach($db->selectGetAll('arcscrape.nekobt_torrents', 'id',
		'(CONCAT(",",audio_lang,",") LIKE "%,en,%" OR (CONCAT(",",audio_lang,",") RLIKE ",(ja|ko|zh)," AND CONCAT(",",sub_lang,",",fsub_lang,",") LIKE "%,en,%")) AND `arcscrape.nekobt_torrents`.deleted=0 AND hidden=0 AND `arcscrape.nekobt_torrents`.id >= '.nekobt_timestamp_to_id($timebase).' AND nekobt_id IS NULL',
		'`arcscrape.nekobt_torrents`.*', ['order' => '`arcscrape.nekobt_torrents`.id ASC', 'joins' => [
			['left', 'toto', 'id', 'nekobt_id'],
		]]
	) as $item) {
		if($item['imported']) {
			$nyaa_updates[$item['imported']] = ['nid' => $item['imported'], 'kid' => $item['id']];
		} else {
			$rel = nekobt_to_rowinfo($item);
			$item['source'] = 'nekobt';
			if(in_array($item['video_type'], [4,5,11,14,16]))
				$item['skip_hint'] = true;
			releasesrc_add($rel, 'toto_', false, $item);
		}
	}
	
	if(!empty($nyaa_updates)) {
		$uq = implode(' UNION ALL ', array_map(function($r) {
			return 'SELECT '.$r['nid'].' AS nid, '.$r['kid'].' AS kid';
		}, $nyaa_updates));
		$db->write_query(null, 'UPDATE toto_toto t INNER JOIN ('.$uq.') u ON t.nyaa_id=u.nid SET nekobt_id=u.kid');
		/*WHERE t.nyaa_id IN('.implode(',', array_map(function($r) {
			return $r['nid'];
		}, $nyaa_updates)).')*/
	}
	return true;
}


function nekobt_changes_from_latest($fItems, $idnext) {
	if(empty($fItems)) return [];
	global $db;
	$earliest = end($fItems)->id;
	
	$items = $db->selectGetAll('arcscrape.nekobt_torrents', 'id', 'id BETWEEN '.$earliest.' AND '.($idnext-1), '*', ['order' => 'id DESC']);
	// TODO: also check users/groups?
	
	$ret = [];
	foreach($fItems as $fitem) {
		$id = $fitem->id;
		if($id >= $idnext) continue;
		if(!isset($items[$id])) {
			$ret[$id] = 'new';
			continue;
		}
		$ditem = $items[$id];
		
		foreach(['title','description','category','level','filesize','video_codec','video_type','imported','upgraded','nyaa_upload_time'] as $k) {
			if($fitem->$k != $ditem[$k]) {
				$ret[$id] = 'change:'.$k;
				break;
			}
		}
		if(!isset($ret[$id])) foreach(['audio_lang','sub_lang','fsub_lang'] as $k) {
			if(!empty(array_diff(explode(',', $fitem->$k), explode(',', $ditem[$k])))) {
				$ret[$id] = 'change:'.$k;
				break;
			}
		}
		if(!isset($ret[$id])) foreach(['hidden','otl','hardsub','mtl','waiting_approve'] as $k) {
			if($fitem->$k != (bool)$ditem[$k]) {
				$ret[$id] = 'change:'.$k;
				break;
			}
		}
		if(!isset($ret[$id])) {
			$dMediaid = null;
			if(rtrim($ditem['media_id_type']))
				$dMediaid = $ditem['media_id_type'].$ditem['media_id'];
			$fUploader = $fitem->anonymous ? null : $fitem->uploader->id;
			list($fPmy_group, $fSnd_groups) = nekobt_extract_groupinfo($fitem);
			
			if($fitem->media_id != $dMediaid) {
				$ret[$id] = 'change:media_id';
			}
			elseif((bool)$fitem->deleted != (bool)$ditem['deleted'])
				$ret[$id] = 'change:deleted';
			elseif($fUploader != $ditem['uploader']) {
				$ret[$id] = 'change:uploader';
			}
			elseif($fPmy_group != $ditem['uploading_group']) {
				$ret[$id] = 'change:uploading_group';
			}
			elseif(!empty(array_diff($fSnd_groups, explode(',', $ditem['secondary_groups'])))) {
				$ret[$id] = 'change:secondary_groups';
			}
		}
		
		if(hex2bin($fitem->infohash) != $ditem['infohash'])
			warning("Unexpected infoHash mismatch for $id: {$fitem->infohash}<>".bin2hex($ditem['infohash']), 'nekobt');
		
		unset($items[$id]);
	}
	
	foreach($items as $ditem) {
		if(!$ditem['deleted'])
			$ret[$ditem['id']] = 'deleted';
	}
	return $ret;
}
