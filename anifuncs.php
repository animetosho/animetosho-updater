<?php
// (UTF-8 char: –)

if(!function_exists('ANIDB_DBG')) {
	function ANIDB_DBG($msg) {}
}

// determine whether the season number needs to be appended
function anidb_append_season($name, $season) {
	// check if we have a special case
	$s1 = array_map(function($s) {
		return filename_id(preg_replace('~(1st|first) season$~i', '', $s));
	}, anidb_get_title_list('s1'));
	if(in_array(filename_id($name), $s1)) return true;
	return $season > 1;
}

function anidb_normalize_season($title, $batch_hint=null) {
	if(preg_match('~^major (2nd|second)$~i', $title)) // "Major 2nd" and "Major S2" aren't the same thing
		return $title;
	$title_append = '';
	$romans = array(
		'I'     => 1,
		'II'    => 2,
		'III'   => 3,
		'IV'    => 4,
		'V'     => 5,
		'VI'    => 6,
		'VII'   => 7,
		'VIII'  => 8,
		'IX'    => 9,
		'X'     => 10,
		'XI'    => 11,
		'XII'   => 12,
		'XIII'  => 13,
		'XIV'   => 14,
		'XV'    => 15,
		'XVI'   => 16,
		'XVII'  => 17,
		'XVIII' => 18,
		
		// hijack this for textual numbering
		'ONE' => 1,
		'TWO' => 2,
		'THREE' => 3,
		'FOUR' => 4,
		'FIVE' => 5,
		'SIX' => 6,
		'SEVEN' => 7,
		'EIGHT' => 8,
		'NINE' => 9,
		'TEN' => 10
	);
	foreach([
		'part' => ['regex' => '(?<= |\d)[(\[]?(?:p(?:(?:ar)?t\.? ?)?|cour ?)', 'romrx' => '(?:Part |PART |part |Cour |COUR |cour )', 'newlabel' => 'Part '],
		'season' => ['regex' => ' [(\[]?s(?:eason ?)?', 'romrx' => '', 'newlabel' => '']
	] as $label => $opts) {
		// convert "S2" etc to "2" (seems to resolve best with AniDB)
		$wordThs = array('first'=>1, 'second'=>2, 'third'=>3, 'fourth'=>4, 'fifth'=>5, 'sixth'=>6);
		if(preg_match('~ [(\[]?('.implode('|',array_keys($wordThs)).') '.$label.'[)\]]?$~i', $title, $match)) {
			$value = $wordThs[strtolower($match[1])];
			$title = preg_replace('~[\s,\-|]*$~', '', substr($title, 0, -strlen($match[0])));
			if($label == 'part') {
				if($value > 1)
					$title_append = ' '.$opts['newlabel'].$value . $title_append;
			} else {
				if(anidb_append_season($title, $value) || !empty($title_append))
					$title_append = ' '.$opts['newlabel'].$value . $title_append;
			}
		}
		// also do "2nd" etc
		elseif(preg_match('~'.$opts['regex'].'(\d+'.($batch_hint?'( ?[+,&\-] ?\d+| (?:and|to) \d+)*':'').')[)\]]?$~i', $title, $match) || preg_match('~ [(\[]?(\d*?(?:1st|2nd|3rd|[04-9]th|1[0-9]th))(?: '.$label.')'.($label=='part'?'':'?').'[)\]]?$~i', $title, $match)) {
			// for cases like "part 1-2": because we don't handle ranges well, we'll strip it to a single one
			$value = intval($match[1]); // conveniently will strip of 'nd', 'th' etc
			if($value > 0) { // guaranteed to be true, but meh
				$title = preg_replace('~[\s,\-|]*$~', '', substr($title, 0, -strlen($match[0])));
				if($label == 'part') {
					if($value > 1)
						$title_append = ' '.$opts['newlabel'].$value . $title_append;
				} else {
					if(anidb_append_season($title, $value) || !empty($title_append))
						$title_append = ' '.$opts['newlabel'].$value . $title_append;
				}
			}
		}
		// roman numeral form (consider case) + textual numeral form (case insensitive)
		elseif(preg_match('~ '.$opts['romrx'].'(I{2,3}|I[VX]|[VX]I{1,3}|XVI{0,3})$~', $title, $match) || preg_match('~ '.$opts['romrx'].'(ONE|TWO|THREE|FOUR|FIVE|SIX|SEVEN|EIGHT|NINE|TEN)$~i', $title, $match)) {
			$title = preg_replace('~[\s,\-|]*$~', '', substr($title, 0, -strlen($match[0])));
			$num = $romans[strtoupper($match[1])];
			if($num > 1 || $label != 'part')
				$title_append = ' '.$opts['newlabel'].$num . $title_append;
		}
		// slightly stricter matching for single letter romans
		elseif(preg_match('~ (?:'.$label.'|'.ucfirst($label).'|'.strtoupper($label).') ([IVX])$~', $title, $match)) {
			$title = preg_replace('~[\s,\-|]*$~', '', substr($title, 0, -strlen($match[0])));
			$num = $romans[strtoupper($match[1])];
			if($label == 'part') {
				if($num > 1)
					$title_append = ' '.$opts['newlabel'].$num . $title_append;
			} else {
				if(anidb_append_season($title, $num))
					$title_append = ' '.$opts['newlabel'].$num . $title_append;
			}
		}
	}
	return $title.$title_append;
}

function anidb_cat_data($get_cat) {
	static $cats = null; // unknown, tv series, ova, movie, other, web, tv special, music video
	static $langs = null;
	if(!isset($cats)) {
		$cats = $langs = array();
		$d = $GLOBALS['db']->selectGetAll('anidb.cat', 'id', 'id LIKE "lang:%" OR id LIKE "cat:%"', 'id,name');
		foreach($d as $e) {
			if(!preg_match('~^(cat|lang)\:(\d+)$~', $e['id'], $m)) continue;
			${$m[1].'s'}[strtolower($e['name'])] = $m[2];
		}
	}
	
	if($get_cat) return $cats;
	else return $langs;
}
function anidb_lang_where() {
	$langs = anidb_cat_data(false);
	return 'langid IN ('.$langs['unknown'].','.$langs['english'].','.$langs['japanese (transcription)'].','.$langs['korean (transcription)'].','.$langs['chinese (transcription)'].','.$langs['japanese'].','.$langs['chinese (simplified)'].','.$langs['chinese (traditional)'].','.$langs['korean'].')';
}

function anidb_load_data() {
	global $db;
	$cats = anidb_cat_data(true);
	
	// need to pre-cache this to avoid queries in the main loop
	anidb_get_title_list('s1');
	$langs = anidb_cat_data(false);
	
	$animes = array();
	$anititles = array();
	$titlesSeen = [];
	$mainTitles = [];
	$enTitles = [];
	// TODO: consider whether we want to remove the language selection limitation
	$q = $db->select('anidb.animetitle', '('.anidb_lang_where().' OR type=1) AND (type!=3 OR LENGTH(name) > 6) AND aid NOT IN(357,4552)', 'aid,name,type,langid');
	while($r = $db->fetchArray($q)) {
		// some minor filtering
		$r['name'] = anidb_normalize_season($r['name']);
		$aid = (int)$r['aid'];
		
		$name = filename_id($r['name']);
		$name2 = filename_id(preg_replace('~\: .+$~', '', $r['name'])); // AniDB often puts long names for OVAs, 2nd seasons etc after a colon
		$ak = $r['aid'].'-'.$name;
		if(isset($animes[$ak])) {
			if($name2 != $name) {
				// create dupe entry
				$ak = $r['aid'].'-'.$name2;
				if(!isset($animes[$ak])) {
					$animes[$ak] = array('id' => $aid, 'names' => [$name2], 'origname' => $r['name']);
				}
			}
			// duplicate entry, discard
			continue;
		}
		$animes[$ak] = array('id' => $aid, 'names' => [$name], 'origname' => $r['name']);
		if($name != $name2)
			$animes[$ak]['names'][] = $name2;
		
		$titlesSeen[$name] = 1;
		if($r['type'] == 1) {
			$mainTitles[$aid] = $r['name'];
			if(isset($anititles[$name])) {
				if(is_array($anititles[$name])) {
					if(!in_array($aid, $anititles[$name]))
						$anititles[$name][] = $aid;
				} else {
					if($anititles[$name] !== $aid)
						$anititles[$name] = array($anititles[$name], $aid);
				}
			}
			else
				$anititles[$name] = $aid;
		}
		elseif(($r['type'] == 2 || $r['type'] == 4) && $r['langid'] == $langs['english'] && strpos($r['name'], ': ')) {
			if(!isset($enTitles[$aid])) $enTitles[$aid] = [];
			$enTitles[$aid][] = $r['name'];
		}
		// TODO: consider adding name2...?
	}
	$db->freeResult($q);
	
	// some releases mix the English main title with the (transliterated) Japanese suffix, or vice versa, so mix + add these as aliases
	foreach($enTitles as $aid => $titles) {
		if(!isset($mainTitles[$aid])) continue; // something wrong
		$p = strpos($mainTitles[$aid], ': ');
		if(!$p) continue;
		
		$jpParts = [substr($mainTitles[$aid], 0, $p), substr($mainTitles[$aid], $p+2)];
		$jpPartsId = array_map('filename_id', $jpParts);
		foreach($titles as $title) {
			$enParts = explode(': ', $title, 2);
			$enPartsId = array_map('filename_id', $enParts);
			if($jpPartsId[0] != $enPartsId[0] && $jpPartsId[1] != $enPartsId[1]) {
				foreach([
					$jpParts[0].': '.$enParts[1],
					$enParts[0].': '.$jpParts[1]
				] as $newTitle) {
					$newTitleId = filename_id($newTitle);
					if(isset($titlesSeen[$newTitleId])) continue;
					$ak = $aid.'-'.$newTitleId;
					if(isset($animes[$ak])) continue; // this shouldn't happen
					$animes[$ak] = array('id' => $aid, 'names' => [$newTitleId], 'origname' => $newTitle);
					$titlesSeen[$newTitleId] = 1;
				}
			}
		}
	}
	unset($enTitles);
	
	// add aliases
	$q = $db->select('adb_aniname_alias');
	while($r = $db->fetchArray($q)) {
		$name = filename_id($r['name']);
		$ak = $r['aid'].'-'.$name;
		if(isset($animes[$ak])) continue;
		$animes[$ak] = array('id' => (int)$r['aid'], 'names' => [$name], 'origname' => $r['name']);
		
		$titlesSeen[$name] = 1;
	}
	$db->freeResult($q);
	
	// name fixes:
	// - "Is the Order a Rabbit?" (strip exclamation/question marks if there's only one)
	// - strip 'Eiga ' and 'Gekijouban ' prefixes because many user specified titles don't include them
	foreach($animes as &$r) {
		$newNames = [];
		if(preg_match('~\w[?!]$~', $r['names'][0])) {
			$newNames[] = substr($r['names'][0], 0, -1);
		} elseif(substr($r['origname'], 0, 5) == 'Eiga ') {
			foreach($r['names'] as $nam) {
				if(substr($nam, 0, 4) == 'eiga') {
					$stripped_name = substr($nam, 4);
					$newNames[] = $stripped_name;
					// TODO: placement at the end may be undesirable, e.g. "Eiga Crayon Shin-chan: Ora-tachi no Kyouryuu Nikki" - the "movie" should be before the ":"
					$newNames[] = $stripped_name.'movie';
				}
			}
		} elseif(substr($r['origname'], 0, 11) == 'Gekijouban ') {
			foreach($r['names'] as $nam) {
				if(substr($nam, 0, 10) == 'gekijouban') {
					$stripped_name = substr($nam, 10);
					$newNames[] = $stripped_name;
					$newNames[] = $stripped_name.'movie';
				}
			}
		}
		foreach($newNames as $newName) {
			if(!isset($titlesSeen[$newName])) {
				$r['names'][] = $newName;
				$titlesSeen[$newName] = 1;
				if(!isset($anititles[$newName]))
					$anititles[$newName] = $r['id'];
			}
		}
	} unset($r);
	
	// calc sequel namings
	$q = $db->select('anidb.seq', 'type=1', 'aid,nextaid,airdate,dateflags,cid', ['joins' => [
		['inner', 'anidb.anime', 'aid', 'id']
	]]);
	$seq = $preq = [];
	$relaids = [];
	$animeseq = $aniseqtitles = [];
	while($r = $db->fetchArray($q)) {
		$relaids[$r['aid']] = $r['aid'];
		$relaids[$r['nextaid']] = $r['nextaid'];
		
		$tmp =& $seq[$r['aid']];
		if(!isset($tmp)) $tmp = [];
		$tmp[] = (int)$r['nextaid'];
		
		$tmp =& $preq[$r['nextaid']];
		if(!isset($tmp)) $tmp = [];
		$tmp[] = (int)$r['aid'];
	} unset($tmp);
	$db->freeResult($q);
	
	$aniInfo = $db->selectGetAll('anidb.anime', 'id', '', 'id,airdate,dateflags,cid,year');
	$relAniInfo = [];
	foreach($aniInfo as $id=>$info) {
		if(isset($relaids[$id]))
			$relAniInfo[$id] = [(int)$info['cid'], ($info['dateflags'] & 0x23) ? 0 : (int)$info['airdate']];
	}
	unset($relaids);
	
	// filter seq/preq
	foreach($relAniInfo as $aid => $info) {
		list($cat, $airdate) = $info;
		// filter out OVAs etc from chain
		if($cat != $cats['tv series'] && $cat != $cats['web']) { // we currently only consider these types
			// delete this entry from the chain
			if(isset($seq[$aid]) && isset($preq[$aid])) {
				// link the two
				if(count($seq[$aid]) == 1 || count($preq[$aid]) == 1) { // skip if we have some insane structure
					if(count($seq[$aid]) > 1) {
						$pid = reset($preq[$aid]);
						$seq[$pid] = array_diff($seq[$pid], [$aid]);
						foreach($seq[$aid] as $sid) {
							$preq[$sid] = array_diff($preq[$sid], [$aid]);
							$preq[$sid][] = $pid;
							$seq[$pid][] = $sid;
						}
					} else {
						$sid = reset($seq[$aid]);
						$preq[$sid] = array_diff($preq[$sid], [$aid]);
						foreach($preq[$aid] as $pid) {
							$seq[$pid] = array_diff($seq[$pid], [$aid]);
							$seq[$pid][] = $sid;
							$preq[$sid][] = $pid;
						}
					}
					unset($seq[$aid], $preq[$aid]);
					unset($relAniInfo[$aid]);
				}
			} else {
				// just delete the reference
				if(isset($seq[$aid])) {
					foreach($seq[$aid] as $sid) {
						$preq[$sid] = array_diff($preq[$sid], [$aid]);
						if(empty($preq[$sid])) unset($preq[$sid]);
					}
					unset($seq[$aid]);
				}
				if(isset($preq[$aid])) {
					foreach($preq[$aid] as $pid) {
						$seq[$pid] = array_diff($seq[$pid], [$aid]);
						if(empty($seq[$pid])) unset($seq[$pid]);
					}
					unset($preq[$aid]);
				}
				unset($relAniInfo[$aid]);
			}
		} elseif($cat != $cats['web']) {
			// filter out 'web' if there's a TV series of equal standing
			// eg Steins;Gate has a season 2 as well as a 'web sequel' of the 1st season - don't identify this web special as a sequel in this case
			
			foreach(['seq' => 'preq', 'preq' => 'seq'] as $x => $y) {
				if(isset($$x[$aid])) foreach($$x[$aid] as $i => $xid) {
					if(count($$y[$xid]) > 1) {
						// check if there's a TV series amongst siblings
						$hasTv = false;
						foreach($$y[$xid] as $sibid) {
							if($relAniInfo[$sibid][0] == $cats['tv series']) {
								$hasTv = true;
								break;
							}
						}
						if($hasTv) {
							// sever this relationship
							unset($$x[$aid][$i]);
							$$y[$xid] = array_diff($$y[$xid], [$aid]);
						}
					}
				}
				if(empty($$x[$aid]))
					unset($$x[$aid]);
			}
		}
	}
	foreach($relAniInfo as $aid => $info) {
		list($cat, $airdate) = $info;
		// prequels aired later are not considered a prequel, e.g. Fate/Stay Night is NOT Fate/Zero S2
		if(!$airdate) continue; // skip if start date not known
		if(isset($seq[$aid])) foreach($seq[$aid] as $i => $sid) {
			$seqaired = $relAniInfo[$sid][1];
			if($seqaired && $seqaired < $airdate) {
				// break the sequel chain
				unset($seq[$aid][$i]);
				$preq[$sid] = array_diff($preq[$sid], [$aid]);
				if(empty($preq[$sid])) unset($preq[$sid]);
			}
			if(empty($seq[$aid])) unset($seq[$aid]);
		}
	}
	
	// traverse anime, finding the first season of items
	foreach($animes as &$anime) {
		$aid = $anime['id'];
		if(isset($preq[$aid])) {
			// TODO: if title is different enough, consider allowing this as a first season - e.g. "MF Ghost" is a sequel to "Initial D Final Stage", but its sequel should be aliased to "MF Ghost 2"
			continue;
		}
		
		$name = preg_replace('~[ _]*_(?:19|20)\d\d_$~', '', $anime['names'][0]); // strip year if present
		if($name == $anime['names'][0] && isset($aniInfo[$aid]) && preg_match('~^(19|20)\d\d~', $aniInfo[$aid]['year'])) {
			// name didn't have a year, consider adding one
			$newName = $name.filename_id(' ('.substr($aniInfo[$aid]['year'], 0, 4).')');
			if(!isset($titlesSeen[$newName])) { // skip if this name is already used
				$anime['names'][] = $newName;
				$titlesSeen[$newName] = 1;
				if(!isset($anititles[$newName]))
					$anititles[$newName] = $anime['id'];
			}
		}
		
		if(!isset($seq[$aid])) continue;
		
		$season = 1;
		// we now have a first season, so traverse sequels
		$seqs =& $seq[$aid];
		while(isset($seqs) && count($seqs) == 1 && isset($preq[$sid = reset($seqs)]) && count($preq[$sid]) == 1) { // skip titles with complex sequel/prequel structures for now
			
			// TODO: more complex naming structures, e.g. 'anime s2 part 2' style naming
			// TODO: recursive titles, e.g. if s2 has a different name, s3 should probably be caused s2 of s2
			$newName = $name.filename_id(' '.(++$season));
			if(!isset($titlesSeen[$newName])) { // skip if this name is already used
				$ak = $sid.'-'.$newName;
				$animeseq[$ak] = ['id' => $sid, 'names' => array_map(function($nam) use($season) {
					return $nam.filename_id(' '.$season);
				}, $anime['names']), 'origname' => @$mainTitles[$sid] ?: '', 'derived' => true];
				$animeseq[$ak]['names'][0] = $newName;
				
				if($anime['names'][0] == filename_id(@$mainTitles[$aid] ?: '')) {
					if(isset($aniseqtitles[$newName])) {
						if(is_array($aniseqtitles[$newName])) {
							if(!in_array($sid, $aniseqtitles[$newName]))
								$aniseqtitles[$newName][] = $sid;
						} else {
							if($aniseqtitles[$newName] != $sid)
								$aniseqtitles[$newName] = array($aniseqtitles[$newName], $sid);
						}
					}
					else
						$aniseqtitles[$newName] = $sid;
				}
			}
			
			$seqs =& $seq[$sid];
		} unset($seqs);
	} unset($anime);
	
	return [
		'cats' => $cats,
		'animes' => array_merge($animeseq, $animes),
		'anititles' => array_merge($aniseqtitles, $anititles)
	];
}

function _anidb_get_title_list($qtype) {
	global $db;
	$where_lang = ' and langid IN(1,2,4,75)';
	$opts = [];
	$deflist = [];
	switch($qtype) {
		case 's1':
			$deflist = ['Girls Bravo: First Season','GIRLSブラボー first season','Major 1st Season','Black Lagoon 1st Season','Black Lagoon First Season','Fruits Basket 1st Season','フルーツバスケット 1st season','Build Divide 1st season'];
			$where = '(name like "%1st season" or name like "% first season")'.$where_lang;
			break;
		case 'zero':
			$deflist = ['Otome 0','Birdy the Mighty Decode 0','Macross 0','Baby Princess 3D Paradise 0','Yu-Gi-Oh! Season 0','Grisaia no Meikyuu: Caprice no Mayu 0','グリザイアの迷宮 カプリスの繭 0','Steins;Gate 0','The Labyrinth of Grisaia: The Cocoon of Caprice 0','The Laws of the Universe Part 0','LayereD Stories 0','劇場版 呪術廻戦 0','Gekijouban Jujutsu Kaisen 0','Scissor Seven 0'];
			$where = 'name LIKE "% 0" AND name NOT LIKE "%episode 0"'.$where_lang;
			break;
		case 'num_suf':
			$deflist = ["Adieu 999","Adieu Galaxy Express 999","Age 12","Ai Sky Tree 21","Area 88","BJ 04","Backkom Bear: Agent 008","Barangay 143","Binetsukko flat 37","Black Jack 21","Bondage 101","Brave 10","Choujikuu Romanesque Samy: Missing 99","Choujikuu Seiki Orguss 02","Claire of the Glass: Galaxy Express 999","Condition Green: Platoon # 801","Crayon Shin-chan - Film 24","Cutey Honey 73","Cyber City Oedo 808","Cyborg 007","Cyborg 009","Deep Sea Fleet: Submarine 707","Digimon 01","Digimon Adventure 02","Eatman 98","Eien no 831","Electric Man 337","Elf 17","Eyeshield 21","Future Card Buddyfight 100","Future GPX Cyber Formula 11","GG BOND 11","GG Bond 01","GG Bond 02","GG Bond 03","GG Bond 04","GG Bond 05","GG Bond 06","GG Bond 07","GG Bond 08","GG Bond 09","GG Bond 10","GG Bond 12","Galactic Drifter Vifam 13","Galaxy Express 999","Gatchaman 94","Gate Keepers 21","Gatekeepers 21","Ginga Hyouryuu Vifam 13","Ginga Tetsudo 999","Ginga Tetsudou 999","Go! Go! 575","Golgo 13","Goodbye 999","Green Green 13","Gundam 00","Gundam Formula 91","Harlock 84","Hello Kitty's Animation Theater 10","Hello Kitty's Animation Theater 11","Hello Kitty's Animation Theater 12","Hello Kitty's Animation Theater 13","Hidamari Sketch x 365","Inazuma 11","Iron Man 28","Ironman 007","Kanon 06","Keisatsu Sensha Tai TANK S.W.A.T. 01","Keisatsu Sensha Tai TANK SWAT 01","Kidou Senshi Gundam 00","Lupin the Mysterious Thief--Enigma of the 813","Lupin the Thief--Enigma of the 813","MAD★BULL 34","Mad Bull 34","Mail Order Maiden 28","Megazone 23","Messenger of The Sun Tetsujin 28","Mob Psycho 100","Mobile Suit Gundam 00","NG Kishin Lamune & 40","NG Knight Lamune & 40","NG Knight Ramune & 40","NINETEEN 19","Nanjin 28","New Legend of the Heroes of the Warring Nations - The Ten Sanada Brave Soldiers Sanada 10","New Tetsujin 28","Nineteen - 19","Nineteen 19","Number 24","ORGUSS 02","Orguss 02","Princess 69","Round Vernian Vifam 13","Sayonara 999","Shin Seiki GPX Cyber Formula 11","Shinkai no Kantai: Submarine 707","Shonen Hollywood: Holly Stage for 49","Shonen Hollywood: Holly Stage for 50","Shounen Hollywood: Holly Stage for 49","Shounen Hollywood: Holly Stage for 50","Slime 300","Space Castaways Vifam 13","Submarine Super 99","Super Dimension Century Orguss 02","Superdimensional Romanesque Samy: Missing 99","TANK S.W.A.T. 01","Tank Police Team TANK S.W.A.T. 01","Tetsujin 28","The Prince of Tennis II OVAs vs Genius 10","Theatre of Darkness: Yamishibai 10","They Were 11","Time Bokan 24","ULTRAVIOLET Code 044","Uchuu Densetsu Ulysses 31","Ultraviolet: Code 044","Ulysses 31","Vampire Knight Guilty - 02","Vanilla Series - Bondage 101","Yami Shibai 10","gundam 79","バランガイ 143","ブラック・ジャック 21","愛・スカイツリー 21","闇芝居 10"];
			$where = 'name rlike "^[^0-9]+ [0-9]{2,3}$" and name not rlike " (movie|episode|special|ova) [0-9]{2,3}$"'.$where_lang;
			break;
		case 'eplike_num':
			$deflist = ['Record of 12 countries','Kidou Senshi Gundam: Dai 08 MS Shoutai','Figure 17: Tsubasa & Hikaru','VS Knight Ramune & 40 Fresh','Sayonara Ginga Tetsudou 999: Andromeda Shuuchakueki','Nankyoku 28 Gou','Kikaider 01 The Animation','Dragon Ball Z: Gekitotsu!! 100-oku Power no Senshi-tachi','Golgo 13: Queen Bee','Digimon Adventure 02: Zenpen Digimon Hurricane Jouriku!! - Kouhen Chouzetsu Shinka!! Ougon no Digimental','Digimon Movie 02 - Supreme Evolution','Digimon Movie 02 - Digimon Hurricane Touchdown!','Digimon Movie 02 - Golden Digimentals','Digimon Movie 02 - Digimon Hurricane and Supreme Evolutions','Digimon Adventure 02: Diablomon no Gyakushuu','Digimon Movie 02 - Return of Diablomon','Dirty Pair: Bouryaku no 005-bin','Cyborg 009: The Cyborg Soldier','NG Knight Ramune & 40 EX: Biku Biku Triangle Ai no Arashi Daisakusen','NG Knight Ramune & 40 DX: Wakuwaku Jikuu - Honoo no Daisousasen','VS Knight Ramune & 40 Fire','Kidou Senshi Gundam: Dai 08 MS Shoutai - Miller\'s Report','Digimon Movie 02 - Diaboromon no Gyakushuu','Tetsujin 28-gou','Choudendou Robo Tetsujin 28-gou FX','Iron Man 28 FX','Gekijouban Konjiki no Gash Bell!! 101 Banme no Mamono','劇場版 金色のガッシュベル!! 101番目の魔物','God Mars: The Legend of 17 Years','Ginga Tetsudou 999: Eternal Fantasy','Galaxy Express 999: Eternal Fantasy','Woof Woof 47 Ronin','Cyborg 009 Kaijuu Sensou','Cyborg 009 and the Monster Wars','Nagagutsu o Haita Neko: 80 Nichikan Sekai Isshuu','長靴をはいた猫 80日間世界一周','Puss-in-Boots: Travels Around the World in 80 Days','Ginga Tetsudou 999: Glass no Clair','Cyborg 009: Chou Ginga Densetsu','Ginga Hyouryuu Vifam: Atsumatta 13-nin','Ginga Hyouryuu Vifam: Kieta 12-nin','Galaxy Express 999: The Signature Edition','Space Symphony Maetel: Ginga Tetsudou 999 Gaiden','Space Symphonic Poem Maetel: Galaxy Express 999 Side Story','Submarine 707 Revolution','Love Machine: Animaid Shoufu 23-gou','名探偵コナン 14番目の標的(ターゲット)','DRAGON BALL Z 激突!! 100億パワーの戦士たち','Hitomi no Naka no Shounen: 15 Shounen Hyouryuuki','Anime 80 Nichikan Sekai Isshuu','アニメ 80日間世界一周','Ichigo 100%','Kaitou Lupin: 813 no Nazo','怪盗ルパン 813の謎','Digimon Movie 02 - Digimon Hurricane Touchdown! Supreme Evolution! The Golden Digimentals.','Digimon Adventure 02 - Diablomon Strikes Back','NG Knight Lamune & 40 DX','NG Knight Lamune & 40 EX','VS Knight Lamune & 40 Fire','VS Knight Lamune & 40 Fresh','New Legend of the Heroes of the Warring Nations - The Ten Sanada Brave Soldiers Sanada 10 TV','Dragon Ball Z: Super Android 13!','Dragon Ball Z: Collision! 10 Billion Powered Warriors','Ichigo 100% TV','NG Kishin Lamune & 40 EX Biku Biku Triangle Ai no Arashi Daisakusen','VS Toshi Lamune & 40 En','NG Kishin Lamune & 40 DX Wakuwaku Jiku, Hono no Daisousasen','Megazone 23 Part II: Himitsu Kudasai','Megazone 23 III','Meitantei Conan: 16 Nin no Yougisha','瞳のなかの少年 15少年漂流記','Ginga Tetsudou 999: Kimi wa Haha no You ni Aiseruka!!','Galaxy Railway 999: Can You Love Like a Mother?!!','Strawberry 100%','Lemon Cocktail - Love 30 S','Ginga Tetsudou 999: Kimi wa Senshi no You ni Ikirareruka!!','Galaxy Express 999: Can You Live Like A Warrior?','Area 88 TV','Goodbye Galaxy Railway 999: Andromeda Terminal','Ginga Tetsudou 999: Eien no Tabibito Emeraldas','Hello Harinezumi: File 170 Satsui no Ryoubun','Kidou Senshi Gundam SEED C.E. 73 Stargazer','名探偵コナン 16人の容疑者!?','Detective Conan: 16 Suspects','Princess 69: Midnight Gymnastics','Tetsujin 28-gou: Hakuchuu no Zangetsu','House of 100 Tongues','Area 88 OVA','Megazone 23 PART I','Megazone 23 PART III','MEGAZONE 23 The Third','Daughter of 20-Faces','Anata no Shiranai Kangofu: Seiteki Byoutou 24 Ji','Pro Yakyuu o 10-bai Tanoshiku Miru Houhou','Tonari no 801-chan R','Meitantei Conan: 10 Nengo no Stranger','Detective Conan: The Stranger of 10 Years','The Story of 15 Beautiful Girls Adrift','Pokemon: Movie 10 Short','Pokemon: Movie 11 Short','Pokemon: Movie 12 Short','Ginga Tetsudou 999: Diamond Ring no Kanata e','Galaxy Express 999 - Beyond the Diamond Ring','Cyborg 009: Legend of the Super Galaxy','Area 88 Gekijouban','Valkyrie Choukyou Samen Tank no Ikusa Otome 10-nin Shimai','Kidou Senshi Gundam 00 Special Edition I: Celestial Being','Kidou Senshi Gundam 00 Special Edition II: End of World','Apo Apo World: Giant Baba 90-bun Ippon Shoubu','Valkyrie Choukyou Cum Dump no Ikusa Otome 10-nin Shimai','Oppai no Ouja 48: Nanimo Kangaezu Me no Mae no Oppai Zenbu Shabure!','Dirty Pair: Flight 005 Conspiracy','Kidou Senshi Gundam 00 Special Edition III: Return the World','Gekijouban Kidou Senshi Gundam 00: A Wakening of the Trailblazer','Knights of Ramune & 40 Fresh','Mobile Suit Gundam 00 Second Season','King of Breasts 48: Don\'t Think About Anything, Just Suck All the Breasts in Front of You!','Route 20: Galactic Airport Pilot Film','Mobile Suit Gundam 00: A Wakening of the Trailblazer','Robin-kun to 100 nin no Otomodachi','Robin with his 100 Friends','Ryouma 30 Seconds','龍馬 30 Seconds','Golgo 13: The Professional','Strawberry 100% OVA','Pokemon: Movie 13 Short','Mail Order Maiden 28: The Dutch Wife','Kidou Senshi Gundam 00 Special Edition I Celestial Being','VS Toshi Ramune & 40 En','Tezuka Osamu ga Kieta?! 20 Seiki Saigo no Kaijiken','手塚治虫が消えた?! 20世紀最後の怪事件','J League o 100-bai Tanoshiku Miru Houhou!!','Pokemon: Movie 14 Short','Meitantei Conan: 11-ninme no Striker','名探偵コナン 11人目のストライカー','Ebiten: Kouritsu Ebisugawa Koukou Tenmonbu - Gigazon 23 Part II Himitsu Kudasai','Pokemon: Movie 15 Short','Mobile Suit Gundam 00 Special Edition I: Celestial Being','Mobile Suit Gundam 00 Special Edition II: End of World','Mobile Suit Gundam 00 Special Edition III: Return the World','Boys 007 Galaxies Together','Tetsujin 28 Gou Gao!','Pokemon: Movie 16 Short','Kami nomi zo Shiru Sekai: Magical Star Kanon 100%','The World God Only Knows: Magical Star Kanon 100%','Ikitemasu, 15-sai.','生きてます, 15歳.','Saikin, Imouto no Yousu ga Chotto Okashiinda ga. Moa no Hatsukoi Memory? 16 Nenme no Happy Christmas','最近, 妹のようすがちょっとおかしいんだが. 萌亜の初恋メモリー? 16年目のハッピークリスマス','Namanaka 100%!','ドラゴンボールZ 激突!! 100億パワーの戦士たち','少年ハリウッド -HOLLY STAGE FOR 49-','Taimadou Gakuen 35 Shiken Shoutai','Galaxy Express 999: Can You Love Like a Mother?','Detective Conan Movie 18: The Sniper from Another Dimension','少年ハリウッド -HOLLY STAGE FOR 50-','Rance 01: Hikari o Motomete The Animation','We Without Wings: More than 90% Flesh-Toned','Pokemon: Movie 17 Short','Pokemon: Movie 18 Short','Cyborg 009 vs. Devilman','Pocket Monsters XY Movie 18 TV Special: Hoopa no Odemashi Daisakusen','Taiko no Tatsujin: 15 Shuunenkinen Short Animation','Ulysses 31 Pilot','マイナビ賃貸 20代の部屋編','Cyborg 009: The Reopening','Ebiten: Gigazone 23 Part II - Give Us Your Secrets','Age 12.','ACCA 13-ku Kansatsu-ka','Detective Conan Movie 20 : The Darkest Nightmare','The World God Only Knows OVA: Magical Star Kanon 100%','劇場版 暗殺教室 365日の時間','Gekijouban Ansatsu Kyoushitsu: 365-nichi no Jikan','Cyborg 009: Call of Justice','Yume Oukoku to Nemureru 100 Nin no Ouji-sama','Yume Oukoku to Nemureru 100 Nin no Ouji-sama: Short Stories','Futari wa 80-sai','ACCA: 13-Territory Inspection Dept.','Original Dirty Pair: Flight 005 Conspiracy','Megazone 23 XI','Megazone 23 Sin','Sasami 14: Mahou Shoujo Pretty Sammy','Pokémon the Movie 20: I Choose You!','Itsudatte Bokura no Koi wa 10 Centi Datta.','Rance 01: The Quest for Hikari The Animation','My Neighbor 801-chan R','We Have Always Been 10 cm Apart.','Crayon Shin-chan Movie 20: The Storm Called! Me and the Space Princess','Mob Psycho 100 Reigen: Shirarezaru Kiseki no Reinouryokusha','Our Love Has Always Been 10 Centimeters Apart.','How to Steal 55 Kisses','Detective Conan Movie 21: The Crimson Love Letter','Girls und Panzer: Dai 63 Kai Sensha-dou Zenkoku Koukousei Taikai - Soushuuhen','Crayon Shin-chan Movie 21: Ridiculously Tasty! B-class Food Survival!!','The 25 Year Old High School Girl','Assassination Classroom the Movie: 365 Days Time','Assassination Classroom the Movie 365 Days','Mob Psycho 100 II','Mob Psycho 100 Reigen','Mob Psycho 100 Reigen: The Miraculous Unknown Psychic','Dai 501 Tougou Sentou Koukuu Dan Strike Witches: Road to Berlin','Strike Witches: 501 Butai Hasshin Shimasu!','ストライクウィッチーズ 501部隊発進しますっ!','Yume Oukoku to Nemureru 100-nin no Ouji-sama','Pokémon the Movie 21: Everyone\'s Story','Detective Conan Movie 22: Zero\'s Executioner','Astro Boy: Tetsuwan Atom - 10-man Kounen no Raihousha - IGZA','Detective Conan Movie 23: The Fist of Blue Sapphire','Pokémon the Movie 21: Our Story','ACCA 13-ku Kansatsu-ka: Regards','Megido 72: Nagakisen Tabi no Katawara de','Mob Psycho 100: Daiikkai Rei toka Soudansho Ian Ryokou - Kokoro Mitasu Iyashi no Tabi','Mob Psycho 100: The Spirits and Such Consultation Office\'s First Company Outing - A Healing Trip That Warms the Heart','Strike Witches: Gekijouban 501 Butai Hasshin Shimasu!','ストライクウィッチーズ 劇場版 501部隊発進しますっ!','Adieu, Galaxy Express 999: Last Stop Andromeda','Valkyrie Training: Semen Tank of 10 Battlemaid Sisters','Oi-san to 40 no Monogatari','Mob Psycho 100: Spirits and Such Company Trip - A Journey That Mends the Heart and Heals the Soul','Case File No. 221: Kabukicho','Slime Taoshite 300-nen, Shiranai Uchi ni Level Max ni Nattemashita','I\'ve Been Killing Slimes for 300 Years and Maxed Out My Level','Shin Tetsujin 28-gou','ACCA: 13-Territory Inspection Dept. - Regards','Ginga Tetsudou 999: Niji no Michishirube','Tetsujin 28: Morning Moon of Midday','Strike Witches: Dai 501 Tougou Sentou Koukuu Dan Road to Berlin','Detective Conan Movie 24: The Scarlet Bullet','Tokyo 24-ku','Mob Psycho 100 III','フェアリーテイル 100年クエスト','Fairy Tail: 100 Years Quest','FAIRY TAIL 100 YEARS QUEST','Fairy Tail: 100-nen Quest','Abandon: 100 Nuki Shinai to Derarenai Fushigi na Kyoushitsu','I\'ve Been Killing Slimes for 300 Years','Detective Conan Movie 21: Deep Red Love Letter','Detective Conan Movie 19: The Hellfire Sunflowers','Can I Make Your Ears Happy in 180 Seconds?'];
			$where = 'name rlike "^[^0-9]+ [0-9]{2,3}[^_0-9a-zA-Z][^0-9]*$" and name not rlike " (movie|episode|special|ova) [0-9]{2,3}$"'.$where_lang;
			break;
		case 'eplike_num2':
			$deflist = ['VS Knight Ramune & 40 Fresh','NG Knight Ramune & 40','NG Knight Ramune & 40 EX: Biku Biku Triangle Ai no Arashi Daisakusen','NG Knight Ramune & 40 DX: Wakuwaku Jikuu - Honoo no Daisousasen','VS Knight Ramune & 40 Fire','City Hunter: .357 Magnum','City Hunter: $1,000,000 Conspiracy','City Hunter: Plot of $1,000,000','Hamtaro\'s Birthday! - 3000 Hammy Steps in Search of Mommy','Kitty Pleasure Pack #1 (1)','Kitty Pleasure Pack #1 (2)','Kitty Pleasure Pack #2 (2)','Kitty Pleasure Pack #2 (1)','Lupin III: $1 Money Wars','Best of Kitty #4 (1)','ガンダム新体験 -0087- グリーンダイバーズ','Cyberteam in Akihabara - 2011 Summer Vacations','Best of Kitty #1 (2)','Best of Kitty #1 (1)','Best of Kitty #1 (3)','Best of Kitty #2 (3)','Best of Kitty #3 (1)','Best of Kitty #3 (2)','Best of Kitty #3 (3)','Best of Kitty #4 (2)','Best of Kitty #4 (3)','がんばれ!! タブチくん!! 第2弾 激闘ペナントレース','NG Knight Lamune & 40 DX','NG Knight Lamune & 40 EX','NG Knight Lamune & 40','VS Knight Lamune & 40 Fire','VS Knight Lamune & 40 Fresh','ななみちゃん 第2シリーズ','NG Kishin Lamune & 40','NG Kishin Lamune & 40 EX Biku Biku Triangle Ai no Arashi Daisakusen','VS Toshi Lamune & 40 En','NG Kishin Lamune & 40 DX Wakuwaku Jiku, Hono no Daisousasen','拳闘士 第1巻','Nineteen - 19','King Kong - 00 1/7 Tom Thumb','ツバサ・クロニクル 第2シリーズ','ねとらん者[もん] THE MOVIE #1 ネットのすみでブログと叫ぶ','Memories Off #5 Togireta Film','Memories Off #5 とぎれたフィルム','Condition Green: Platoon # 801','Memories Off #5','Memories Off #5 The Broken Off Film','Best of Kitty #2 (1)','Best of Kitty #2 (2)','ゼロの使い魔 第2シリーズ','彩雲国物語 第2シリーズ','Vexille - 2077 Japan National Isolation','Attack # 1','Attack #1','Vexille - 2077 Nippon Sakoku','Vampire Knight Guilty - 02','Knights of Ramune & 40 Fresh','VS Toshi Ramune & 40 En','探偵オペラ ミルキィホームズ 第2幕','Tetsujin #28: The Daytime Moon','Tetsujin #28 - The Lingering Moon of Midday','今日からマ王! 第3シリーズ','Toybox Series #3: Picture Book 1936','メジャー 第3シリーズ','メジャー 第2シリーズ','メジャー 第4シリーズ','メジャー 第5シリーズ','メジャー 第6シリーズ','探検ドリランド -1000年の真宝-','ガッ活! 第2シーズン','ガッ活! 第2シリーズ','ななみちゃん 第4シリーズ','ななみちゃん 第5シリーズ','ななみちゃん 第6シリーズ','キングダム 第2シリーズ','Sexy Anime Rama #2','Sexy Anime Rama #1','Sexy Anime Rama #4','Sexy Anime Rama #3','The Idolmaster: Cinderella Girls - 2 Shuunen Kinen PV','わしも 第2','Chiaki Kuriyama: 「0」','Marginal #4 Kiss kara Tsukuru Big Bang','美少女戦士セーラームーンCrystal 第3期','美少女戦士セーラームーンCrystal 第2期','美少女戦士セーラームーンCrystal 第1期','Space Brothers #0','Marginal #4 the Animation','ガールズ&パンツァー 第63回戦車道全国高校生大会 総集編','僕のヒーローアカデミア THE MOVIE -2人の英雄[ヒーロー]-','Fate/Grand Order x 氷室の天地 ~7人の最強偉人篇~','働くお兄さん! の2!','じょしおちっ! ~2階から女の子が... 降ってきた!?~','Astro Boy: Tetsuwan Atom - 10-man Kounen no Raihousha - IGZA','ダヤンとタマと飛び猫と ~3つの猫の物語~','キングダム 第3シリーズ','暗殺教室 第2期 課外授業編','この音とまれ! 第2クール','とんがり頭のごん太 ー2つの名前を生きた福島被災犬の物語ー','ハイキュー!! TO THE TOP 第2クール','Abandon -100ヌキしないと出られない不思議な教室-','キボウノチカラ~オトナプリキュア ‘23~','Burn the Witch #0.8: Don\'t Judge a Book by Its Cover','BURN THE WITCH #0.8: Don\'t Judge A Book By Its Cover','明治撃剣 -1874-','Burn the Witch #0.8: Don\'t Judge a Book by Its Cover'];
			$where = 'name rlike "^[^0-9]+ [^a-zA-Z0-9\'(] ?[0-9]{1,4}(\.[0-9]+)?($|[^a-z0-9A-Z)])"'.$where_lang;
			break;
		case 'eplike_title':
			$deflist = ['Abandon -100ヌキしないと出られない不思議な教室-','Aika R-16','Aika R-16 Virgin Mission','Aika: R-16','Astro Boy: Tetsuwan Atom - 10-man Kounen no Raihousha - IGZA','Black Magic M-66','Blue Lock vs. U-20 Japan','Blue Lock vs. U-20 Japan','Fairy Tail - 100 Years Quest','kikaider-01','King Kong - 00 1/7 Tom Thumb','Metal Skin Panic Madox-01','Nineteen - 19','R-15','R-15 R15少年漂流記','R-15: R15 Shounen Hyouryuuki','Shin Tennis no Ouji-sama: U-17 World Cup','Shin Tennis no Ouji-sama: U-17 World Cup Semifinal','The Prince of Tennis II: U-17 World Cup','The Prince of Tennis II: U-17 World Cup Semifinal','Vampire Knight Guilty - 02','ブラックマジックM(マリオ)-66','ブルーロック VS. U-20 JAPAN','メタルスキンパニックMADOX-01','新テニスの王子様 U-17 WORLD CUP','新テニスの王子様 U-17 WORLD CUP SEMIFINAL'];
			$where = 'name rlike "^[^0-9]+ ?- ?[0-9]{2,3}([^_0-9a-zA-Z]|$)"'.$where_lang;
			break;
		case 'many_ep':
			$deflist = ['One Piece','Meitantei Conan','Crayon Shin-chan','Doraemon (1979)','ドラえもん (1979)','ONE PIECE','Chibi Maruko-chan (1995)','Chibi Maruko-chan 2','ちびまる子ちゃん (1995)','Case Closed','クレヨンしんちゃん','名探偵コナン','Detective Conan','Nintama Rantarou','忍たま乱太郎','Ninja Boy Rantaro','Rakudai Nintama Rantarou','Nintama Rantaro','Hoka Hoka Kazoku','ほかほか家族','The Affectuous Family','Ojarumaru','おじゃる丸','Case Closed: One Truth Prevails','Detective Conan tv','One Piece TV','Shinchan','One Piece','Sore Ike! Anpanman','それいけ! アンパンマン','Oyako Club','親子クラブ','ワンピース','Gudetama','ぐでたま','Albert and Sydney','大山版ドラえもん','Doraemon Oyama Edition'];
			$where = 'epno=999 and `anidb.ep`.type=1 and `anidb.animetitle`.type!=3'.$where_lang;
			$opts['joins'] = [['inner', 'anidb.ep', 'aid']];
			break;
		case 'ext_title':
			$deflist = ['Rockman.EXE','Mt.Head','A.LI.CE','Hi.Me.Go.To','Mai-Otome 0: S.ifr','舞-乙HiME 0 ~S.ifr~','Evangelion 1.01','Takanotsume.jp','鷹の爪.jp','Peeping Life 5.0ch','Peeping Life 5.0ch','Dancing Maiden 0: S.ifr','Aldnoah.Zero','ALDNOAH.ZERO','ΛLDNOΛH.ZERO','4.eyes','Wanna Be.jk','わーなびっ.jk','Waanabi.jk','Aldnoah.Zero','himitsukesshatakanotsume.jp','A.LI.CE','Candy.zip','Candy.zip','Himitsukessha Taka no Tsume .jp','NieR:Automata Ver1.1a','NieR:Automata Ver1.1a','ニーア オートマタ Ver1.1a','A.Z2','NieR:Automata Ver1.1a','GOD.app','KamiErabi GOD.app'];
			$where = 'name rlike "\\\\.[a-z0-9]{2,4}\\\\W{0,3}$"'.$where_lang;
			break;
		case 'quotation':
			$deflist = ['Lupin Sansei Episode 0: \'First Contact\'', 'Daigo of Company \'Me\'', '攻殻機動隊 S.A.C. 2nd GIG GHOST IN THE SHELL "STAND ALONE COMPLEX"', 'Rupan Sansei Episode: 0 \'First Contact\'', 'Geobreeders: (File-X) "Get Back The Kitty"', 'Project \'City Flying in the Sky\'', 'Minna no Uta "Egao"', 'World Masterpiece Theater Complete Edition: Perrine de "En Famille"', 'The Princess Knight "Janne"', 'Hangyodon no "Hadaka no Ousama"', 'Tabi Suru Nuigurumi: Traveling "Daru"', 'Marriage Blue: "Kon\'yakusha ga Iru no ni, Doushite Konna Otoko ni......"', 'Say "I Love You"', 'Boku no Imouto wa "Osaka Okan"', 'Soukou Kihei Votoms Vol. I: Stories of the "AT Votoms"', '装甲騎兵ボトムズVOL.I STORIES OF THE "A.T.VOTOMS"', 'Soukou Kihei Votoms Vol. II: Highlights from the "AT Votoms"', '装甲騎兵ボトムズVOL.II HIGHLIGHTS FROM THE "A.T.VOTOMS"', 'Dragon Ball Z: Fukkatsu no "F"', 'Dragon Ball Z: Resurrection of "F"', 'Dragon Ball Z: Resurrection "F"', 'Dragon Ball Z: Revival of "F"', 'Old Master Q & "San-T"', 'Tabisuru Nuigurumi: Traveling "Daru"', 'Ieiri Leo x Jungle Taitei: "A Boy"', 'Love Stage!!: It Wasn\'t Just a "Little"', 'Animated Revue "Spring"', 'Kemono Friends x Anisama 2017 "Anisama"', 'SAMURAI NOODLES "THE ORIGINATOR"', 'This Hero Is Invincible but "Too Cautious"', 'Satie no "Parade"', 'The Ultimate Esper "R"', 'The Low Tier Character "Tomozaki-kun"', 'Chotto Ugoku!? "Futeneko"', 'Code Geass: Lelouch of the Rebellion Special Edition \'Black Rebellion\'', 'Busty Elf Mother and Daughter Hypnosis "Yes... Let us mother and daughter suck your highly esteemed human cock..."', 'Ensemble Stars!! Tsuioku Selection "Element"', 'Blue Archive Short Animation "Beautiful Day Dreamer"', 'Yamanaka Sadao ni Sasageru Manga Eiga "Nezumi Kozou Jirokichi"', 'Ensemble Stars!! Tsuioku Selection "Crossroad"', 'Votoms: Stories of the "AT Votoms"', 'Jashin-chan Dropkick "Seikimatsu Hen"', 'Dropkick on My Devil! "Apocalypse Arc"', 'Rinkan Gakuen: "Yamete! ...Okaasan, Minaide!"', 'Pioneer Log of the Storied Hot Springs "Alternate World\'s Springs"', 'Useless Skill "Nut Master"', 'Ensemble Stars!! Tsuioku Selection "Checkmate"', 'Tooi-san wa Seishun Shitai! "Baka to Sumaho to Romance to"', 'I am "Yingtai"'];
			$where = '(name like "% \'%\'" or name like \'% "%"\')'.$where_lang;
			break;
		default:
			echo "Query $qtype not defined!\n";
			return [];
	}
	if(isset($db))
		$list = $db->selectGetAll('anidb.animetitle', 'id', $where, '`anidb.animetitle`.id,`anidb.animetitle`.name', $opts);
	if(empty($list)) return $deflist;
	return array_unique(array_map(function($e) { return $e['name']; }, $list));
}
function anidb_get_title_list($qtype) {
	static $cache = array();
	if(isset($cache[$qtype])) return $cache[$qtype];
	return $cache[$qtype] = _anidb_get_title_list($qtype);
}

function anidb_search_anime_joinpreg($words, $word_boundaries=false) {
	$len = 0;
	$esc = array_map(function($s) use(&$len) {
		$len += strlen($s);
		return preg_quote(filename_id($s), '~');
	}, $words);
	$len = $len *2; // don't allow a distance of more than double total length - this prevents completely wild matches, although this filter is rather permissive
	$r = $word_boundaries ? '(?:\W|\W.{0,'.$len.'}?\W)?' : '.{0,'.$len.'}?';
	return '~^'.$r.implode($r, $esc).'~';
}
function anidb_search_anime_trhint($typehint, $cats) {
	if($typehint == 'oad' || $typehint == 'oav')
		return $cats['ova'];
	elseif($typehint == 'ona')
		return array($cats['ova'], $cats['web']);
	elseif($typehint == 'tv' || $typehint == 'season')
		return $cats['tv series'];
	else
		return $cats[$typehint];
}
define('ANIDB_SEARCH_LOW_LEVEN_LEVEL', 6);
function anidb_search_check_low_leven($leven_values) {
	asort($leven_values, SORT_NUMERIC);
	// our heuristic works by requiring the lowest item to be significantly lower than the second lowest, _and_ the third lowest (if exists) being relatively close to the second lowest
	foreach($leven_values as $aid=>$lev) {
		if(!isset($low1)) {
			$low1 = $lev;
			$lowAid = $aid;
		}
		elseif(!isset($low2)) $low2 = $lev;
		elseif(!isset($low3)) $low3 = $lev;
		else break;
	}
	if($low1 > 180) return false; // ignore ridiculous matches
	if(!isset($low2)) return $lowAid; // only 1 entry, return it
	if($low1/$low2 > 0.65 || $low2-$low1 < 20) // difference must be large
		return false;
	if(isset($low3) && ($low3-$low2 > ($low2-$low1)*0.6))
		return false;
	return $lowAid;
}
define('ANIDB_MATCHLEVEL_STRONG_THRESH', 3);
function anidb_search_anime($string, $hints=array(), &$matchlevel=null) {
	global $db;
	$searchstr = trim($string);
	
	extract(anidb_load_data());
	
	$typehint = ''; // tv, ova etc
	if(preg_match('~ (tv|season|o[vn]a|movie|oa[dv])\'?s?( ?\d*)$~i', $searchstr, $match)) {
		$typehint = anidb_search_anime_trhint(strtolower(trim($match[1])), $cats);
	} elseif(!preg_match('~ (tv|season|o[vn]a|movie|oa[dv])~i', $searchstr)) {
		if(@$hints['noep'])
			$typehint = array($cats['ova'], $cats['movie']);
		else
			$typehint = array($cats['tv series'], $cats['web']); // default, assume TV series; web series also slightly likely
	} else {
		// term in the middle of the name?
		// try to find a hint in the middle of the name (e.g. "One Punch Man - OVA : Road to Hero")
		if(preg_match_all('~ (tv|seasons?|o[vn]a\'?s?|movie\'?s?|oa[dv]\'?s?|s)( ?\d+|\W)~i', $searchstr, $matches) == 1) {
			$typehint = strtolower(trim($matches[1][0]));
			if($typehint == 's')
				$typehint = $cats['tv series'];
			else
				$typehint = anidb_search_anime_trhint(rtrim($typehint, 's'), $cats);
		}
	}
	if($typehint) $hints['type'] = $typehint;
	if(!empty($hints))
		ANIDB_DBG('Hints:', $hints);
	
	$doSearch = function($searchstr) use(&$animes, $anititles, $typehint, &$cats, &$db) {
		// look thru all titles and determine matchability
		$aids = array(array(), array(), array(), array(), array(), array(), array(), array(), array());
		$possible_season_no = preg_match('~ \d{1,2}$~', $searchstr);
		$checkstr = filename_id($searchstr);
		// quick path for most cases
		if(isset($anititles[$checkstr])) {
			if(is_array($anititles[$checkstr])) {
				// we've hit a rare case - get rid of the filename_id
				$checkstr2 = strtr($searchstr, array('_'=>' ','-'=>' '));
				$aid = $db->selectGetField('anidb.animetitle', 'aid', 'REPLACE(REPLACE(name,"_"," "),"-"," ")='.$db->escape($checkstr2).' AND type=1');
				if($aid) return [0, array((int)$aid)];
				// otherwise, we can't differentiate between the two - give up
				return [0, $anititles[$checkstr]];
			}
		}
		$cslen = strlen($checkstr);
		$thresh = 0 + strlen($checkstr)*4.5;
		$strwords = preg_split('~\s+~',
			preg_replace('~\:(?!$)~', '', $searchstr), // we strip colons here because filename_id won't do it properly due to its logic
		null, PREG_SPLIT_NO_EMPTY);
		// for the purposes of preg matching, remove single character words (may cause odd matches, or may even conlict (eg "nichijou +" (example of bad stripping))
		if(count($strwords) > 1) {
			$strwords2 = array_filter($strwords, function($s) {
				return strlen($s) > 1;
			});
			if(!empty($strwords2)) $strwords = $strwords2;
		}
		$preg_str = anidb_search_anime_joinpreg($strwords);
		$preg_str_pruned = null;
		$preg_str_pruned_wb = null;
		if(count($strwords) > 3) {
			$preg_str_pruned = anidb_search_anime_joinpreg(array_slice($strwords, 0, -((int)(count($strwords)/3))));
			$preg_str_pruned_wb = anidb_search_anime_joinpreg(array_slice($strwords, 0, -((int)(count($strwords)/3))), true);
			ANIDB_DBG('preg_str_pruned_wb: '.$preg_str_pruned_wb);
		} elseif(isset($strwords[1]) && in_array(end($strwords), ['ova','ona','oad','oav','special','movie','series','extra'])) {
			// last word might be an extra, so prune that
			$preg_str_pruned = anidb_search_anime_joinpreg(array_slice($strwords, 0, -1));
			$preg_str_pruned_wb = anidb_search_anime_joinpreg(array_slice($strwords, 0, -1), true);
			ANIDB_DBG('preg_str_pruned_wb: '.$preg_str_pruned_wb);
		}
		$leven_values = [];
		foreach($animes as $r) {
			$names = $r['names'];
			$check_derived = ($possible_season_no || !isset($r['derived']));
			$matchlevel = null;
			if(in_array($checkstr, $names))
				$matchlevel = 0; // we set all names to the same level as an exact match so that cases like "Fate/Stay Night: Unlimited Blade Works" will correctly match to the TV/Movie depending on type hints
			elseif(substr($names[0], 0, $cslen) == $checkstr)
				$matchlevel = 1;
			elseif($check_derived && preg_match($preg_str, $names[0]))
				$matchlevel = 2;
			// TODO: consider not doing these early?
			elseif($preg_str_pruned && $check_derived && preg_match($preg_str_pruned, $names[0]))
				$matchlevel = preg_match($preg_str_pruned_wb, $names[0]) ? 3:4;
			else {
				$lev = array_map(function($nam) use($checkstr) {
					// levenshtein() has a hard coded max length, so we need to chop up inputs...
					// other problem is that levenshtein isn't multi-byte aware, but oh well...
					return levenshtein(substr($nam, 0, 255), substr($checkstr, 0, 255), 10, 14, 7);
				}, $names);
				$min_lev = min($lev);
				if(($min_lev < 20 && $min_lev < $thresh/2))
					$matchlevel = 5;
				else {
					$in_thresh = ($min_lev < $thresh);
					if($in_thresh) {
						if(isset($leven_values[$r['id']])) {
							$leven_values[$r['id']] = min($min_lev, $leven_values[$r['id']]);
						} else {
							$leven_values[$r['id']] = $min_lev;
						}
					}
					// below, matchLevel >= ANIDB_SEARCH_LOW_LEVEN_LEVEL
					if($in_thresh)
						$matchlevel = 8;
					foreach($names as $nam) {
						// this prefers ignoring gunk at the end (sometimes appended by submitters), but has the issue of preferencing first seasons
						if(strlen($nam) > 5 && substr($checkstr, 0, strlen($nam)) == $nam) {
							$matchlevel = $in_thresh ? 6:7;
							break;
						}
					}
				}
				
				if(isset($matchlevel))
					ANIDB_DBG('Levenshtein ('.implode('/', $lev).') '.implode(' / ', $names).' <-> '.$checkstr);
			}
			if(isset($matchlevel))
				$aids[$matchlevel][$r['id']] = $r['id'];
		}
		
		ANIDB_DBG('doSearch:', $aids);
		
		// movies tend to get confused with the TV series naming, so try apply type hinting early on strongish matches first, to avoid wrongly matching with the TV series
		if(!is_array($typehint) && in_array($typehint, [$cats['movie']])) {
			$strong_aids = [];
			for($i=0; $i<ANIDB_MATCHLEVEL_STRONG_THRESH; ++$i)
				if(isset($aids[$i]))
					$strong_aids = array_merge($strong_aids, $aids[$i]);
			
			if(!empty($strong_aids)) {
				$animes = $db->selectGetAll('anidb.anime', 'id', 'id IN ('.implode(',', $strong_aids).')', 'id,year,date,eps,cid,airdate,enddate,dateflags,restricted');
				for($i=0; $i<ANIDB_MATCHLEVEL_STRONG_THRESH; ++$i) {
					if(!isset($aids[$i])) continue;
					$retAids = [];
					foreach($aids[$i] as $aid) {
						if(@$animes[$aid]['cid'] == $typehint) {
							$retAids[$aid] = $aid;
						}
					}
					if(!empty($retAids)) {
						ANIDB_DBG('Type hint filter returning level '.$i.':', $retAids);
						return [$i, $retAids];
					}
				}
			}
		}
		
		foreach($aids as $i => $aidsL) {
			if($i == ANIDB_SEARCH_LOW_LEVEN_LEVEL && count($leven_values) >= 2) {
				// check to see if we have an obvious outlier in Levenshtein matches
				// TODO: consider multiple outliers
				if($levAid = anidb_search_check_low_leven($leven_values)) {
					ANIDB_DBG('Using low (outlier) levenshtein value for '.$levAid, $leven_values);
					return [ANIDB_SEARCH_LOW_LEVEN_LEVEL, [$levAid]];
				} else {
					ANIDB_DBG('No outlier levenshtein value found', $leven_values);
				}
			}
			if(!empty($aidsL)) return [$i, $aidsL];
		}
		
		return [PHP_INT_MAX, array()];
	};
	
	
	$prev_aids = null;
	$prev_ml = null;
	$prev_searchstr = null;
	while(1) {
		$searchstr = anidb_normalize_season(strtr($searchstr, $GLOBALS['id_transforms']));
		ANIDB_DBG('Searching string: '.$searchstr);
		// query DB
		list($matchlevel, $aids) = $doSearch($searchstr);
		
		if(isset($prev_aids)) {
			if($matchlevel >= ANIDB_SEARCH_LOW_LEVEN_LEVEL && ($prev_ml < 100 || $matchlevel >= 100)) {
				ANIDB_DBG('doSearch: new matches still poor ('.$matchlevel.') - reverting to original matches');
				if(isset($prev_searchstr)) { // implies !empty($prev_aids)
					$searchstr = $prev_searchstr;
					$prev_searchstr = null;
				}
				$aids = $prev_aids;
				$prev_aids = null;
				$matchlevel = $prev_ml;
			} else {
				ANIDB_DBG('doSearch: got better matches after one filter - using new matches');
			}
			// otherwise, we got some better matches, so just go with them
		} elseif($matchlevel >= ANIDB_SEARCH_LOW_LEVEN_LEVEL && !isset($prev_aids)) {
			// if our matches our lousy, try one of the following filters to see if we get better matches
			ANIDB_DBG('doSearch: poor match level ('.$matchlevel.') - trying one more search');
			$prev_aids = $aids;
			$prev_ml = $matchlevel;
			if(!empty($aids))
				$prev_searchstr = $searchstr;
			$aids = array();
		}
		
		anidb_search_anime_aid_check:
		if(empty($aids)) {
			if(!isset($prev_aids)) ANIDB_DBG('doSearch: none found');
			
			// if this appears to be a slash title, be more cautious with stripping it
			$slashtitle = preg_match('~^(\.?hack/|22/7|4-1/2|5cm/s|bubuki/|Chiruran ?1/2|Chou ?Kadou ?Girl ?1/6|F/SN|(gekijouban )?fate/|In/Spectre|I\'ll/CKBC|Nanaka ?6/17|Ranma ?1/2|Yuusha/Brave|Z/X|ななか ?6/17|らんま ?1/2|フェイト/)~i', str_replace('／','/',$searchstr));
			// TODO: probably should replace ／ with / in the above case instead
			
			// '1080p' is almost always wrong in the title
			if(preg_match('~ (2160|1080|720|576|540|480|360)[pP] .*$~', $searchstr, $m)) {
				$searchstr = trim(substr($searchstr, 0, -strlen($m[0])));
				continue;
			// try stripping off stuff like "season 2" or 's2' and 'dvd', 'bd' etc
			} elseif(preg_match('~ ((?:19|20)\d\d|\((?:19|20)\d\d\))$~', $searchstr, $m)) { // try stripping off the year if it's there
				$searchstr = trim(substr($searchstr, 0, -strlen($m[0])));
				continue;
			} elseif(substr_count($searchstr, '!') + substr_count($searchstr, '?') == 1) { // also try removing !'s
				$searchstr = trim(str_replace('?', ' ', str_replace('!', ' ', $searchstr)));
				continue;
			} elseif(($p = strpos($searchstr, ' / ')) || (($p = strpos($searchstr, '／')) && !$slashtitle) || ($p = strpos($searchstr, ' | ')) || ($p = strpos($searchstr, ' + '))) {
				// maybe an alternative title is given, eg "Your Name / Kimi no na wa" - we'll just try the first
				$searchstr = trim(substr($searchstr, 0, $p));
				continue;
			} elseif($searchstr != ($us2 = preg_replace("~[\x80-\xFF]+~", ' ', $searchstr)) && strlen(trim($us2)) / strlen($searchstr) > 0.2) { // try stripping unicode characters (only if this doesn't strip most of the title (to deal with foreign titles being stripped to the episode number or similar))
				$searchstr = trim($us2);
				continue;
			} elseif(preg_match('~-? (tv|o[vn]a|(?:the )?movie|oa[dv])\'?s?$~i', $searchstr, $match)) {
				$searchstr = trim(substr($searchstr, 0, -strlen($match[0])));
				continue;
			} elseif(($p = strrpos($searchstr, ':')) && !preg_match('~\([^)]*?\:.*?\)~', $searchstr)) { // strip explanations; hacky approach to avoid stripping colons within brackets, e.g. '[MiniFreeza] Fire Force (Enn Enn no Shouboutai: Ni no Shou) - S02E01 [1080p x264][WebRIP][English Dub]'
				$searchstr = trim(substr($searchstr, 0, $p));
				continue;
			} elseif(preg_match('~ \([^()]+\)$~i', $searchstr, $match)) { // maybe an alias?
				$searchstr = trim(substr($searchstr, 0, -strlen($match[0])));
				continue;
			} elseif(preg_match('~ \([^()]+\) ~i', $searchstr)) {
				// alternative title stuck inside the title? e.g. "Kaguya sama wa Kokurasetai?: Tensai tachi no Renai Zunousen (Kaguya sama: Love is War) - 2"
				$searchstr = trim(preg_replace('~ +\([^()]+\) +~i', ' ', $searchstr));
				continue;
			} elseif((($p = strpos(str_replace('／','/',$searchstr), '/')) && !$slashtitle) || ($p = strpos($searchstr, '|'))) {
				// same alternative check as above, but with less confidence, e.g. "Garden of Words/Kotonoha no Niwa"; breaks stuff like "Fate/stay night" so manually exclude them
				// exclusions use query: `SELECT * FROM `animetitle` where name rlike '^.{0,8}/' and langid in (1,2,4,75) order by name` (choosing select titles only)
				$searchstr = trim(substr($searchstr, 0, $p));
				continue;
			} else {
				$newstring = preg_replace(array(
					//'~ s(?:eason)? ?\d+$~i',
					'~-? (?:dvd|tv|bd|remastered|prequel|drama)$~i',
				), '', $searchstr);
				if($newstring != $searchstr) {
					$searchstr = trim($newstring);
					continue;
				}
				
				// still here? - try to remove movie/episode names if given
				$newstring = preg_replace(array(
					'!(.+) [~-] .+?$!',
					'!(.+) ([~-]).+?\2$!',
				), '$1', $searchstr);
				if($newstring != $searchstr) {
					$searchstr = trim($newstring);
					continue;
				}
				
				// try removing arbitrary bracketed terms
				$newstring = preg_replace(array(
					'~\([^()]+\)~',
					'~\[[^\[\]]+\]~',
				), '', $searchstr);
				if($newstring != $searchstr) {
					$searchstr = trim($newstring);
					continue;
				}
				
			}
			
			if(isset($hints['title_alt'])) {
				ANIDB_DBG('doSearch: trying alternative title: '.$hints['title_alt']);
				$searchstr = $hints['title_alt'];
				unset($hints['title_alt']);
				continue;
			}
			
			if(isset($prev_aids) && !empty($prev_aids)) {
				ANIDB_DBG('doSearch: no more searches can be done, using our poor matches');
				$aids = $prev_aids;
				$searchstr = $prev_searchstr;
				goto anidb_search_anime_aid_check;
			}
			
			return false; // can't find
			
		} elseif(count($aids) == 1) {
			// found exact match
			ANIDB_DBG('doSearch: found');
			return reset($aids);
		} else {
			
			ANIDB_DBG('doSearch: found '.count($aids).' items');
			
			// grab details
			$animes = $db->selectGetAll('anidb.anime', 'id', 'id IN ('.implode(',', $aids).')', 'id,year,date,eps,cid,airdate,enddate,dateflags,restricted');
			
			// filter out music video cid
			foreach($animes as $aid => $a) {
				if($a['cid'] == $cats['music video'])
					unset($animes[$aid]);
			}
			if(empty($animes))
				ANIDB_DBG('All results were music video and filtered out');
			
			// note that this filter isn't absolute (it can be bypassed with a 'good' match)
			foreach($animes as $aid => $a) {
				if($a['restricted'])
					unset($animes[$aid]);
			}
			if(empty($animes))
				ANIDB_DBG('All results were 18+ and filtered out');
			
			$try_filter = function($name, callable $f) use(&$animes) {
				if(empty($animes)) return;
				$fanimes = array_filter($animes, $f);
				if(!empty($fanimes)) {
					$a = count($animes);
					$b = count($fanimes);
					if($a != $b) {
						ANIDB_DBG($name.' success: '.$a.' -> '.$b);
						$animes = $fanimes;
					} else {
						ANIDB_DBG($name.' ineffective: '.$a);
					}
				} else {
					ANIDB_DBG($name.' failed');
				}
			};
			
			// try year filter
			if(preg_match('~\W((?:20|19)\d\d)[\])]($|\W)~', $searchstr, $m)) { // perhaps use $string instead of $searchstr?
				$try_filter('Year filter', function($anime) use($m) {
					return in_array($m[1], preg_split('~[-,]~', $anime['year']));
				});
			}
			
			// try type hint filtering
			if($typehint) {
				$try_filter('Type hinting', function($anime) use($typehint) {
					if(is_array($typehint))
						return in_array($anime['cid'], $typehint);
					else
						return $anime['cid'] == $typehint;
				});
			}
			
			// TODO: should we do an exact name check here?
			// we'll do an exact match with main name filter regardless; this is useful for the anime named "Charlotte"
			$checkstr = filename_id($searchstr);
			if(isset($anititles[$checkstr]) && !is_array($anititles[$checkstr]) && isset($animes[$anititles[$checkstr]])) {
				ANIDB_DBG('Matched via filename_id');
				return (int)$anititles[$checkstr];
			}
			
			$aninames = null;
			if(!empty($animes)) {
				// grab names
				$names = $db->selectGetAll('anidb.animetitle', 'id', 'aid IN ('.implode(',', array_keys($animes)).') AND '.anidb_lang_where());
				$aninames = array();
				foreach($names as $e) {
					isset($aninames[$e['aid']]) or $aninames[$e['aid']] = array();
					$aninames[$e['aid']][] = $e['name'];
				}
			}
			
			// text matching is problematic on sequels due to the number at the end having little impact on weight, so we try to explicitly handle this by filtering out entries which don't match the number at the end
			if(isset($aninames) && preg_match('~(?<!\s(?:part|cour))\s(\d{1,2})$~i', $searchstr, $m)) {
				if((int)$m[1] > 1) {
					$try_filter('Sequel filter', function($anime) use($m, &$aninames) {
						if(empty($aninames[$anime['id']])) return true;
						foreach($aninames[$anime['id']] as $name) {
							if(preg_match('~\s(\d{1,2})($|: )~', $name, $m2) && $m[1] == $m2[1]) {
								return true;
							}
						}
						return false;
					});
				} elseif((int)$m[1] == 1) {
					$try_filter('No-sequel filter', function($anime) use($m, &$aninames) {
						if(empty($aninames[$anime['id']])) return true;
						foreach($aninames[$anime['id']] as $name) {
							if(preg_match('~\s(\d{1,2})($|: )~', $name, $m2) && $m2[1] != 1) {
								return false;
							}
						}
						return true;
					});
				}
			}
			
			// if alternative title exists, try overlapping two searches
			if(isset($hints['title_alt']) && count($animes) > 1) {
				$title_alt = $hints['title_alt'];
				unset($hints['title_alt']);
				$alt_ml = null;
				$alt_animes = anidb_search_anime($title_alt, $hints, $alt_ml);
				if(!empty($alt_animes)) {
					if(!is_array($alt_animes)) {
						if(isset($animes[$alt_animes])) {
							ANIDB_DBG('Matched via title_alt');
							return $alt_animes;
						} elseif($matchlevel >= $alt_ml + 5) {
							ANIDB_DBG("title_alt matches much more likely ($matchlevel -> $alt_ml)");
							return $alt_animes;
						} else {
							ANIDB_DBG('Alt title filter failed - title_alt matched with '.$alt_animes.' not in set ['.implode(',', array_keys($animes)).']');
						}
					} else {
						$try_filter('Alt title', function($anime) use($alt_animes) {
							return isset($alt_animes[$anime['id']]);
						});
					}
				}
			}
			
			// try different name matching
			// TODO: this resolves "ma ihime" to "mai-hime" and not "maihime" which is somewhat undesirable, since the query should be indeterminate
			if(!empty($animes) && isset($aninames)) {
				// do matching
				$dn = anidb_search_dumbname($searchstr);
				$fanimes = array();
				foreach($animes as $aid => $anime) {
					if(!empty($aninames[$aid])) foreach($aninames[$aid] as $name) {
						$name = anidb_search_dumbname($name);
						// try stripping "tv" off the end
						if($name == $dn || preg_replace('~\s+tv$~i', '', $name) == $dn) {
							$fanimes[$aid] = $anime;
						}
						// stripping off "OVA" might be too much...
					}
				}
				if(!empty($fanimes)) {
					ANIDB_DBG('dumbname filter applied: '.count($animes).' -> '.count($fanimes));
					$animes = $fanimes;
				} else {
					ANIDB_DBG('dumbname filter failed');
				}
			}
			
			// try filtering shows which air in the future (this screws up previews, but if we're already at this point where we can't decide which is correct, may as well take a stab)
			$cutoff = time() + 45*86400;
			$try_filter('Future filter', function($anime) use($cutoff) {
				return $anime['airdate'] && $anime['airdate'] < $cutoff;
			});
			
			
			if(count($animes) == 1) {
				ANIDB_DBG('result: 1 entry matched');
				$a = reset($animes);
				return (int)$a['id'];
			} else {
				ANIDB_DBG('result: '.count($animes).' entries match');
				return $animes;
			}
		}
	}
}

function anidb_search_group($string) {
	global $db;
	$string = trim(str_replace(array('_'), ' ', $string)); // strip these completely
	$rep_start = 'REPLACE(';
	$rep_end = ',"_"," ")';
	
	if(strlen(preg_replace('~[^a-zA-Z0-9]~', '', $string)) < 2)
		// name is mostly non-alphanumeric
		$matchstr = '= '.$db->escape($string);
	else
		$matchstr = 'RLIKE '.$db->escape('^'.preg_replace('~[^a-zA-Z0-9]~u', '[^a-zA-Z0-9]', $string).'$');
	// TODO: replace filters on name?
	$groups = $db->selectGetAll('anidb.group', 'id', $rep_start.'name'.$rep_end.' '.$matchstr.' OR '.$rep_start.'shortname'.$rep_end.'='.$db->escape($string), 'id,name,shortname');
	
	if(empty($groups))
		return false;
	elseif(count($groups) == 1) {
		reset($groups);
		return (int)key($groups);
	} else {
		// TODO: are there any meaningful filters that can be applied?
		
		
		return $groups;
	}
}

function &anidb_search_dumbname($s) {
	// exclamation kept for K-On!! type naming schemes; same for question mark
	// always replace underscore with space, because this is the behaviour of parse_filename
	$s = strtolower(str_replace('_', ' ', $s));
	$ds = preg_replace('~[^a-z0-9]~', '', filename_id($s));
	if($ds !== '') return $ds;
	return $s; // fallback, eg for groups like -__-'
}

function anidb_getadetails($aid) {
	global $db;
	$where = 'id='.(int)$aid;
	$ret = $db->selectGetArray('anidb.anime', $where);
	if(empty($ret)) return false;
	
	if(!($ret['dateflags'] & 0x23) && $ret['airdate']) // if start day/month/year all known
		$ret['startdate'] = (int)$ret['airdate'];
	if(($ret['dateflags'] & 0x4C) || !$ret['enddate']) // if any part of the end date is not known
		unset($ret['enddate']);
	else
		$ret['enddate'] = (int)$ret['enddate'];
	
	$_types = $db->selectGetAll('anidb.cat', 'id', 'id LIKE "cat:%"', 'id,name');
	$types = array();
	foreach($_types as $type) {
		$type['name'] = strtolower($type['name']);
		if($type['name'] == 'tv series') $type['name'] = 'tv';
		$types[substr($type['id'], 4)] = $type['name'];
	}
	unset($_types);
	
	$ret['type'] = $types[$ret['cid']];
	
	
	$episodes = $db->selectGetAll('anidb.ep', 'id', 'a'.$where, 'id,name,aired,epno,length,type', array('order' => 'type, epno'));
	$ret['eps'] = array();
	$epgroup = 0; $ep_pmycount = 0; $last_aired = 0; $epCurGroup = null;
	$epTypeMap = array(null,'','S','C','T','P','O');
	foreach($episodes as $ep) {
		// emulate the old epgroup etc
		if($epCurGroup != $ep['type']) {
			++$epgroup;
			$epCurGroup = $ep['type'];
		}
		if($epgroup < 2) ++$ep_pmycount;
		
		$ret['eps'][$ep['id']] = array(
			'epgroup' => $epgroup,
			'num' => $epTypeMap[$ep['type']] . $ep['epno'],
			'title' => $ep['name'],
			'duration' => (int)$ep['length'],
			'aired' => (int)$ep['aired'],
		);
		$last_aired = max($last_aired, (int)$ep['aired']);
	}
	$ret['eps_pmycount'] = $ep_pmycount;
	if($last_aired) $ret['last_aired'] = $last_aired;
	
	
	$groups = $db->selectGetAll('anidb.group', 'id', 'id IN(SELECT DISTINCT gid FROM anidb.file WHERE a'.$where.')', 'id,name,shortname');
	$ret['groups'] = array();
	foreach($groups as $grp) {
		$ret['groups'][$grp['id']] = array(
			'name' => $grp['name'],
			'name_alt' => $grp['shortname']
		);
	}
	
	$title_aids = [$aid];
	$ret['relations'] = array();
	$rel = $db->selectGetAll('anidb.seq', 'id', '`anidb.seq`.a'.$where, '`anidb.anime`.cid, `anidb.seq`.type, `anidb.seq`.id, nextaid', array('joins' => array(
		array('inner', 'anidb.anime', 'nextaid', 'id')
	)));
	$relMap = array(
		1 => 'sequel',
		2 => 'prequel',
		11 => 'same setting',
		12 => 'same setting',
		21 => 'alternative setting',
		22 => 'alternative setting',
		31 => 'alternative version',
		32 => 'alternative version',
		41 => 'character',
		42 => 'character',
		51 => 'side story',
		52 => 'parent story',
		61 => 'summary',
		62 => 'full story',
		100 => 'other',
	);
	foreach($rel as $r) {
		$ret['relations'][$r['nextaid']] = array(
			'relation' => $relMap[$r['type']],
			'type' => $types[$r['cid']]
		);
		$title_aids[] = $r['nextaid'];
	}
	
	$langs = anidb_cat_data(false);
	$titles = $db->selectGetAll('anidb.animetitle', 'id', 'aid IN('.implode(',', $title_aids).') AND (type=1 OR (type=4 AND langid IN('.$langs['english'].','.$langs['japanese (transcription)'].','.$langs['korean (transcription)'].','.$langs['chinese (transcription)'].')))', 'id,aid,type,name');
	foreach($titles as $title) {
		if($title['aid'] == $aid)
			$ainfo =& $ret;
		else
			$ainfo =& $ret['relations'][$title['aid']];
		if($title['type'] == 1)
			$ainfo['title'] = $title['name'];
		else {
			if(!isset($ainfo['title_alt'])) $ainfo['title_alt'] = [];
			$ainfo['title_alt'][] = $title['name'];
		}
	} unset($ainfo);
	
	return $ret;
}

function anidb_getagfiles($aid, $gid) {
	global $db;
	$ret = $db->selectGetAll('anidb.file', 'id', 'aid='.(int)$aid.' AND gid='.(int)$gid, 'id,eid,size,crc,ed2k,released,length');
	foreach($ret as &$r) {
		if($r['crc']) {
			$r['crc32'] = bin2hex($r['crc']);
			unset($r['crc']);
		}
		if($r['ed2k'])
			$r['ed2k'] = bin2hex($r['ed2k']);
	}
	return $ret;
}

// tries to fix unbalanced brackets
function parse_filename_fix_brackets($s, $MAX_DEPTH=1) {
	// fix case where someone forgot the opening bracket, e.g. "DeadFish] SK∞ - 02 [720p][AAC].mp4"
	$s = preg_replace('~^[a-zA-Z0-9\\-]+\\] ~', '[$0', $s);
	$stack = []; $ret = '';
	$closing = ['['=>']','{'=>'}','('=>')'];
	$pastPrefix = false;
	while(($ns = strpbrk($s, '[{()}]')) !== false) {
		$word = substr($s, 0, -strlen($ns));
		$char = $ns[0];
		$s = substr($ns, 1);
		
		$ret .= $word;
		if(isset($closing[$char])) { // opening bracket
			$stack[] = [$closing[$char], strlen($ret)];
			$ret .= $char;
		} else { // closing bracket
			if(empty($stack) && !$pastPrefix) {
				if($char == ']' && @$s[0] == '[') {
					// special case: handle as ']['=>'II' case (this only works if it's not itself within brackets)
					$s = substr($s, 1);
					$ret .= 'II';
				}
				// otherwise, this is an invalid token
			} else {
				if(!empty($stack)) {
					$top = $stack[count($stack)-1];
					if($top[0] != $char) {
						// this is either an invalid closing bracket, or an earlier closing bracket was forgotten
						$p = strpos($s, $top[0]);
						if($p !== false && strpbrk(substr($s, 0, $p), '[{(') === false) {
							// a proper closing bracket was found for the top of the stack -> maybe consider current as invalid?
							// we'll also check the stack
							$endIsInvalid = true;
							foreach($stack as $item) {
								if($item[0] == $char) { // closing bracket in stack, this is likely badly formed, so fix it below (e.g. "a[b(c]d)" => "a[b(c)]d")
									$endIsInvalid = false;
									break;
								}
							}
							if($endIsInvalid)
								continue;
						}
					}
				}
				while(!empty($stack)) {
					$top = array_pop($stack);
					$ret .= $top[0];
					if($top[0] == $char) break;
				}
				if(!isset($closing[@$s[0]]))
					$pastPrefix = true;
				if(empty($stack)) {
					// invalid closing bracket, drop it
				}
			}
		}
	}
	// remove open brackets that weren't closed
	while(!empty($stack)) {
		$top = array_pop($stack);
		$ret = substr($ret, 0, $top[1]).substr($ret, $top[1]+1);
	}
	$ret .= $s;
	
	// strip nested brackets as they're problematic and likely contain info we don't care about
	// e.g. "[ToonsHub] The Quintessential Quintuplets* S01E02 1080p B-Global WEB-DL AAC2.0 H.264 (The Quintessential Quintuplets Specials 2, Gotōbun no Hanayome* (Honeymoon Special), Multi-Subs)"
	if($MAX_DEPTH >= 0) {
		$s = $ret;
		$ret = '';
		$depth = 0;
		while(($ns = strpbrk($s, '[{()}]')) !== false) {
			$word = substr($s, 0, -strlen($ns));
			$char = $ns[0];
			$s = substr($ns, 1);
			
			if($depth <= $MAX_DEPTH) $ret .= $word;
			if(isset($closing[$char])) { // opening bracket
				++$depth;
				if($depth <= $MAX_DEPTH) $ret .= $char;
			} else {
				if($depth <= $MAX_DEPTH) $ret .= $char;
				--$depth;
			}
		}
		$ret .= $s;
	}
	
	return $ret;
}

function pfn_trim($s) {
	return trim($s, " \n\r\t\v\0,-#");
}
// returns pipe separated words that correspond with a season number, useful for regex
function pfn_season_word($season) {
	$season = (int)$season;
	if($season < 1) return $season.'th';
	if($season < 10) {
		$map = ['', '1st|first', '2nd|second', '3rd|third', '4th|fourth', '5th|fifth', '6th|sixth', '7th|seventh', '8th|eighth', '9th|ninth'];
		return $map[$season];
	} else {
		$lastDigit = $season % 10;
		if($season < 20 || ($lastDigit > 3))
			return $season.'th';
		else {
			$map = ['th', 'st', 'nd', 'rd'];
			return $season.$map[$lastDigit];
		}
	}
}

function pfn_is_eplike_title($title, $ep, $eptext='') {
	static $eplike_matches = null;
	if($eptext && stripos($eptext, 'e') !== false) return false;
	if(!isset($eplike_matches)) {
		$eplike_matches = [];
		foreach(anidb_get_title_list('eplike_title') as $t) {
			if(!preg_match('~^(.*?) ?- ?(\d{2,3})~', $t, $m)) continue;
			$eplike_matches[filename_id($m[1].'-'.((int)$m[2]))] = 1;
		}
	}
	
	return isset($eplike_matches[filename_id(pfn_trim($title).'-'.$ep)]);
}

// try to get info from a filename
function &parse_filename($fn, $batch_hint=null) {
	$fn = preg_replace('!(\d) ?~ ?(\d)!', '$1-$2', $fn); // episode fix, e.g. 01~13
	$fn = pfn_trim(strtr($fn, array('_'=>' ', ' ~ ' => ' - ', '~'=>' ', '【' => '[', '】' => ']', '｜' => '|', '`' => '\''))); // dots might be needed for episode numbers like "10.5"
	$ret = array();
	
	// if too short, we'll say it doesn't seem right and bail
	if(!isset($fn[3])) return $ret; // must be at least 4 chars
	
	$extension_rx = '\.(avi|mk[vsa]|mp4|og[gmv]|rm|rmvb|flv|f4v|webm|vob|m2ts|mpe?g|mov|m4[av]|asf|mp[23]|aac|ac3|dts|flac|ape|wav|wm[av]|opus|jpe?g|bmp|png|gif|tiff?|zip|rar|[0r]\d\d|part\d\d?|7z|xdelta|iso|txt|nfo|sfv|md5|ass|ssa|srt|docx?|pdf|html?|exe|ttf)';
	
	// strip extension (+ some trailing gunk like in "[horrible] Radiant S2 01 [480p].mkv.")
	if(preg_match('~\.([a-z0-9]{2,4}|torrent|xdelta|patch)\W{0,3}$~i', $fn, $match)) {
		// exclude titles that look like they have extensions
		$extTitles = anidb_get_title_list('ext_title');
		$extTitles = implode('|', array_unique(array_map(function($t) {
			return preg_quote(filename_id($t), '~');
		}, $extTitles)));
		if(!preg_match('~(^|[^a-zA-Z0-9])(?:'.$extTitles.')$~', filename_id($fn))) {
			$ret['ext'] = strtolower($match[1]);
			$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
		}
	}
	// strip excess extensions
	if(preg_match('~('.$extension_rx.')+$~i', $fn, $match)) {
		$ret['extraext'] = strtolower($match[0]);
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
	}
	$fn = parse_filename_fix_brackets($fn);
	
	// Yameii's alt title detection, e.g. "[Yameii] My Hero Academia - S00E18 [English Dub] [CR WEB-DL 1080p] [8F632669] (Boku no Hero Academia: Memories)"
	if(preg_match('~^(\[Yameii\].*\]) ?\(([^)]+?)\)$~', $fn, $match)) {
		$ret['title_alt'] = $match[2];
		$fn = pfn_trim($match[1]);
	}
	
	// strip CRC
	if(preg_match('~\.?[\[({]([0-9a-f]{8})[\])}]$~i', $fn, $match)) {
		$ret['crc'] = strtolower($match[1]);
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
	}
	// since this pattern is difficult to match, we may as well also strip off anything that looks like a CRC
	elseif(preg_match('~[\[({]([0-9a-fA-F]{8})[\])}]~', $fn, $match, PREG_OFFSET_CAPTURE)) {
		$ret['crc'] = strtolower($match[1][0]);
		$fn = pfn_trim(pfn_trim(substr($fn, 0, $match[0][1])) .' '. pfn_trim(substr($fn, $match[0][1]+strlen($match[0][0]))));
	}
	
	// some raws have this
	$fn = preg_replace([
		'~ 第(\d+(?: ?[\\-–] ?\d+)?)話($| )~',
		'~ 全(\d+)話($|[+ ])~',
		'~ 第(\d+(?: ?[\\-–] ?\d+)?)巻($| )~',
		// someone actually does this
		'~ Episode One($|[+ ])~i',
		
		'~ # (?!\d)~', // from "[Stay Gold] Ojamajo Doremi # Audio Drama: Secret ♥ Story"
		
		// random crap
		'~\[rarbg\]$~i',
		'~\Wrarbg$~i',
		'~\[国漫\]~i',
	], [
		' Episode $1$2',
		' Episodes 1-$1$2',
		' Vol $1$2',
		' Episode 01$2',
		
		' - ',
		
		'',
		'',
		'',
	], $fn);
	
	// annoying unicode character
	$fn = strtr($fn, ['–' => '-']);
	
	// One Pace gets treated specially
	if(preg_match('~^\[(One Pace)\](?:\[([0-9\\-]+)\])?~i', $fn, $m)) {
		$ret['title'] = $m[1];
		if(isset($m[2])) {
			if(strpos($m[2], '-'))
				$ret['eprange'] = $m[2];
			else
				$ret['ep'] = (int)$m[2];
		}
		$fn = ltrim(substr($fn, strlen($m[0])));
		$info = array();
		while(preg_match('~\[([^\\]]+)\]$~', $fn, $m)) {
			$info[] = $m[1];
			$fn = rtrim(substr($fn, 0, -strlen($m[0])));
		}
		$info = array_reverse($info);
		foreach($info as $i=>$inf)
			$ret['info'.($i ? $i+1:'')] = $inf;
		$ret['eptitle'] = $fn;
		$ret['noep'] = false;
		return $ret;
	}
	
	// deal with YURASUKA's naming scheme, e.g. "We.Never.Learn.BOKUBEN.S01.1080p.BluRay.10-Bit.FLAC2.0.x265-YURASUKA (Bokutachi wa Benkyou ga Dekinai)"
	// also handle scene naming + bracketed term hybrids, e.g. "SPYxFAMILY.S01E13.1080p.DSNP.WEB-DL.DDP2.0.H.264-VARYG.mkv (Multi-Sub)"
	if(preg_match('~^([^ ()\[\]]+-[a-zA-Z]+(?:\.[a-z]{2,5})?) \(([^()]+)\)$~', $fn, $match)) {
		$fn = pfn_trim($match[1]);
		$ret['title_alt'] = pfn_trim($match[2]);
	}
	// handle NanDesuKa's naming scheme, e.g. "Mushoku Tensei - Isekai Ittara Honki Dasu - S01 - 720p WEB H.264 -NanDesuKa (FUNi)"
	if(preg_match('~ -(NanDesuKa|Tsundere-Raws)( \(.*)?$~i', $fn, $match)) {
		$ret['group'] = $match[1];
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0]))) . (isset($match[2]) ? $match[2] : '');
	}
	// like above, but VARYG doesn't include a space
	// e.g. TONIKAWA Over The Moon For You S02 1080p CR WEB-DL AAC2.0 H 264-VARYG (Tonikaku Kawaii, Dual-audio, Multi-Subs)
	if(preg_match('~(?<=\d|multi|dual)-(VARYG|anotherone|FLUX|CRUCiBLE|Emmid)( \(.*)?$~i', $fn, $match)) {
		$ret['group'] = $match[1];
		if(isset($match[2]))
			$ret['info'] = $match[2];
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
		// handle trip-ups in VARYG's naming scheme; might eventually lessening the strictness
		if(preg_match('~[. \\-]S\d+(?:E\d+.*?)?([. ](?:720p|1080p)[. ][a-z0-9]{2,5}[. ][a-z0-9\\-]{2,10}[. ][a-z]{2,4}\d[. ]\d[. ]H[. ]26\d(?:[. ]MULTI|[. ]DUAL)?)$~i', $fn, $match)) {
			if($match[0][0] == '-') { // e.g. "TSUKIMICHI-Moonlit Fantasy-S02E08"
				// add spaces around the dash
				$fn = pfn_trim(substr($fn, 0, -strlen($match[0]))).' - '.pfn_trim(substr($match[0], 1));
			}
			$fn = pfn_trim(substr($fn, 0, -strlen($match[1])));
		}
	}
	// handle quoted alternative names, e.g. [Bunny-Apocalypse] The Demon Girl Next Door - S02 [WEB-DL 2160p HEVC AAC] "Machikado Mazoku: 2-choume"
	if(preg_match('~[\\])] "([^[("]+)"$~', $fn, $match)) {
		$ret['title_alt'] = $match[1];
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0]) +1));
	}
	// handle Anime Time's suffixed naming
	// e.g. "[Anime Time] Yuru Yuri (S01+S02+S03+OVAs) [BD] [1080p][HEVC 10bit x265][AAC][Eng Sub] YuruYuri Happy Go Lily"
	if(preg_match('~(?:\[[^\]]+\]\s?){2,}( [a-zA-Z0-9 :;\\-!?,.\'@%_+]+)$~', $fn, $match)) {
		// but don't get fooled by "[US] [BDMV] ROSARIO VAMPIRE"
		$new_fn = pfn_trim(substr($fn, 0, -strlen($match[1])));
		if(!preg_match('~^(\[[^\]]+\]\s?)+$~', $new_fn)) {
			$ret['title_alt'] = pfn_trim($match[1]);
			$fn = $new_fn;
		}
	}
	// handle LostYears' weird alternative naming format
	// e.g. "[LostYears] Date A Live - S05 (CR WEB-DL 1080p x264 AAC E-AC-3) [Dual-Audio] [Multi-Sub] | Date・A・Live V (Season 05, 49-60, S5 - 01-12, Batch) (Japanese, English Dubs)"
	if(preg_match('~^(\[[^\]]+\] [a-zA-Z0-9].*?\]) \| ([a-zA-Z0-9][^|]+)$~', $fn, $match)) {
		$fn = $match[1];
		$ret['title_alt'] = $match[2]; // may include junk terms
	}
	// handle KuroNeko's wtf naming scheme
	// e.g. "Versailles.no.Bara.Movie.2025.MULTI.Audio.Sub[FRE][ENG][GER][ITA][SPA][POR][GRE][CHI][ARA][KOR].1080p.BDRip.CUSTOM.x264.DTSHDMA.DTS.PCM.EAC3-KuroNeko VF VOSTFR The Rose of Versailles (Movie) ベルサイユのばら"
	if(preg_match('~^([^ ]+)\.(?:E?AC3|PCM|DTS|DTSHDMA|TrueHD)-(KuroNeko) ~', $fn, $m)) {
		$fn = preg_replace('~\.(?:MULTI|DUAL)(?:Sub)?[.[].+$~i', '', $m[1]);
		$ret['group'] = $m[2];
	}
	// another "partial scene" hybrid naming scheme
	// e.g. "Gakuen.Heaven.S01.1080p.CR.WEB-DL.AAC2.0.x264-TROLLORANGE | 学園ヘヴン / Boy's Love Scramble (2006)"
	if(preg_match('~^([^ ]+\.\d+p\.[^ ]+)\.x26[45]-([^ .]+) ?[|/] ?(.*)$~i', $fn, $m)) {
		$fn = $m[1];
		$ret['group'] = $m[2];
		$ret['title_alt'] = $m[3];
	}
	// handle Valenciano's weird spacing with season/episode indicators, e.g. "[Valenciano] Komi-san wa, Komyushou Desu (Komi Can`t Communicate) -S02E02 [1080p][AV1 10 bit][Opus][Multi-Sub]"
	$fn = preg_replace('! (-)(S\d+E\d+) (?=\[)!', ' \\1 \\2 ', $fn);
	// TODO: perhaps just handle all bad spacing surrounding "-SxxExx" forms
	
	
	$spc = ' ';
	$dotAsSpc = false;
	// scene naming uses dots instead of spaces
	if(strpos($fn, '.') && substr_count($fn, ' ') < 2) {
		$spc = '[. ]';
		$dotAsSpc = true;
	}
	$pregEp = '(?:\d{2,3}|1[0-8]\d\d)'; // restricted episode identifier
	$longSeries = anidb_get_title_list('many_ep');
	if(preg_match('~(?:'.implode('|', str_replace(' ', $spc, array_map(function($s) {
		return preg_quote($s, '~');
	}, $longSeries))).')~i', $fn)) {
		$pregEp = '(?:[12]\d{3}|\d{2,3})'; // need to be careful as this could be a year specifier
	}
	
	// handle schemes that double up season/episode, e.g. "[Anime Chap] Shadows House S02E01 [WEB 1080p] - Episode 1"
	if(preg_match('~^(.*'.$spc.'s0*(\d+)e0*(\d+)(?:'.$spc.'.*?)?'.$spc.')(season'.$spc.'?0*\\2(?:'.$spc.'|$))?(episode'.$spc.'?0*\\3(?:'.$spc.'|$))(.*$)~i', $fn, $match)) {
		// at least 'season' or 'episode' needs to match
		if(isset($match[4]) || isset($match[5])) {
			$fn = pfn_trim($match[1]);
			if($match[6]) $fn .= ' '.pfn_trim($match[6]);
		}
	}
	// example "Hells Paradise Jigokuraku Season 1 S01 1080p CR WEBRip 10bits x265-Rapta"
	elseif($batch_hint && (preg_match('~^(.*'.$spc.'s0*(\d+)(?:'.$spc.'.*?)?'.$spc.')season'.$spc.'?0*\\2(?:'.$spc.'|$)~i', $fn, $match) || preg_match('~^(.*'.$spc.'season'.$spc.'?0*(\d+)(?:'.$spc.'.*?)?'.$spc.')s0*\\2(?:'.$spc.'|$)~i', $fn, $match))) {
		$fn = pfn_trim($match[1]);
	}
	
	// handle doubled names with pipe separator - we'll just use the doubled 'episode' moniker for now, but should expand later
	// example: Kaguya-sama - Love Is War -Ultra Romantic Episode 13 English Dub | Kaguya-sama -Love Is War Season 3 Episode 13 English Dub
	if(preg_match('~^(.*? (episode \d+) .*?) \| (.*?) \\2 .*?$~i', $fn, $match)) {
		$fn = pfn_trim($match[1]);
		if(!isset($ret['title_alt']) && $match[2])
			$ret['title_alt'] = pfn_trim($match[2]);
	}
	// since pipe separated names are common, try to identify a typical pattern
	// example: [DemiHuman] Pop Team Epic - S01 (BD Remux 1080p AVC TrueHD 5.1) [Dual-Audio] (Japanese, English Dubs) | Poputepipikku | PPTP | PTE | ポプテピピック
	if(preg_match('~^([^|]+? (?:- |\((?:19|20)\d\d\))[^|]+?[)\]]) \| ([^\[(]+)$~', $fn, $match)) {
		$ret['title_alt'] = trim($match[2]); // may contain multiple names
		$fn = pfn_trim($match[1]);
	}
	
	// detect and strip group(s)
	$groupcnt = 0;
	while(true) {
		// prefix with '-' to deal with cases like '[HIG]-[Azunyan] The Bush Baby (大草原の小さな天使 ブッシュベイビー) ENGLISH DUB (VHS)'
		if(preg_match('~^ ?-? ?\[([^\[]*?)\]\.?~', $fn, $match)
		|| preg_match('~^ ?-? ?\(([^(]*?)\)\.?~', $fn, $match)
		|| preg_match('~^ ?-? ?\{([^{]*?)\}\.?~', $fn, $match)
		// and pick up cases of bad matching (but be restrictive)
		|| preg_match('~^ ?-? ?[\[({]([a-zA-Z0-9\-]*?)[\])}]\.?~', $fn, $match)) {
			$match[1] = pfn_trim($match[1]);
			if(!isset($ret['group']))
				$ret['group'] = $match[1];
			else {
				$groupcnt = 2;
				while(isset($ret['group'.$groupcnt]))
					++$groupcnt;
				$ret['group'.$groupcnt] = $match[1];
			}
			$fn = pfn_trim(substr($fn, strlen($match[0])));
		} else break;
	}
	
	// is everything in the filename bracketed??
	// eg [高压1080p][Koi_to_Senkyo_to_Chocolate][全13話][BDRip][X264_AAC][Hi10P]
	// if so, generally title is in the first or second term
	if($fn==='' && $groupcnt > 1) {
		// well, we have to make a decision, so guess the 2nd term
		$groupcnt = 2;
		$titleKey = 'group2';
		// ...but if it's rather long, or contains junk terms, select 1st; TODO: consider better mechanism
		if(strlen($ret['group']) > 20) {
			// e.g. "[Soulmate Adventure S2: Special / Feng Ling Yu Xiu Di Er Ji: Tebie Pian][13-14 Fin][1080P]"
			$groupcnt = 1;
			$titleKey = 'group';
		} else {
			$tstJunk = function($s) {
				$tstTitle = 'DummyTitle '.$s;
				$tst = $tstTitle;
				$junk = array();
				parse_filename_strip_end($tst, $junk);
				return $tst != $tstTitle || ctype_digit($s);
			};
			if($tstJunk($ret[$titleKey])) {
				$groupcnt = 1;
				if(strtoupper($ret['group']) == 'DBD-RAWS' && isset($ret['group3'])) {
					// DBD exception
					$titleKey = 'group3';
				} else {
					$titleKey = 'group';
					
					// check if this is a junk term
					if(isset($ret['group3']) && $tstJunk($ret[$titleKey])) {
						$titleKey = 'group3';
					}
				}
			}
			elseif(strtoupper($ret[$titleKey]) == 'DBD-RAWS' && isset($ret['group3'])) {
				// e.g. "[Rev][DBD-Raws][Gekijouban Sword Art Online Progressive Hoshinaki Yoru no Aria][1080P] ..."
				$titleKey = 'group3';
			}
		}
		
		$fn = $ret[$titleKey];
		unset($ret[$titleKey]);
		$possible_ep = true;
		while(isset($ret['group'.(++$groupcnt)])) {
			if($possible_ep && preg_match('~^[0-9.\- ]+(?:v\d+)?$~', $ret['group'.$groupcnt]))
				$fn .= ' - '.$ret['group'.$groupcnt];
			else
				$fn .= ' ['.$ret['group'.$groupcnt].']';
			unset($ret['group'.$groupcnt]);
			$possible_ep = false;
		}
	}
	
	// specifically handle '[VipapkStudios] Douluo Dalu - Soul Land | 154v1 | (1080p) | Official Subs | [x265]'
	if(preg_match('~([^(\[\|{]+) \| (?:(S(?:eason)?\d+) (?:- )?)?((?:\d+ ?- ?)?\d+(?:v\d+)?) \| ((?:\w+ \| )?[\[{(].*)$~i', $fn, $m)) {
		// remap to a friendlier format
		$info = explode('|', $m[4]);
		$info = array_map('trim', $info);
		foreach($info as &$inf) {
			if($inf[0] != '(' && $inf[0] != '[' && $inf[0] != '{')
				$inf = '['.$inf.']';
		} unset($inf);
		if($m[2]) $m[2] = ' '.$m[2];
		$fn = $m[1].$m[2].' - '.$m[3].' '.implode('', $info);
	}
	
	// strip off any trailing bracketed info
	$oldFn = $fn;
	parse_filename_strip_end($fn, $ret);
	
	// handle: '「Ratsasan_Enkodes」 「Kaifuku Jutsushi no Yarinaoshi」 「04」 「NVENC」 「1080p」 「60fps」'
	if($fn==='' && $oldFn!=='' && isset($ret['info']) && isset($ret['info2'])) {
		// fix this by pulling the last two terms as group+title
		$infoCnt = 3;
		while(isset($ret['info'.$infoCnt]))
			++$infoCnt;
		--$infoCnt;
		// this discards the last bit of info (interpreted as a group)
		$ret['group'] = $ret['info'.$infoCnt];
		$titleKey = 'info'.($infoCnt>2 ? $infoCnt-1 : '');
		$fn = $ret[$titleKey];
		unset($ret[$titleKey], $ret['info'.$infoCnt]);
		if($infoCnt >= 3) { // handle bracketed episode
			$epKey = 'info'.($infoCnt>3 ? $infoCnt-2 : '');
			if(preg_match('~^[0-9.\- ]+(?:v\d+)?$~', $ret[$epKey])) {
				$fn .= ' - '.$ret[$epKey];
				unset($ret[$epKey]);
			}
		}
	}
	
	// try to detect some special naming schemes (where we've managed to get nothing so far
	if(!isset($ret['crc']) && !isset($ret['group']) && !isset($ret['ep'])) {
		// scene naming rules
		if(preg_match('~^([a-z0-9.\\-+]+)\.s\.?(\d+)(?:\.?(?:pt|part|cour)\.?(\d+))?(?:\.?e\.?(\d+))?\.([a-z0-9.\\-()+]+\.)?(xvid|divx|avi|[hx][. ]?26[45]|avc|hevc|av1|mpeg-?[124]|mkv|mp4|10bit|(?:dv[\-.]?)?hdr|bdmv|dts(?:-x|-hd(?: ?ma)?)|(?:e-?)?ac-?3|l?pcm|(?:ddp?)?[57]\.1(?: surround(?: sound)?)?|2\.0|[268]ch|[257]\.1 ?ch)(?:\.[a-z0-9.\\-()+]+)?-([a-z0-9]+)$~i', $fn, $match)) {
			$fn = $match[1];
			$ret['season'] = $match[2] = intval($match[2]);
			$match[3] = intval($match[3]);
			$match[4] = intval($match[4]);
			$append_season = anidb_append_season($fn, $match[2]);
			if($match[3]) {
				if($append_season)
					$fn .= ' S'.$match[2].'P'.$match[3]; // won't trip up %%{TOKEN}%% part
				else
					$fn .= ' Part '.$match[3];
			}
			elseif($append_season) $fn .= ' '.$match[2];
			
			if($match[4]) $ret['ep'] = $match[4];
			
			// incorrect and useless, but oh well....
			if(isset($match[5])) {
				if($dotAsSpc) $match[5] = str_replace('.', ' ', $match[5]);
				$ret['eptitle'] = pfn_trim($match[5]);
			}
			
			$ret['group'] = $match[7];
			
			// TODO: should probably skip most of the logic below
		}
		elseif(preg_match('~^([a-z0-9.\\-+]+\.(?:19\d\d|20\d\d))\.\d{3,4}p\.([a-z0-9.\\-()+]+\.)?(xvid|divx|avi|[hx][. ]?26[45]|avc|hevc|av1|mpeg-?[124]|mkv|mp4|10bit|(?:dv[\-.]?)?hdr|bdmv|dts(?:-x|-hd(?: ?ma)?)|(?:e-?)?ac-?3|l?pcm|(?:ddp?)?[57]\.1(?: surround(?: sound)?)?|2\.0|[268]ch|[257]\.1 ?ch)(?:\.[a-z0-9.\\-()+]+)?-([a-z0-9]+)$~i', $fn, $match)) {
			$fn = $match[1];
			$ret['group'] = $match[4];
		}
	}
	
	// try to deal with specific group naming scheme
	if((
		(
			preg_match('~(?:\([^)]+?\)|\[[^\]]+?\])'.$spc.'?-'.$spc.'?([a-zA-Z]{2,5})$~', $fn, $m) && $fn == $oldFn
		) || preg_match('~'.$spc.'(?:x26[45]|av1)-([a-zA-Z]{2,5})$~', $fn, $m)
	) && empty($ret['crc']) && empty($ret['group'])) { // for non-bracket schemes (e.g. blah.EP01.x264-BZ), the 'else' clause will handle it
		$ret['group'] = $m[1];
		$fn = substr($fn, 0, -strlen($m[1])-1);
		parse_filename_strip_end($fn, $ret);
	} elseif($fn == $oldFn) {
		// try to deal with unknown trailing junk terms (e.g. group names) by testing if stripping some unknown terms to see if it improves the situation
		$try_strip = function($len) use(&$fn, &$ret, $spc, &$pregEp) {
			
			// refuse to strip terms if they match an episode pattern
			$term = substr($fn, -$len);
			if(preg_match('~^(episodes?|eps?|teasers?|teaser'.$spc.'(?:trailers?|pv|preview)|cms?|commercial|pre[- ]?air|trailers?|pvs?|previews?|movies?|the'.$spc.'movie|dramas?|(?:picture|audio)'.$spc.'dramas?|(?:drama|character) cds?|specials?|(?:dvd |bd |blu[- ]?ray )?extras?|prequels?|omake|bonus(?: episodes?)?|remastered|parody|(?:un)?censored|(?:nc ?|creditless |textless )?(?:op|ed|opening|ending)|(?:nc ?|creditless |textless )?op[\-&+]ed|ncop[\-&+]nced|sp|vol(?:ume)?\.?|dis[ck]|seasons?|part|pt\.?|cour|web-?(?:cast|stream|rip|dl))('.$spc.'?#?(\d*|'.$spc.'[A-F]|\((?:[A-F]|\d+)\)|\d+'.$spc.'?-'.$spc.'?\d+|\d+(?:, ?\d+)+)(v\d{1,2}(?:\.\d{1,2})?)?)?$~', strtolower(pfn_trim($term))) || preg_match('~^'.$spc.'?-?'.$spc.'?'.$pregEp.'$~', $term) || preg_match('~^([eEsSpP][ .]?(\d*|\d+'.$spc.'?-'.$spc.'?\d+|\d+(?:, ?\d+)+))+(v\d{1,2}(?:\.\d{1,2})?)?$~', pfn_trim($term)))
				return false;
			$oldFn = $fn;
			$fn = substr($fn, 0, -$len);
			$newFn = $fn;
			$newRet = $ret;
			parse_filename_strip_end($newFn, $newRet);
			if($fn != $newFn && $newFn) {
				// something was stripped! assume that this is an improvement
				$fn = $newFn;
				$ret = $newRet;
				return true;
			} else {
				$fn = $oldFn;
				return false;
			}
		};
		// try stripping one word
		if(preg_match('~[. \-]+[\w,&]+\.?$~', $fn, $match)) {
			if(!$try_strip(strlen($match[0])) && preg_match('~([. \-]+[\w,&]+){2}$~', $fn, $m2)) {
				// didn't work, try two words
				$try_strip(strlen($m2[0]));
			} elseif(!isset($ret['group']) && preg_match('~^-(\w+)$~', $match[0])) {
				// this could be a group
				$ret['group'] = substr($match[0], 1);
			}
		}
	}
	
	// remove junk terms from anywhere
	$fn = preg_replace('~[\[({]anime[\])}]~i', '', $fn);
	
	// strip out additional items, eg "Show S1 + extras"
	// not the most ideal, but we don't deal with these additional terms, so don't really need them
	// be careful to avoid cases like "Luck & Logic"
	$strip_addition = function() use(&$fn, $spc, &$ret) {
		if(preg_match('~(?<=\d)(?:('.$spc.')[+&]('.$spc.'|\d+)(\w+('.$spc.'\w+)?)?)+$~', $fn, $match) ||
		   preg_match('~(?:('.$spc.')[+&]'.$spc.'?(?:o[nv]a|oa[dv]|special|movie|extra)s?)+$~', $fn, $match) ||
		   preg_match('~(?<=\d)(?:[+&](?:o[nv]a|oa[dv]|special|movie|extra)s?)+$~i', $fn, $match)) {
			$ret['addition'] = pfn_trim($match[0]);
			// to handle case: "[RH] Hyouka - 01-11 + 11.5 (OVA) [English Dubbed] [uncut] [1080p]"
			foreach(['special','special2'] as $k) {
				if(isset($ret[$k])) {
					$ret['addition'] .= ' '.$ret[$k];
					unset($ret[$k]);
				}
			}
			$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
		}
	};
	$strip_addition();
	
	// strip version
	if(preg_match('~[\- .]+[Vv](\d{1,2}(?:\.\d{1,2})?)$~u', $fn, $match)) {
		$ret['ver'] = floatval($match[1]);
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
	} elseif(preg_match('~(?<=[A-Z0-9])v(\d{1,2}(?:\.\d{1,2})?)$~', $fn, $match)) { // pick up some cases
		$ret['ver'] = floatval($match[1]);
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
	}
	
	// temporarily remove non-episode terms (like 'season') to avoid confusing the episode parser
	$tokenReplace = [];
	$discRegex = '(?:dvd|bd|blu[- ]?ray)(?: box| set| box[ -]?set)?';
	$fn = preg_replace_callback('~(?<='.$spc.')(?:(1st|2nd|3rd|[4-9]th|1\dth|first|second|third|fourth|fifth|sith|seventh|eighth|ninth|tenth)'.$spc.')?(season|part|pt\.?|cour|'.$discRegex.')'.$spc.'?(\d+'.($batch_hint?'('.$spc.'?[+,&\-]'.$spc.'?\d+|'.$spc.'(?:and|to)'.$spc.'\d+)*':'').')~i', function($match) use(&$tokenReplace, &$ret, $discRegex) {
		if(!empty($match[1])) {
			// if there's a 'second season' instance, the number following it is not a season indicator
			// example: "Hayate no Gotoku 2nd Season 24 (Blu-Ray 1080p) [Chihiro]"
			$tokenReplace[] = $match[1].' '.$match[2];
			return '%%{TOKEN}%% '.$match[3];
		}
		if(preg_match('~'.$discRegex.'~i', $match[2])) {
			// disc info should just get stripped out
			$ret['disc_info'] = $match[0];
			return '';
		}
		$tokenReplace[] = $match[0];
		return '%%{TOKEN}%%';
	}, $fn);
	$strip_double_season = function($fn, $season, &$fn_extra = null) use(&$tokenReplace, $spc) {
		// strip double season if it exists, e.g. '[HR] Enen no Shouboutai S2 - S02E01.mkv'
		$fn = preg_replace('~'.$spc.'(S0*'.$season.'|('.pfn_season_word($season).')'.$spc.'season)(?:'.$spc.'?[.\\-])?'.$spc.'?$~i', '', $fn);
		
		// remove it from tokens too - only if token count matches
		$fn_tokens = substr_count($fn, '%%{TOKEN}%%');
		$fnx_tokens = $fn_extra ? substr_count($fn_extra, '%%{TOKEN}%%') : 0;
		if($fn_tokens + $fnx_tokens == count($tokenReplace)) {
			foreach($tokenReplace as $k => $token) {
				if(preg_match('~season'.$spc.'?0*'.$season.'~i', $token)) {
					if($k < $fn_tokens) {
						$fn = preg_replace('~(%%\{TOKEN\}%%.*){'.$k.'}%%\{TOKEN\}%%~', '', $fn);
					} else {
						$fn_extra = preg_replace('~(%%\{TOKEN\}%%.*){'.($k - $fn_tokens).'}%%\{TOKEN\}%%~', '', $fn_extra);
					}
					array_splice($tokenReplace, $k, 1);
					break;
				}
			}
		}
		
		return $fn;
	};
	
	// this may trip up the episode matcher, so strip it out
	$fn = preg_replace('~(?<=\W)(E-?)?AC-3('.$spc.'|(?=\W))~i', '', $fn);
	
	
	$ep_preg = '\d+(?:\.[1-9]\d*|\.0\d+)?';
	$ep_preg2 = '\d+(?:\.[1-9]\d*|\.0\d+|,\d)?';
	$erange_suf = '(?:\.[1-9]|[\\-\\~](?:S\d+)?E?\d{1,4})?(?:v[2-5])?'; // currently use a restricted set of versions, though probably not necessary
	$e_suf = '(?:\.[1-9])?(?:v[2-5])?';
	// workaround for "[Yameii] A Returner's Magic Should Be Special - S01E10" - don't match "special" if we have a strong indicator of an episode marker
	$strong_ep_preg = '~(?<!\w)s(?:easons?'.$spc.'?)?\d\d?'.$spc.'?(?:p(?:arts?'.$spc.'?)?\d\d?|e(?:p|pis?|pisodes?)?'.$spc.'?'.$ep_preg.')~i';
	if($batch_hint) $e_suf = $erange_suf;
	
	// firstly, if given an obvious separator, try to respect that (don't consider case where ep num is at end - we handle that case later)
	if(preg_match('!(?<='.$spc.')(o[vn]as?|oa[dv]\'?s?|special\'?s?|movie\'?s?|(?:drama\'?s?|character)'.$spc.'cd\'?s?)'.$spc.'?(\d*(?:\.[1-9])?)'.$spc.'?[+,&\-]'.$spc.'?(.*)$!iu', $fn, $match)
	&& !preg_match($strong_ep_preg, $match[3])) {
		if(preg_match('~^\d*(?:\.[1-9])?$~', $match[3]) && $match[2]) {
			// this is likely a range, not a title
			$ret['eprange'] = $match[2].'-'.$match[3];
			$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
			$fn .= ' '.$match[1];
		} elseif(!$match[2] && is_numeric($match[3])) {
			// "OVA - 02" style naming
			$ret['ep'] = floatval($match[3]);
			$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
			$fn .= ' '.$match[1];
		} else {
			$ret['eptitle'] = pfn_trim($match[3]);
			$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
			if(in_array(strtolower($match[1]), array('ova','ona','oad','oav','special','movie'))) {
				$fn .= ' '.$match[1];
				if($match[2])
					$ret['ep'] = floatval($match[2]);
				elseif(strtolower($match[1]) == 'movie' && $ret['eptitle']) {
					// if the movie doesn't have a number, the "title" probably contains uniquifying info about the movie (for those which have multiple movies)
					$fn .= ' '.$ret['eptitle'];
					unset($ret['eptitle']);
				}
			}
			else
				$ret['special'] = $match[1].$match[2];
		}
	}
	elseif( // strong seasonal indicator for batches
		preg_match('~(?<!\w)s\d+('.$spc.'?([\-&+,]|and)'.$spc.'?s\d+)+$~i', $fn, $match) || (
			$batch_hint && preg_match('~(?<!\w)s(0?)(\d)(?:'.$spc.'?(?:[\-&+,]|and)'.$spc.'?s?\\1\d)*'.$spc.'?(?:[\-&+,]|and)'.$spc.'?s?\\1(\d)$~i', $fn, $match) && (int)$match[2] < (int)$match[3]
		)) {
		$ret['seasons'] = $match[0];
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
	}
	elseif(preg_match('~'.$spc.'S(\d{1,2})'.$spc.'?(?:P(\d{1,2})'.$spc.'?)?E(\d{1,4}'.$erange_suf.')('.$spc.'.*)?$~i', $fn, $match)
	// example: [horrible] Radiant S2 01 [480p].mkv.
	|| preg_match('~'.$spc.'S(\d{1,2})(?:'.$spc.'?P(\d{1,2}))?'.$spc.'('.$pregEp.$e_suf.')$~i', $fn, $match)
	// the next line is the same as above, but season is wrapped in brackets, e.g. "Bananya (S2) 03"
	|| preg_match('~'.$spc.'[\[({]S(\d{1,2})(?:'.$spc.'?P(\d{1,2}))?[\])}]'.$spc.'('.$pregEp.$e_suf.')('.$spc.'.*)?$~i', $fn, $match)) {
		if($batch_hint && strpos($match[3], '-'))
			$ret['eprange'] = $match[3];
		else
			$ret['ep'] = floatval($match[3]);
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
		$ret['season'] = $match[1] = intval($match[1]);
		empty($match[2]) or $match[2] = (int)$match[2];
		$fn = $strip_double_season($fn, $match[1]);
		if(anidb_append_season($fn, $match[1]) || !empty($match[2])) {
			$fn .= ' '.$match[1];
			if(isset($match[2]) && $match[2] > 1)
				$fn .= ' Part '.$match[2];
		}
		if(@$match[4])
			$ret['eptitle'] = substr($match[4], 1);
	}
	// handle: "[Judas] Yakusoku no Neverland - S02SP01 (The Promised Neverland) [1080p][HEVC x265 10bit][Eng-Subs] (Weekly)"
	elseif(preg_match('~'.$spc.'S(\d{1,2})'.$spc.'?SP(\d{1,3}(?:\.[1-9])?)('.$spc.'.*)?$~i', $fn, $match)) {
		$ret['special'] = floatval($match[2]);
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
		$ret['season'] = $match[1] = intval($match[1]);
		$fn = $strip_double_season($fn, $match[1]);
		if(anidb_append_season($fn, $match[1]))
			$fn .= ' '.$match[1];
		if(@$match[3])
			$ret['eptitle'] = substr($match[3], 1);
	}
	// handle "Sxx" indicator for batches, e.g. "[Trix] Oshi no Ko S02 v2 (COMPLETE) (1080p AV1 E-AC3) [Multi Subs] - 【推しの子】第2期 2nd Season (VOSTFR) (Batch)"
	elseif($batch_hint && preg_match('~'.$spc.'S(\d{1,2})'.$spc.'?((?:[\\-\\~]'.$spc.'?S\d+'.$spc.'?)?)(?:v[2-5])?('.$spc.'.*)?$~i', $fn, $match)) {
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
		$ret['season'] = $match[1] = intval($match[1]);
		$fn = $strip_double_season($fn, $match[1], $match[3]);
		if(anidb_append_season($fn, $match[1]))
			$fn .= ' '.$match[1];
		if(@$match[2])
			$ret['seasons_extra'] = $match[2];
		if(@$match[3]) {
			$match[3] = substr($match[3], 1);
			if(isset($match[3][0])) { // this could be stripped by $strip_double_season
				if(($match[3][0] == '[' || $match[3][0] == '(' || preg_match('~^\d+[pP]'.$spc.'~', $match[3])) && !preg_match('~[[(](19|20)\d\d[^a-z0-9]~', $match[3]))
					// this is probably just misc info
					$ret['extra_info'] = $match[3];
				elseif(in_array(strtolower($match[3]), ['custom','hybrid'])) {} // junk term - ignore
				elseif(preg_match('~(?:official'.$spc.')?(teasers?|teaser'.$spc.'(?:trailers?|pv|preview)|cms?|commercial|pre[- ]?air|trailers?|pvs?|previews?|movies?|the'.$spc.'movie|dramas?|(?:picture|audio)'.$spc.'dramas?|(?:drama|character) cds?|specials?|(?:dvd |bd |blu[- ]?ray )?extras?|prequels?|omake|bonus(?: episodes?)?|remastered|parody|(?:un)?censored|(?:nc ?|creditless |textless )?(?:op|ed|opening|ending)|(?:nc ?|creditless |textless )?op[\-&+]ed|ncop[\-&+]nced|sp|vol(?:ume)?\.?|dis[ck]|web-?(?:cast|stream|rip|dl))('.$spc.'?#?(\d*|'.$spc.'[A-F]|\((?:[A-F]|\d+)\)|\d+'.$spc.'?-'.$spc.'?\d+|\d+(?:, ?\d+)+)(v\d{1,2}(?:\.\d{1,2})?)?|:'.$spc.'[^:]+|'.$spc.'-'.$spc.'.+)?$~i', $match[3], $m2)) {
					$ret['special'] = pfn_trim($m2[0]);
					// TODO: consider processing special like in later case
				} else
					// we don't know what this is - append it for now
					$fn .= ' '.$match[3];
			}
		}
	}
	elseif(preg_match('~(?<!\d)'.$spc.'-'.$spc.'#?('.$pregEp.'(?:\.[1-9]?)?)'.$spc.'-?(.*)$~u', $fn, $match)
	// this is to work around "Strike Witches - 501 Butai Hasshin Shimasu! - 01" - don't allow this if there appears to be an episode number right at the end of the "title"
	// also don't get confused with "title - 01 - 13" style formatting
	&& !preg_match('~(?:'.$spc.'-|\))?'.$spc.'('.$pregEp.'(?:\.[1-9]?)?)$~', $match[2])
	&& !pfn_is_eplike_title(substr($fn, 0, -strlen($match[0])), $match[1])
	) {
		// fix for cases which end in a decimal episode
		$epNumIfDec = $match[1].'.'.$match[2];
		if(substr($match[0], -strlen($epNumIfDec)) == $epNumIfDec) {
			$ret['ep'] = (float)$epNumIfDec;
		} else {
			$ret['ep'] = (float)$match[1];
			$ret['eptitle'] = pfn_trim($match[2]);
		}
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
	}
	// try to detect PV/trailer etc
	elseif(preg_match('~(?:-'.$spc.'?|([^a-z0-9]))(?:official'.$spc.')?(teasers?|teaser'.$spc.'(?:trailers?|pv|preview)|cms?|commercial|pre[- ]?air|trailers?|pvs?|previews?|movies?|the'.$spc.'movie|dramas?|(?:picture|audio)'.$spc.'dramas?|(?:drama|character) cds?|specials?|(?:dvd |bd |blu[- ]?ray )?extras?|prequels?|omake|bonus(?: episodes?)?|remastered|parody|(?:un)?censored|(?:nc ?|creditless |textless )?(?:op|ed|opening|ending)|(?:nc ?|creditless |textless )?op[\-&+]ed|ncop[\-&+]nced|sp|vol(?:ume)?\.?|dis[ck]|web-?(?:cast|stream|rip|dl))('.$spc.'?#?(\d*|'.$spc.'[A-F]|\((?:[A-F]|\d+)\)|\d+'.$spc.'?-'.$spc.'?\d+|\d+(?:, ?\d+)+)(v\d{1,2}(?:\.\d{1,2})?)?|:'.$spc.'[^:]+|'.$spc.'-'.$spc.'.+)?$~i', $fn, $match) &&
		// this expression excludes well formed parts that are part of the title, e.g. "Lupin III Part 5 - 02"
		//!preg_match('~(part)'.$spc.'?#?[2-9]'.$spc.'-'.$spc.'\d{2,}(v\d{1,2}(?:\.\d{1,2})?)?$~iu', $fn) &&
		// exclude "title - 02 - special" type structures ("special" here is more like an episode title), but also carefully avoid screwing up something like "R-15 - special"
		!preg_match('~-'.$spc.'\d+$~', pfn_trim(substr($fn, 0, -strlen($match[0]))))
		// as in an earlier condition, exclude "Special - SxxExx" style indicators as well
		&& !preg_match($strong_ep_preg, $match[3])
		) {
		if($dotAsSpc) $match[2] = str_replace('.', ' ', $match[2]);
		$ret['special'] = pfn_trim($match[2]);
		switch(strtolower($ret['special'])) {
			// modify 'special' to match AniDB format better
			// ?? sometimes AniDB uses "C1" etc for OP/ED
			case 'ncop':
				$ret['special'] = 'OP'; break;
			case 'nced':
				$ret['special'] = 'ED'; break;
			case 'teaser': case 'trailer': case 'teaser trailer': case 'teaser pv': case 'teaser preview': case 'pv': case 'preview': case 'cm': case 'commercial':
				$ret['special'] = 'T'; break;
			case 'special': case 'sp': case 'omake': case 'bonus': case 'drama': case 'picture drama':
				$ret['special'] = 'S'; break;
			case 'parody':
				$ret['special'] = 'P'; break;
			// others: O=other
		}
		$spnum = '';
		if(isset($match[4])) {
			// avoid stripping brackets around years
			$spnum = preg_replace('~\((19|20)\d\d\)~', '[$0]', $match[4]); // converts to square brackets (the round brackets will get stripped in next line)
			$spnum = pfn_trim(strtr($spnum, array('.'=>' ', '(' => '', ')' => '')));
			$spnum = preg_replace('~\[((19|20)\d\d)\]~', '($1)', $spnum);
		}
		if($spnum) { // the brackets, eg "OP (B)" won't actually work because they'll be assumed to be info
			if(strpos($spnum, '-') !== false || strpos($spnum, ',') !== false) {
				$spnum = str_replace(' ','',$spnum);
			}
			elseif(strpos($spnum, '(') === false) {
				if(!is_numeric($spnum)) $spnum = ord(strtoupper($spnum)) - ord('A') +1;
				else $spnum = (int)$spnum;
			}
			$ret['special'] .= (isset($ret['special'][1]) ? ' ':''). $spnum; // single letter special - don't stick in space
		}
		elseif(!isset($ret['special'][2])) // ugly hack
			$ret['special'] .= '1';
		if(isset($match[5]) && $match[5])
			$ret['ver'] = intval(substr(pfn_trim($match[5]), 1));
		$matchlen = strlen($match[0]);
		if(isset($match[1]) && $match[1])
			--$matchlen;
		$fn = pfn_trim(substr($fn, 0, -$matchlen));
		
		if(stripos($ret['special'], 'movie') !== false && $match[3] && ($match[3][0] == ':' || substr(pfn_trim($match[3]), 0, 1) == '-')) {
			// retain movie name, e.g. "Demon Slayer -Kimetsu no Yaiba- The Movie: Mugen Train (2020)"
			$fn .= ' '.$ret['special'].$match[3];
			unset($ret['special']); // prevent it being appended onto the end later
		}
	}
	// remove special episode numbers
	elseif(preg_match('~[\- .](o[nv]a|oa[vd])('.$spc.'?#?('.$ep_preg.')(v\d{1,2}(?:\.\d{1,2})?)?)$~i', $fn, $match)) {
		$ret['ep'] = floatval($match[3]);
		if(isset($match[4]) && $match[4])
			$ret['ver'] = intval(substr(pfn_trim($match[4]), 1));
		$fn = pfn_trim(substr($fn, 0, -strlen($match[2])));
	}
	// try to detect episode number
	elseif(
		// these three copies are very similar
		//  special case for epranges like "[HorribleSubs] Omamori Himari 1 - 12 [480p]" - we can assume that the '1' on the end of the 'title' is a range because 1 is a common episode to start a range from, and rarely appears at the end of a title
		// TODO: handle negative batch hints
		preg_match('!(-?'.$spc.'(episodes?[ .\\-]?|epi?s?\.?[ \\-]?|[ce])?[.#]?'.($batch_hint?'(\d+)':'0*(1)').'(?:v[0-5])?)(?:'.$spc.'?([\-+,&]|and|to)'.$spc.'?(?:(?:episodes?|epi?s?\.?)'.$spc.'?#?|#?|[ce][.#]?|)('.$ep_preg.'))+(v\d{1,2}(?:\.\d{1,2})?)?(?:'.$spc.'?(?:end|final))?(?: ?\+ ?(movies?|o[nv]as?|specials?|extras?|bonus|v[234]s?))*$!iu', $fn, $match)
		||
		// these two copies have a workaround
		//  basically, for "R-15 - 01" style - if there's no space around first candidate, cannot have spaces later
		((
			preg_match('`(-?'.$spc.'((?:episodes?[.\\-]?|epi?s?[.\\-]?|[\- .])'.$spc.'?#?|[\- .][ce][.#]?)('.$ep_preg2.')(?:v[0-5])?)(?:'.$spc.'?([\-+,&]|and)'.$spc.'?(?:(?:episodes?|epi?s?\.?)'.$spc.'?#?|#?|[ce][.#]?|)('.$ep_preg.'))*(v\d{1,2}(?:\.\d{1,2})?)?(?:'.$spc.'?(?:end|final))?(?: ?\+ ?(movies?|o[nv]as?|specials?|extras?|bonus|v[234]s?))*$`iu', $fn, $match)
			||
			preg_match('`((?:[\-])?((?:episodes?[.\\-]?|(?<![a-z])epi?s?[.\\-]?|-)'.$spc.'?#?|[\- .][ce][.#]?)('.$ep_preg2.')(?:v[0-5])?)(?:([\-+]|\s?[,&]\s?|'.$spc.'and'.$spc.')(?:(?:episodes?|epi?s?\.?)'.$spc.'?#?|#?|[ce][.#]?|)('.$ep_preg.'))*(v\d{1,2}(?:\.\d{1,2})?)?(?:'.$spc.'?(?:end|final))?(?: ?\+ ?(movies?|o[nv]as?|specials?|extras?|bonus|v[234]s?))*$`iu', $fn, $match)
		) && !pfn_is_eplike_title(substr($fn, 0, -strlen($match[0])), $match[3], $match[2]))
		||
		// also match non-anchored version with a stronger delimiter, eg "Episode 1"
		preg_match('!(-?'.$spc.'((?:episodes?[.\\-]?|epi?s?[.\\-]?)'.$spc.'?[#e]?|[\- .][ce][.#]?)('.$ep_preg2.')(?:v[0-5])?)(?:'.$spc.'?([\-+,&]|and)'.$spc.'?(?:(?:episodes?|epi?s?\.?)'.$spc.'?[#e]?|#?|[ce][.#]?|)('.$ep_preg.'))*(v\d{1,2}(?:\.\d{1,2})?)?(?:'.$spc.'?(?:end|final))?(?: ?\+ ?(movies?|o[nv]as?|specials?|extras?|bonus|v[234]s?))*(.+)$!iu', $fn, $match)
	
	) {
		// match tokens: 1=lots of stuff, 2=1st ep, 3=ep separator, 4=2nd ep, 5=ver, 6=ep-special marker, 7=title (only last regex)
		if(isset($match[5]) && $match[5]) {
			if(is_numeric($match[3]) && (
				(
					$batch_hint && ((strlen($match[3])>1 && strlen($match[3]) <= strlen($match[5])) || $match[3] == '1')
				) || $match[3] == '1' /*|| $match[3] == '01'*/ || strlen($match[3]) == strlen($match[5]) || (
					strlen($match[3])>1 && strlen($match[3]) == strlen($match[5]) -1
				)
			))
				$ret['eprange'] = floatval($match[3]).(
					$match[4] == ',' || $match[4] == '&' || strtolower($match[4]) == 'and'?',':'-'
				).intval($match[5]);
				// TODO: properly support episode comma lists, such as "01,46,94"
				// .. and even "Tama and Friends TV 01,02,05,07-13 (EngDub)"
			else {
				// we accidentally matched a range when we shouldn't have
				$ret['ep'] = floatval($match[5]);
				// shorten $match[0] (unmatch range)
				$match[0] = substr($match[0], 0, -strlen($match[1]));
			}
		}
		else {
			$ret['ep'] = floatval(str_replace(',','.',$match[3]));
		}
		if(isset($match[6]) && $match[6])
			$ret['ver'] = intval(substr(pfn_trim($match[6]), 1));
		if(isset($match[8]) && $match[8])
			$ret['eptitle'] = pfn_trim($match[8]);
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
	}
	// SxxExx strong indicator, but submitter didn't delimit with spaces
	// this is largely the same as an earlier condition, but $spc is replaced with \W
	elseif(preg_match('~\WS(\d{1,2})\W?(?:P(\d{1,2})\W?)?E(\d{1,4}'.$erange_suf.')(\W.*)?$~i', $fn, $match)
	// example: [horrible] Radiant S2 01 [480p].mkv.
	|| preg_match('~\WS(\d{1,2})(?:\W?P(\d{1,2}))?\W('.$pregEp.$e_suf.')$~i', $fn, $match)) {
		// this is copy-pasted from earlier condition
		if($batch_hint && strpos($match[3], '-'))
			$ret['eprange'] = $match[3];
		else
			$ret['ep'] = floatval($match[3]);
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
		$ret['season'] = $match[1] = intval($match[1]);
		empty($match[2]) or $match[2] = (int)$match[2];
		$fn = $strip_double_season($fn, $match[1]);
		if(anidb_append_season($fn, $match[1]) || !empty($match[2])) {
			$fn .= ' '.$match[1];
			if(isset($match[2]) && $match[2] > 1)
				$fn .= ' Part '.$match[2];
		}
		if(@$match[4])
			$ret['eptitle'] = substr($match[4], 1);
	}
	// eprange + title
	// this is a weak identifier (no preceeding dash), but only way to handle such case as the following one would interpret the dash as a separator
	elseif($batch_hint && preg_match('!(?:'.$spc.'?|(?<=\W))(?:(?:episode[.\\-]?'.$spc.'?|epi?[.\\-]?[ .]?|'.$spc.')#?|[ce][.#]?)(\d+(?:'.$spc.'?([\-+,&~]|and|to)'.$spc.'?(?:(?:episodes?|epi?s?\.?)[ .]?#?|#?|[ce][.#]?|)('.$ep_preg.'))+)( ?v\d{1,2}(?:\.\d{1,2})?)?,?(?:'.$spc.'?-|'.$spc.')'.$spc.'*-?(.+?)$!iu', $fn, $match)) {
		$ret['eprange'] = $match[1];
		$ret['eptitle'] = pfn_trim($match[5]);
		if(isset($match[4]) && $match[4])
			$ret['ver'] = intval(substr(pfn_trim($match[4]), 1));
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
	}
	elseif(!isset($ret['ep']) && preg_match('~(?:'.$spc.'?|(?<=\W))-'.$spc.'?((?:episode[.\\-]?|epi?[.\\-]?|)[ .]?#?|[ce][.#]?)('.$ep_preg2.')( ?v\d{1,2}(?:\.\d{1,2})?)?,?(?:'.$spc.'?-|'.$spc.')'.$spc.'*-?(.+?)$~iu', $fn, $match) && !pfn_is_eplike_title(substr($fn, 0, -strlen($match[0])), $match[2], $match[1])) { // try do something about eps with ep titles in them
		$ret['ep'] = floatval(str_replace(',','.',$match[2]));
		$ret['eptitle'] = pfn_trim($match[4]);
		if(isset($match[3]) && $match[3])
			$ret['ver'] = intval(substr(pfn_trim($match[3]), 1));
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
	}
	// attempt to detect episode numbers even if no obvious separator is supplied - only works if titles have no numbers; also must have episode title otherwise above would've filtered it out
	elseif(!isset($ret['ep']) && !isset($ret['season']) &&
		preg_match('~^(\D+(?:\((?:19|20)\d\d\))?)'.$spc.'(episode[ .\\-]?|epi?[.\\-]? ?|[ce]\.?|)#?('.$pregEp.'(?:\.[1-9])?)( ?v\d{1,2}(?:\.\d{1,2})?)?\W(\D*)$~iu', $fn, $match)
		&& !pfn_is_eplike_title($match[1], $match[3], $match[2])
	) {
		// handle special cases which would overlap titles
		$number_titles = array_map('strtolower', anidb_get_title_list('num_suf'));
		$ret['eptitle'] = pfn_trim($match[5]);
		if(in_array(strtolower(pfn_trim($match[1])).' '.$match[2].$match[3], $number_titles)) {
			// this isn't an episode number - AVOID!
			$fn = pfn_trim($match[1]).' '.$match[2].$match[3];
			// workaround for 'Mob Psycho 100 II' (this may not be needed any more)
			if(preg_match('~^(\d+|I{1,3}|I?V|VI{1,3})( |$)~i', $ret['eptitle'], $m)) {
				$ret['eptitle'] = substr($ret['eptitle'], strlen($m[0]));
				if(!$ret['eptitle']) unset($ret['eptitle']);
				$fn .= ' '.$m[1];
			}
		} else {
			// handle titles like 'Tokyo 24-ku' which look like an episode number, but aren't
			$number_titles = array_map('filename_id', anidb_get_title_list('eplike_num'));
			if(in_array(filename_id($fn), $number_titles)) {
				// not an episode number - do nothing
				unset($match[4]); // prevent version matching
			} else {
				$ret['ep'] = floatval($match[3]);
				$fn = pfn_trim($match[1]);
			}
		}
		if(isset($match[4]) && $match[4])
			$ret['ver'] = intval(substr(pfn_trim($match[4]), 1));
	}
	// try using some common season indicators (like year, or "II"/"III") as an indicator if we still can't find an episode (e.g. "Mob Psycho 100 II 07" or "Blah (2019) 05" or "86 Eighty Six 2nd 01" etc)
	elseif(preg_match('~'.$spc.'(?:\((?:19|20)\d\d\)|III?|\d*?(?:1st|2nd|3rd|[04-9]th|1[0-9]th))'.$spc.'('.$pregEp.'(?:\.[1-9])?)('.$spc.'.*)?$~i', $fn, $match)) {
		$ret['ep'] = floatval($match[1]);
		$cutlen = strlen($match[1]);
		if(@$match[2]) {
			$ret['eptitle'] = substr($match[2], 1);
			$cutlen += strlen($match[2]);
		}
		$fn = pfn_trim(substr($fn, 0, -$cutlen));
	}
	// unusual # marker e.g. 'Iya na Kao Sarenagara Opantsu Misete Moraitai 2 #06'; should expand this in the future
	elseif(preg_match('~#(\d{2,})$~', $fn, $match)) {
		$ret['ep'] = floatval($match[1]);
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
	}
	// if only a single number at the end, and not a batch (or is a batch but an additional term was taken out, e.g. "42 & 43"), probably an episode, e.g. "[Weeaboo-Shogun] Transformable Shinkansen Robot Shinkalion Z 07v2" [version handled earlier]
	elseif(!isset($ret['ep']) && !isset($ret['season']) && ($batch_hint === false || isset($ret['addition'])) && preg_match('~^(\D+(?:\((?:19|20)\d\d\))?)'.$spc.'('.$pregEp.'(?:\.[1-9])?)$~iu', $fn, $match) && !preg_match('~(?<=\W|^)(movie|episode|season|dvd|bd|bluray)(?=\W)~i', $fn) && !preg_match('~(?:(?:[^a-zA-Z]|^)-|-(?:[^a-zA-Z]|$))\w~', $fn)) {
		$ret['ep'] = floatval($match[2]);
		$fn = pfn_trim($match[1]);
	}
	elseif(preg_match('~^(.+'.$spc.'(?:19|20)\d\d)'.$spc.'(?:360|480|576|720|1080|2160)[pP]'.$spc.'~', $fn, $match)) {
		// common 'scene-like' naming for movies
		$fn = pfn_trim($match[1]);
	}
	
	// fix bad prefix caught in episode title
	if(isset($ret['eptitle']))
		$ret['eptitle'] = preg_replace('~^[:-] *~', '', $ret['eptitle']);
	
	while(!empty($tokenReplace)) {
		$tokenReplace1 = array_shift($tokenReplace);
		if(($p = strpos($fn, '%%{TOKEN}%%')) !== false) {
			$fn = substr_replace($fn, $tokenReplace1, $p, strlen('%%{TOKEN}%%'));
		} else {
			// erm, not good... token probably got put into somewhere else
			foreach($ret as &$rs) {
				if(($p = strpos($fn, '%%{TOKEN}%%')) !== false) {
					$rs = substr_replace($rs, $tokenReplace1, $p, strlen('%%{TOKEN}%%'));
					break;
				}
			} unset($rs);
		}
	}
	
	// special cases for some shows _end_ in 0!
	$zero_titles = array_map('filename_id', anidb_get_title_list('zero'));
	$fnid = filename_id($fn);
	$is_zero_title = false;
	foreach($zero_titles as $zt)
		if(substr($fnid, 0, strlen($zt)) == $zt) {
			$is_zero_title = true;
			break;
		}
	
	// fix for weird case of using episode 0 with a special, eg "[DeadFish] Kyousougiga - 00v2 - Special [720p][AAC].mp4"
	if(isset($ret['special']) && !$is_zero_title && preg_match('~('.$spc.'?-?'.$spc.')(?:episode'.$spc.'?|epi?\.? ?|[ce]\.?|)#?(0{1,4})( ?v\d{1,2}(?:\.\d{1,2})?)?$~iu', $fn, $match)) {
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
	}
	
	// fix bad shiteater naming
	// e.g. "(shiteater) New Maple Town Stories - Palm Town Edition 09 [15F3769E]"
	// ^ a strong delimiter is used in the title, but no marker for episode number, causing confusion on whether the number is part of the title or an episode
	if(@$ret['group'] == 'shiteater' && !isset($ret['ep']) && preg_match('~ - .+ (\d+)$~', $fn, $match)) {
		$fn = trim(substr($fn, 0, -strlen($match[1])));
		$ret['ep'] = (int)$match[1];
	}
	
	// special cases where the episode number is actually in the title (like 0 eps above)
	if(isset($ret['ep'])) {
		foreach(anidb_get_title_list('eplike_num2') as $eptitle) {
			if(!preg_match("~^([^0-9]+)( [^a-zA-Z0-9'(] ?([0-9]{1,4}(?:\.[0-9]+)?))(?:$|[^a-z0-9A-Z)])~", $eptitle, $match))
				continue; // shouldn't happen
			if(filename_id($match[1]) == $fnid && $ret['ep'] == $match[3]) {
				// undo episode separation as it's actually a title
				$fn .= $match[2];
				unset($ret['ep']);
				break;
			}
		}
	}
	
	// fix special case of 'vol#' etc not being detected
	if(isset($ret['ep']) && !isset($ret['special']) && preg_match('~(?:-'.$spc.'?|([^a-z0-9]))(?:official'.$spc.')?(teasers?|teaser'.$spc.'(?:trailers?|pv|preview)|cms?|commercial|pre[- ]?air|trailers?|pvs?|previews?|movies?|the'.$spc.'movie|dramas?|(?:picture|audio)'.$spc.'dramas?|(?:drama|character) cds?|(?:dvd |bd |blu[- ]?ray )?extras?|prequels?|omake|bonus(?: episodes?)?|remastered|parody|sp|vol(?:ume)?\.?|dis[ck]|part|cour|web-?(?:cast|stream|rip|dl))$~i', $fn, $m)) {
		// (?:nc ?)?(?:op|ed)|(?:nc ?)?op[\-&+]ed|ncop[\-&+]nced|
		$ret['special'] = $m[2];
		$fn = pfn_trim(substr($fn, 0, -strlen($m[0])));
	}
	
	// strip off any trailing bracketed info
	parse_filename_strip_end($fn, $ret, true);
	
	// cleanup case of nested junk terms placed in the middle
	// this occurs in titles like:
	//   [DemiHuman] Sound! Euphonium - S01 + Extras (REPACK) (BD 1080p x265 Opus 2.0) [Dual-Audio] | Hibike! Euphonium | 響け！ユーフォニアム | feat. the "Ready, Set, Monaka" (Kakedasu Monaka) OVA & "The Everyday Life of Band" (Suisougaku-bu no Nichijou) Specials
	// at this point, we get something like "title - - - OVA"
	if(preg_match('~(\s+-\s+){2,}(.*)$~', $fn, $match)) {
		$fn = substr($fn, 0, -strlen($match[0]));
	}
	
	// fix for decimal'd episode numbers
	$fnappend = '';
	if(isset($ret['ep']) && ($p = strpos($ret['ep'], '.'))) {
		// TODO: if prepended by something like "episode" skip this
		if($dotAsSpc) {
			// this file possibly using '.' as delimeter
			// -> take right part as ep num, and left as series
			$fn .= ' '.substr($ret['ep'], 0, $p);
			$ret['ep'] = substr($ret['ep'], $p+1);
		} elseif(strlen($ret['ep'])-2 > $p) {
			// has more than one digit, assume name, eg "Evangelion 2.22"
			// use fnappend to get around the issue of '.' -> ' ' conversion later on
			$fnappend = ' '.$ret['ep'];
			unset($ret['ep']);
		}
	}
	
	// note for the above that some things may be interpreted as an ep number when it should eg "some movie 2"
	
	// strip bad prefixes
	if(preg_match('~^([sS]\d{1,2})'.$spc.'?(?:[eE](\d+)'.$spc.'?)?-'.$spc.'?(.{3,})$~', $fn, $m)) {
		// inverted season ordering
		if(@$m[2])
			$ret['ep'] = (int)$m[2];
		$fn = pfn_trim($m[3]).' '.$m[1];
	}
	
	
	// fix for some info that may get caught in the eptitle
	if(isset($ret['eptitle']) && !isset($ret['special'])) {
		switch(strtolower($ret['eptitle'])) {
			case 'ova': case 'ona': case 'oav': case 'oad':
				$ret['special'] = $ret['eptitle'];
				unset($ret['eptitle']);
		}
	}
	
	// finally, parse title
	// firstly, special title brackets
	if(preg_match('~『(.*?)』~', $fn, $match)) { // (?:TVアニメ)?
		$fn = $match[1];
	}
	elseif(preg_match('~「(.*?)」~', $fn, $match)) { // (?:TVアニメ)?
		$fn = $match[1];
	}
	
	if($dotAsSpc) {
		if(!strpos($fn, ' '))
			$fn = str_replace('.', ' ', $fn);
		else
			// some titles have dots at the end, with special meaning, so preserve them
			// also preserve those which have a space following it, e.g. "no. 1" - may also catch cases like above but junk left in the title
			$fn = preg_replace('~\.(?!\.*$|[ :])~', ' ', $fn);
	}
	$fn = pfn_trim($fn.$fnappend);
	
	// try to handle common case of pipe separated aliases
	// TODO: it might make more sense to do this prior to episode parsing, but we'd have to take care with bracketed terms since pipes could be in brackets
	$titles = explode(' | ', $fn);
	if(count($titles) == 1 && isset($ret['eptitle'])) {
		// cater for cases where the alt names got caught in the episode title
		$eptitles = explode(' | ', $ret['eptitle']);
		if(count($eptitles) > 1) {
			// assume pipe separated stuff is really meant to be alt titles
			$ret['eptitle'] = $eptitles[0];
			parse_filename_strip_end($ret['eptitle'], $ignore);
			$eptitles[0] = $titles[0];
			$titles = $eptitles;
		}
	}
	if(count($titles) > 1) {
		// if titles are just junk terms, remove them
		$test_titles = $titles;
		foreach($test_titles as $i => $candidate) {
			$ignore = [];
			$candidate = ' '.$candidate;
			parse_filename_strip_end($candidate, $ignore, true);
			if(!ltrim($candidate)) unset($test_titles[$i]);
		}
		if(!empty($test_titles))
			$titles = $test_titles;
		
		// we need to figure out what's the best alias to use - since season numbers are sometimes excluded, we'll prefer the one with the numbers first, otherwise use the first title
		$idxNum = null;
		foreach($titles as $i => $candidate) {
			if(preg_match('~\W\d+(\W|$)~', $candidate)) {
				if(isset($idxNum)) {
					$idxNum = null; // multiple titles contain a number, bail
					break;
				} else
					$idxNum = $i;
			}
		}
		if(!isset($idxNum)) $idxNum = 0;
		$fn = $titles[$idxNum];
		unset($titles[$idxNum]);
		if(isset($ret['title_alt']))
			array_unshift($titles, $ret['title_alt']);
		$ret['title_alt'] = array_shift($titles);
		if(!empty($titles))
			$ret['title_alt_extra'] = $titles;
		
		parse_filename_strip_end($fn, $ret, true);
		
		// if there's an addition before the pipe, try stripping again
		if(!isset($ret['addition']))
			$strip_addition();
	}
	
	// remove TV/DVD/BD from end of title; only remove TV if bracketed
	// UPDATE: the following are probably useless now
	$tvcomp = strtolower(substr($fn, -4));
	if($tvcomp == '(tv)' || $tvcomp == '[tv]')
		$fn = pfn_trim(substr($fn, 0, -4));
	if(preg_match('~[\[({]?(?:dvd|bd|blu[- ]?ray)(?: box)?[\])}]$~i', $fn, $match))
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
	// some stuff that we'll always strip off
	if(preg_match('~ (?:drama|character) cd$~i', $fn, $match))
		$fn = pfn_trim(substr($fn, 0, -strlen($match[0])));
	
	// try to strip doubled season; TODO: see if there's a nicer way to do this, e.g. with normalization?
	$fn = preg_replace([
		'~( season 0*(\d+)) s0*\2$~i',
		'~( s0*(\d+)) season 0*\2$~i'
	], '$1', $fn);
	// TODO: set $ret['season']
	$fn = anidb_normalize_season($fn, $batch_hint);
	// workaround for something like "Kaguya sama - Love is War (Season 3) - Ultra Romantic"
	if(!preg_match('~\d$~', $fn)) {
		$season_rx = 's(?:eason)?'.$spc.'?\d+(?:'.$spc.'?p(?:art)?'.$spc.'?\d+)?';
		$fn = preg_replace_callback('~(?<='.$spc.')(?:-'.$spc.'?)?(?:[(\[]'.$season_rx.'[)\]]|'.$season_rx.'('.$spc.'?-|:))~i', function($m) use($batch_hint) {
			$info = trim($m[0], '()[]-: ');
			$info = trim(anidb_normalize_season(' '.$info, $batch_hint));
			if(isset($m[1])) $info .= $m[1];
			return $info;
		}, $fn);
	}
	
	// specific handling for NC-Raws format, e.g: "[NC-Raws] 异世界迷宫黑心企业 / Meikyuu Black Company - 01 [B-Global][WEB-DL][2160p][AVC AAC][CHS_CHT_ENG_TH_SRT][MKV]"
	if(preg_match("~^([\x80-\xff A-Z0-9:,\-]{9,}) [/\-|] (.{4,})$~", $fn, $m)) {
		// only allow if most characters in first half is non-ASCII, and most in the second is
		if(strlen(preg_replace("~[\x80-\xff ]~", '', $m[1])) <= 3 && strlen(preg_replace('~[\x00-\x7f]~', '', $m[2])) <= strlen($m[2])*0.25) {
			$fn = pfn_trim($m[2]);
			$ret['title_alt'] = pfn_trim($m[1]);
		}
	}
	// fix for Furretar's naming scheme
	$fn = preg_replace('~^(?:中|台|粤|文){1,3}(?:配|音){1,2} - ~', '', $fn);
	
	// to aid type hinting in anidb_search
	// put "movie" back into title
	if(isset($ret['special']) && (stripos($ret['special'], 'movie') !== false || preg_match('~^(o[nv]a|oa[vd])$~i', $ret['special'])))
		$fn .= ' '.str_replace($dotAsSpc?'.':' ', ' ', $ret['special']);
	// episode 0 -> treat as ova
	elseif(isset($ret['ep']) && $ret['ep'] == '0' && stripos($fn, ' ova') === false && stripos($fn, ' oav') === false && stripos($fn, ' oad') === false && stripos($fn, ' movie') === false) {
		if($is_zero_title) { // (unless it's a 0 title)
			$fn .= ' 0';
			unset($ret['ep']);
		} elseif(isset($ret['eptitle']) && stripos($ret['eptitle'], 'movie') !== false) {
			// special case for "[Chihiro] Grisaia Phantom Trigger - 00 - Movie 2 [Blu-ray 1080p HEVC FLAC][208A076F].mkv"
			$fn .= ' 0 '.$ret['eptitle'];
			unset($ret['ep'], $ret['eptitle']);
		} else
			$fn .= ' OVA';
	}
	
	// if we have what appears to be a movie, and there's an episode number, this should probably go into the movie title
	if(isset($ret['special']) && stripos($ret['special'], 'movie') !== false && isset($ret['ep'])) {
		// we can't handle epranges, because there's multiple movies together
		$fn .= ' '.$ret['ep'];
		unset($ret['ep']);
	}
	
	if(!empty($fn)) {
		$ret['title'] = $fn;
		
		// try to cater to S00 TVDB naming
		if((isset($ret['season']) && $ret['season'] == 0) || preg_match('~\WS00E~i', $fn)) {
			// prefer alt name if present
			if(!empty($ret['title_alt'])) {
				$ret['title'] = $ret['title_alt'];
				$ret['title_alt'] = $fn;
				unset($ret['ep'], $ret['eprange']);
			}
			// throw in the presumed episode title, which may give better hints
			elseif(!empty($ret['eptitle'])) {
				$ret['title'] = preg_replace('~\WS00E\d+$~i', '', $fn);
				$ret['title'] .= ' - '.$ret['eptitle'];
				unset($ret['ep'], $ret['eprange'], $ret['eptitle']);
			}
		}
		$ret['title'] = preg_replace(array('~(?<=^|[^\s])-~', '~-(?=$|[^\s])~'), ' ', $ret['title']); // replacing dashes with spaces works better for AniDB
		// strip unnecessary spaces
		$ret['title'] = pfn_trim(preg_replace('~ +~', ' ', $ret['title']));
	} elseif(!empty($ret['title_alt'])) {
		$ret['title'] = $ret['title_alt'];
		unset($ret['title_alt']);
	}
	$ret['noep'] = (!isset($ret['ep']) && !isset($ret['eprange']) && (!isset($ret['special']) || stripos($ret['special'], 'movie') !== false));
	return $ret;
}

function parse_filename_strip_end_brackets(&$fn, &$ret, $before_ep=false) {
	while(true) {
		if(preg_match('~^(.*)\.?(?:[\~\\-][ .]?)?(?:\[(.*?)\]|\((.*?)\)|\{(.*?)\}|「(.*?)」)$~', $fn, $match)) {
			$term = pfn_trim($match[2]);
			for($i=3; $i<=5; ++$i)
				if(isset($match[$i]))
					$term or $term = $match[$i];
			if(preg_match('~^(o[vn]a\'?s?|oa[vd]\'?s?|movie\'?s?|special\'?s?|(?:drama\'?s?|character)[ .]cd\'?s?|(?:audio|picture)[ .]dramas?|(vol(?:ume\'?s?)?|dis[ck]|dvd)[ .]?\d+(v\d+)?)$~i', $term)) {
				if(isset($ret['special'])) {
					if(strtolower($ret['special']) != strtolower($term))
						$ret['special2'] = $term; // we hope this is rare so won't bother putting multiple special items for this
				} else
					$ret['special'] = $term;
			} elseif(preg_match('~^(?:19|20)\d\d(?: ?season| ?series)?$~i', $term) && !preg_match('~[\])}]\.*$~', $match[1])) {
				// handle special case of year being in the title, eg "Rozen Maiden (2013)" - we'll only match this when there's no further bracketed terms
				// if we see it, we stop all bracketed term stripping
				// TODO: consider stripping off "season/series" term
				// TODO: do we want to continue stripping bracketed terms past this point?
				break;
			} elseif(
					(preg_match('~^s(?:eason)?[ _.]?\d\d?(?:[ _.,|\-]{1,3}(?:part|cour|pt\.?)[ _.]?\d\d?|[ _.]-[ _.][^)]+)?($|[ .]?[+,\-&])~i', $term) && !preg_match('~[\])}]\.*$~', $match[1]))
					|| preg_match('~^s\d\d?(?:p\d\d?|e\d{1,4})($|[ .]?[+,\-&])~i', $term)
					|| preg_match('~^p(?:art|t\.?)?[ _.]?\d\d?$~i', $term)
					|| (preg_match('~^(the )?(final|last) season~i', $term) && !preg_match('~\Ws(?:eason)?[ _.]?\d+\W~i', $fn))) {
				// as above condition (years) but for seasons in brackets
				$fn = pfn_trim(substr($fn, 0, strlen($match[1])-strlen($match[0])));
				// continue stripping bracketed terms for cases where they include it, e.g. "[Anime Chap] JoJo's Bizarre Adventure Part 6: Stone Ocean [WEB 1080p] (S05E02) {Correct Names & Better Subs} [Dual Audio]"
				parse_filename_strip_end_brackets($fn, $ret, $before_ep);
				
				// handle case of doubled episode, e.g. "[GJM] Ascendance of a Bookworm (Honzuki no Gekokujou) - 37 (S04E01) (WEB 1080p) [89B3E047].mkv"
				if(strtolower($term[0]) == 's' && preg_match('~-[ .]\d+$~', $fn, $match2))
					$fn = pfn_trim(substr($fn, 0, -strlen($match2[0])));
				
				$fn .= ' '.$term;
				break;
			} elseif($before_ep && in_array(strtolower($term), [
				'shin',   // Shintaisou (Shin)
				'heisei han', // Konchuu Monogatari Minashigo Hutch (Heisei Han)
				'kari',  // e.g. 100 Byou Cinema: Robo to Shoujo (Kari)
				'shiro', // Nekomonogatari (Shiro)
				'kuro',  // Nekomonogatari (Kuro)
				'black', 'white', // Cat Story
				'toei',     // Yu-Gi-Oh! (Toei)
				'shinsaku', // e.g. Mahou Shoujo Lyrical Nanoha Vivid (Shinsaku)
				'onpu',     // Girl Friend (Onpu)
				'shin series', // Macross (Shin Series)
				'kabu',     // Dia Horizon (Kabu)
				'zokuhen',  // Kidou Senshi Gundam 00 (Zokuhen)
				'shin anime',  // Nora to Oujo to Noraneko Heart (Shin Anime)
				'rimen',    // Otona no Bouguya-san (Rimen)
			])) {
				// anime specific cases which have bracketed terms in title
				break;
			// workaround for some likely season indicators e.g. "[naiyas-weeklies] Demon Slayer - Kimetsu no Yaiba (Mugen Ressha-hen) - 01 (1080p HEVC - 10 AAC Multiple Subtitle).mkv"
			} elseif($before_ep && preg_match('~\w[\- ](hen|arc|chapter)$~i', $term)) {
				$fn = pfn_trim(substr($fn, 0, strlen($match[1])-strlen($match[0])));
				$fn .= ' - '.$term;
				break;
			} else {
				$possible_group = false;
				if(!isset($ret['group'])) {
					$possible_group = true;
					// does this not look like a group?
					if(
						   preg_match('~^(\d{3,4}[Pp]|hq|lq|v[2-9])$~', $term) // eg '480p'
						|| preg_match('~(^|\W)(uncensored|censored|(?:pal |ntsc )?dvd(?:[- ]?box|set|rip){0,2}|b[dr](?:[- ]?box|set|rip){0,2}|bdmv|blu[- ]?ray(?:[- ]?box|set|rip){0,2}|blu|(?:[bc]d|(?:pal |ntsc )?dvd|)iso|dis[ck]|web-?(?:cast|stream|rip|dl)|nflx|amzn|bili|b[- ]?global|u3-web|hdtv|uhd|4k|7z|rar|zip|mkv|avi|ogm|(?:dual|triple|tri) ?(?:audio|sub(?:title)s?)|[hx][. ]?26[45]|(?:8|10)[ \-]?bits?|hi10p|xvid|divx(?:[ .][0-9.]+)?|avc|hevc|av1|mpeg-?[124]|(?:dual[ .]|tri(?:ple)?[ .])?(?:aac|vorbis|ogg|opus|flac|mp[234]|(?:e-?)?ac-?3|dts(?:-x|-hd(?: ?ma)?)|dolby|l?pcm|truehd|atmos|ddp?)(?:[\- .]?(?:2\.0|[57]\.1(?: surround(?: sound)?)?|[268]ch|[257]\.1 ?ch))?|[57]\.1(?: surround(?: sound)?)?|2\.0|[268]ch|[257]\.1 ?ch|webm|mini[\- ][hl]q|mini[\- ][sh]d|[sh]d|(?:dvd|bd|br)?remux|rm|rmvb|srt|ssa|en|jpn?|rus?|ger|ita|vostfr|end|final|patch|raw|all seasons|\d{1,4} ?mb|\d(?:\.\d+)? ?gb)($|\W)~i', $term)
						|| preg_match('~(^|\W)\d{3,4}x\d{3,4}($|\W)~i', $term)
					)
						$possible_group = false;
				}
				
				if($possible_group)
					$ret['group'] = $term;
				elseif(!isset($ret['info']))
					$ret['info'] = $term;
				else {
					$infocnt = 2;
					while(isset($ret['info'.$infocnt]))
						++$infocnt;
					$ret['info'.$infocnt] = $term;
				}
			}
			$fn = pfn_trim(substr($fn, 0, strlen($match[1])-strlen($match[0])));
			// remove dots (space replacement) at the end
			$fn = preg_replace('~\.+$~', '', $fn);
		} else break;
	}
}

function parse_filename_strip_end(&$fn, &$ret, $before_ep=false) {
	static $quotTitles = null;
	if(!isset($quotTitles)) {
		$quotTitles = [];
		foreach(anidb_get_title_list('quotation') as $qt) {
			if(!preg_match('~^(.+) ([\'"])(.+)\\2$~', $qt, $m)) continue; // shouldn't happen
			$k = filename_id($m[3]);
			if(!isset($quotTitles[$k])) $quotTitles[$k] = [];
			$quotTitles[$k][] = filename_id($m[1]);
		}
	}
	do {
		$oldfn = $fn;
		parse_filename_strip_end_brackets($fn, $ret, $before_ep);
		
		// strip off junk terms on the end
		$lang_junk = 'eng|english|jpn|jap|japanese|chi?|cn|chinese|ko?r|korean|rus|ita|ger|fre?|vostfr|spanish|kara|multi|bd|dvd|blu[- ]?ray';
		$lang_junk2 = 'eng?|english|jpn?|jap|japanese|cn|chinese|ko?r|korean|rus|ita|ger|fre?|vostfr|spanish'; // 'ru' could be confused in e.g. "To Love-ru"
		$junk_stuff = 'uncensored|censored|(?:pal |ntsc )?dvd(?:[- .]?box|set|rip){0,2}\d?|b[dr](?:[- .]?box|set|rip){0,2}\d?|bdmv|blu|blu[- .]?ray(?:[- .]?box|set|rip){0,2}|(?:[bc]d|(?:pal |ntsc )?dvd|)iso\d?|dis[ck]\d?|web-?(?:cast|stream|rip|dl)|nflx|amzn|bili|b[- ]?global|cr[- .](?:web[- .]?)?(?:rip|dl)|u3-web|hdtv|uhd|4k|7z|rar|zip|mkv|avi|ogm|(?:'.$lang_junk.'|dual|triple|tri)[\- .]?(?:audio|subs?)|[hx][. ]?26[45]|(?:8|10)[ \-.]?bits?|hi10p|xvid|divx(?:[ .][0-9.]+)?|avc|hevc|av1|mpeg-?[124]|(?:dv[\- .]?)?hdr|(?:dual[ .]|tri(?:ple)?[ .])?(?:aac|vorbis|ogg|opus|flac|mp[234]|(?:e-?)?ac-?3|dts(?:-x|-hd(?: ?ma)?)|dolby|l?pcm|truehd|atmos|ddp?)(?:[\- .]?(?:2\.0|[57]\.1(?: surround(?: sound)?)?|[268]ch|[257]\.1 ?ch))?|[57]\.1(?: surround(?: sound)?)?|2\.0|[268]ch|[257]\.1 ?ch|webm|mini[\- .][hl]q|mini[\- .][sh]d|hq|lq|[sh]d|(?:dvd|bd|br)?remux|(?:mt-)?muxed|mux|repack|telesync|rm|rmvb|(?:no )?sfv|srt|ssa|'.$lang_junk2.'|(?:'.$lang_junk.'(?: ara)?|hard|soft)[\- .]?[ds]ubs?|[ds]ubs?[\- .]?(?:'.$lang_junk.'(?: ara)?)|[ds]ubbed(?:[\- .]version)?|subtitle[ds]?|(?:fixed|improved) subs?|'.str_replace('|','(?: ara)?|','jap|rus|ita|ger|fre?|vostfr').'|batch|(?:the[\- .])?complete(?:[\- .]collection|[\- .]series)?|patch|raw|music videos?|all seasons|\d{1,4} ?mb|\d(?:\.\d+)? ?gb|\d{3,4}x\d{3,4}|\d\d ?fps|(?:dual[ .]|multi[ .])?\d{3,4}p(?:[- .]jr)?|netflix|funimation|vrv|rip|(?:\\+ ?)?extra\'?s|(?:19[6-9][0-9]|20[0-2][0-9])[.\-][01][0-9][.\-][0-3][0-9]|v[0-4]|(?:2[3459]|[36]0|59|48)(?:\.\d+)? ?fps';
		if(!$before_ep) $junk_stuff .= '|end|dts';
		$fn = preg_replace('~([\- .+]+('.$junk_stuff.'))+$~i', '', $fn);
		$fn = preg_replace('~(?<=[\])}])('.$junk_stuff.')$~i', '', $fn);
		$fn = preg_replace('~(\+(extras?))+$~i', '', $fn);
		// TODO: put above in info?
		
		// less certain terms like 'NF' (NetFlix) - strip if there's likely junk preceeding it
		$fn = preg_replace('~(?<=\W)((?:dual[ .]|multi[ .])?(?:360|480|540|720|1080|2160)p|\d+x(?:360|480|540|720|1080|2160))(?:\W.*)?\W(NF|CR|HDCAM|CAM|TS|WEB|Funi|Toei)$~i', '', $fn);
		$fn = preg_replace('~(\WS\d{1,2}(?:\W?P\d{1,2})?(?:\W?E\d{1,4})?)\W(NF|CR|HDCAM|CAM|TS|WEB|Funi|Toei)$~i', '$1', $fn);
		
		// strip quoted titles
		if(preg_match('~^(.*)[ \-.]\'(\w[^\']*?)\'$~u', $fn, $m)) {
			$k = filename_id($m[2]);
			if(isset($quotTitles[$k])) {
				// some titles include quoted parts - don't strip these
				if(in_array(filename_id($m[1]), $quotTitles[$k]))
					continue;
			}
			// remove the quoted part
			$fn = $m[1];
		}
		
		// if there's a year stuck after a bracketed thing, get rid of it too
		$fn = preg_replace('~([\])}])[ .]+(19[789][0-9]|20[012][0-9])$~', '$1', $fn);
		
		
		// our algorithm is weak against some unbracketed terms (eg groups) after brackets, so maybe we can strip these brackets; we'll keep stuff after the brackets just in case
		// eg: The_Borrowers_Arrietty_(2010)_[1080p,BluRay,x264,DTS]_-_THORA-UTW
		// (above case won't work properly, but can't do much about that)
		if($before_ep) {
			$fn = pfn_trim(preg_replace('~^(.*)[\[({](uncensored|censored|(?:pal |ntsc )?dvd(?:[- .]?box|set|rip){0,2}|b[dr](?:[- .]?box|set|rip){0,2}|bdmv|blu[- .]?ray(?:[- .]?box|set|rip){0,2}|(?:[bc]d|(?:pal |ntsc )?dvd|)iso|dis[ck]|web|web-?(?:cast|stream|rip|dl)|nflx|amzn|bili|b[- ]?global|cr[- .](?:web[- .]?)?(?:rip|dl)|u3-web|hdtv|uhd|4k|7z|rar|zip|mkv|avi|ogm|(?:'.$lang_junk.'|dual|triple|tri)[\- .]?(?:audio|sub(?:title)s?)|[hx][. ]?26[45]|(?:8|10)[ \-.]?bits?|hi10p|xvid|divx(?:[ .][0-9.]+)?|avc|hevc|av1|mpeg-?[124]|(?:dv[\-. ])?hdr|(?:aac|vorbis|ogg|opus|flac|mp[234]|(?:e-?)?ac-?3|dts(?:-x|-hd(?: ?ma)?)|dolby|l?pcm|truehd|atmos|ddp?)(?:[\- .]?(?:2\.0|[57]\.1(?: surround(?: sound)?)?|[268]ch|[257]\.1 ?ch))?|webm|mini[\- .][hl]q|mini[\- .][sh]d|hq|lq|[sh]d|(?:dvd|bd|br)?remux|(?:mt-)?muxed|mux|repack|telesync|rm|rmvb|(?:no )?sfv|srt|ssa|'.$lang_junk2.'|eng[\- .]?[ds]ubs?|[ds]ubs?[\- .]?eng|[ds]ubbed(?:[\- .]version)?|subtitle[ds]?|jap|rus|ita|ger|vostfr|batch|(?:the[\- .])?complete(?:[\- .]collection|[\- .]series)?|patch|raw|music videos?|all seasons|\d{1,4} ?mb|\d(?:\.\d+)? ?gb|\d{3,4}x\d{3,4}|\d\d ?fps|\d{3,4}p|netflix|funimation|vrv|rip|v[0-4])(\W.*?)?[\])}](?:[ .]?-[ .]?)?([^{(\[]+?)$~i', '$1 - $4', $fn));
			// handle case of some junk at the end, e.g. "Boku no Hero Academia S2 [1080p]."
			$fn = rtrim($fn, " -.");
		}
		
		// junk AnimetiC likes to stick on the end of their names
		$fn = pfn_trim(preg_replace([
			'~^(.*)\|[\w\s\-_+=]*?\|$~',
			'~(.*) // [\w\s\-_+=]*?$~'
		], [
			'$1',
			'$1'
		], $fn));
		
		// many scene-ish releases also split on the resolution, so assume everything after is junk
		if(preg_match('~[ .](?:dual[ .]|multi[ .])?(?:360|480|540|720|1080|2160)p[ .][a-z]{2,6}([ .].*)?$~i', $fn, $m)) {
			// don't do this if resolution is in brackets
			if(!preg_match('~^[^(]*\)~', $m[0])) {
				$fn = substr($fn, 0, -strlen($m[0]));
				if(isset($m[1]) && preg_match('~-([a-zA-Z0-9]{2,})$~', $m[1], $m2))
					$ret['group'] = $m2[1];
			}
		}
	} while($oldfn != $fn);
}

// generate identifier for filenames
$GLOBALS['id_transforms'] = array(
	'×'=>'x',
	'｜'=>'|',
	
	"\xe2\x88\x95"=>'/',
	"\xe2\x81\x84"=>'/',
	//'／'=>' / ', // ??? spaces so that the alternative title search works?
	
	// * is only useful for Seitokai Yakuindomo S2
	"\xe2\x88\x97"=>'*',
	"\xe2\x83\xb0"=>'*',
	"\xe2\x81\x8e"=>'*',
	'✴'=>'*',
	"\xe2\x8b\x86"=>'', // star character
	"\xe2\x98\x86"=>'', // star character (☆)
	"\xe2\x98\x85"=>'', // star character
	'꞉'=>':',
	'：'=>':',
	'﹖'=>'?',
	'？'=>'?',
	// eg Dog Days"
	'″'=>'"',
	'”'=>'"',
	'“'=>'"',
	'’' => '\'',
	'`' => '\'',
	
	// ♪
	
	'Ⅰ'=>'I',
	'ⅰ'=>'i',
	'Ⅱ'=>'II',
	'ⅱ'=>'ii',
	'Ⅲ'=>'III',
	'ⅲ'=>'iii',
	'Ⅳ'=>'IV',
	'ⅳ'=>'iv',
	'Ⅴ'=>'V',
	'ⅴ'=>'v',
	'Ⅵ'=>'VI',
	'ⅵ'=>'vi',
	'Ⅶ'=>'VII',
	'ⅶ'=>'vii',
	'Ⅷ'=>'VIII',
	'ⅷ'=>'viii',
	'Ⅸ'=>'IX',
	'ⅸ'=>'ix',
	
	// umlaut reduction
	'Š' => 'S',
	'Œ' => 'O',
	'Ž' => 'Z',
	'š' => 's',
	'œ' => 'o',
	'ž' => 'z',
	'Ÿ' => 'Y',
	'¥' => 'Y',
	'µ' => 'u',
	'À' => 'A',
	'Á' => 'A',
	'Â' => 'A',
	'Ã' => 'A',
	'Ä' => 'A',
	'Å' => 'A',
	'Æ' => 'AE',
	'Ç' => 'C',
	'È' => 'E',
	'É' => 'E',
	'Ê' => 'E',
	'Ë' => 'E',
	'Ì' => 'I',
	'Í' => 'I',
	'Î' => 'I',
	'Ï' => 'I',
	'Ð' => 'D',
	'Ñ' => 'N',
	'Ò' => 'O',
	'Ó' => 'O',
	'Ô' => 'O',
	'Õ' => 'O',
	'Ö' => 'O',
	'Ø' => 'O',
	'Ù' => 'U',
	'Ú' => 'U',
	'Û' => 'U',
	'Ü' => 'U',
	'Ý' => 'Y',
	'ß' => 'b',
	'à' => 'a',
	'á' => 'a',
	'â' => 'a',
	'ã' => 'a',
	'ä' => 'a',
	'å' => 'a',
	'æ' => 'ae',
	'ç' => 'c',
	'è' => 'e',
	'é' => 'e',
	'ê' => 'e',
	'ë' => 'e',
	'ì' => 'i',
	'í' => 'i',
	'î' => 'i',
	'ï' => 'i',
	'ð' => 'o',
	'ñ' => 'n',
	'ò' => 'o',
	'ó' => 'o',
	'ô' => 'o',
	'õ' => 'o',
	'ö' => 'o',
	'ø' => 'o',
	'ù' => 'u',
	'ú' => 'u',
	'û' => 'u',
	'ü' => 'u',
	'ý' => 'y',
	'ÿ' => 'y',
	
	'Ā' => 'A',
	'ā' => 'a',
	'Ă' => 'A',
	'ă' => 'a',
	'Ą' => 'A',
	'ą' => 'a',
	'Ć' => 'C',
	'ć' => 'c',
	'Ĉ' => 'C',
	'ĉ' => 'c',
	'Ċ' => 'C',
	'ċ' => 'c',
	'Č' => 'C',
	'č' => 'c',
	'Ď' => 'D',
	'ď' => 'd',
	'Đ' => 'D',
	'đ' => 'd',
	'Ē' => 'E',
	'ē' => 'e',
	'Ĕ' => 'E',
	'ĕ' => 'e',
	'Ė' => 'E',
	'ė' => 'e',
	'Ę' => 'E',
	'ę' => 'e',
	'Ě' => 'E',
	'ě' => 'e',
	'Ĝ' => 'G',
	'ĝ' => 'g',
	'Ğ' => 'G',
	'ğ' => 'g',
	'Ġ' => 'G',
	'ġ' => 'g',
	'Ģ' => 'G',
	'ģ' => 'g',
	'Ĥ' => 'H',
	'ĥ' => 'h',
	'Ħ' => 'H',
	'ħ' => 'h',
	'Ĩ' => 'I',
	'ĩ' => 'i',
	'Ī' => 'I',
	'ī' => 'i',
	'Ĭ' => 'I',
	'ĭ' => 'i',
	'Į' => 'I',
	'į' => 'i',
	'İ' => 'I',
	'ı' => 'i',
	'Ĳ' => 'IJ',
	'ĳ' => 'ij',
	'Ĵ' => 'J',
	'ĵ' => 'j',
	'Ķ' => 'K',
	'ķ' => 'k',
	'ĸ' => 'K',
	'Ĺ' => 'L',
	'ĺ' => 'l',
	'Ļ' => 'L',
	'ļ' => 'l',
	'Ľ' => 'L',
	'ľ' => 'l',
	'Ŀ' => 'L',
	'ŀ' => 'l',
	'Ł' => 'L',
	'ł' => 'l',
	'Ń' => 'N',
	'ń' => 'n',
	'Ņ' => 'N',
	'ņ' => 'n',
	'Ň' => 'N',
	'ň' => 'n',
	'ŉ' => 'n',
	'Ŋ' => 'n',
	'ŋ' => 'n',
	'Ō' => 'O',
	'ō' => 'o',
	'Ŏ' => 'O',
	'ŏ' => 'o',
	'Ő' => 'O',
	'ő' => 'o',
	'Œ' => 'CE',
	'œ' => 'ce',
	'Ŕ' => 'R',
	'ŕ' => 'r',
	'Ŗ' => 'R',
	'ŗ' => 'r',
	'Ř' => 'R',
	'ř' => 'r',
	'Ś' => 'S',
	'ś' => 's',
	'Ŝ' => 'S',
	'ŝ' => 's',
	'Ş' => 'S',
	'ş' => 's',
	'Š' => 'S',
	'š' => 's',
	'Ţ' => 'T',
	'ţ' => 't',
	'Ť' => 'T',
	'ť' => 't',
	'Ŧ' => 'T',
	'ŧ' => 't',
	'Ũ' => 'U',
	'ũ' => 'u',
	'Ū' => 'U',
	'ū' => 'u',
	'Ŭ' => 'U',
	'ŭ' => 'u',
	'Ů' => 'U',
	'ů' => 'u',
	'Ű' => 'U',
	'ű' => 'u',
	'Ų' => 'U',
	'ų' => 'u',
	'Ŵ' => 'W',
	'ŵ' => 'w',
	'Ŷ' => 'Y',
	'ŷ' => 'y',
	'Ÿ' => 'Y',
	'Ź' => 'Z',
	'ź' => 'z',
	'Ż' => 'Z',
	'ż' => 'z',
	'Ž' => 'Z',
	'ž' => 'z',

);
function filename_id($name) {
	$word_replacements = [
		// AniDB largely treats "wo" and "o" the same
		'wo' => 'o',
		// normalize "the movie" to "movie"
		'the movie' => 'movie',
		'the.movie' => 'movie',
		// word aliases
		'first' => '1st',
		'second' => '2nd',
		'third' => '3rd',
		'fourth' => '4th',
		'fifth' => '5th',
		'sixth' => '6th',
		'seventh' => '7th',
		'eighth' => '8th',
		'ninth' => '9th',
		'tenth' => '10th',
	];
	$name = preg_replace_callback('~(?<=\W|^)('.implode('|', array_map('preg_quote', array_keys($word_replacements))).')(?=\W|$)~i', function($m) use($word_replacements) {
		return $word_replacements[strtolower($m[1])];
	}, $name);
	$tr = array_merge($GLOBALS['id_transforms'], array(
		'['=>'_',
		']'=>'_',
		'('=>'_',
		')'=>'_',
		'{'=>'_',
		'}'=>'_',
		' '=>'', // '_',
		'-'=>'', // '_',
		'+'=>'_',
		'&'=>'_',
		'.'=>'_',
		','=>'_',
		';'=>'', // I can't find any anime where the ';' character has really made a difference (except for abbreviations like 'C;H')
		//'!'=>'',
		//'?'=>'',
		'~'=>'',
		' & '=>'and',
	));
	return strtolower(preg_replace('~_{2,}~', '_',
		preg_replace('~\:(?!$)~', '', // we don't replace all colons as a workaround for "Nisekoi:" being different to "Nisekoi"
			strtr(
				unhtmlspecialchars(
					urldecode(
						str_replace('+','%2B',trim($name))
					)
				),
				$tr
			)
		)
	));
}

// guess a group name from a URL
function group_from_website($url) {
	$purl = @parse_url($url);
	if(!isset($purl['host'])) return false;
	
	// currently, only look in host
	
	$tlddot = strrpos($purl['host'], '.');
	if($tlddot) {
		$host_notld = substr($purl['host'], 0, $tlddot);
		$host = strtolower($purl['host']);
		
		// strip www. here
		if(substr($host, 0, 4) == 'www.') {
			$host_notld = substr($host_notld, 4);
			$host = substr($host, 4);
		}
		
		$host_found = false;
		$delim_p = '';
		switch($host) {
			case 'multiurl.com':
				if(preg_match('~^/la/([^/]+)~', $purl['path'], $m)) {
					$host_found = true;
					// test common format, eg "IB-animetitle"
					if(substr_count($m[1], '-') == 1)
						$host = substr($m[1], 0, strpos($m[1], '-'));
					else
						$host = $m[1];
				}
				break;
			case 'evaforum.com':
			case 'community.livejournal.com':
				if(preg_match('~^/([^/]+)~', $purl['path'], $m)) {
					$host_found = true;
					$host = $m[1];
				}
				break;
		}
		
		if(!$host_found) {
			// definitely not a group
			if(in_array($host, array(
				'nyaa.se', 'nyaa.eu', 'nyaa.si', 'nyaatorrents.org', 'anidex.moe', 'anidex.info', 'nyaa.si', 'nyaa.pantsu.cat', 'anirena.com', 'nekobt.to',
				'megaupload.com', 'fileserve.com', 'filesonic.com', 'wupload.com', 'multiurl.com', // TODO: name from folders
				'discord.gg', 'discord.com',
				'myanimelist.net', 'anidb.net',
				'ur.ly', 't.me',
				'3.ly', 'tinyurl.com', 'is.gd', 'bit.ly', 'adf.ly',
				'hotimg.com', 'imageshack.us', 'tinypic.com', 'imgur.com',
				'facebook.com', 'instagram.com', 'twitter.com', 'x.com',
			))) return false;
			
			$tld2dot = strrpos($host_notld, '.');
			if($tld2dot) {
				$domain = strtolower(substr($host, $tld2dot+1));
				
				// remove common hosts
				if(in_array($domain, array(
					'wordpress.com', 'blogspot.com', 'blog.com', 'livejournal.com',
					'freehostingcloud.com',
					'niceboard.net', 'freeforums.org',
					'co.cc', 'cz.cc',
					'edwardk.info', 'nyaatorrents.org', // should we do this ???
				))) {
					$host = substr($host, 0, $tld2dot);
				}
				else
					$host = $host_notld;
				
				// strip bad top hosts
				if($p = strpos($host, '.')) {
					$tophost = substr($host, 0, $p);
					if($tophost == 'forums' || $tophost == 'community' || $tophost == 'blog' || $tophost == 'anime')
						$host = substr($host, $p+1);
				}
				
				// if there's still a dot, grab top host
				if($p = strpos($host, '.')) {
					$host = substr($host, 0, $p);
				}
			} else {
				// this is a top level domain
				$host = $host_notld;
			}
		}
	} else
		$host = $purl['host']; // should never happen
	
	
	
	// strip words
	if(preg_match('~-?(?:fansubs?|anime|raws|encoding|rips)$~i', $host, $m))
		$host = substr($host, 0, -strlen($m[0]));
	elseif(preg_match('~-?(subs?)$~i', $host, $m))
		$host = substr($host, 0, -strlen($m[0])) .' '. $m[1];
	
	$host = strtr($host, array('-' => ' ', '.' => ' '));
	return $host;
}

function &resolve_name($id,$nam,$time,$info=array()) {
	global $db;
	$ret = array();
	
	$toto = $db->selectGetArray('toto', 'id='.$id, 'cat,torrentfiles');
	if(!in_array($toto['cat'], [1, 11, 10, 7])) {
		log_event('Skip resolving AniDB reference for '.$id.' due to bad category');
		return $ret; // only resolve anime for now
		// may add: music(2), hentai(4), musicvideo(9), hentai-anime(12)
	}
	
	$queue_add = array(
		'toto_id' => $id,
		'name' => $nam,
		'added' => $time,
		'dateline' => time(),
	);
	foreach(array('filesize','crc32','ed2k','video_duration') as $idx) {
		if(isset($info[$idx]) && $info[$idx]) $queue_add[$idx] = $info[$idx];
	}
	$db->insert('adb_resolve_queue', $queue_add, true);
	
	
	// try resolving name locally if possible
	$f = parse_filename($nam);
	if(@$f['noep']) {
		// batches are commonly marked incorrectly as not having an episode
		if($toto['torrentfiles'] >= 6) // TODO: proper batch detection
			$f['noep'] = false;
	}
	if(!empty($f) && isset($f['title'])) {
		// perform local index check
		if(($aid = $db->selectGetField('adb_aniname_map', 'aid', 'nameid='.$db->escape(filename_id($f['title'])).' AND noep='.$db->escape($f['noep']))) || $aid === 0) {
			$ret['aid'] = $aid;
		}
	}
	return $ret;
}

