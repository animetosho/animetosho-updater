<?php


function find_source_url_urlcheck($url, &$purl) {
	// forum URL identification
	if(preg_match('~\W(showthread\.php(?:\?t(?:id)?=|/)|viewtopic\.php\?(?:.*\W)?(?:id|t)=|topic[=/]|thread[\-=/]|threads/[^.]*\.)\d+(\W|$)~i', $url)) {
		return array($url, 'forumthread');
	}
	
	$purl = @parse_url($url);
	if(empty($purl) || !isset($purl['host'])) return false;
	if(!isset($purl['path'])) $purl['path'] = '/';
	
	switch(strtolower($purl['host'])) {
		case 'nyaatorrents.org': case 'nyaa.eu': case 'nyaa.se': case 'www.nyaatorrents.org': case 'www.nyaa.eu': case 'www.nyaa.se': case 'nyaa.si': case 'nyaa.pantsu.cat':
			// TODO: double check that this is a torrent page, and perhaps grab description?  maybe grab description from torrent listing if possible + this function fails
			return array($url, 'torrentlisting');
	}
	
	// get 2nd level TLD
	if(!($tld = get_2nd_tld_from_host($purl['host'])))
		return false;
	// the following items are part of a DB enum field - if adding new ones, update DB too!
	switch($tld) {
		case 'megaupload.com': case 'fileserve.com': case 'filesonic.com': case 'wupload.com': case 'multiurl.com':
			return array(false, 'fileshare');
		//case 'ur.ly': case '3.ly': case 'tinyurl.com': case 'is.gd': case 'bit.ly': case 'adf.ly':
		//	return array(false, 'urlshort');
		case 'hotimg.com': case 'imageshack.us': case 'tinypic.com': case 'imgur.com':
			return array(false, 'imghost');
		case 'anidb.net':
		case 'discord.gg':
		case 'twitter.com':
			return array(false, 'unrelated');
		// the following are sites, but with no articles
		case 'golumpa.moe':
		case 'erai-raws.info':
		case 'horriblesubs.info':
			return array(false, 'unrelated');
		case 'anidex.info':
		case 'anidex.moe':
		case 'edwardk.info':
		case 'nekobt.to':
			return array($url, 'torrentlisting');
		case 'niceboard.net':
		case 'freeforums.org':
		case 'forumotion.com':
		case 'proboards.com':
		case 'invisionfree.com':
			return array(false, 'forum');
	}
	return false;
}

function find_source_init($website, &$out) {
	log_event('find_source_init('.$website.')');
	$data = ''; $headers = array();
	$out = array('wstype' => '');
	$newurl = dereference_link($website, $data, $headers);
	if(!$newurl || !$data) return false;
	
	log_event('find_source_init: after link dereference');
	if($newurl != $website) {
		$out['website'] = $website = $newurl;
		$ret = find_source_url_urlcheck($website, $out['purl']);
		if($ret) return $ret;
	}
	
	// filter out forums (ie if they linked to the forum home, or similar)
	// TODO: more reliable detection perhaps?
	if(preg_match('~Powered by \<a [^>]+>(phpBB|MyBB|vBulletin&reg;|FluxBB|PunBB)\</a\>~i', $data) 
	|| preg_match('~\>(Powered by SMF[ .0-9a-z]+|Community Forum Software by IP\.Board[ .0-9a-z]+)\</a\>~i', $data)
	|| stripos($data, '<html id="XenForo"')
	|| stripos($data, '<a href="http://www.simplemachines.org" title="Simple Machines"')
	) {
		return array(false, 'forum');
	}
	
	$out['feedurl'] = get_feed_url($data, $website);
	// determine pingback URL
	if(!empty($headers)) foreach($headers as &$header) {
		if(strtolower(substr($header, 0, 11)) == 'x-pingback:') {
			$out['pingback'] = trim(substr($header, 11));
			break;
		}
	}
	if(!isset($out['pingback'])) {
		if(preg_match('~\<link rel\="pingback" href\="([^">]+?)"\s*/\>~i', $data, $m))
			$out['pingback'] = unhtmlspecialchars($m[1]);
	}
	if(!isset($out['pingback']) || !preg_match('~^https?\://~i', $out['pingback']))
		$out['pingback'] = null; // prevent PHP warning
	
	// check for Wordpress
	// TODO: this won't detect blogspot.com [which also doesn't use shortlink]
	// TODO: wordpress.com generator is wordpress.com, and has a shortlink even on homepage
	// TODO: blog.com is totally different
	if(preg_match('~\<meta name\="generator" content\="(?:WordPress[ 0-9.]+|wordpress\.com)" ?/\>~i', $data) || (
		// if all these found, also consider that this is Wordpress
		preg_match('~\<link rel=["\']pingback~i', $data) && preg_match('~\<link rel=["\']EditURI~i', $data) && preg_match('~\<link rel=["\']wlwmanifest~i', $data)
	)) {
		$out['wstype'] = 'wordpress';
		// likely a blog
		// check URL - does it point to a page?
		// or maybe, we'll just use a dirty trick
		if(preg_match('~\<link rel\=\'index\' [^>]*href=([\'"])([^"]+?)\\1~i', $data, $match))
			$out['indexurl'] = resolve_relative_url(unhtmlspecialchars($match[2]), $website);
		elseif($out['feedurl']) {
			// try to make a guess on the homepage from the feed URL
			$feedurl = $out['feedurl'];
			wordpress_filter_feed_urls($feedurl, ''); // assumes domain level feed URL...
			if($feedurl) {
				$pfu = parse_url($feedurl);
				if(strtolower(substr(@$pfu['query'], 0, 5)) == 'feed=')
					$out['indexurl'] = substr($feedurl, 0, stripos($feedurl, '?feed=')); // abuse the fact that you can't have ='s in other parts of the URL
				else
					$out['indexurl'] = dirname($feedurl);
			}
		}
	}
	
	return null;
}


function find_source_url($website, $timebase, $tlink, &$files) {
	// determine website - need to try either submitted website or website on AniDB's group page
	if(!$website) return false;
	// *** try to identify some common patterns and exit out early ***
	$ret = find_source_url_urlcheck($website, $purl);
	if($ret) return $ret;
	
	$time = time();
	
	log_event('Init phase (website='.$website.')');
	// ** init phase: grab URL and look for feeds or type
	// firstly, a cache lookup
	global $db;
	if(!isset($website[80])) { // don't cache really long URLs
		// cache expiry after 1 day for now
		$init_out = $db->selectGetField('src_cache_init', 'result', 'url='.$db->escape($website).' AND dateline>'.($time-86400));
		if(!empty($init_out))
			$init_out = unserialize($init_out);
		else
			$init_out = null;
		
		if(!mt_rand(0,19)) // prune cache
			$db->delete('src_cache_init', 'dateline<='.($time-86400));
	}
	if(!isset($init_out)) { // cache miss
		$_ret = find_source_init($website, $init_out);
		$init_out['_ret'] =& $_ret;
		// write to cache
		if(!isset($website[80]))
			$db->insert('src_cache_init', array('url' => $website, 'result' => serialize($init_out), 'dateline' => $time), true);
	}
	extract($init_out);
	if(isset($_ret)) return $_ret;
	
	log_event('Init phase done (data: '.jencode($init_out).')');
	unset($init_out);
	
	// pre-process files array
	$cfiles = array();
	foreach($files as &$file) {
		// ignore small files (eg .txt)
		if((float)$file['filesize'] > 1024*1024*8) {
			$bfn = mb_basename($file['filename']);
			isset($cfiles[$bfn]) or $cfiles[$bfn] = array();
			$cfiles[$bfn][] = $file['filesize'];
		}
	}
	
	if($wstype == 'wordpress') {
		if(isset($indexurl))
			$indexpurl = @parse_url($indexurl);
		
		
		wordpress_filter_feed_urls($feedurl, (
			isset($indexpurl) && isset($indexpurl['path']) ?
			$indexpurl['path'] :
			$purl['path']));
		
		if(isset($indexurl)) {
			if(!empty($indexpurl) && isset($indexpurl['host'])) {
				// we also need to trim off trailing slashes in this comparison, because "/wordpress/" is the same as "/wordpress"
				$cmp1 = $purl['path'];
				if(substr($cmp1, -1) == '/') $cmp1 = substr($cmp1, 0, -1);
				if(isset($purl['query'])) $cmp1 .= '?'.$purl['query'];
				if(!isset($indexpurl['path'])) $indexpurl['path'] = '/';
				$cmp2 = $indexpurl['path'];
				if(substr($cmp2, -1) == '/') $cmp2 = substr($cmp2, 0, -1);
				if(isset($indexpurl['query'])) $cmp2 .= '?'.$indexpurl['query'];
				if($cmp1 != $cmp2 && strlen($cmp1) > strlen($cmp2)) { // heuristic: the article page's URL is typically longer than the homepage's
					// assume this is an article page
					$is_article_page = true;
				}
			}
		}
		
		if(!$feedurl) {
			// some Wordpress installs don't have the <link rel="alternate" > thing, so take a wild guess
			if(isset($indexurl))
				$feedurl = resolve_relative_url('index.php?feed=rss2', $indexurl);
			else
				// don't know homepage - this is possible if we were sent direclty to an article page - we'll assume blog is installed in root
				$feedurl = resolve_relative_url('/index.php?feed=rss2', $website);
		}
		if($feedurl)
			$feedurl = wordpress_append_feedurl_paged($feedurl);
		
		log_event('This is a Wordpress site');
		
		if(isset($is_article_page)) {
			// try to grab summary from feed
			if($feedurl) {
				$time_cut = false;
				$page = 1;
				while(!$time_cut && $page <=3) { // limit to 3 pages for now
					$feed_items = get_feed_items($feedurl.$page, $timebase, $time_cut);
					if(empty($feed_items)) break;
					foreach(feed_filter_wordpress($feed_items) as $item) {
						if($item['link'] == $website) // TODO: check if this is accurate
							return array($website, 'blogentry', $item['title'], $item['content'], $pingback);
					}
					if(count($feed_items) < 5) break; // unlikely there's less than 5 items per page; this reduces requests to Subsplease feed, which only has 2 items
					++$page;
				}
			}
			return array($website, 'blogentry');
		}
		
		// Wordpress nonSEO URL formats: page: /?p=\d+  feed: /?feed=rss
		
		
		// so this is possibly the home page - grab the RSS feed
		if(!$feedurl) {
			return array(false, 'blog');
		}
		
		// parse feed - we'll only look on first page (assume it's there, since this is a somewhat natural filter for older posts)
		$time_cut = false;
		$page = 1;
		$cnt = 0;
		while(!$time_cut && $page <=3) { // limit to 3 pages for now (we need a limit anyway - some wordpress blogs might send us to a different feed thing, like feedburner)
			foreach(feed_filter_wordpress(get_feed_items($feedurl.$page, $timebase)) as $item) {
				if(++$cnt >30) break;
				if(check_content_is_match($item['content'], $website, $tlink, $cfiles))
					return array($item['link'], 'blogentry', $item['title'], $item['content'], $pingback);
			}
			if($cnt > 30) break;
			++$page;
		}
	}
	elseif($feedurl) {
		if(is_array($feedurl))
			// just try first one
			$feedurl = reset($feedurl);
		
		// generic feed parser
		$cnt = 0;
		foreach(get_feed_items($feedurl, $timebase) as $item) {
			// next, grab the announcement and look for matches
			if(!isset($item['content'])) {
				if(!@$item['description']) continue;
				$item['content'] =& $item['description'];
			}
			// now scan the main post for links
			// [ isset($item['content']) check is unnecessary here ]
			if(++$cnt >30) break;
			if(isset($item['content']) && check_content_is_match($item['content'], $website, $tlink, $cfiles))
				return array($item['link'], 'feedentry', $item['title'], $item['content'], $pingback);
			
			// doesn't look like the right page, continue searching...
		}
	}
	log_event('Can\'t find source');
	// don't recognise this page otherwise
	return false;
}

function get_2nd_tld_from_host($host) {
	if(!preg_match('~(?:^|\.)([a-z0-9\-]+\.[a-z0-9]{2,8})$~i', $host, $m))
		return false;
	return strtolower($m[1]);
}

// returns filename+size estimate for a DDL link; will always be an array to accomodate folder URLs
function get_ddl_filename($url) {
	return false; // TODO: implement this function
	
	$purl = @parse_url($url);
	if(empty($purl) || !$purl['host']) return false;
	$tld = get_2nd_tld_from_host($purl['host']);
	$path = (string)@$purl['path'];
	if(isset($purl['query']) && $purl['query'] !== '')
		$path .= '?'.$purl['query'];
	
	// lookup cache
	global $db;
	$time = time();
	$files = $db->selectGetField('src_cache_ddllookup', 'files', 'host='.$db->escape($tld).' AND path='.$db->escape($path).' AND dateline>'.($time-86400*5));
	if(isset($files)) {
		if($files)
			return unserialize($files);
		else
			return false;
	}
	
	if(!mt_rand(0,9)) // prune cache
		$db->delete('src_cache_ddllookup', 'dateline<='.($time-86400*5));
	
	
	$ret = array();
	switch($tld) {
		case 'megaupload.com':
			if(preg_match('~^\?d\=([A-Z0-9\-]{8})~', $path)) {
				
				preg_match('~\<a href\="http\://www\d+\.megaupload\.com/files/[a-f0-9]{32}/(.+?)" class\="down_butt1"\>\</a\>~i', $data);
				preg_match('~\<strong\>File size\:\</strong\> ([0-9.]+) ([KMGT]B|Bytes?)\<br /\>~i', $data);
				
			}
			break;
		case 'hotfile.com':
		case 'rapidshare.com':
		case 'sendspace.com':
		case 'mediafire.com':
		case 'depositfiles.com':
		case 'fileserve.com':
		case 'filesonic.com':
		case 'wupload.com':
		case 'zshare.com':
		case 'uploading.com':
		case 'easy-share.com':
		case 'sharingmatrix.com':
		case 'filefactory.com':
		
		// TODO: maikuando.tv
		default:
			$ret = false;
	}
	$db->insert('src_cache_ddllookup', array('host' => $tld, 'path' => $path, 'files' => ($ret ? serialize($ret) : ''), 'dateline' => $time), true);
	return $ret;
}

function get_torrent_filelist(&$tinfo) {
	if(!isset($tinfo['info'])) return false;
	$info =& $tinfo['info'];
	if(empty($info['files'])) {
		if(!isset($info['name']) || !isset($info['length'])) return false;
		return array(str_replace("\0", '', fix_utf8_encoding($info['name'])) => $info['length']);
	}
	
	if(!is_array($info['files'])) return false;
	
	$ret = array();
	foreach($info['files'] as &$f) {
		if(!isset($f['path']) || !is_array($f['path']) || !isset($f['length']) || !is_numeric($f['length'])) return false;
		$ret[str_replace("\0", '', fix_utf8_encoding(implode('/', $f['path'])))] = $f['length'];
	}
	return $ret;
}


// try to determine the content HTML from the summary of a Wordpress blog
// TODO: this method is somewhat unreliable for really short summaries [will now error out if second instance is found]
function match_article_content_from_summary(&$summary, &$data) {
	// firstly fix bad stuff and remove scripts in the process, cause they mess up everything, lol
	$s = fix_html(preg_replace('~\<script(\s[^>]+)?\>.*?\</script\>~is', '', $data));
	// we only need stuff in the body tag
	if(!preg_match('~\<body(?:\s[^>]+?)?\>(.+)\</body\>~si', $s, $m))
		return false;
	
	// we won't care about excess whitespace (hope we don't need any <pre> tags)
	// (note, the PCRE \s pattern stuffs up UTF-8 sequences for some odd reason)
	$whitespace = "[ \t\r\n]";
	// also strip out scripts completely due to some bad usages or tidy messing up on them
	$s = preg_replace('~'.$whitespace.'+~', ' ', '<body>'.$m[1].'</body>'); // need to put an encapsulating element for later
	unset($m);
	
	// work around entity issues of HTML -> XML
	// we want to keep &#...; entities as they are, which is why we don't use tidy's conversion abilities
	$entity_tbl = array_flip(get_html_translation_table_CP1252(HTML_ENTITIES));
	foreach($entity_tbl as &$e) {
		$e = '&#'.ord($e).';';
	}
	// add more that PHP is missing
	// note that array_merge will overwrite dupe keys using the second array
	$entity_tbl = array_merge(
		array('&yuml;'=>'&#255;', '&OElig;'=>'&#338;', '&oelig;'=>'&#339;', '&Scaron;'=>'&#352;', '&scaron;'=>'&#353;', '&Yuml;'=>'&#376;', '&fnof;'=>'&#402;', '&circ;'=>'&#710;', '&tilde;'=>'&#732;', '&Alpha;'=>'&#913;', '&Beta;'=>'&#914;', '&Gamma;'=>'&#915;', '&Delta;'=>'&#916;', '&Epsilon;'=>'&#917;', '&Zeta;'=>'&#918;', '&Eta;'=>'&#919;', '&Theta;'=>'&#920;', '&Iota;'=>'&#921;', '&Kappa;'=>'&#922;', '&Lambda;'=>'&#923;', '&Mu;'=>'&#924;', '&Nu;'=>'&#925;', '&Xi;'=>'&#926;', '&Omicron;'=>'&#927;', '&Pi;'=>'&#928;', '&Rho;'=>'&#929;', '&Sigma;'=>'&#931;', '&Tau;'=>'&#932;', '&Upsilon;'=>'&#933;', '&Phi;'=>'&#934;', '&Chi;'=>'&#935;', '&Psi;'=>'&#936;', '&Omega;'=>'&#937;', '&alpha;'=>'&#945;', '&beta;'=>'&#946;', '&gamma;'=>'&#947;', '&delta;'=>'&#948;', '&epsilon;'=>'&#949;', '&zeta;'=>'&#950;', '&eta;'=>'&#951;', '&theta;'=>'&#952;', '&iota;'=>'&#953;', '&kappa;'=>'&#954;', '&lambda;'=>'&#955;', '&mu;'=>'&#956;', '&nu;'=>'&#957;', '&xi;'=>'&#958;', '&omicron;'=>'&#959;', '&pi;'=>'&#960;', '&rho;'=>'&#961;', '&sigmaf;'=>'&#962;', '&sigma;'=>'&#963;', '&tau;'=>'&#964;', '&upsilon;'=>'&#965;', '&phi;'=>'&#966;', '&chi;'=>'&#967;', '&psi;'=>'&#968;', '&omega;'=>'&#969;', '&thetasym;'=>'&#977;', '&upsih;'=>'&#978;', '&piv;'=>'&#982;', '&ensp;'=>'&#8194;', '&emsp;'=>'&#8195;', '&thinsp;'=>'&#8201;', '&zwnj;'=>'&#8204;', '&zwj;'=>'&#8205;', '&lrm;'=>'&#8206;', '&rlm;'=>'&#8207;', '&ndash;'=>'&#8211;', '&mdash;'=>'&#8212;', '&lsquo;'=>'&#8216;', '&rsquo;'=>'&#8217;', '&sbquo;'=>'&#8218;', '&ldquo;'=>'&#8220;', '&rdquo;'=>'&#8221;', '&bdquo;'=>'&#8222;', '&dagger;'=>'&#8224;', '&Dagger;'=>'&#8225;', '&bull;'=>'&#8226;', '&hellip;'=>'&#8230;', '&permil;'=>'&#8240;', '&prime;'=>'&#8242;', '&Prime;'=>'&#8243;', '&lsaquo;'=>'&#8249;', '&rsaquo;'=>'&#8250;', '&oline;'=>'&#8254;', '&frasl;'=>'&#8260;', '&euro;'=>'&#8364;', '&image;'=>'&#8465;', '&weierp;'=>'&#8472;', '&real;'=>'&#8476;', '&trade;'=>'&#8482;', '&alefsym;'=>'&#8501;', '&larr;'=>'&#8592;', '&uarr;'=>'&#8593;', '&rarr;'=>'&#8594;', '&darr;'=>'&#8595;', '&harr;'=>'&#8596;', '&crarr;'=>'&#8629;', '&lArr;'=>'&#8656;', '&uArr;'=>'&#8657;', '&rArr;'=>'&#8658;', '&dArr;'=>'&#8659;', '&hArr;'=>'&#8660;', '&forall;'=>'&#8704;', '&part;'=>'&#8706;', '&exist;'=>'&#8707;', '&empty;'=>'&#8709;', '&nabla;'=>'&#8711;', '&isin;'=>'&#8712;', '&notin;'=>'&#8713;', '&ni;'=>'&#8715;', '&prod;'=>'&#8719;', '&sum;'=>'&#8721;', '&minus;'=>'&#8722;', '&lowast;'=>'&#8727;', '&radic;'=>'&#8730;', '&prop;'=>'&#8733;', '&infin;'=>'&#8734;', '&ang;'=>'&#8736;', '&and;'=>'&#8743;', '&or;'=>'&#8744;', '&cap;'=>'&#8745;', '&cup;'=>'&#8746;', '&int;'=>'&#8747;', '&there4;'=>'&#8756;', '&sim;'=>'&#8764;', '&cong;'=>'&#8773;', '&asymp;'=>'&#8776;', '&ne;'=>'&#8800;', '&equiv;'=>'&#8801;', '&le;'=>'&#8804;', '&ge;'=>'&#8805;', '&sub;'=>'&#8834;', '&sup;'=>'&#8835;', '&nsub;'=>'&#8836;', '&sube;'=>'&#8838;', '&supe;'=>'&#8839;', '&oplus;'=>'&#8853;', '&otimes;'=>'&#8855;', '&perp;'=>'&#8869;', '&sdot;'=>'&#8901;', '&lceil;'=>'&#8968;', '&rceil;'=>'&#8969;', '&lfloor;'=>'&#8970;', '&rfloor;'=>'&#8971;', '&lang;'=>'&#9001;', '&rang;'=>'&#9002;', '&loz;'=>'&#9674;', '&spades;'=>'&#9824;', '&clubs;'=>'&#9827;', '&hearts;'=>'&#9829;', '&diams;'=>'&#9830;')
	, $entity_tbl);
	$s = strtr($s, $entity_tbl);
	
	// remove bad entities
	$s = preg_replace('~&amp;(#\d+|#x[a-fA-F0-9]+|[a-zA-Z0-9]+);~', '&$1;', 
		str_replace('&','&amp;',$s)
	);
	
	// TODO: what about stripping excess whitespace after stripping tags?
	
	// match all tags and remove them
	$regex = '~'.$whitespace.'*(\<([a-zA-Z0-9\-_:]+(\s+[^>]*?)?|/[a-zA-Z0-9\-_:]+|\!--.*?--)\>'.$whitespace.'*)+~s';
	preg_match_all($regex, $s, $tags, PREG_OFFSET_CAPTURE);
	$cs = preg_replace_callback($regex, function($m) {
		return strlen(strip_tags($m[0])) ? ' ':'';
	}, $s);
	$tags = $tags[0];
	
	$has_full_article = !(substr($summary, -5) == '[...]');
	if($has_full_article) {
		$smy = $summary;
	} else {
		$smy = trim(substr($summary, 0, -5));
	}
	$smy = strtr($smy, $entity_tbl); // fix entities
	
	$p = strpos($cs, $smy);
	if(!$p) return false; // it shouldn't be at the 0'th position either
	// just assume first instance is the right one
	if(strpos($cs, $smy, $p+strlen($smy)) !== false) return false;
	
	// prepare this for later
	$entity_tbl = array_flip($entity_tbl);
	
	// so we now have a position where the article starts
	// determine position of *actual* start by adding up removed tags
	$shift = 0;
	for($i=0, $c=count($tags); $i<$c; ++$i) {
		$tag =& $tags[$i];
		if($tag[1] - $shift > $p) break;
		$shift += strlen($tag[0]);
	}
	$ap = $p + $shift;
	
	// find end position
	$ep = $ap + strlen($smy);
	$shift = 0;
	for(; $i<$c; ++$i) {
		$tag =& $tags[$i];
		if($tag[1] - $shift > $ep) break;
		$shift += strlen($tag[0]);
	}
	$aep = $ep + $shift;
	if($container = xml_find_encompasing_pos($s, $ap, $aep)) { // should we trust this so readily if we don't have the complete article?
		$start = $container[0];
		$len = $container[1]-$container[0];
	}
	// handle fallback cases
	elseif($has_full_article) {
		$start = $ap;
		$len = $aep - $ap;
	} else {
		// search for a <div> tag preceeding what we have
		$before_ap = substr($s, 0, $ap);
		if(preg_match('~(.*)\<[a-z0-9\-:_]+(\s[^>]+?)?\>~i', $before_ap, $m)) {
			// just grab container tag and hope for the best
			$divpos = strlen($m[1]);
		} else {
			// no tag found at all???
			$divpos = 0;
		}
		$xmlp = xml_parser_create('UTF-8');
		xml_set_element_handler($xmlp, false, 'match_article_content_from_summary_xml_reclast');
		xml_parse($xmlp, '<?xml version="1.0" encoding="UTF-8"?>'.substr($s, $divpos), true);
		xml_parser_free($xmlp);
		$divepos = $GLOBALS['__xml_last_offs'] -38 /* XML signature thing */;
		unset($GLOBALS['__xml_last_offs']);
		
		// TODO: check $divepos with end position from summary
		
		$start = $divpos;
		$len = $divepos;
	}
	return trim(strtr(substr($s, $start, $len), $entity_tbl));
}

function match_article_content_from_summary_xml_reclast(&$h, $n) {
	$GLOBALS['__xml_last_offs'] = xml_get_current_byte_index($h);
}

// class to get a tree of the XML, containing the position of start/end tags
// note that positions are always AFTER the tags; for self closing tags, the posend value will be ==pos+1
class XmlGetTagPos {
	private $ret;
	private $retref;
	private $stack;
	private $xmloffs = 0;
	public $error;
	
	public function parse($data, $charset = 'UTF-8') {
		$this->ret = array();
		$this->stack = array();
		
		$this->retref =& $this->ret;
		$this->error = false;
		
		$xmlp = xml_parser_create($charset);
		xml_set_element_handler($xmlp, array($this, 'tag_start'), array($this, 'tag_end'));
		$hdr = '<?xml version="1.0" encoding="'.$charset.'"?>';
		$this->xmloffs = strlen($hdr);
		xml_parse($xmlp, $hdr.$data, true);
		$this->error = xml_get_error_code($xmlp);
		xml_parser_free($xmlp);
		
		$ret = $this->ret;
		unset($this->retref, $this->stack, $this->ret);
		return $ret;
	}
	
	private function tag_start(&$h, $name, &$attribs) {
		$this->retref[] = array(
			'name' => $name,
			'attributes' => $attribs,
			'pos' => xml_get_current_byte_index($h) - $this->xmloffs +1,
			'children' => array(),
		);
		// grab reference to children element of just added array
		end($this->retref);
		$added =& $this->retref[key($this->retref)];
		$this->stack[] = array(
			'name' => $name,
			'parent' => &$this->retref,
			'self' => &$added
		);
		$this->retref =& $added['children'];
	}
	private function tag_end(&$h, $name) {
		$tag = array_pop($this->stack);
		$tag['self']['posend'] = xml_get_current_byte_index($h) - $this->xmloffs;
		$this->retref =& $tag['parent'];
	}
}

// find position of encompasing XML element for a given byte range
function xml_find_encompasing_pos($xml, $s, $e) {
	$x = new XmlGetTagPos;
	$pos = $x->parse($xml);
	// TODO: what to do on errors?
	if($x->error) {
		warning('[XML-Find-Pos] XML parsing error '.xml_error_string($x->error));
	}
	unset($x);
	
	if(!empty($pos))
		foreach($pos as &$p) {
			if(isset($p['posend'])) { // only look for good elements
				$elem = _xml_find_encompasing_pos($p, $s, $e);
				if($elem) break;
			}
		}
	if(!isset($elem)) return false;
	
	if($elem['name'] != 'DIV') {
		// search up to 2 levels above
		for($i=0; $i<2; ++$i) {
			$parent =& $elem['parents'][$i];
			if($parent['name'] == 'DIV') {
				// TODO: also check positioning so we don't get some huge DIV
				$elem = $parent;
			}
		}
	}
	
	return array($elem['pos'], $elem['posend']);
}
function _xml_find_encompasing_pos(&$elem, $s, $e) {
	$posend = $elem['posend'] - strlen($elem['name']) - 3; // find inner end position
	if($elem['pos'] > $posend) return false; // will be true for self-closing tags, which we don't care about
	
	// slight optimisation - return something different if looped way past; only slight since this should be a rare occurance
	if($e < $elem['pos']) return 0;
	if($s < $elem['pos'] || $e > $posend) return false;
	
	$ret = $elem;
	$ret['posend'] = $posend;
	$ret['parents'] = array();
	// so range is valid, recurse down into children
	foreach($elem['children'] as &$child) {
		$recur_ret = _xml_find_encompasing_pos($child, $s, $e);
		if($recur_ret) {
			unset($ret['children'], $ret['parents']); // save some memory
			$recur_ret['parents'][] = $ret;
			$ret = $recur_ret;
			break;
		} elseif($recur_ret === 0) // looped past target range
			break;
	}
	return $ret;
}



function resolve_relative_url($ref, $base) {
	$ref = trim($ref);
	if(preg_match('~^(https?|irc|magnet|[a-z0-9]{2,8})\://~i', $ref))
		return $ref;
	elseif(isset($ref[0]) && $ref[0] == '/') {
		$p = strpos($base, '/', 8);
		if(!$p)
			return $base.$ref;
		else
			return substr($base, 0, $p).$ref;
	}
	else {
		if(!strpos($base, '/', 8)) {
			// passed base is a domain without trailing slash
			$bn = $base.'/';
		} else {
			if(substr($base, -1) == '/')
				$bn = $base;
			else
				$bn = dirname($base).'/';
		}
		$bn .= $ref;
		
		// chomp .. and . dirs
		if($p=strpos($bn, '/', 8)) {
			$bnb = substr($bn, 0, $p+1);
			$bne = explode('/', substr($bn, $p+1));
			$dir = array();
			foreach($bne as &$s) {
				if($s === '' || $s == '.') continue;
				if($s == '..')
					array_pop($dir);
				else
					$dir[] = $s;
			}
			$bn = substr($bn, 0, $p+1) . implode('/', $dir);
		}
		
		return $bn;
	}
}

// this is actually slightly different to the toto_fix_link() in toto.php
function toto_fix_link(&$url) {
	// fix up stuff for nyaa etc
	if(preg_match('~^(https?://(?:www\.|sukebei\.)?nyaa\.si/)view/(\d+)(&|$)~i', $url, $m)) {
		$url = $m[1].'download/'.$m[2].'.torrent';
	}
	elseif(preg_match('~^(https?\://(?:www\.|sukebei\.)?nyaa(?:torrents\.org|\.[a-z]{2,4})/\?page=)(?:torrentinfo|view)&tid=(\d+)(&|$)~i', $url, $m)) {
		$url = $m[1].'download&tid='.$m[2];
	}
	elseif(preg_match('~^(https?://(?:www\.)?anidex\.(?:moe|info)/)torrent/(\d+)(&|$)~i', $url, $m)) {
		$url = $m[1].'dl/'.$m[2];
	}
	// redirect nyaatorrents.org -> nyaa.se
	$url = preg_replace('~^https?\://(www\.)?(?:nyaatorrents\.org|nyaa\.eu)/\?page=download~i', 'https://$1nyaa.se/?page=download', $url);
}


function get_feed_url(&$data, $website) {
	$feeds = $atomfeeds = array();
	preg_match_all('~\<link( [^>]*?rel\=(["\'])alternate\\2[^>]*?)/?\>~i', $data, $matches);
	foreach($matches[1] as &$m) {
		preg_match_all('~(?<=\s)(type|href|title)\=(["\'])([^">]+?)\\2~i', $m, $tags);
		$type = $href = $title = '';
		foreach($tags[3] as $k => &$val) {
			${strtolower($tags[1][$k])} = unhtmlspecialchars($val);
		}
		if($type && $href) {
			$fu = resolve_relative_url($href, $website);
			if(strtolower($type) == 'application/rss+xml')
				$feeds[$fu] = $title;
			elseif(strtolower($type) == 'application/atom+xml')
				$atomfeeds[$fu] = $title;
		}
	}
	if(empty($feeds) && !empty($atomfeeds)) // no RSS found -> fallback to ATOM feeds
		$feeds =& $atomfeeds;
	
	if(count($feeds) > 1) {
		// determine the proper feed - usually there should be two: announcements, and comments
		// TODO: better filtering?
		$feeds_filt = $feeds;
		foreach($feeds_filt as $url => $title) {
			if(stripos($title, 'comments') || stripos($url, 'comments'))
				unset($feeds_filt[$url]);
		}
		if(!empty($feeds_filt))
			$feeds = $feeds_filt;
	}
	// Joomla filter: assume one with index.php is the legit one
	if(count($feeds) > 1) {
		$feeds_filt = $feeds;
		foreach($feeds_filt as $fu => &$title) {
			$pfu = @parse_url($fu);
			if(!empty($pfu)) {
				if(stripos(@$pfu['path'], '/index.php') !== false)
					continue;
			}
			unset($feeds_filt[$fu]);
		}
		if(!empty($feeds_filt))
			$feeds = $feeds_filt;
	}
	
	if(empty($feeds)) return false;
	if(count($feeds) != 1) {
		return $feeds;
	} else {
		reset($feeds);
		return key($feeds);
	}
}

function wordpress_filter_feed_urls(&$feedurl, $pth) {
	if(is_array($feedurl)) {
		if(substr($pth, -1) == '/')
			$pathlen = strlen($pth);
		else
			$pathlen = strlen(dirname($pth)) +1;
		// assume /feed or ?feed= is the correct one
		foreach($feedurl as $fu => &$title) {
			$pfu = @parse_url($fu);
			if(!empty($pfu)) {
				if(strtolower(substr(@$pfu['query'], 0, 5)) == 'feed=')
					continue;
				if(strtolower(@$pfu['path']) == '/feed' || strtolower(@$pfu['path']) == '/feed/') {
					// this is almost definitely the feed
					$feedurl = $fu;
					return;
				}
				if(strtolower(substr(@$pfu['path'], $pathlen, 4)) == 'feed')
					continue;
			}
			unset($feedurl[$fu]);
		}
		if(count($feedurl) == 1) {
			reset($feedurl);
			$feedurl = key($feedurl);
		} else
			$feedurl = false;
	}
}

// $time_cut is an output variable: will be set to true if we stopped grabbing items because we're outside the time limit
function &get_feed_items($url, $timebase, &$time_cut=null) {
	static $feedcache = null;
	$time = time();
	if(!isset($feedcache)) $feedcache = array();
	
	if(isset($feedcache[$url]) && $feedcache[$url][0] > $time-300) {
		$time_cut = $feedcache[$url][2];
		log_event('Get feed items for '.$url.' (cached)');
		return $feedcache[$url][1];
	}
	
	$time_cut = false;
	// we'll only look on first page (assume it's there, since this is a somewhat natural filter for older posts)
	$feeditems = parse_feed($url);
	if(empty($feeditems)) {
		$feeditems = array();
		$time_cut = true; // no items left?  don't continue
	}
	else foreach($feeditems as $k => &$item) {
		// filter out old entries (more than 28 days from the timebase)
		$old_entry = (isset($item['time']) && abs($timebase - $item['time']) > 28*86400);
		// also, require an article link
		if(!isset($item['link']) || $old_entry) {
			unset($feeditems[$k]);
			if($old_entry) $time_cut = true;
			continue;
		}
		$item['link'] = resolve_relative_url($item['link'], $url);
		// trim some stuffs
		if(isset($item['title']))
			$item['title'] = trim($item['title']);
		else
			$item['title'] = '';
		if(isset($item['content'])) $item['content'] = trim($item['content']);
		if(isset($item['description'])) $item['description'] = trim($item['description']);
	}
	$feedcache[$url] = array($time, $feeditems, $time_cut);
	log_event('Get feed items for '.$url);
	return $feeditems;
}

function &feed_filter_wordpress(&$feed) {
	global $db;
	foreach($feed as $k => &$item) {
		// grab the announcement and look for matches
		if(!isset($item['content'])) {
			if(!isset($time)) $time = time();
			// older version of Wordpress which doesn't put the content of the post in the feed
			if(!mt_rand(0,9)) //prune cache
				$db->delete('src_cache_articlecontent', 'dateline<='.($time-1800));
			
			$content = $db->selectGetField('src_cache_articlecontent', 'content', 'url='.$db->escape(mb_substr($item['link'],0,250)).' AND dateline>'.($time-1800));
			if(isset($content)) {
				if($content !== '') // only set if we actually have content
					$item['content'] = $content;
			} elseif(@$item['description']) {
				$feeddata = @send_request($item['link']);
				
				// if Wordpress gives us these lovely dividers, use them!
				// UPDATE: actually, this is only a theme thing
				// (note, take first one - sometimes, WP supplies two, and the first one is correct)
				if(preg_match('~\<\!-- article-content --\>(.+?)\<\!-- /article-content --\>~is', $feeddata, $m)) {
					$item['content'] = $m[1];
				} else {
					// try to get article by comparing with summary
					// kill Feedburner image
					$item['description'] = preg_replace('~\<img src\="http\://feeds\.feedburner\.com/[^"]+"[^>]+/\>$~i', '', $item['description']);
					$item['content'] = match_article_content_from_summary($item['description'], $feeddata);
				}
				$content = '';
				if($item['content']) {
					$content = $item['content'];
					// hard limit content length to 48KB
					if(isset($content[49152]))
						$content = substr($content, 0, 49152);
				}
				// write to cache - even if failed, so we don't retry it
				$db->insert('src_cache_articlecontent', array('url' => mb_substr($item['link'],0,250), 'content' => $content, 'dateline' => $time), true);
			}
			unset($content);
		}
		if(!isset($item['content'])) unset($feed[$k]);
	}
	return $feed;
}
function wordpress_append_feedurl_paged($url) {
	$furl = @parse_url($url);
	if(empty($furl) || !isset($furl['host'])) return false;
	if(isset($furl['fragment']))
		$url = substr($url, 0, -strlen($furl['fragment'])-1);
	if(isset($furl['query']))
		$url .= '&paged=';
	else
		$url .= '?paged=';
	return $url;
}

// returns true if it looks like the links in specified content indicate that this is the correct article
function check_content_is_match(&$data, &$website, $torrentlink, &$cfiles) {
	if(!$data) return false;
	log_event('check_content_is_match()');
	$purl = parse_url($website);
	preg_match_all('~\<a [^>]*?href\=((["\'])[^">]+?\\2|[^"\' >]+)[^>]*\>~i', $data, $links);
	if(!empty($links)) $links = $links[1];
	if(empty($links)) {
		// try auto-url'ing links
		preg_match_all('~(?<=\W)(https?\://[^"<>()|\s]+)(?=\W)~i', ' '.$data.' ', $links);
		if(!empty($links)) $links = $links[1];
		
		if(empty($links)) return false;
	}
	$linkx = array(
		'fshare' => array(),
		'torrent' => array(),
		'internal' => array(),
		'external' => array(),
		'other' => array(),
	);
	// clean up links data + do some categorisation
	foreach($links as &$link) {
		if(($link[0] == '"' || $link[0] == "'") && $link[0] == substr($link, -1)) {
			$link = substr($link, 1, -1);
		}
		if($link === '') {
			unset($link);
			continue;
		}
		$link = resolve_relative_url(unhtmlspecialchars($link), $website);
		// TODO: may need to do some dereferencing.
		// for now, handle a basic adf.ly format
		if(preg_match('~^http\://adf\.ly/\d+/([a-z0-9.\-]{4,}/.*)$~i', $link, $m)) {
			$link = 'http://'.$m[1];
		} elseif(preg_match('~^https?\://t\.umblr\.com/redirect\?z\=([a-z0-9.\-]{4,}/.*)&~i', $link, $m)) {
			$link = rawurldecode($m[1]);
		}
		
		// do some link filtering - eg, DDL links, torrent links etc
		if(preg_match('~^(magnet|irc)\:~i', $link))
			$linkx['external'][] = $link;
		else {
			$plink = @parse_url($link);
			if(empty($plink) || !isset($plink['host']) || !($linktld = get_2nd_tld_from_host($plink['host'])))
				$linkx['external'][] = $link;
			elseif(isset($plink['path']) && strtolower(substr($plink['path'], -8)) == '.torrent')
				// high likelihood of being a torrent
				$linkx['torrent'][] = $link;
			elseif(preg_match('~(^|\.)'.preg_quote($purl['host'], '~').'$~i', $plink['host']))
				$linkx['internal'][] = $link;
			else {
				switch($linktld) {
					case 'megaupload.com':
					case 'hotfile.com':
					case 'rapidshare.com':
					case 'sendspace.com':
					case 'mediafire.com':
					case 'depositfiles.com':
					case 'fileserve.com':
					case 'filesonic.com':
					case 'wupload.com':
					case 'zshare.com':
					case 'uploading.com':
					case 'easy-share.com':
					case 'sharingmatrix.com':
					case 'filefactory.com':
					case 'bitshare.com':
					case 'ul.to':
					case 'usershare.net':
						$linkx['fshare'][] = $link;
						break;
					case 'blog.com':
					case 'blogspot.com':
					case 'wordpress.com':
					case 'tumblr.com':
					case 'myanimelist.com':
					case 'animenewsnetwork.com':
					case 'animenfo.com':
					case 'anidb.net':
					case 'anidb.info':
					case 'google.com':
					case 'yahoo.com':
					case 'wikipedia.org':
					case 'facebook.com':
					case 'twitter.com':
					case 'discord.gg':
					case 'addtoany.com':
					case 'multiurl.com':
					case 'hotimg.com':
					case 'doubleclick.net':
					case 'googleusercontent.com':
					case 'animetosho.org':
						// TODO: add more
						$linkx['external'][] = $link;
						break;
					case 'nyaatorrents.org':
					case 'nyaa.eu':
					case 'nyaa.se':
					case 'nyaa.si':
					case 'anidex.info':
					case 'anidex.moe':
					case 'edwardk.info':
					case 'nekobt.to':
					//case 'minglong.org': // will be caught by the .torrent filter
					// TODO: add more
						$linkx['torrent'][] = $link;
						break;
					default:
						$linkx['other'][] = $link;
				}
			}
		}
		unset($link);
	}
	// analyse links - check whether points to same torrent URL, torrent has same name + data, or fileshare links which lead to same files?
	
	// for now, include all unknown links as possible torrent sources
	$torrentlinks = array_merge($linkx['torrent'], $linkx['internal'], $linkx['other']);
	// check link count -> many links = unlikely
	if(count($torrentlinks) > 30) {
		// maybe we grabbed too many links?
		if(!empty($linkx['torrent']) && count($linkx['torrent']) < 20)
			$torrentlinks = $linkx['torrent'];
		else
			return false;
	}
	// TODO: other checks?
	
	// find links to torrent
	$tlinkcmp = preg_replace('~^(https?\://)www\.~', '$1', strtolower($torrentlink));
	foreach($torrentlinks as &$link) {
		toto_fix_link($link);
		// strip www's
		$linkcmp = preg_replace('~^(https?\://)www\.~', '$1', strtolower($link));
		if($linkcmp == $tlinkcmp) {
			// we have an exact match!
			return true;
		}
	} unset($link);
	
	if(empty($cfiles)) return false; // only small files in torrent, can't do much, bail
	
	// try grabbing possible torrent links
	if(!empty($linkx['torrent']) && count($linkx['torrent']) < 10) {
		require_once ROOT_DIR.'3rdparty/BDecode.php';
		foreach($linkx['torrent'] as &$link) {
			// grab file, see if torrent and do comparisons
			$tfiles = get_url_torrent_filelist($link);
			if(!empty($tfiles)) {
				log_event('check_content_is_match: attempting to match torrent files');
				// do torrent comparision - ignoring small files, the basenames and their sizes MUST match exactly, for a match to be considered
				$matchcnt = 0;
				foreach($tfiles as $fn => $fs) {
					// ignore all small (eg .txt) files
					// TODO: put this into cache routine instead?
					if((float)$fs <= 1024*1024*8) {
						unset($tfiles[$fn]);
						continue;
					}
					$bfn = mb_basename($fn);
					if(isset($cfiles[$bfn]) && in_array($fs, $cfiles[$bfn])) {
						// valid match
						++$matchcnt; // need to keep track of it actually matching, not the whole thing being dropped because of small files
						unset($tfiles[$fn]);
					} else
						break;
				}
				if($matchcnt && empty($tfiles)) {
					// all torrent files matched, assume valid page
					return true;
				}
			}
		}
	} else {
		// try accessing these URLs?
	}
	
	log_event('check_content_is_match: checking direct links');
	// search for ddls, parse filename and size comparision
	$valid_fshare_cnt = 0;
	foreach($linkx['fshare'] as &$link) {
		$fns = get_ddl_filename($link);
		if(!empty($fns)) {
			foreach($fns as $fn => $fs) {
				// does this match any file we have?
				// size: give 1% error margin
				// TODO: allow get_ddl_filename to specify partial filenames and size ranges rather than assume 1%
				$fs = (float)$fs;
				if(isset($cfiles[$fn])) {
					foreach($cfiles[$fn] as $cfs) {
						$cfs = (float)$cfs;
						if(abs($fs-$cfs)/$cfs < 0.01)
							// valid match
							++$valid_fshare_cnt;
					}
				}
				// if this is a small file, should we ignore it as well?
			}
		}
	}
	// if > 1/3rd fshare links match, assume this is the correct article
	// TODO: also think about comparing with count($cfiles)
	if($valid_fshare_cnt && $valid_fshare_cnt*3 > count($linkx['fshare']))
		return true;
	
	return false;
}

function get_url_torrent_filelist($url) {
	log_event('get_url_torrent_filelist('.$url.')');
	global $db;
	toto_fix_link($url);
	$time = time();
	$tfiles = $db->selectGetField('src_cache_torlookup', 'content', 'url='.$db->escape($url).' AND dateline>'.($time-86400*3));
	if(isset($tfiles)) {
		log_event('get_url_torrent_filelist: cache hit');
		if($tfiles)
			return unserialize($tfiles);
		else
			return false;
	}
	
	if(!mt_rand(0,9)) // prune cache
		$db->delete('src_cache_torlookup', 'dateline<='.($time-86400*3));
	
	log_event('get_url_torrent_filelist: fetching torrent');
	$context = stream_context_create(array('http' => array('header'=>"Connection: close\r\n", 'timeout'=>15)));
	$data = @file_get_contents($url, false, $context, 0, 256*1024);
	if(!isset($data[256*1024-1])) { // check if buffer possibly exceeded
		log_event('get_url_torrent_filelist: decoding torrent data');
		$tinfo = BDecode($data);
		if(!empty($tinfo) && !empty($tinfo['info']['name']) && $tfiles = get_torrent_filelist($tinfo)) {
			$db->insert('src_cache_torlookup', array('url' => $url, 'content' => serialize($tfiles), 'dateline' => $time), true);
			log_event('get_url_torrent_filelist: fetch success');
			return $tfiles;
		}
	}
	$db->insert('src_cache_torlookup', array('url' => $url, 'content' => '', 'dateline' => $time), true);
	log_event('get_url_torrent_filelist: fetch failed');
	return false;
}

function dereference_link($url, &$datacache=null, &$headers=null) {
	log_event('dereference_link('.$url.')');
	fix_website_url($url);
	$context = stream_context_create(array(
		'http' => array('max_redirects' => 3),
		'https' => array('max_redirects' => 3)
	));
	$fp = @fopen($url, 'rb', false, $context);
	if(!$fp) return false;
	stream_set_timeout($fp, 15);
	$meta = stream_get_meta_data($fp);
	$data = stream_get_contents($fp);
	fclose($fp);
	if(!$data) return false;
	// grab redirected location through meta-data if given
	$loc = '';
	if(isset($meta['wrapper_data'])) {
		foreach($meta['wrapper_data'] as &$header) {
			if(preg_match('~^location\:\s*(.+)$~i', $header, $m))
				$loc = $m[1];
		}
		if(isset($headers)) $headers = $meta['wrapper_data'];
	}
	if($loc) $url = resolve_relative_url($loc, $url);
	
	// check for adf.ly
	if(strtolower(substr($url, 0, 14)) == 'http://adf.ly/' && isset($url[15])) {
		if(preg_match('~\svar url = \'(.+?)\';'."\r?\n".'~', $data, $m))
			$redirurl = stripslashes($m[1]); // TODO: proper JS evaluation
		else
			return false;
	}
	
	// check for .co.nr JS redirect
	if(preg_match('~^\s*\<script language\="JavaScript"\>\s*\<!--\s*window\.location\s*\=\s*"(https?\://[^"]+)";//--\>\s*\</script\>~', $data, $m)) {
		$redirurl = stripslashes($m[1]); // TODO: proper JS evaluation
	}
	
	// generic <meta> redirect
	if(preg_match('~\<meta ([^>]*?)http-equiv\=(["\']|)refresh\\2([^>]*?)/?\>~i', $data, $m)) {
		$tagstuff = trim($m[1]).' '.trim($m[3]);
		if(preg_match('~(^|\s)content\=(["\'])0;url\=([^>]+?)\\2($|\s)~i', $tagstuff, $m)) {
			$redirurl = unhtmlspecialchars($m[3]);
		}
	}
	
	if(isset($redirurl)) {
		if(isset($datacache))
			$datacache = send_request($redirurl);
		return $redirurl;
	}
	
	if(isset($datacache))
		$datacache = $data;
	return $url;
}

// static rewrites/redirects
function fix_website_url(&$url) {
	if(preg_match('~^http\://manganime\.biz(/|^)~i', $url))
		$url = 'http://site.manganime.biz/';
}

// function from http://www.php.net/manual/en/function.get-html-translation-table.php#76564
function &get_html_translation_table_CP1252() {
	$trans = get_html_translation_table(HTML_ENTITIES);
	//$trans["'"] = '&apos;';
	$trans[chr(130)] = '&sbquo;';	// Single Low-9 Quotation Mark
	$trans[chr(131)] = '&fnof;';	// Latin Small Letter F With Hook
	$trans[chr(132)] = '&bdquo;';	// Double Low-9 Quotation Mark
	$trans[chr(133)] = '&hellip;';	// Horizontal Ellipsis
	$trans[chr(134)] = '&dagger;';	// Dagger
	$trans[chr(135)] = '&Dagger;';	// Double Dagger
	$trans[chr(136)] = '&circ;';	// Modifier Letter Circumflex Accent
	$trans[chr(137)] = '&permil;';	// Per Mille Sign
	$trans[chr(138)] = '&Scaron;';	// Latin Capital Letter S With Caron
	$trans[chr(139)] = '&lsaquo;';	// Single Left-Pointing Angle Quotation Mark
	$trans[chr(140)] = '&OElig;';	// Latin Capital Ligature OE
	$trans[chr(145)] = '&lsquo;';	// Left Single Quotation Mark
	$trans[chr(146)] = '&rsquo;';	// Right Single Quotation Mark
	$trans[chr(147)] = '&ldquo;';	// Left Double Quotation Mark
	$trans[chr(148)] = '&rdquo;';	// Right Double Quotation Mark
	$trans[chr(149)] = '&bull;';	// Bullet
	$trans[chr(150)] = '&ndash;';	// En Dash
	$trans[chr(151)] = '&mdash;';	// Em Dash
	$trans[chr(152)] = '&tilde;';	// Small Tilde
	$trans[chr(153)] = '&trade;';	// Trade Mark Sign
	$trans[chr(154)] = '&scaron;';	// Latin Small Letter S With Caron
	$trans[chr(155)] = '&rsaquo;';	// Single Right-Pointing Angle Quotation Mark
	$trans[chr(156)] = '&oelig;';	// Latin Small Ligature OE
	$trans[chr(159)] = '&Yuml;';	// Latin Capital Letter Y With Diaeresis
	return $trans;
}
