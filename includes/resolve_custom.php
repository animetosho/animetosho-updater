<?php

function resolve_prefilter_name(&$pfn) {
	// BlackRabbit's sequel naming ("Anime (s1_year) - S02") confuses the matcher, so strip the year
	if(@$pfn['group'] == 'BlackRabbit' && !empty($pfn['title']))
		$pfn['title'] = preg_replace('~ \((?:19|20)\d\d\)( [2-9]|\d{2,})$~', '$1', $pfn['title']);
}

function resolve_postfilter_ani($aid, &$f, $name) {
	
	// Demon Slayer S3 <> S4
	if($aid == 16054 /*Kimetsu no Yaiba: Yuukaku Hen*/ && preg_match('~\W(Swordsmith|Katanakaji)\W~i', $name))
		return 17198;
	// S4 <> S5
	if($aid == 17198 /*Kimetsu no Yaiba: Katanakaji no Sato Hen*/ && preg_match('~\W(Hashira)\W~i', $name))
		return 18067;
	
	// Bungou Stray Dogs episode numbering: many label the OVA as ep 25, so episodes after are off by 1
	if($aid == 11523 && isset($f['ep']) && $f['ep'] >= 25) {
		if($f['ep'] == 25) {
			$f['ep'] = 1;
			return 12442;
		}
		--$f['ep'];
	}
	
	// KQRM's release of Mushoku Tensei 2 have episodes off by 1
	// this stopped on episode 2
	/*if($aid == 17236 && isset($f['ep']) && $f['ep'] > 0 && preg_match('~^Mushoku\.Tensei\.Jobless\.Reincarnation\.S02E\d+.+\.WEB-DL\.DDP2\.0\.H\.264-KQRM$~', $name)) {
		--$f['ep'];
	}*/
	
	if($aid == 17822 && $name == '[DarkWispers-Orphan-LonelyChaser] Heart Cocktail') {
		return 1786;
	}
	
	// Tensei Shitara Slime Datta Ken, episode numbering ignores OVA
	if($aid == 13871 && isset($f['ep']) && $f['ep'] >= 48.5) {
		$f['ep'] -= 48;
		return 17709;
	}
	
	// Ranma 1/2 2024 confusion
	// e.g. "Ranma ½ - 04 | Ranma ½ (2024) [English Dub][1080p]" - the parser mostly ignores everything after the pipe
	if($aid == 4576 && strpos($name, '(2024)')) {
		return 18700;
	}
	
	// Breeze/Sokudo use confusing S17Exx naming for Bleach Soukoku-tan
	if($aid == 18220 && isset($f['ep']) && $f['ep'] >= 27 && preg_match('~\WS17E'.$f['ep'].'\W~', $name)) {
		$f['ep'] -= 26;
	}
	
	// NEET Kunoichi: airs two episodes back-to-back, so Ep1 = Ep1+2, Ep2 = Ep3+4 etc, but SubsPlus+ uses correct naming
	if($aid == 18491 && isset($f['ep']) && !isset($f['eprange']) && !preg_match('~E\d+-E\d+ ~i', $name)) {
		$f['ep'] = $f['ep'] * 2 - 1;
	}
	
	// KonoSuba 3 OVAs: some groups name them as episode 12/13
	if($aid == 17431 && isset($f['ep']) && in_array((int)$f['ep'], [12,13])) {
		$f['ep'] -= 11;
		return 18865;
	}
	
	return $aid;
}

// $ret can get/set aid/eid at this point
function resolve_postfilter_ep(&$ret, $f, $name) {
	
}
