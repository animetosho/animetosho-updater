<?php

// determine whether this file should be skipped or not
function releasesrc_skip_file(&$total_size, &$tinfo, &$rowinfo, &$extra_srcInfo, &$still_add) {
	$still_add = true;
	global $db;
	
	// when fetching from TT's batch category, apply some filtering
	if(@$rowinfo['tosho_id'] && @$rowinfo['cat'] == 11 && ($tt_skip = toto_batch_skip2($rowinfo, $tinfo))) {
		$still_add = false;
		return 'Ignoring TT batch file: '.$tt_skip;
	}
	if(@$rowinfo['tosho_id'] && in_array(@$rowinfo['tosho_uname'], ['nyaapantsu','sizzlingkenny','nyaasi'])) {
		$still_add = false;
		return 'Ignoring nyaa-pantsu.site entry';
	}
	
	$fts = (float)$total_size;
	$nyaa_trusted = @$rowinfo['nyaa_class'] >= 3;
	$info =& $tinfo['info'];
	$files = [];
	if(!empty($info['files']))
		$files = $info['files'];
	elseif(isset($info['name']) && isset($info['length']))
		$files = [['path' => explode('/', $info['name']), 'length' => $info['length']]];
	
	$filenames = [];
	foreach($files as $f) {
		if(!isset($f['path']) || !is_array($f['path'])) continue;
		$filenames[] = implode('/', $f['path']);
	}
	
	$tor_matches = null;
	$torpc_is_dupeOrV2 = false;
	if($torpc = torrent_compute_torpc_sha1($tinfo)) {
		$calctorpc = [16, 32, 64, 128, 256, 512, 1024, 2048, 4096, 8192, 16384];
		$piece_size_kb = $torpc['piece_size']/1024;
		// only check torpc if the hash covers >97% of the file
		if(($torpc['piece_size'] % 1024 == 0) && in_array($piece_size_kb, $calctorpc) && ($torpc['hash_coverage']/$torpc['filesize']) > 0.97) {
			// if from admin-check-skip, don't consider itself as a dupe
			$exclude_self = (isset($extra_srcInfo['_id']) ? ' AND toto_id!='.$extra_srcInfo['_id'] : '');
			$tor_matches = $db->selectGetAll('files_extra', 'fid', 'torpc_sha1_'.$piece_size_kb.'k='.$db->escape($torpc['torpc_sha1']).' AND filesize='.$torpc['filesize'].$exclude_self, 'files_extra.fid, filename', [
				'joins' => [['inner', 'files', 'fid', 'id']]
			]);
		}
		$torpc_is_dupeOrV2 = !empty($tor_matches) || preg_match('~[.eEpP#\\- ]\d{2,4}[. ]?(?:[vV][2-9]|\([vV][2-9]\)|\[[vV][2-9]\])\W~', $torpc['name']);
	}
	
	// skip harleyjones7900, Nyasha for suspicious files
	// skip jakirraju9 for constantly posting applications in wrong category
	if(@$rowinfo['anidex_id'] && in_array(@$rowinfo['anidex_uid'], [21435 /*harleyjones7900*/, 21567 /*Nyasha*/, 21977 /*dvuddsxin*/, 21810 /*Yuki21*/, 22172 /*JordanWalker*/, 21760 /*Sabo*/, 20504 /*Zolidveltuz*/, 22135 /*Onichi*/, 15220 /*RaishinNyck*/, 12664 /*milesh50*/, 22188 /*jakirraju9*/, 11786 /*Dodai*/, 21756 /*Mikazuki401*/, 17267 /*FeitanKK*/, 21393 /*Stifler*/, 8178 /*35arata*/, 11756 /*Adolfis*/, 14330 /*LordDiAmbre*/, 20184 /*DHost11*/, 12977 /*vincent46*/, 18737 /*momoti*/, 14798 /*souma*/, 17344 /*ninjaofpaulo*/, 10455 /*WajahatAzmat*/, 8924, 14347, 9524, 17093 ])) {
		$still_add = false;
		return 'Skipping malicious AniDex user';
	}
	if(@$rowinfo['tosho_id'] && in_array(@$rowinfo['tosho_uname'], ['ZaiTsev', 'lopesec534', 'Konazumii', 'uploaderamineuk', 'EnjoyoJAV', 'Konazmi', 'honod48076', 'seojina'])) {
		$still_add = false;
		return 'Skipping malicious TokyoTosho user';
	}
	if(@$rowinfo['tosho_id'] && in_array(@$rowinfo['tosho_uname'], ['ThisisLX'])) {
		$still_add = false;
		return 'Skipping TokyoTosho user (requested)';
	}
	
	// check Tosho user posting fake RARs
	if(@$rowinfo['tosho_id'] && count($files) == 2 && substr($rowinfo['link'], 0, 27) == 'https://www.anirena.com/dl/') {
		// they post a RAR and a text file - isolate these
		$rarfile = null; $txtfile = null;
		foreach($filenames as $filename) {
			$ext = get_extension($filename);
			if($ext == 'rar') $rarfile = $filename;
			if($ext == 'txt') $txtfile = $filename;
		}
		if(isset($rarfile) && isset($txtfile) && preg_match('~^Folder Torrent .*\.rar$~i', $rarfile) && preg_match('~^Extract .*Torrent.*\.txt$~i', $txtfile)) {
			return 'Skipping likely malicious TokyoTosho post';
		}
	}
	
	if(@$rowinfo['anidex_id']) {
		if(anidex_user_is_flooding(@$rowinfo['anidex_uid'], $rowinfo['dateline']))
			return 'Skipping Anidex post-flooding user';
		if(anidex_user_posted_porn(@$rowinfo['anidex_uid']))
			return 'Skipping Anidex bad porn user';
	}
	if(@$rowinfo['nyaa_id']) {
		if(@$extra_srcInfo['uploader_name']) {
			// TODO: fix this - can wrongly trap bot posts
			//if(nyaasi_user_is_flooding($extra_srcInfo['uploader_name'], $rowinfo['dateline']))
			//	return 'Skipping Nyaa post-flooding user';
			if(nyaasi_user_posted_porn($extra_srcInfo['uploader_name']))
				return 'Skipping Nyaa bad porn user';
		}
		if(nyaasi_user_posts_same_size(@$extra_srcInfo['uploader_name'], $rowinfo['dateline'], $fts))
			return 'Skipping Nyaa user flooding same size torrents';
	}
	
	if(releasesrc_contains_suspicious_files($filenames) > 0) {
		// user posting libvlc.dll
		if(@$extra_srcInfo['source'] == 'nyaasi' && count($files) == 3 && in_array('libvlc.dll', $filenames) && in_array('Subs/video.mkv', $filenames)) {
			$still_add = false;
			return 'Skipping+hiding fake libvlc.dll poster';
		}
		// skip corresponding suspicious files posted at Anidex
		if(@$extra_srcInfo['source'] == 'anidex' && (
			// !! if this list is updated, don't forget to update anidex_user_posted_suspicious
			(stripos($rowinfo['name'], '[SubsPlease]') === 0) ||
			(stripos($rowinfo['name'], '[Anime Time]') === 0 && @$rowinfo['anidex_uid'] != 17220) ||
			(stripos($rowinfo['name'], '[Erai-raws]') === 0 && @$rowinfo['anidex_uid'] != 7933) ||
			(stripos($rowinfo['name'], '[SlyFox]') === 0 && @$rowinfo['anidex_uid'] != 21440) || // unofficial poster?, but seems to be the only one posting SlyFox
			(stripos($rowinfo['name'], '[1080P][WEB-DL][AAC AVC][CHT][MP4]') && anidex_user_younger_than(@$rowinfo['anidex_uid'], 14))
		)) {
			$still_add = false;
			return 'Skipping+hiding suspicious fake file';
		}
		// skip suspicious HorribleSubs/Erai files
		if(@$extra_srcInfo['source'] == 'nyaasi' && (
			(strpos($rowinfo['name'], '[HorribleSubs]') === 0 && @$extra_srcInfo['uploader_name'] != 'HorribleSubs') ||
			(strpos($rowinfo['name'], '[Erai-raws]') === 0 && @$extra_srcInfo['uploader_name'] != 'Erai-raws') ||
			(strpos($rowinfo['name'], '[SubsPlease]') === 0 && @$extra_srcInfo['uploader_name'] != 'subsplease') ||
			(strpos($rowinfo['name'], '[HR]') === 0 && @$extra_srcInfo['uploader_name'] != 'So-Now-What') ||
			(strpos($rowinfo['name'], '[Judas]') === 0 && @$extra_srcInfo['uploader_name'] != 'Judas') ||
			(strpos($rowinfo['name'], '[EMBER]') === 0 && @$extra_srcInfo['uploader_name'] != 'Ember_Encodes')
		))
			return 'Skipping suspicious fake file';
		// skip corresponding suspicious files posted at TT
		if(@$extra_srcInfo['source'] == 'tosho' && (
			(strpos($rowinfo['name'], '[Erai-raws]') === 0 && @$rowinfo['tosho_uname'] != 'Erai-raws') ||
			(strpos($rowinfo['name'], '[SubsPlease]') === 0 && @$rowinfo['tosho_uname'] != 'subsplease') ||
			(strpos($rowinfo['name'], '[HorribleSubs]') === 0 && @$rowinfo['tosho_uname'] == '')
		))
			return 'Skipping suspicious fake file';
		if(@$extra_srcInfo['source'] == 'anidex' && count($files) == 1 && (anidex_user_younger_than(@$rowinfo['anidex_uid'], 14) || anidex_user_oldest_upload_lt(@$rowinfo['anidex_uid'], 14) || anidex_user_posted_suspicious(@$rowinfo['anidex_uid']))) {
			return 'Skipping suspicious file (user history)';
		}
		// common naming pattern
		if(@$extra_srcInfo['source'] == 'anidex' && preg_match('~(\w\[TGx\]|-PHOENiX|-GalaxyRG)$~', $rowinfo['name'])) {
			return 'Skipping suspicious file (name)';
		}
		if(@$extra_srcInfo['source'] == 'nyaasi' && nyaasi_user_oldest_upload_lt(@$extra_srcInfo['uploader_name'], 2)) {
			return 'Skipping suspicious file (new user)';
		}
		
		if(@$extra_srcInfo['source'] == 'tosho' && preg_match('~^1.*\.(zip|rar)~i', $rowinfo['name']) && @$rowinfo['tosho_uname'] == '' && count($files) == 1 && preg_match('~^https://www\.anirena\.com/dl/~i', $rowinfo['link'])) {
			return 'Skipping suspicious fake file';
		}
		if(@$extra_srcInfo['source'] == 'tosho' && @$rowinfo['tosho_uname'] == '' && in_array($rowinfo['tosho_uhash'], array_map('hex2bin', ['3B61226354B36748D67A6CFB9DE991E91A784223', 'EC471D80F5BA3397E647C84C8B5A35809ABBD9F5', 'D5266841AD3760814CF9C607C4A2F938208CE5FA']))) {
			return 'TT submitter hash posting malicious content';
		}
	}
	
	
	$is_batch = $fts > 1024*1024*1024 && !empty($info['files']) && count($info['files']) > 4;
	
	// skip a single ISO file (assume that it's a batch)
	if($fts > 2*1024*1024*1024 && !empty($info['name']) && strtolower(substr($info['name'], -4)) == '.iso')
		return 'Skipping ISO file';
	// skip collection of ISO files
	elseif($fts > 2*1024*1024*1024 && !empty($info['files']) && releasesrc_is_iso_batch($info['files']))
		return 'Skipping ISO batch';
	// skip if it looks like a remux
	elseif(@preg_match('~(?:^|\W)(?:us|jp)?(bd|dvd([ \\-.]r[1-9][a-z]?)?|blu[ \\-.]?ray|hybrid)[ \\-.]?remux(?:$|\W)~i', $rowinfo['name'], $name_remux) && !empty($files) && releasesrc_possible_remux($files, strtolower($name_remux[1][0])))
		return 'Skipping remux';
	// skip film scan releases
	elseif(@preg_match('~(?:^|\W)(?:35mm|16mm|4k)[ \\-.]?(?:(?:4k|1080p|hd)[ \\-.])*[ \\-.]?scan(?:$|\W)~i', $rowinfo['name']) && !empty($files) && releasesrc_possible_remux($files, 'd'))
		return 'Skipping film scan';
	// filter out [glm8892] remuxes
	elseif(@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == ''
		&& @preg_match('~(?:^|\W)(bd|dvd|blu[ \\-.]?ray) Remux(?:$|\W)~i', $rowinfo['comment'], $name_remux)
		&& @preg_match('~ \[glm8892\](\.[a-zA-Z0-9]{3,5})?$~', $info['name'])
		&& !empty($files) && releasesrc_possible_remux($files, strtolower($name_remux[1][0]))
	)
		return 'Skipping glm8892 remux';
	// filter out [ZesseiBijin] remuxes
	elseif(@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == ''
		&& @preg_match('~^\[ZesseiBijin\] ~', $info['name'])
		&& @preg_match('~(?:^|\W)(bd|dvd|blu[ \\-.]?ray) raw(?:$|\W)~i', @$extra_srcInfo['description'], $name_remux)
		&& !empty($files) && releasesrc_possible_remux($files, strtolower($name_remux[1][0]))
	)
		return 'Skipping ZesseiBijin remux';
	// sometimes sukebei.nyaa stuff gets posted to anime section of TT
	elseif(preg_match('~^https?\://sukebei\.nyaa\.[a-z]{2,3}/~i', $rowinfo['link']))
		return 'Skipping Nyaa-sukebei link';
	// TODO: consider using Nyaa username for horriblesubs filter
	elseif($fts > 2*1024*1024*1024 && (stripos($rowinfo['name'], 'horriblesubs') || stripos($rowinfo['name'], 'horrible subs')) && (@$rowinfo['nyaa_id'] || stripos($rowinfo['name'], 'wolfpack')) && !$nyaa_trusted && $is_batch)
		return 'Skipping Horriblesubs unofficial batch';
	elseif(@preg_match('~(- ?Unofficial Batch$|\[Unofficial Batch\]|\(Unofficial Batch\))~i', $rowinfo['name']) && count($info['files']) >= 4)
		return 'Skipping labelled unofficial batch';
	
	// skip unofficial Erai from Anidex
	elseif(@$rowinfo['anidex_id'] && @$rowinfo['anidex_uid'] && $rowinfo['anidex_uid'] != 7933 && stripos($rowinfo['name'], '[Erai-raws]') === 0)
		return 'Skipping unofficial Erai-raws file';
	// skip Ember posted stuff on Anidex (duplicate of Nyaa, but different BTIH)
	elseif(@$rowinfo['anidex_id'] && @$rowinfo['anidex_uid'] == 17906 && stripos($rowinfo['name'], '[EMBER]') === 0)
		return 'Skipping Anidex posted Ember file';
	// skip AniEncodeX posted stuff on Anidex (duplicate of Nyaa, but different BTIH)
	elseif(@$rowinfo['anidex_id'] && @$rowinfo['anidex_uid'] == 10846 && stripos($rowinfo['name'], '[AniEncodeX]') === 0)
		return 'Skipping Anidex posted AniEncodeX file';
	// skip New-raws on Anidex - they post to Nyaa, and someone posts non-subbed New-raws files incorrectly
	elseif(@$rowinfo['anidex_id'] && @$rowinfo['anidex_uid'] == 11323 && stripos($rowinfo['name'], '[New-raws] ') === 0)
		return 'Skipping Anidex posted New-raws file';
	
	// mislabelled Toonshub raw
	elseif(@$rowinfo['anidex_id'] && @$rowinfo['anidex_uid'] == 21238 && (stripos($rowinfo['name'], ' UNEXT WEB-DL ') || stripos($rowinfo['name'], ' ABEMA WEB-DL ') || stripos($rowinfo['name'], ' AO WEB-DL ') || stripos($rowinfo['name'], ' ADN WEB-DL ')))
		return 'Skipping Toonshub raw';
	// just skip all Anidex Toonshub posts, as the Nyaa ones are categorized correctly
	elseif(@$rowinfo['anidex_id'] && @$rowinfo['anidex_uid'] == 21238)
		return 'Skipping Toonshub Anidex';
	
	// skip AnimeIsMyWaifu entries, as requested
	elseif(@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'AnimeIsMyWAifu')
		return 'Skipping as requested by uploader';
	
	// skip Russian ARR entries
	elseif(((@$rowinfo['anidex_id'] && @$rowinfo['anidex_uid'] == 15274) || (@$extra_srcInfo['source'] == 'tosho' && @$rowinfo['tosho_uname'] == 'ARR')) && preg_match('~\(19\d\d\) ?\[ARR\]\.mp4$~i', $rowinfo['name']))
		return 'Skipping ARR Russian shows';
	
	elseif(preg_match('#^\[Erai-raws\] .* - (?:0[01]|1) ~ (\d{2,3})(v\d)?(?: \+ [^\[]+| \([A-Z]+\))? \[\d+p(?: [\\-a-z34 ]+)?\](?:\[BATCH\])? ?(?:\[Multiple Subtitles?\])?(?: ?(?:\[[A-Z\\-]+\])+)*(?:\[Unofficial Batch\])?$#i', $rowinfo['name'], $m) && $is_batch && count($info['files']) >= (int)$m[1] && $torpc_is_dupeOrV2)
		return 'Skipping Erai batch';
	elseif(preg_match('#^\[Erai-raws\] .* - .*?(\d{2,3})(v\d)? \(Repack\)(?: \+ [^\[]+| \([A-Z]+\))? \[\d+p\]#i', $rowinfo['name']) && $fts > 1024*1024*1024)
		return 'Skipping Erai repack';
	elseif($fts > 2*1024*1024*1024 && preg_match('#^\[SubsPlease\] .* \((\d{2,3})-(\d{2,3})\) .*\[Batch\]$#i', $rowinfo['name'], $m) && $is_batch && count($info['files']) >= (int)$m[2]-(int)$m[1] && $torpc_is_dupeOrV2 && ((@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'subsplease') || (@$extra_srcInfo['source'] == 'tosho' && @$rowinfo['tosho_uname'] == 'subsplease')))
		return 'Skipping SubsPlease batch';
	elseif($fts > 2*1024*1024*1024 && preg_match('#^\[SubsPlease\] #i', $rowinfo['name'], $m) && $is_batch && ((@$extra_srcInfo['source'] == 'nyaasi' && (@$extra_srcInfo['uploader_name'] == 'KlausKleins' || @$extra_srcInfo['uploader_name'] == 'thedarkness000' || @$extra_srcInfo['uploader_name'] == 'AmiBot007'))))
		return 'Skipping SubsPlease unofficial batch';
	elseif($fts > 2*1024*1024*1024 && preg_match('#\WUnofficial Batch\W$#i', $rowinfo['name'], $m) && $is_batch && ((@$extra_srcInfo['source'] == 'nyaasi' && (@$extra_srcInfo['uploader_name'] == 'thedarkness000'))))
		return 'Skipping thedarkness000 unofficial batch';
	elseif(preg_match('#^\[ASW\] .* \(Batch\)($| )#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'AkihitoSubsWeeklies'))
		return 'Skipping ASW batch';
	elseif(preg_match('#^\[EMBER\] .* \(202\d\) \(Season \d+(?: \| Part \d+| \+ [^)]+)?\) (?:\([a-z]+\) )?\[1080p\] (?:\[HEVC WEBRip.*?\]|\[.*HEVC.*\] \([^()]+\) \(Batch\))#i', $rowinfo['name']) && !strpos($rowinfo['name'], 'Dual Audio') && $is_batch && $torpc_is_dupeOrV2 && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'Ember_Encodes'))
		return 'Skipping EMBER batch';
	elseif(preg_match('#^\[EMBER\] .* \(20[12]\d-20[12]\d\) \(Season 1 ?\+ ?2#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'Ember_Encodes'))
		return 'Skipping EMBER multi-season batch';
	elseif(preg_match('#^\[SSA\] .*\[Batch\]$#i', $rowinfo['name']) && $is_batch && ((@$extra_srcInfo['source'] == 'tosho' && @$rowinfo['tosho_uname'] == 'SmallSizedAnimations') || (@$extra_srcInfo['source'] == 'anidex' && @$rowinfo['anidex_uid'] == 17017) || (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'SmallSizedAnimations')))
		return 'Skipping SSA batch';
	elseif(preg_match('#^\[HorribleRips\] #i', $rowinfo['name']) && $is_batch && (
		(@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'HorribleRips')
		|| (@$extra_srcInfo['source'] == 'anidex' && @$rowinfo['anidex_uid'] == 17745)
	))
		return 'Skipping HorribleRips batch';
	elseif(preg_match('#^\[YuiSubs\] .* \[Batch\]$#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'YuiSubs'))
		return 'Skipping YuiSubs batch';
	elseif(preg_match('#^\[DKB\] .*\[batch\]$#i', $rowinfo['name']) && $is_batch && ((@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'DKB0512') || (@$extra_srcInfo['source'] == 'anidex' && @$rowinfo['anidex_uid'] == 17095)))
		return 'Skipping DKB batch';
	elseif(preg_match('#^\[Anime Time\] .* \[Batch\](?: +\(.*\))?$#i', $rowinfo['name']) && !stripos($rowinfo['name'], '[Dual Audio]') && $is_batch && ((@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'sff') || (@$extra_srcInfo['source'] == 'anidex' && @$rowinfo['anidex_uid'] == 17220)))
		return 'Skipping Anime Time batch';
	// Judas releases unlabelled v2s in batches, so don't do dupe checks
	elseif(preg_match('#^\[Judas\] .* \(Batch\)$#i', $rowinfo['name']) && !stripos($rowinfo['name'], '[Dual-Audio]') && $is_batch && ((@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'Judas') || (@$extra_srcInfo['source'] == 'anidex' && @$rowinfo['anidex_uid'] == 20695)))
		return 'Skipping Judas batch';
	elseif(preg_match('#^\[Valenciano\] .*\[Multi-Sub\](?: \(Batch\))?$#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'Valenciano'))
		return 'Skipping Valenciano batch';
	elseif(preg_match('#^\[Trix\] .* \(Batch\)($| - | \(| \[)#i', $rowinfo['name']) && $is_batch && $torpc_is_dupeOrV2 && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'NekoTrix'))
		return 'Skipping Trix batch';
	elseif(preg_match('#^\[New-raws\] .* - (0?1|\d\d) ?[~\-] ?\d+ \[\d+p\]#i', $rowinfo['name']) && $is_batch && $torpc_is_dupeOrV2 && ((@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == '' && strpos(@$extra_srcInfo['description'], '[New-raws ') !== false) || (@$extra_srcInfo['source'] == 'tosho' && @$rowinfo['tosho_uname'] == '')))
		return 'Skipping New-raws batch';
	elseif(preg_match('#^\[ShouryuuReppa\] .*\[Batch\]$#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'ShouryuuReppa'))
		return 'Skipping ShouryuuReppa batch';
	elseif(preg_match('#^\[Yameii(?:-Unofficial)?\] #i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'TRC'))
		return 'Skipping Yameii-Unofficial batch';
	elseif(preg_match('#^\[A-L\] .* \(Batch\)$#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'Haruki_04'))
		return 'Skipping A-L batch';
	elseif(preg_match('# S\d+ \d+p [A-Z0-9. ]+ -Tsundere-Raws \(#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'Tsundere-Raws'))
		return 'Skipping Tsundere-Raws batch';
	// VARYG posts a mix of new batches and reposts; hard to differentiate, so we just block by default now
	// VARYG often makes minor adjustments for batches (e.g. subs) so we'll skip the hash check
	elseif(preg_match('# S\d+(?:E01-E\d\d)? (?:Part \d+ )?\d+p [A-Z0-9. ]+ WEB-DL [A-Z0-9. ]+-VARYG \(#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'varyg1001'))
		return 'Skipping VARYG batch';
	// someone is posting VARYG to TT but with scene naming scheme
	elseif(preg_match('#\.S\d+(?:E01-E\d\d)?\.(?:Part\.\d+\.)?\d+p\.[A-Z0-9.]+\.WEB-DL\.[A-Z0-9.]+-VARYG$#i', $rowinfo['name']) && $is_batch && @$extra_srcInfo['source'] == 'tosho')
		return 'Skipping VARYG batch (tosho)';
	elseif(preg_match('#^\[Seigyoku\] .* \(Batch\)$#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'Lumpstud'))
		return 'Skipping Seigyoku batch';
	elseif(preg_match('#^\[amZero\] .* \(Batch\)$#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'denis18312'))
		return 'Skipping amZero batch';
	elseif(preg_match('#^\[Ironclad\] .* \(Batch\)$#i', $rowinfo['name']) && $is_batch && !preg_match('~ \[BD\.\d+p\.~', $rowinfo['name']) && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'Ironclad'))
		return 'Skipping Ironclad batch';
	elseif(preg_match('#^\[DB\] .* \((Season \d-\d+|Complete Series)\W#i', $rowinfo['name']) && $is_batch && ((@$extra_srcInfo['source'] == 'nyaasi' && (@$extra_srcInfo['uploader_name'] == 'dragonballz4' || @$extra_srcInfo['uploader_name'] == 'animencodes')) || (@$extra_srcInfo['source'] == 'anidex' && (@$rowinfo['anidex_uid'] == 12677 || @$rowinfo['anidex_uid'] == 11412))))
		return 'Skipping DB multi-batch';
	elseif(preg_match('#^\[AniSuki\] .* (\(Batch\)|\[Batch\])$#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'KingBushido'))
		return 'Skipping AniSuki batch';
	elseif(preg_match('#^\[SubsPlus\+\] .* \(Batch\)$#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'SubsPlus'))
		return 'Skipping SubsPlus batch';
	elseif(preg_match('#^\[SanKyuu\] .* \[WEB \d+p.+ \[Batch\]$#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'SanKyuu'))
		return 'Skipping SanKyuu batch';
	elseif(preg_match('#^\[LostYears\] .* \(WEB \d+p.+ \(Batch\)$#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'LostYears'))
		return 'Skipping LostYears batch';
	elseif(preg_match('#^\[Commie\] [^[]+( [BD \d+p [a-z]+])?$#i', $rowinfo['name']) && !stripos($rowinfo['name'], ' - Volume ') && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'Commie'))
		return 'Skipping Commie batch';
	elseif(preg_match('#^\[(MiniMTBB|MTBB|Okay-Subs)\] [^(]+ \(WEB \d+p\) \|#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'motbob'))
		return 'Skipping MTBB web batch';
	elseif(preg_match('#^\[MA0MA0\] .+? - S\d+ E01~E\d+ \[Madhouse\]\[(CR|NF) WEBRip\]#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'tosho' && @$rowinfo['tosho_uname'] == 'MA0MA0'))
		return 'Skipping MA0MA0 batch';
	elseif(preg_match('#^\[Breeze\] .+? S\d+(?: \+ .+?)? \[1080p #i', $rowinfo['name']) && $is_batch &&  $torpc_is_dupeOrV2 && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'LastBreeze'))
		return 'Skipping LastBreeze batch';
	elseif(preg_match('#^\[Sokudo\] .+? S\d+(?: \+ .+?)? \[1080p #i', $rowinfo['name']) && $is_batch &&  $torpc_is_dupeOrV2 && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == ''))
		return 'Skipping Sokudo batch';
	elseif(preg_match('#^\[Reza\] .+? - Season \d+ \[WEBRip #i', $rowinfo['name']) && $is_batch &&  $torpc_is_dupeOrV2 && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'Reza27'))
		return 'Skipping Reza batch';
	
	elseif(preg_match('#\Wunofficial\W(.+\W)?batch\W$#i', $rowinfo['name']) && $is_batch && (@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == ''))
		return 'Skipping generic unofficial batch';
	
	// skip Exiled Destiny reposter
	elseif(preg_match('#^\[Exiled-Destiny\] #i', $rowinfo['name']) && ((@$extra_srcInfo['source'] == 'nyaasi' && !@$extra_srcInfo['uploader_name'] && ($rowinfo['comment'] == 'Taken From Exiled-Destiny IRC Channel "https://exiled-destiny.com/ "' || $rowinfo['comment'] == '' || preg_match('~\WComplete name\s*: [DH]:\\\\Exiled-Destiny\\\\[A-Z0-9][\\\\\[]~i', @$extra_srcInfo['description']))) || (@$extra_srcInfo['source'] == 'anidex' && @$rowinfo['anidex_uid'] == 14435)))
		return 'Skipping Exiled-Destiny reposter';
	elseif($fts > 1024*1024*1024 && preg_match('#^\[Neo-raws\] .*( |\])\[2160p\]#i', $rowinfo['name']) && (@$extra_srcInfo['source'] == 'anidex' && @$rowinfo['anidex_uid'] == 17447))
		return 'Skipping Neo-raws 4K upscale';
	elseif($fts > 512*1024*1024 && preg_match('#\W4K Upscaled\W#i', $rowinfo['name']) && (@$extra_srcInfo['source'] == 'anidex' && @$rowinfo['anidex_uid'] == 18804))
		return 'Skipping AnimeIn4K 4K upscale';
	// gunk
	elseif(preg_match('#^\[\\\\ɐ\\\\utism\] #i', $rowinfo['name']) && ((@$extra_srcInfo['source'] == 'nyaasi' && !@$extra_srcInfo['uploader_name'] && $rowinfo['website'] == 'https://4chan.org/a/')))
		return 'Skipping [\ɐ\utism]';
	elseif(preg_match('#^\[LECV140291\] #i', $rowinfo['name']) && (@$extra_srcInfo['source'] == 'anidex' && @$rowinfo['anidex_uid'] == 21348))
		return 'Skipping lecv140291';
	elseif(preg_match('#^\[FREEPALESTINE\] #i', $rowinfo['name']) && ((@$extra_srcInfo['source'] == 'nyaasi' && !@$extra_srcInfo['uploader_name'])))
		return 'Skipping [FREEPALESTINE]';
	elseif(preg_match('#^\[Everest\] #i', $rowinfo['name']) && ((@$extra_srcInfo['source'] == 'nyaasi' && @$extra_srcInfo['uploader_name'] == 'Reza27')))
		return 'Skipping [Everest] joke encodes';
	elseif(@$extra_srcInfo['source'] == 'nekobt' && @$extra_srcInfo['uploading_group'] == 7313765950000)
		return 'Skipping VARYG from nekoBT'; // VARYG uploads to both Nyaa & nekoBT, but has inconsistent BTIH; it seems like the torrents are completely different, despite the nBT torrent referencing Nyaa tracker
	
	// we could do a second check for Nyaa/TT category, for people who submit stuff in the wrong category, but, since this prevents the torrent download, if Nyaa category was wrong but TT was right, download would always be skipped
	elseif(@$rowinfo['nyaa_cat'] && !in_array($rowinfo['nyaa_cat'], $GLOBALS['nyaa_cats']))
		return 'Skipping non-fetched Nyaa category';
	// skip Tompelsubs / casper403 / Serenium / 7Deadlysiner (for repeatedly re-posting the same thing)
	// skip Rawback for constantly posting in the wrong category (and never posting anything that we'd want)
	// skip ZacksAnimeDubs for reposting iAHD and others
	// skip fapforfun for posting wrong category
	elseif(@$rowinfo['anidex_id'] && in_array(@$rowinfo['anidex_uid'], [9918, 13342, 12410, 13354, 16696 /*Rawback*/, 18127 /*ofkkyu*/, 22000 /*ZacksAnimeDubs*/, 8036 /*fapforfun*/ ]))
		return 'Skipping unwanted AniDex users entry';
	elseif(@$rowinfo['anidex_id'] && $rowinfo['anidex_id'] < 50000)
		return 'Skipping old AniDex entry';
	elseif(@$rowinfo['nyaa_id'] && $rowinfo['nyaa_id'] < 923000)
		return 'Skipping old Nyaa entry';
	// refuse anything over 16GB, or 32GB if Nyaa trusted (Nyaa, dead, not doing same rules for Nyaa.si now)
	elseif($fts > ($nyaa_trusted ? 16384 : 16384)*1048576)
		return 'Skipping large file';
	
	// check BTIH in list of old Nyaa entries
	if(@$tinfo['btih']) {
		$seen = $db->selectGetField('btih_seen', 'seen', 'btih='.$db->escape($tinfo['btih']));
		if($seen && $seen < (time()-86400*7))
			return 'Skipping old torrent (BTIH match)';
	}
	
	if(!empty($extra_srcInfo['skip_hint']))
		return 'Skipping due to source hint';
	
	return false;
}

function releasesrc_is_iso_batch($files) {
	if(empty($files) || !is_array($files)) return false;
	
	$iso_len = $other_len = 0;
	foreach($files as $f) {
		if(!isset($f['path']) || !is_array($f['path']) || !isset($f['length']) || !is_numeric($f['length'])) continue;
		$ext = get_extension(end($f['path']));
		if($ext == 'iso' || $ext == 'vob' || $ext == 'm2ts')
			$iso_len += (float)$f['length'];
		else
			$other_len += (float)$f['length'];
	}
	$total = ($iso_len+$other_len);
	if($total <= 0) return false;
	return ($iso_len / $total) > 0.9;
}

// 'hint' is 'b' for BD, 'd' for DVD
function releasesrc_possible_remux($files, $hint='') {
	if(empty($files) || !is_array($files)) return false;
	
	// there's no real nice way to figure this out, so we'll just check if there's a large MKV/MP4 present
	// large: >2.5GB for BD remux, >768MB for DVD remux
	//$threshold = ($hint[0] == 'b' ? 2560 : 768) * 1048576;
	// since the above allows through remuxed specials, we'll just do a blanket 500MB threshold
	$threshold = 500 * 1048576;
	foreach($files as $f) {
		if(!isset($f['path']) || !is_array($f['path']) || !isset($f['length']) || !is_numeric($f['length'])) continue;
		$ext = get_extension(end($f['path']));
		if(($ext == 'mkv' || $ext == 'mp4') && ($f['length'] > $threshold))
			return true;
	}
	return false;
}

function releasesrc_contains_suspicious_files($filenames) {
	if(empty($filenames)) return 0;
	
	return max(array_map(function($filename) {
		$ext = get_extension($filename);
		if($ext == 'scr') return 1;
		if($ext == 'exe' || $ext == 'com' || $ext == 'vbs' || $ext == 'lnk')
			return 0.8;
		if($ext == 'rar' || $ext == 'zip' || $ext == 'zipx' || $ext == '7z')
			return 0.5;
		if($ext == 'iso' || $ext == 'bat' || $ext == 'cmd' || $ext == 'reg')
			return 0.3;
		if(preg_match('~\.(mkv|mp[34]|avi)\.([a-z0-9]{2,5})$~i', $filename, $m) && !in_array(strtolower($m[2]), ['mkv','mp4','avi','sfv','md5','sha1','txt','par2'])) // double-extension is suspicious (or accidental)
			return 0.5;
		return 0;
	}, $filenames));
}

function anidex_user_younger_than($uid, $days) {
	if(!$uid) return false;
	static $cache = [];
	if(!isset($cache[$uid])) {
		global $db;
		$cache[$uid] = $db->selectGetField('arcscrape.anidex_users', 'joined', 'id='.((int)$uid));
		if(!$cache[$uid]) {
			// user not fetched yet (early scrape?) - assume a new user
			unset($cache[$uid]);
			return true;
		}
	}
	return ($GLOBALS['curtime'] - $cache[$uid]) <= $days*86400;
}
function anidex_user_oldest_upload_lt($uid, $days) {
	if(!$uid) return false;
	static $cache = [];
	if(!isset($cache[$uid])) {
		global $db;
		$cache[$uid] = $db->selectGetField('arcscrape.anidex_torrents', 'MIN(date)', 'uploader_id='.((int)$uid).' AND deleted=0');
	}
	return ($GLOBALS['curtime'] - $cache[$uid]) <= $days*86400;
}
function anidex_user_posted_suspicious($uid, $days=14) {
	if(!$uid) return false;
	static $cache = [];
	if(!isset($cache[$uid])) {
		global $db;
		$cache[$uid] = (bool)$db->selectGetField('arcscrape.anidex_torrents', 'id', '
			uploader_id='.((int)$uid).'
			AND date > '.($GLOBALS['curtime'] - $days*86400).'
			AND category=1 AND language=1 AND (labels&6 = 0)
			AND (filename LIKE "[SubsPlease] %" OR filename LIKE "[Anime Time] %" OR filename LIKE "[Erai-raws] %" OR filename LIKE "[SlyFox] %")');
		// !! ^ if this list is updated, don't forget to update main filter
	}
	return $cache[$uid];
}
function anidex_user_is_flooding($uid, $time) {
	if(!$uid) return false;
	static $cache = [];
	if(!isset($cache[$uid])) {
		global $db;
		$cache[$uid] = $db->selectGetField('arcscrape.anidex_torrents', 'COUNT(id)', '
			uploader_id='.((int)$uid).'
			AND date BETWEEN '.($time-1800).' AND '.($time+900));
	}
	return $cache[$uid] >= 10;
}

// look to see if user posted porn (in the last 20 entries) but not marked as such
function anidex_user_posted_porn($uid) {
	if(!$uid) return false;
	static $cache = [];
	if(!isset($cache[$uid])) {
		$cache[$uid] = false;
		global $db;
		$data = $db->selectGetAll('arcscrape.anidex_torrents', 'id', 'uploader_id='.((int)$uid).' AND (labels&4 = 0) AND category!=15', 'id,filename', ['limit'=>20, 'order' => 'id DESC']);
		foreach($data as $row) {
			if(releasesrc_is_porn($row['filename'])) {
				$cache[$uid] = true;
			}
		}
		
	}
	return $cache[$uid];
}
// TODO: same size filter + check index usage


function nyaasi_user_is_flooding($uname, $time) {
	if(!$uname) return false;
	static $cache = [];
	if(!isset($cache[$uname])) {
		global $db;
		$cache[$uname] = $db->selectGetField('arcscrape.nyaasi_torrents', 'COUNT(id)', '
			uploader_name='.$db->escape($uname).'
			AND created_time BETWEEN '.($time-1800).' AND '.($time+900));
	}
	return $cache[$uname] >= 10;
}
function nyaasi_user_posted_porn($uname) {
	if(!$uname) return false;
	static $cache = [];
	if(!isset($cache[$uname])) {
		$cache[$uname] = false;
		global $db;
		$data = $db->selectGetAll('arcscrape.nyaasi_torrents', 'id', 'uploader_name='.$db->escape($uname), 'id,display_name', ['limit'=>20, 'order' => 'id DESC']);
		foreach($data as $row) {
			if(releasesrc_is_porn($row['display_name'])) {
				$cache[$uname] = true;
			}
		}
	}
	return $cache[$uname];
}
// detect many torrents of the same size posted by the same user - this is typically either a buggy bot re-uploading the same thing, or a flood
function nyaasi_user_posts_same_size($uname, $time, $size) {
	if(!$size) return false;
	static $cache = [];
	$key = $uname.'_'.$size;
	if(!isset($cache[$key])) {
		global $db;
		$cache[$key] = $db->selectGetField('arcscrape.nyaasi_torrents', 'COUNT(id)', '
			uploader_name='.$db->escape($uname).'
			AND filesize='.$size.'
			AND created_time BETWEEN '.($time-($uname ? 3600 : 900)).' AND '.($time+900));
	}
	return $cache[$key] >= 6;
}
function nyaasi_user_oldest_upload_lt($uname, $days) {
	if(!$uname) return false;
	static $cache = [];
	if(!isset($cache[$uname])) {
		global $db;
		$cache[$uname] = $db->selectGetField('arcscrape.nyaasi_torrents', 'MIN(created_time)', 'uploader_name='.$db->escape($uname)) ?: 0; // .' AND flags&32=0'
	}
	if(!$cache[$uname]) return true; // no uploads
	return ($GLOBALS['curtime'] - $cache[$uname]) <= $days*86400;
}


function releasesrc_ignore_file(&$rowinfo, $extra=[]) {
	global $db;
	if($db->selectGetField('skip_fetch', 'dateline', 'link='.$db->escape(@$rowinfo['link'])))
		return true;
	if(@$rowinfo['tosho_id'] && @$rowinfo['cat'] == 11) {
		$time = time();
		if(!mt_rand(0, 10))
			$db->delete('skip_fetch', 'dateline < '.($time - 3*86400));
		
		if($tt_skip = toto_batch_skip($rowinfo)) {
			if($tt_skip != 'Nyaa entry') { // don't blacklist Nyaa entries
				$db->insert('skip_fetch', array('link' => @$rowinfo['link'], 'dateline' => $time));
				return 'Ignoring TT batch file: '.$tt_skip;
			} else
				return true;
		}
	}
	
	// ignore Erai-raws posted on TT/Anidex, as Nyaa is assumed to be key source
	if(stripos($rowinfo['name'], '[Erai-raws]') === 0 && ((@$extra['source'] == 'anidex' && @$rowinfo['anidex_uid'] == 7933) || (@$extra['source'] == 'tosho' && @$rowinfo['tosho_uname'] == 'Erai-raws' && (stripos($rowinfo['link'], 'https://ddl.erai-raws.info/') === 0 || stripos($rowinfo['link'], 'https://nyaa.si/download/') === 0))))
		return 'Ignoring Anidex/TT Erai';
	
	return releasesrc_is_porn($rowinfo['name']) ? 'Ignoring porn' : false;
}

function releasesrc_is_porn($srcName) {
	$title = ' '.@strtolower($srcName).' ';
	$title = preg_replace('~(\w+)\. ~', '$1 ', $title); // have seen a case of trying to escape filters by sticking a '.' after each word
	$nstitle = str_replace(' ', '', $title); // have seen "C h i l d porno C h i l d p o r n o" attempts
	if(strpos($nstitle, 'childporn') || strpos($nstitle, 'childrenporn') || strpos($nstitle, 'childsex') || strpos($nstitle, 'childrensex') || strpos($nstitle, 'jailbait') || strpos($nstitle, 'i2pmagnet') || strpos($nstitle, '409gbvideos') || strpos($nstitle, 'rapinglittlegirl') || strpos($title, ' 2.4TB - ') === 0 || strpos($title, 'FULL LINKS - ') === 0 || strpos($title, 'MAGNET LINKS FULL PACK - ') === 0)
		return true;
	if(preg_match('~^\d+(?:\.\d+)?+ ?[GT]B (?:collection )?(link|video)s? ~i', $srcName) || preg_match('~^full collection (link|video)~i', $srcName)) // e.g. "2 TB LINKS ..."
		return true;
	$cnt = substr_count($title, 'lolita')
		 + substr_count($title, 'loli video')
		 + substr_count($title, 'little girl')
		 + substr_count($title, ' sex ')
		 + substr_count($title, ' nude ')
		 + substr_count($title, ' nudes')
		 + substr_count($title, 'candydoll')
		 + substr_count($title, 'nudist')
		 + substr_count($title, 'pussy')
		 + substr_count($title, 'anal')
		 + substr_count($title, 'amateur')
		 + substr_count($title, 'incest')
		 + substr_count($title, 'slut')
		 + substr_count($title, 'hurtcore')
		 + substr_count($title, 'forbidden fruit')
		 + substr_count($title, 'family secret')
		 + substr_count($title, 'hurting kids')
		 + substr_count($title, 'spycam')
		 + substr_count($title, 'webcam')
		 + substr_count($title, 'blowjob')
		 + substr_count($title, 'necrophilia')
		 + substr_count($title, 'zoofilia')
		 + substr_count($title, 'orgasm')
		 + substr_count($title, 'omegle')
		 + substr_count($title, 'porno')
		 + substr_count($title, 'gang bang')
		 + substr_count($title, 'onlyfans')
		 + substr_count($title, 'preteen')
		 + substr_count($title, 'red room')
		 + substr_count($title, 'hidden cam')
		 + substr_count($title, 'cp links')
		 + substr_count($title, 'zoophile')
		 + substr_count($title, 'pedophile')
		 + substr_count($title, 'fetish')
		 + substr_count($title, 'playpen')
		 + substr_count($title, ' pthc ')
		 + substr_count($title, ' rape ')
		 + substr_count($title, ' jb ')
		 + substr_count($title, ' cp ')
		 + substr_count($title, ' xxx ')
		 + substr_count($title, ' illicit ')
		 + substr_count($title, ' illegal ')
		 + substr_count($title, ' onlyfans ')
		 + substr_count($title, ' cuties kids ')
		 + substr_count($title, ' child model')
		 + substr_count($title, ' toddlercon ')
		 + substr_count($title, ' voyeurism ')
		 + substr_count($title, ' pornhub ')
		 + substr_count($title, ' pornohub ')
		 + substr_count($title, ' pedo ')
		 + substr_count($title, ' porn ')
		 + substr_count($title, ' pornography ')
		 + substr_count($title, ' darkweb ')
		 + substr_count($title, ' darknet ')
		 + substr_count($title, ' snuff ')
		 + substr_count($title, ' webcam ')
		 + substr_count($title, ' lesbian ')
		 + substr_count($title, ' gay ')
		 + substr_count($title, ' sexual ')
		 + substr_count($title, ' kiddie ')
		 + substr_count($title, ' jav ')
		 + substr_count($title, ' piss ')
		 + substr_count($title, 'young model')
		 + substr_count($title, 'teeniesex')
		 + substr_count($title, ' sexdoll ')
		 + substr_count($title, ' tokyodoll ')
		 + substr_count($title, 'doodstream')
		 + substr_count($title, ' dood stream ')
		 + substr_count($title, ' pedomom ')
		 + substr_count($title, ' pedomum ')
		 + substr_count($title, ' pedodad ')
		 + substr_count($title, 'loliporn')
		 + substr_count($title, '.onion')
		 + preg_match_all('~(?<=\W|^)[01]?\d ?(yo|yrs?)(?=\W|$)~i', $title);
	// illicit posters seem to love all caps names, so penalize it more
	if($srcName == strtoupper($srcName)) ++$cnt;
	if($cnt > 3)
		return true;
	return false;
}

$toto_cats = array(
	1, // Anime
	//10, // Non-English
	//3, // Manga
	//8, // Drama
	// 2, // Music
	//9, // Music Video
	//7, // Raws
	//4, // Hentai
	//12, // Hentai Anime
	//13, // Hentai Manga
	//14, // Hentai Games
	//15, // JAV
	//11, // Batch  [2024-11-17: removed due to largely being pointless - most entries are at Nyaa or should be skipped anyway]
	//5, // Other
);

$nyaa_cats = array(
	'1_37', // Anime (English)
	//'1_11', // Anime (Raw)
	//'1_38', // Anime (Non-English)
	//'1_32', // Anime (AMV)
	//'2_12', // Books (English)
	//'2_13', // Books (Raw)
	//'2_39', // Books (Non-English)
	//'3_14', // Audio (Lossless)
	//'3_15', // Audio (Lossy)
	//'4_17', // Pictures (Photos)
	//'4_18', // Pictures (Graphics)
	//'5_19', // Live Action (English)
	//'5_20', // Live Action (Raw)
	//'5_21', // Live Action (Non-English)
	//'5_22', // Live Action (Promo)
	//'6_23', // Software (Apps)
	//'6_24', // Software (Games)
	
	// sukebei
	//'7_25', // Art (Anime)
	//'7_26', // Art (Manga)
	//'7_27', // Art (Games)
	//'7_28', // Art (Pictures)
	//'7_33', // Art (Doujinshi)
	//'8_30', // Real Life (Videos)
	//'8_31', // Real Life (Photobooks & Pictures)
);
// !mirror the above for this!
$nyaasi_cats = array(
	'1_2', // Anime (English)
	//'1_4', // Anime (Raw)
	//'1_3', // Anime (Non-English)
	//'1_1', // Anime (AMV)
	//'3_1', // Books (English)
	//'3_3', // Books (Raw)
	//'3_2', // Books (Non-English)
	//'2_1', // Audio (Lossless)
	//'2_2', // Audio (Lossy)
	//'5_2', // Pictures (Photos)
	//'5_1', // Pictures (Graphics)
	//'4_1', // Live Action (English)
	//'4_4', // Live Action (Raw)
	//'4_3', // Live Action (Non-English)
	//'4_2', // Live Action (Promo)
	//'6_1', // Software (Apps)
	//'6_2', // Software (Games)
); $nyaasis_cats = array(
	// sukebei
	//'1_1', // Art (Anime)
	//'1_4', // Art (Manga)
	//'1_3', // Art (Games)
	//'1_5', // Art (Pictures)
	//'1_2', // Art (Doujinshi)
	//'2_2', // Real Life (Videos)
	//'2_1', // Real Life (Photobooks & Pictures)
);


// to be able to fetch stuff from the Batch category, we'll try to skip some stuff
function toto_batch_skip($info) {
	// skip Nyaa because it'll be handled by the Nyaa fetcher
	if($info['nyaa_id']) return 'Nyaa entry';
	
	$purl = @parse_url($info['link']);
	if(empty($purl)) return false; // bad link, can't test it, so let it pass through
	$host = strtolower($purl['host']);
	
	// skip known foreign/raw sources
	if(preg_match('~(^|\.)anisource\.net$~', $host)
	|| $host == 'ani-tsuzuki.net'
	|| $host == 'ani-tsuzuki.net:6969'
	|| $host == 'www.ani-tsuzuki.net'
	|| $host == 'www.ani-tsuzuki.net:6969'
	|| $host == 'tracker.tvnihon.com' // usually not anime (i.e. live action content)
	|| $host == 'trackerums.altervista.org'
	|| $host == 'anime.mine.nu'
	)
		return 'Foreign source';
	
	// allow known ok sources
	if($host == 'bakabt.me' || $host == 'bakabt.com'
	|| $host == 'tracker.cartoon-world.org'
	|| $host == 'tracker.minglong.org' // unchecked, but should generally be fine
	)
		return false;
	
	// skip if title contains 'raw'
	if(preg_match('~(^|\W)raw($|\W)~i', $info['name'])) return 'RAW in title';
	
	
	// if further checking is required, do it
	if($host == 'tracker.anime-index.org') {
		// check this later cause we need the BTIH
		return false;
	}
	elseif(($host == 'anirena.com' || $host == 'www.anirena.com') && preg_match('~^/dl/(\d+)~', $purl['path'], $m)) {
		require_once ROOT_DIR.'releasesrc/anirena.php';
		$det = anirena_get_item_info((int)$m[1], $info['name']);
		if(empty($det)) return false;
		return (@$det['cat'] == 2) ? false : 'Skipped Anirena category'; // 2 = Anime category
	}
	
	return false; // unknown, default = allow
}
function toto_batch_skip2($info, $tinfo) {
	$purl = @parse_url($info['link']);
	if(empty($purl)) return false; // bad link, can't test it, so let it pass through
	$host = strtolower($purl['host']);
	
	if(substr($host, 0, 8) == 'sukebei.')
		return true;
	
	if((isset($info['anidex_cat']) && $info['anidex_cat'] != 1) || @$info['anidex_labels'] & 6) // skip entries marked as 'raw' or 'hentai', but ignore 'batch' and 'reencode'
		return true;
	
	return false; // default=allow
}

// find files already processed - this is mostly for Guodong who does rolling releases
function releasesrc_unwanted_files($tinfo, $rowinfo, $extra_srcInfo) {
	$info =& $tinfo['info'];
	if(empty($info['files'])) return false;
	
	// for now, only apply to GuodongSubs
	if(stripos($rowinfo['name'], '[GuodongSubs]') === false) return false;
	
	return releasesrc_query_unwanted_files($info['files']);
}

// try to find existing files by filesize, CRC and filename
function releasesrc_query_unwanted_files($files) {
	if(empty($files) || !is_array($files)) return false;
	
	global $db;
	$checkQ = [];
	$idx = -1;
	$filesKeyed = [];
	foreach($files as $f) {
		++$idx;
		if(!isset($f['path']) || !is_array($f['path']) || !isset($f['length']) || !is_numeric($f['length'])) continue;
		
		$fn = end($f['path']);
		if(!preg_match('~\[([a-fA-F0-9]{8})\]\.[a-zA-Z0-9]{2,5}$~', $fn, $m)) continue;
		$size = (float)$f['length'];
		// TODO: consider better filename check
		$checkQ[] = "(crc32=X'{$m[1]}' AND filesize=".$size.' AND filename LIKE CONCAT("%",'.strtr($db->escape($fn), ['%'=>'\\%', '_'=>'\\_']).'))';
		
		// build lookup array
		$key = implode(':', [strtolower($m[1]), $size, strtolower($fn)]);
		if(isset($filesKeyed[$key]))
			$filesKeyed[$key][] = $idx;
		else
			$filesKeyed[$key] = [$idx];
	}
	
	if(empty($checkQ)) return false;
	
	// fetch all results
	$found = $db->selectGetAll('files', 'id', implode(' OR ', $checkQ), 'id,crc32,filesize,filename');
	if(empty($found)) return false;
	
	// if have them, loop through files array and mark unwanted
	$unwanted = [];
	foreach($found as $file) {
		$key = implode(':', [bin2hex($file['crc32']), $file['filesize'], strtolower(basename($file['filename']))]);
		if(!isset($filesKeyed[$key])) continue; // should never happen
		$unwanted = array_merge($unwanted, $filesKeyed[$key]);
	}
	
	if(empty($unwanted)) return false; // this should never happen
	$unwanted = array_values(array_unique($unwanted));
	
	if(count($unwanted) == count($files)) return true; // all files skipped
	return $unwanted;
}

