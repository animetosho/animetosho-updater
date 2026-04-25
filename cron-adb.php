<?php
// (UTF-8 char: –)

define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';

make_lock_file(substr(THIS_SCRIPT, 5, -4));

loadDb();
unset($config);

@ini_set('memory_limit', '256M');

$time = time();
@set_time_limit(900);

if(in_array('-l', $argv)) {
	define('PRINT_LOG', 1);
	if(in_array('-v', $argv)) {
		function ANIDB_DBG() {
			$args = func_get_args();
			echo implode(' ', array_map(function($a) {
				if(is_scalar($a))
					return (string)$a;
				return print_r($a, true);
			}, $args)), "\n";
		}
	}
}

require ROOT_DIR.'anifuncs.php';
require ROOT_DIR.'includes/resolve_custom.php';
require ROOT_DIR.'includes/releasesrc.php'; // for getting torrent info, used during batch detection

function quit() {
	// error, so we set the priority to very low
	$GLOBALS['db']->update('adb_resolve_queue', array('priority' => -3), 'toto_id='.$GLOBALS['r']['toto_id']);
	unset($GLOBALS['db']);
	exit;
}

while(true) {
	$r = $db->selectGetArray('adb_resolve_queue', 'dateline < '.$time.' AND priority>-100', '*', array('order' => 'priority*-28800 + dateline ASC'));
	if(!$r) break;
	
	// lower priority temporarily (if script stuffs up, will not immediately retry resolving this entry)
	$db->update('adb_resolve_queue', array('priority' => -100), 'toto_id='.$r['toto_id']);
	
	// get known details on this entry
	$dets = $db->selectGetArray('toto', 'id='.$r['toto_id'], 'name,aid,gids,eid,fid,website,dateline,link,srcurltype,resolveapproved');
	if(empty($dets)) {
		warning('[adb-resolve] Details array empty! id='.$r['toto_id']); // tracking down weird issue
		// TODO: just remove from resolve queue instead? entry probably deleted
		$dets = array();
	}
	else foreach($dets as $k => &$v)
		if(!$v) unset($dets[$k]);
	if(isset($r['video_duration']))
		$dets['video_duration'] = $r['video_duration'];
	
	if(@$dets['resolveapproved']) {
		// already approved, skip
		$db->delete('adb_resolve_queue', 'toto_id='.$r['toto_id']);
		continue;
	}
	
	if(@$dets['name']) {
		// use the current name, not the one stuck in the resolve queue
		$r['name'] = $dets['name'];
		unset($dets['name']);
		// TODO: adb_resolve_queue.name seems to be unused - consider removing
	}
	if(isset($r['crc32']))
		$r['crc32'] = bin2hex($r['crc32']);
	
	// TODO: also try matching CRC in filename + size
	$info = anidb_resolve_name($r['toto_id'], $r['name'], $r['added'], $r['crc32'], $r['filesize'], $dets);
	$possible_aids = null;
	if(isset($info['aids'])) {
		$possible_aids = $info['aids'];
		unset($info['aids']);
	}
	
	if(!@$info['fid'] && isset($r['ed2k'])) {
		// search for file with same hash
		// we restrict this to the same anime, as I've seen instances of AniDB getting it wrong, so the assumption is: if they're both of the same anime, it's more likely to be correct
		$files = $db->selectGetAll('anidb.file', 'id', 'ed2k='.$db->escape($r['ed2k']).(
			@$info['aid'] ? ' AND aid='.$info['aid'] : ($possible_aids ? ' AND aid IN('.implode(',', $possible_aids).')':'')
		), 'id,aid,eid,gid');
		if(count($files) == 1) {
			$file = reset($files);
			log_event('ed2k hash matched for '.$r['name'].', fid='.$file['id']);
			$info = [
				'aid' => $file['aid'],
				'eid' => $file['eid'],
				'gids' => $file['gid'],
				'fid' => $file['id'],
			];
		}
	}
	
	if(!isset($dets['srcurltype'])) {
		// we'll do source resolution in cron-adb so that we can readily retry (as opposed to cron-complete), although there's still the problem that a fully successful adb resolve will prevent this retrying
		$info = array_merge($info,
			resolve_source($dets, $r['toto_id'])
		);
	}
	if(!empty($info)) {
		if(isset($info['fid']) && $info['fid'])
			$info['resolveapproved'] = 1;
		
		// TODO: improve this delayed eid/fid resolution?
		// we'll allow AniDB 2 days to update eid/fid info
		if($r['added'] > $time-2*86400 && @$info['aid'] && !@$info['fid']) {
			$known_info = array_merge($info, $dets);
			$priority = -((isset($known_info['aid']) && isset($known_info['eid']) && isset($known_info['gids']) && $known_info['eid'] && $known_info['gids']) ? 2:1);
			
			if($priority == -2) {
				// try to verify with similarity with another entry
				// (ulcomplete,aid condition unnecessary, but used to take advantage of an index)
				$othername = $db->selectGetField('toto', 'name', 'ulcomplete=1 AND aid='.$known_info['aid'].' AND eid='.$known_info['eid'].' AND gids='.$db->escape($known_info['gids']).' AND resolveapproved!=0', array('order' => 'resolveapproved ASC'));
				
				// strip out CRC+extension for comparison
				$preg_rm = array('~[\[({][0-9a-fA-F]{8}[\])}]~', '~\.[a-zA-Z0-9]{2,5}$~');
				$cmpcname = preg_replace($preg_rm, '', $r['name']);
				$cmponame = preg_replace($preg_rm, '', $othername);
				similar_text($cmpcname, $cmponame, $perc);
				if($perc > 75) {
					$cf = parse_filename($r['name']);
					$of = parse_filename($othername);
					if(isset($of['title']) && isset($of['ep'])
					 && strtolower(@$cf['title']) == strtolower($of['title'])
					 && strtolower(@$cf['group']) == strtolower(@$of['group'])
					 && @$cf['ep'] == $of['ep']) {
						log_event('Found approved similar entry ('.$othername.')');
						$info['resolveapproved'] = 3;
					}
				}
			}
			
			// stick this at end of queue + delay searching for this
			$db->update('adb_resolve_queue', array(
				'priority' => $priority,
				'dateline' => min($r['added']+(2*86400+1), $time+($priority == -1 ? 2:12)*3600)
			), 'toto_id='.$r['toto_id']);
			
			$db->update('toto', $info, 'id='.$r['toto_id']);
			break;
		}
		$db->update('toto', $info, 'id='.$r['toto_id']);
	}
	
	// if ADB wants to be annoying and won't return anything, the above condition will cause
	// us to give up on it too
	
	$db->delete('adb_resolve_queue', 'toto_id='.$r['toto_id']);
	
	
	break;
}

unset($db);



function anidb_resolve_name_sequel_check($det, &$epnum) {
	$sequels = array();
	$titlecmpfunc = function($s) {
		return strtolower(str_replace(' ','', preg_replace(array('~(?<=\w)\: .*$~', '~ S(eason ?)?0?\d$~', '~ (\d\d?|I[IV]|III|VI?)$~', '~(2nd|3rd|[4-9]th|second|third|(four|fif|six|seven|eig|nin)th) season$~i', '~\s*\(\d+\)$~'), '',
			preg_replace('~ \((?:19|20)\d\d(?: Dai \d+.*| Part \d+.*)?\)$~i', '', $s)
		) ));
	};
	$titlecmp = $titlecmpfunc($det['title']);
	$titlecmplen = strlen($titlecmp);
	$sequels_lesser = [];
	foreach($det['relations'] as $relaid => $rel) {
		if(!in_array($rel['type'], ['tv','web']) || $rel['relation'] != 'sequel') continue;
		
		$rel_titles = [$rel['title']];
		// AID=19127 switches the 'main' and 'official' titles for its prequel, so we test both main/official titles
		if(!empty($rel['title_alt'])) $rel_titles = array_merge($rel_titles, $rel['title_alt']);
		$match_level = 0;
		foreach($rel_titles as $rel_title) {
			// only match if names are very similar, or base is a prefix of the relation
			$titlecmprel = $titlecmpfunc($rel_title);
			$titlecmprellen = strlen($titlecmprel);
			if($titlecmprel == $titlecmp) {
				$match_level = 2;
				break;
			}
			elseif(substr($titlecmprel, 0, $titlecmplen) == $titlecmp) {
				if(!ctype_alnum(@$titlecmprel[$titlecmplen])) {
					$match_level = 2;
					break;
				} else
					$match_level = 1;
			}
			// reverse of above as some prequels have a named extension whilst the sequel doesn't
			elseif(substr($titlecmp, 0, $titlecmprellen) == $titlecmprel) {
				if(!ctype_alnum(@$titlecmp[$titlecmprellen])) {
					$match_level = 2;
					break;
				} else
					$match_level = 1;
			}
		}
		if($match_level == 2)
			$sequels[] = $relaid;
		elseif($match_level)
			$sequels_lesser[] = $relaid;
	}
	if(empty($sequels) && !empty($sequels_lesser))
		// "Fanren Xiu Xian Chuan Tebie Pian" is a sequel of "Fanren Xiuxian Chuan: Feng Qi Tian Nan Er", but AniDB didn't put in a colon
		$sequels = $sequels_lesser;
	if(empty($sequels)) {
		// try to handle case where a OVA/movie is wedged between two seasons, e.g. Honzuki no Gekokujou, Ginga Eiyuu Densetsu: Die Neue These
		foreach($det['relations'] as $relaid => $rel) {
			if(in_array($rel['type'], ['ova','movie','tv special']) && $rel['relation'] == 'sequel') {
				if($titlecmpfunc($rel['title']) == $titlecmp)
					$sequels[] = $relaid;
				elseif(!empty($rel['title_alt']))
					foreach($rel['title_alt'] as $rel_title_alt)
						if($titlecmpfunc($rel_title_alt) == $titlecmp) {
							$sequels[] = $relaid;
							break;
						}
			}
		}
	}
	if(count($sequels) == 1) {
		$relaid = reset($sequels);
		log_event('aid might be sequel '.$relaid.' ('.$epnum.' > '.$det['eps_pmycount'].')');
		$reldet = anidb_getadetails($relaid);
		// verify this
		if($reldet && !empty($reldet['relations']) && !empty($reldet['eps']) && @$reldet['startdate'] && (@$reldet['startdate'] <= time()+86400) && @$reldet['eps_pmycount']) {
			if(in_array($det['type'], ['tv','web']))
				$epnum -= $det['eps_pmycount'];
			if(@in_array($reldet['type'], ['tv','web']) && $epnum <= $reldet['eps_pmycount']) {
				log_event('aid changed to '.$relaid);
				return $reldet;
			} else {
				// recursive search
				if($det = anidb_resolve_name_sequel_check($reldet, $epnum))
					return $det;
				else {
					log_event('aid changed to '.$relaid.' (recurse failed)');
					return $reldet;
				}
			}
		}
	} else {
		log_event('Cannot continue because aid '.$det['id'].' has sequels: '.implode(', ', $sequels));
	}
}

function &anidb_resolve_name($id, $nam, $time, $crc='', $size='0', $dets=array()) {
	log_event('Resolving name '.$nam);
	$time_now = time();
	global $db;
	$toto = $db->selectGetArray('toto', 'id='.$id, 'cat,torrentfiles,sigfid,stored_torrent,btih');
	$is_batch = ($toto['torrentfiles'] >= 6);
	if(!$is_batch && $toto['stored_torrent'] && isset($toto['btih'])) {
		// check torrent file listing
		if($tinfo = get_local_torrent($toto['btih'])) {
			$tlist = releasesrc_torrent_filelist($tinfo);
			foreach($tlist as $tfile) {
				$ext = strtolower(substr($tfile, -4));
				if($ext == '.iso' || $ext == '.mds' || $ext == '.bin') {
					$is_batch = true;
					break;
				}
			}
		}
	}
	$ret = array();
	$f = parse_filename($nam, (!$toto['sigfid'] && $toto['torrentfiles'] > 1) || $is_batch);
	if(empty($f)) {
		log_event('Unable to parse filename');
		return $ret;
	}
	
	resolve_prefilter_name($f);
	
	$aid_from_website = function() use($dets, &$ret, &$db) {
		if(!@$dets['website']) return null;
		if(preg_match('~^https?://anidb\.net/(?:anime/|a|perl-bin/animedb\.pl\?show=anime&aid=)(\d+)$~i', $dets['website'], $m)) {
			log_event('Using AniDB ID specified in website');
			return $ret['aid'] = (int)$m[1];
		}
		elseif(preg_match('~^https?://myanimelist\.net/anime/(\d+)(?:/|$)~i', $dets['website'], $m)) {
			$aids = $db->selectGetAll('anidb.animeresource', 'aid', 'type=2 AND data='.$m[1], 'aid');
			if(count($aids) == 1) {
				log_event('Using MAL ID specified in website: '.$m[1]);
				return $ret['aid'] = (int)key($aids);
			} else {
				log_event('MAL ID specified in website ('.$m[1].') resulted in '.count($aids).' AniDB matches');
			}
		}
		return null;
	};
	$check_aid_in_website = false;
	// try to grab group name from website for later use
	$webname = null;
	if(@$dets['website']) {
		// if this specifies an anime ID, use that
		// TODO: consider scanning Nyaa description for URLs
		if(preg_match('~-VARYG(\W|$)~i', $nam) || substr($nam, 0, 8) == '[Cytox] ')
			// VARYG always sticks the wrong season in their links; use our own matching, but fallback to their link
			$check_aid_in_website = true;
		else
			$aid_from_website();
		$webname = group_from_website($dets['website']);
	}
	if(!isset($f['group']) && $webname) {
		$f['group'] = $webname;
		$webname = null;
	}
	
	if($f['noep']) {
		// batches are commonly marked incorrectly as not having an episode
		if($is_batch)
			$f['noep'] = false;
	}
	
	if(isset($f['title']) && !isset($ret['aid'])) {
		// don't use aid in dets - unnecessary, since we have another cache
		// ...but it's possible that this is incorrect
		if(@$dets['aid']) $ret['aid'] = $dets['aid'];
		
		// perform local index check
		$idxname = filename_id($f['title']);
		log_event('Looking up local index for title '.$f['title'].' (query:'.$idxname.', noep:'.(int)$f['noep'].')');
		$aid = $db->selectGetField('adb_aniname_map', 'aid', 'nameid='.$db->escape($idxname).' AND noep='.(int)$f['noep'].' AND (autoadd=0 OR added<FROM_UNIXTIME('.($time_now-86400).'))');
		if($aid || $aid === 0 || $aid === '0') {
			$ret['aid'] = $aid;
		}
		else {
			log_event('Name not found in cache, sending to AniDB search');
			$anidb_search_hints = array('noep' => $f['noep']);
			if(isset($f['title_alt'])) $anidb_search_hints['title_alt'] = $f['title_alt'];
			$aid = anidb_search_anime($f['title'], $anidb_search_hints);
			if(is_array($aid))
				$ret['aids'] = array_keys($aid);
			
			if($aid && !is_array($aid)) {
				$ret['aid'] = $aid;
				$db->insert('adb_aniname_map', array(
					'nameid' => $idxname,
					'aid' => $aid,
					'autoadd' => 1,
					'noep' => $f['noep']
				), true);
			} else if(!$check_aid_in_website || !($aid = $aid_from_website())) {
				log_event('AniDB search failed');
				unset($aid);
			}
		}
	}
	
	// always try re-matching as currently assigned 'eid' could be badly auto-guessed
	if(isset($f['title']) && isset($ret['aid']) && $ret['aid']) {
		$aid = $ret['aid'];
		log_event('aid determined to be '.$aid);
		
		$filtered_aid = resolve_postfilter_ani($aid, $f, $nam);
		if($filtered_aid != $aid) {
			log_event('aid modified by postfilter_ani to '.$filtered_aid);
			$ret['aid'] = $aid = $filtered_aid;
		}
		
		$det = anidb_getadetails($aid);
		// handle case of the episode number referring to a sequel of this show
		// eg Fate/Zero ep14 is really Fate/Zero S2 ep1
		$epnum = null;
		if(isset($f['ep']) && is_numeric($f['ep']))
			$epnum = (float)$f['ep'];
		elseif(isset($f['eprange'])) {
			$epnum = (float)$f['eprange']; // grab first episode of range
			if($epnum < 1) $epnum = null;
		}
		// hackery for "Tensei Shitara Slime Datta Ken - 24.9", which is actually a sequel; we avoid cases like 12.5 (recap/bonus extra) and 12.75 (second bonus?) by assuming >0.8 refers to a subsequent season
		if(isset($epnum) && $epnum - (int)$epnum < 0.8)
			$epnum = (int)$epnum;
		
		$is_tv_type = @in_array($det['type'], ['tv','web']);
		if($det && !empty($det['eps']) && $is_tv_type && isset($epnum)) {
			if(!empty($det['relations']) && @$det['enddate'] && $det['eps_pmycount'] && $epnum > $det['eps_pmycount']) {
				log_event('Considering possibility of a sequel');
				if($seqdet = anidb_resolve_name_sequel_check($det, $epnum)) {
					if(isset($f['ep'])) $f['ep'] = $epnum;
					$det = $seqdet;
					$aid = $ret['aid'] = $seqdet['id'];
				}
			}
		}
		// ignore failure of the following
		if($det) {
			if(@$aid && is_int($aid)) $ret['aid'] = $aid;
			
			if(!empty($det['eps'])) {
				// if we have a movie/ova with only one part, ep = that
				$firstep = reset($det['eps']);
				if(($det['type'] == 'movie' || $det['type'] == 'ova') && ($det['eps_pmycount'] == 1 || ($firstep['num'] == '1' && strtolower($firstep['title']) == 'complete movie'))) {
					foreach($det['eps'] as $eid => &$detep) {
						$ret['eid'] = $eid;
						// clear "episode number" and placeholder titles
						if($detep['num'] == '1') $detep['num'] = '';
						if(strtolower($detep['title']) == 'complete movie')
							$detep['title'] = '';
						break; // only want first ep
					} unset($detep);
				}
				elseif($is_tv_type && isset($f['ep']) && trim($f['ep']) === '0') {
					log_event('Episode 0 => try to match with a special');
					$preair = []; $eps_special = []; $preair_s1 = null;
					foreach($det['eps'] as $eid => $ep) {
						if(!in_array(substr($ep['num'], 0, 1), ['S','O'])) continue;  // only consider special/other types
						if($ep['aired'] < @$det['startdate']) {
							$preair[] = $eid;
							if($ep['num'] == 'S1')
								$preair_s1 = $eid;
						}
						$eps_special[$eid] = $eid;
					}
					// if there's a preair, prefer that, else, try a special
					if(count($preair) == 1)
						$ret['eid'] = reset($preair);
					elseif(isset($preair_s1)) // if S1 is a preair, prefer that over other preairs
						$ret['eid'] = $preair_s1;
					elseif(count($eps_special) == 1) {
						$eps_special_k = array_keys($eps_special);
						$ret['eid'] = reset($eps_special_k);
					}
				}
				elseif(isset($f['ep']) || isset($f['special'])) {
					// try best to match episode number
					if(isset($f['ep']))
						$ep = strtoupper(trim($f['ep']));
					else
						$ep = strtoupper(trim($f['special']));
					if(preg_match('~^0*(\d+)$~', $ep, $m))
						$ep = intval($m[1]);
					
					if(is_numeric($ep) && $ep > $det['eps_pmycount']) {
						// sometimes, we'll get the season number in the ep (in the 00's) - trim it off if this is obvious
						if($ep>100 && $det['eps_pmycount'] < 95 && $ep%100 <= $det['eps_pmycount'])
							$ep %= 100;
						// special case for dvd/bd specials
						elseif(isset($f['ep']) && $det['type'] == 'tv' && $ep-$det['eps_pmycount'] < 10 && @$det['enddate']) {
							$epspecials = array();
							$numepspecials = 0;
							foreach($det['eps'] as $eid => &$detep) {
								if($detep['num'][0] == 'S' && $detep['epgroup'] > 1) {
									$epspecials[substr($detep['num'], 1)] = array($eid, $detep['duration']);
									++$numepspecials;
								}
							} unset($detep);
							if(!empty($epspecials)) {
								$guess_epspec = $ep-$det['eps_pmycount'];
								if($numepspecials == $guess_epspec && isset($epspecials[$numepspecials])) {
									// likely special
									$ret['eid'] = $epspecials[$numepspecials][0];
								} elseif($numepspecials > $guess_epspec) {
									// filter by duration
									if(@$dets['video_duration']) {
										$epspecials2 = array();
										$numepspecials2 = 0;
										foreach($epspecials as $spnum => &$spep) {
											if(abs($spep[1] - $dets['video_duration']/60) < 5) { // TODO: what about bad (0 length) durations?
												$epspecials2[$numepspecials2++] = $spep[0];
											}
										}
										if($numepspecials2 == 1)
											$ret['eid'] = $epspecials2[0];
										elseif($numepspecials2 == $guess_epspec)
											$ret['eid'] = $epspecials2[$guess_epspec];
										elseif($numepspecials2 > $guess_epspec)
											// take a wild guess - unreliable, because duration filtered
											$ret['eid'] = $epspecials2[$guess_epspec];
									}
									
									// or, as we won't get an episode otherwise, take a wild punt
									if(isset($epspecials[$guess_epspec]) && $numepspecials-$guess_epspec < 4)
										$ret['eid'] = $epspecials[$guess_epspec][0];
								}
							}
						}
					} elseif(isset($f['special']) && ($ep == 'OVA' || $ep == 'OAV' || $ep == 'OAD')) {
						// search for an episode named "OVA"
						foreach($det['eps'] as $eid => &$detep) {
							if($detep['title'] == 'ova') {
								$ret['eid'] = $eid;
								break;
							}
						} unset($detep);
					}
					if(!isset($ret['eid'])) {
						foreach($det['eps'] as $eid => &$detep) {
							// rare case with movies
							if($det['type'] == 'movie' && $detep['num'] == '1' && $detep['title'] == 'complete movie') {
								$ret['eid'] = $eid;
								$detep['num'] = $detep['title'] = '';
								break;
							}
							if(strtoupper($detep['num']) == (string)$ep || (isset($f['ep']) && strtoupper($detep['num']) == (string)$f['ep'])) {
								$ret['eid'] = $eid;
								break;
							}
							// OP/ED -> C fix
							elseif(($oe = substr($ep, 0, 2)) && ($oe == 'OP' || $oe == 'ED') && $detep['num'][0] == 'C' && 'C'.substr($ep, 2) == $detep['num']) {
								$ret['eid'] = $eid;
								break;
							}
						} unset($detep);
					}
				} elseif(!isset($f['ep']) && $det['eps_pmycount'] > 1 && !$is_batch) {
					// no episode number, try to match against episode titles
					$eptitle = $f['eptitle'] ?? null;
					if(!isset($eptitle) && preg_match('~(?:\: | - )(.+)$~', $f['title'], $match))
						$eptitle = trim($match[1]);
					
					if(isset($eptitle)) {
						log_event('Trying to find episode by title '.$eptitle);
						$eptitle_id = rtrim(filename_id($eptitle), '!?_');
						// fetch titles
						$eptitles = $db->selectGetAll('anidb.eptitle', 'id', 'eid IN ('.implode(',', array_keys($det['eps'])).') AND '.anidb_lang_where());
						$possible_title = null;
						foreach($eptitles as $title_info) {
							if(rtrim(filename_id($title_info['name']), '!?_') == $eptitle_id) {
								if(!isset($possible_title)) {
									$possible_title = $title_info;
								} elseif($possible_title && $possible_title['eid'] != $title_info['eid']) {
									// conflict, can't decide - discard
									$possible_title = false;
									break;
								}
							}
						}
						if($possible_title) {
							log_event('Matched with episode '.$possible_title['eid'].': '.$possible_title['name']);
							$ret['eid'] = $possible_title['eid'];
						}
					}
				}
				unset($eid); // clear usage of temp var
				if(isset($ret['eid']) && $ret['eid']) {
					$eid = $ret['eid'];
				}
			}
		}
		
		if($aid && empty($eid) && !empty($dets['aid']) && $aid != $dets['aid'] && !empty($dets['eid'])) {
			// aid changed, but no eid was found -> clear old eid, as it's probably invalid
			$ret['eid'] = 0;
		}
	}
	
	$testret = $ret;
	resolve_postfilter_ep($testret, $f, $nam);
	if($testret != $ret) {
		log_event('Mapping modified by postfilter_ep: '.json_encode($testret));
		$ret = $testret;
	}
	
	if(isset($dets['gids']) && $dets['gids']) {
		$gids = array_unique(explode(',', $dets['gids']));
	}
	elseif(isset($f['group'])) {
		if(!isset($det) && isset($aid)) $det = anidb_getadetails($aid);
		// try to resolve gid from a-details
		if(isset($det) && isset($det['groups'])) {
			log_event('Resolving group '.$f['group']);
			// first, build cache of dumb group names
			// TODO: maybe try to perform some basic filtering on last update / last ep info
			$dumbgroups = array();
			foreach($det['groups'] as $gid => &$grp) {
				$dumbname = anidb_search_dumbname($grp['name']);
				if($dumbname === '' || $dumbname == 'subs' || $dumbname == 'sub') $dumbname = strtolower($grp['name']);
				merge_into_array($dumbgroups, $dumbname, $gid);
				if(isset($grp['name_alt'])) {
					$dumbname = anidb_search_dumbname($grp['name_alt']);
					if($dumbname === '' || $dumbname == 'subs' || $dumbname == 'sub') $dumbname = strtolower($grp['name_alt']);
					merge_into_array($dumbgroups, $dumbname, $gid);
				}
			} unset($grp);
		} else
			log_event('No anime/group info to resolve group '.$f['group']);
		
		$gids = $groups_to_search = array();
		$i='';
		// phase 1 - search anidb page for groups
		if(!empty($dumbgroups)) {
			while(isset($f['group'.$i])) {
				$gid = anidb_resolve_name_findgid($f['group'.$i], $dumbgroups);
				if(is_array($gid)) {
					log_event('Group '.$f['group'.$i].' might be '.implode(',', $gid));
					// add all to array?
					$gids = array_merge($gids, $gid);
					$gid = null; // prevent code below adding to $gids
				} elseif(!$gid) {
					log_event('Couldn\'t match group '.$f['group'.$i].' in anime-group listing - defer to search');
					$groups_to_search[] = $f['group'.$i];
				}
				if($gid && !is_array($gid))
					$gids[] = $gid;
				
				if(!$i) $i=1;
				++$i;
			}
			
			// no groups found above?  search for groups stuck in info array
			if(empty($gids)) {
				log_event('No group matches found in anime-group listing - trying to match against info fields');
				$i='';
				while(isset($f['info'.$i])) {
					$gid = anidb_resolve_name_findgid($f['info'.$i], $dumbgroups);
					if(is_array($gid)) {
						log_event('Info field '.$f['info'.$i].' might be group '.implode(',', $gid));
						// add all to array?
						$gids = array_merge($gids, $gid);
						$gid = null; // prevent code below adding to $gids
					}
					if($gid && !is_array($gid))
						$gids[] = $gid;
					
					if(!$i) $i=1;
					++$i;
				}
				
				// okay, maybe try the group from the website?
				if(empty($gids) && $webname) {
					$gid = anidb_resolve_name_findgid($webname, $dumbgroups);
					if($gid && !is_array($gid)) {
						log_event('Webname '.$webname.' might be group '.$gid);
						$gids[] = $gid;
					} else {
						log_event('Webname '.$webname.' not matched to group - deferring to search');
						$groups_to_search[] = $webname;
					}
				}
			}
		}
		// still nothing found?  try searching for groups
		if(empty($gids) && !empty($groups_to_search)) {
			foreach($groups_to_search as $grp) {
				$gid = anidb_search_group($grp);
				if(!$gid || is_array($gid)) {
					if(!$gid)
						log_event('Group '.$grp.' no results - maybe is a multi-group?');
					else {
						log_event('Group '.$grp.' might be: '.implode(',', array_keys($gid)));
						// prefer 'name' matches over 'shortname'
						$name_matches = [];
						$dumbname = anidb_search_dumbname($grp);
						foreach($gid as $g) {
							if(anidb_search_dumbname($g['name']) == $dumbname)
								$name_matches[] = $g['id'];
						}
						if(count($name_matches))
							$gid = reset($name_matches);
						else {
							if(empty($name_matches))
								log_event('No name matches found');
							else
								log_event('Multiple name matches found: '.implode(',', $name_matches));
							$gid = false;
						}
					}
					if(!$gid) {
						// dance around groups like "weapon+"
						$grp = preg_replace('~(?<=.)[+–&](?=.)~u', '-', $grp);
						$gdashes = substr_count($grp, '-');
						$multigrp = ($gdashes > 0 && $gdashes < 4 && strlen($grp) > $gdashes*2+1); // ensure that 'C-W' won't get split
						if($multigrp) {
							foreach(array_map('trim', explode('-', $grp)) as $g) {
								if(!$g) continue;
								$gid = anidb_search_group($g);
								log_event('Group search '.$g.' result: '.(is_array($gid) ? implode(',', array_keys($gid)) : $gid));
								if($gid && !is_array($gid))
									$gids[] = $gid;
							}
							$gid = null; // stop code below duplicating stuff
						}
					}
				}
				if($gid && !is_array($gid)) {
					log_event('Group '.$grp.' matched to '.$gid);
					$gids[] = $gid;
				} else
					log_event('Group '.$grp.' not matched');
			} unset($grp);
		}
		if(!empty($gids)) {
			$gids = array_unique($gids);
			$ret['gids'] = implode(',', $gids);
		}
	}
	
	if(isset($aid)) {
		if(!empty($gids)) {
			foreach($gids as $gid) {
				$files = anidb_getagfiles($aid, $gid);
				if(!empty($files)) {
					// try to filter out files
					if(!isset($eid)) $eid = null; // shut up warning (@$eid doesn't work for some reason)
					$files_tmp = anidb_resolve_name_getfile($files, $f, $eid, $crc, $size);
					if(is_array($files_tmp)) {
						$fid_eid = reset($files_tmp); // reset() must be called before key()
						$ret['fid'] = key($files_tmp);
						$fid_eid = $fid_eid['eid'];
						// update eid if not set or different (since fid is fairly reliable)
						if(!isset($ret['eid']) || $ret['eid'] != $fid_eid) {
							$ret['eid'] = $fid_eid;
						}
						// TODO: since we've got the good file, set group?
						break; // file found, exit loop
					}
				}
			}
		}
		else {
			// TODO: also try resolve group from files??
		}
	}
	
	return $ret;
}

function get_local_torrent($btih_bin) {
	$btih = bin2hex($btih_bin);
	$tfile = TOTO_STORAGE_PATH.'torrents/'.substr($btih, 0, 3).'/'.substr($btih, 3).'.torrent';
	if(!file_exists($tfile) || !($tinfo = releasesrc_get_torrent('file', $tfile, $error)))
		return false;
	return $tinfo;
}

function merge_into_array(&$array, $key, $value) {
	if(isset($array[$key])) {
		// uh-oh, duplicate...
		if(is_array($array[$key])) {
			if(!in_array($value, $array[$key]))
				$array[$key][] = $value;
		}
		elseif($array[$key] == $value)
			return;
		else
			$array[$key] = array($array[$key], $value);
	} else
		$array[$key] = $value;
}

function anidb_resolve_name_findgid($str, &$groups) {
	if(!isset($groups)) return null;
	$str_l = strtolower($str);
	if($str === '' || $str_l == 'subs' || $str_l == 'sub') return null;
	$dumbname = anidb_search_dumbname($str);
	if($dumbname === '' || $dumbname == 'subs' || $dumbname == 'sub')
		$dumbname = $str_l;
	if(isset($groups[$dumbname])) {
		return $groups[$dumbname];
		// TODO: if array, try comparing non-dumb names
	}
	// if this is a multi-group, also try ordering differently (since AniDB may list groups in diff order)
	$str = preg_replace('~(?<=.)[+ _–&](?=.)~u', '-', $str);
	$gdashes = substr_count($str, '-');
	$multigrp = ($gdashes > 0 && $gdashes < 4 && strlen($str) > $gdashes*2+1); // ensure that 'C-W' won't get split
	if($multigrp) {
		$gexpl = array_map('trim', explode('-', $str));
		// we'll assume AniDB likes putting in alpha order
		sort($gexpl);
		$dumbname = anidb_search_dumbname(implode('', $gexpl));
		if(isset($groups[$dumbname]))
			return $groups[$dumbname];
		else {
			// just reverse; a better approach would be to try all combinations, but we won't bother here
			$gexpl = array_reverse($gexpl);
			$dumbname = anidb_search_dumbname(implode('', $gexpl));
			if(isset($groups[$dumbname]))
				return $groups[$dumbname];
			else {
				// try each individual group, eg "MahouShoujo+yesy" -> "yesy"
				$gids = array();
				$gid = null;
				foreach(array_map('anidb_search_dumbname', $gexpl) as $dumbname) {
					if($dumbname === '' || $dumbname == 'subs' || $dumbname == 'sub') continue;
					if(isset($dumbgroups[$dumbname])) {
						if($gid) {
							// matches multiple... chuck into array?
							$gids[] = $gid;
						}
						$gid = $dumbgroups[$dumbname];
					}
				}
				if(empty($gids)) return $gid;
				return $gids;
			}
		}
	}
	return null;
}

function anidb_resolve_name_getfile(&$files, &$f, $eid=null, $crc='', $size='0') {
	// try to filter out files
	// CRC most efficient
	if(isset($crc)) {
		$files_crc = $files;
		anidb_resolve_name_filtarray($files_crc, 'crc32', strtolower($crc));
		if(empty($files_crc)) // filter failed
			unset($files_crc);
	}
	if(isset($f['crc']) && $crc != $f['crc']) { // CRC marked wrong (or not supplied), try marked CRC
		$files_crc2 = $files;
		anidb_resolve_name_filtarray($files_crc2, 'crc32', strtolower($f['crc']));
		if(empty($files_crc2)) // filter failed
			unset($files_crc2);
	}
	if(isset($files_crc) || isset($files_crc2)) {
		// potentially may need to sort this conflict out
		if(isset($files_crc) && isset($files_crc2)) {
			// these point to different files - merge (in fact, prolly reliable, so pick these directly)
			$files = $files_crc + $files_crc2;
			$crc_match_done = true;
			unset($files_crc, $files_crc2);
		} elseif(isset($files_crc2)) {
			// only marked one found
			$files_crc = $files_crc2;
			unset($files_crc2);
		}
		else { // actual CRC match - this is probably reliable, so pick directly
			$files = $files_crc;
			$crc_match_done = true;
			unset($files_crc);
		}
	}
	if(isset($crc_match_done) && count($files) == 1)
		return $files;
	
	// followed by size filtering (problem: assumes single file)
	if($size) {
		$files_size = $files;
		anidb_resolve_name_filtarray($files_size, 'size', $size);
		if(empty($files_size)) // filter failed
			unset($files_size);
		elseif(isset($crc_match_done) && count($files_size) == 1) {
			return $files_size; // crc+file match with 1 file -> this is accurate
		}
		elseif(isset($files_crc)) {
			$match_both = array_intersect(array_keys($files_crc), array_keys($files_size));
			if(!empty($match_both)) {
				$files_crc_size = array();
				foreach($match_both as $fid)
					$files_crc_size[$fid] = $files_crc[$fid];
				// if filesize + crc match gives one file, we'll consider it accurate
				if(count($files_crc_size) == 1)
					return $files_crc_size;
			}
			unset($match_both);
		}
	}
	
	// eid filtering (not so reliable cause relies on parsing)
	if(isset($eid)) {
		$files_eid = $files;
		anidb_resolve_name_filtarray($files_eid, 'eid', $eid);
		if(empty($files_eid))
			unset($files_eid);
		elseif(isset($crc_match_done) && count($files_eid) == 1) {
			return $files_eid; // CRC + eid match
		}
		elseif(isset($files_crc_size)) {
			$match_both = array_intersect(array_keys($files_crc_size), array_keys($files_eid));
			if(count($match_both) == 1) {
				$id = reset($match_both);
				return array($id => $files_crc_size[$id]);
			}
			// otherwise, we just have far too many matches - cannot distinguish...
		}
		elseif(isset($files_crc)) {
			$match_both = array_intersect(array_keys($files_crc), array_keys($files_eid));
			// crc+eid match, we'll consider good enough
			if(count($match_both) == 1) {
				$id = reset($match_both);
				return array($id => $files_crc[$id]);
			}
		}
		elseif(isset($files_size)) {
			// I guess if we've got down here, we don't have many choices left
			$match_both = array_intersect(array_keys($files_size), array_keys($files_eid));
			if(count($match_both) == 1) {
				$id = reset($match_both);
				return array($id => $files_size[$id]);
			}
		}
	}
	
	// TODO: maybe extension matching?
	return false;
}
function anidb_resolve_name_filtarray(&$a, $key, $val) {
	foreach($a as $k => &$v) {
		if(!isset($v[$key]) || $v[$key] != $val)
			unset($a[$k]);
	}
}



function &resolve_source(&$dets, $id) {
	$ret = array('srcurltype' => 'none');
	if(!isset($dets['website']) || !isset($dets['link'])) return $ret;
	
	log_event('Resolving source for '.$id);
	require_once ROOT_DIR.'includes/find_src.php';
	$files = $GLOBALS['db']->selectGetAll('files', 'id', 'toto_id='.$id, 'id,filename,filesize');
	$r = find_source_url($dets['website'], $dets['dateline'], $dets['link'], $files);
	log_event('Resolve source for '.$id.' done');
	if(empty($r)) return $ret;
	
	if($r[0]) $ret['srcurl'] = $r[0];
	$ret['srcurltype'] = $r[1];
	isset($r[2]) and $ret['srctitle'] = $r[2];
	isset($r[3]) and $ret['srccontent'] = substr($r[3], 0, 32768); // restrict content to 32KB
	
	return $ret;
}
