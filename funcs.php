<?php

function warning($s, $sect='') {
	log_error( 'Warning: ' . $s . "\n", $sect );
}
function error($s, $sect='') {
	log_error( 'Error: ' . $s . "\n", $sect );
}
function info($s, $sect='', $dateSplit=false) {
	log_info( $s . "\n", $sect, $dateSplit );
}

function log_error($s, $sect='', $noecho=false) {
	static $logging_started = array();
	$fp = fopen(ROOT_DIR.'logs/errors'.($sect?'-'.$sect:'').'.txt', 'a');
	// TODO: issue! possible to interleave logged events!
	if(!isset($logging_started[$sect])) {
		$logging_started[$sect] = true;
		if($fp) {
			fwrite($fp, "\n---\nLogged events at ".date('r')." [".THIS_SCRIPT."]\n");
		}
	}
	if(!$noecho) echo $s;
	
	if($fp) {
		fwrite($fp, $s);
		fclose($fp);
	}
}
function log_info($s, $sect='', $dateSplit=false) {
	$fp = fopen(ROOT_DIR.'logs/info'.($sect?'-'.$sect:'').($dateSplit?'-'.date('Y-m'):'').'.txt', 'a');
	if($fp) {
		fwrite($fp, date('r').": $s");
		fclose($fp);
	}
	echo 'Info'.($sect?' ['.$sect.']':'').': ', $s;
}

function log_event($s) {
	static $logfile = null;
	if(!isset($logfile)) {
		$logfile = ROOT_DIR.'logs/log-'.substr(THIS_SCRIPT, 5, -4).'-'.date('Y-m').'.txt';
		$firsttime = true;
	}
	if(!$logfile) return;
	
	$fp = @fopen($logfile, 'a');
	if(!$fp) {
		$logfile = false;
		return;
	}
	
	if(isset($firsttime))
		fwrite($fp, "\n");
	
	fwrite($fp, date('r').': '.$s."\n");
	fclose($fp);
	
	if(defined('PRINT_LOG')) echo $s, "\n";
}

function make_lock_file($fn='', $block=LOCK_NB) {
	global $__lockfiles;
	if(!$fn) $fn = substr(THIS_SCRIPT, 5, -4);
	$f = ROOT_DIR.'logs/lock-'.$fn;
	if(!@touch($f)) exit;
	if(!($fp = @fopen($f, 'r'))) exit;
	if(!@flock($fp, LOCK_EX+$block)) exit;
	
	static $inited = false;
	if(!$inited) {
		$__lockfiles = array();
		register_shutdown_function('__lock_file_cleanup');
		$inited = true;
	}
	$__lockfiles[$fn] = $fp;
}
function remove_lock_file($fn='') {
	global $__lockfiles;
	if(!$fn) $fn = substr(THIS_SCRIPT, 5, -4);
	if(!isset($__lockfiles[$fn])) return;
	fclose($__lockfiles[$fn]);
	@unlink(ROOT_DIR.'logs/lock-'.$fn);
	unset($__lockfiles[$fn]);
}
function __lock_file_cleanup() {
	global $__lockfiles;
	if(empty($__lockfiles)) return;
	foreach($__lockfiles as $fn => $fp) {
		fclose($fp);
		@unlink(ROOT_DIR.'logs/lock-'.$fn);
	}
}

function sema_lock($type, $wait=false) {
	if(!function_exists('sem_get')) return true;
	
	global $SEMA_KEYS;
	if(!isset($SEMA_KEYS[$type])) die('Semaphore key not set for '.$type);
	$s =& $SEMA_KEYS[$type];
	if(!($sem = @sem_get($s[0], $s[1], 0666, false)))
		return false;
	if(!@sem_acquire($sem, !$wait)) return false;
	return $sem;
}

function sema_unlock($sem) {
	if(!function_exists('sem_release')) return true;
	if(!$sem) return false;
	return @sem_release($sem);
}

function log_dump_data(&$data, $prefix='') {
	if(empty($data) && $data !== '0')
		return '  No data returned';
	if($prefix) $prefix .= '_';
	$dumpfn = $prefix.'dump_'.uniqid().'.html';
	if(is_string($data))
		file_put_contents(ROOT_DIR.'logs/'.$dumpfn, $data);
	else
		file_put_contents(ROOT_DIR.'logs/'.$dumpfn, serialize($data));
	return '  Data dumped to '.$dumpfn;
}

function jencode($o) {
	return json_encode($o, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

// tidy doesn't retain whitespace etc
function fix_xml($s, $charset='utf8') {
	$t = new tidy;
	return $t->repairString($s, array(
		'drop-empty-paras' => false,
		'fix-backslash' => false,
		'input-xml' => true,
		'literal-attributes' => true,
		'lower-literals' => false,
		'merge-divs' => false,
		'merge-spans' => false,
		'output-xml' => true,
		'preserve-entities' => true,
		'quote-marks' => true,
		'indent-spaces' => 0,
		'tab-size' => 0,
		'wrap' => 0,
		'wrap-sections' => false,
	), 'utf8');
}
function fix_html($s, $charset='utf8') {
	$t = new tidy;
	return $t->repairString($s, array(
		'drop-empty-paras' => false,
		'fix-backslash' => false,
		'literal-attributes' => true,
		'lower-literals' => false,
		'merge-divs' => false,
		'merge-spans' => false,
		'output-xhtml' => true,
		'preserve-entities' => true,
		'quote-marks' => true,
		'indent-spaces' => 0,
		'tab-size' => 0,
		'wrap' => 0,
		'wrap-sections' => false,
	), 'utf8');
}

function _xml_parse_data_decode_ent($s, $charset='UTF-8') {
	// convert nbsp to spaces rather than char160, and html_entitity_decode doesn't handle apos
	return html_entity_decode(strtr($s, array('&nbsp;' => ' ', '&apos;' => '\'')), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, $charset);
}
function &xml_parse_data($data, $charset='UTF-8') {
	$xp = xml_parser_create();
	xml_parser_set_option($xp, XML_OPTION_SKIP_WHITE, true);
	$dummy_add = (substr(trim($data), 0, 2) != '<?');
	$data = str_replace('&', '&amp;', $data); // force entities to be kept, since we'll handle them better
	if($dummy_add)
		$data = '<dummy>'.$data.'</dummy>';
	xml_parse_into_struct($xp, $data, $vals);
	xml_parser_free($xp);
	// make nested array
	$ret = array();
	$astack = array(&$ret);
	
	foreach($vals as &$tag) {
		if($tag['type'] == 'close') {
			array_pop($astack);
			continue;
		}
		$tag['children'] = array();
		
		// fix up the mess we made with our PHP entity workaround
		if(isset($tag['value']))
			$tag['value'] = _xml_parse_data_decode_ent($tag['value'], $charset);
		if(!empty($tag['attributes'])) {
			foreach($tag['attributes'] as $k => &$v)
				$v = _xml_parse_data_decode_ent($v, $charset);
		}
		
		$add2 =& $astack[count($astack)-1];
		$add2[] = $tag;
		if($tag['type'] == 'open') {
			$astack[] =& $add2[count($add2)-1]['children'];
		}
	}
	if($dummy_add)
		return $ret[0]['children'];
	else
		return $ret;
}

function fix_utf8_encoding($data) {
	$possible_utf8 = mb_check_encoding($data, 'UTF-8');
	// check BOM
	if(substr($data, 0, 3) == "\xEF\xBB\xBF" && $possible_utf8) {
		return substr($data, 3);
	}
	$bomcheck = substr($data, 0, 2);
	if($bomcheck == "\xFE\xFF" && mb_check_encoding($data, 'UTF-16BE'))
		return mb_convert_encoding(substr($data, 2), 'UTF-8', 'UTF-16BE');
	elseif($bomcheck == "\xFF\xFE" && mb_check_encoding($data, 'UTF-16LE'))
		return mb_convert_encoding(substr($data, 2), 'UTF-8', 'UTF-16LE');
	
	if(!$possible_utf8) {
		// try to detect SHIFT_JIS encoding
		// maybe use mb_detect_encoding instead?
		if(mb_check_encoding($data, 'SHIFT_JIS'))
			return mb_convert_encoding($data, 'UTF-8', 'SHIFT_JIS');
		else // assume ISO-8859-1
			return utf8_encode($data);
	}
	return $data;
}

//define('CLOUDFLARE_CAPTCHA_COOKIE', 'cf_clearance=');
define('CLOUDFLARE_CAPTCHA_UA', 'Mozilla/5.0 (X11; Linux i686; rv:2.0.1) Gecko/20100101 Firefox/4.0.1 Fennec/2.0.1');
// NOTE: setting $headers implies no redirect following
function &send_request($url, &$headers=null, $extra=array(), $proxy=null) {
	static $cf_domains = array();
	
	$req_headers = array('Accept: */*');
	if(isset($extra['headers'])) {
		$req_headers = $extra['headers'];
	}
	
	$url_host = strtolower(parse_url($url, PHP_URL_HOST));
	switch($url_host) { // host specific rules
	case 'anidex.info':
		if(!isset($extra['cookie'])) {
			if(!function_exists('anidex_ddos_cookie')) {
				require ROOT_DIR.'releasesrc/anidex.php';
			}
			$extra['cookie'] = anidex_ddos_cookie();
		}
	break;
	}
	if(!isset($ch))
		$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_FAILONERROR => false,
		CURLOPT_HEADER => isset($headers),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => @$extra['conntimeout'] ?: 20,
		CURLOPT_TIMEOUT => @$extra['timeout'] ?: 60,
		CURLOPT_ENCODING => '',
		CURLOPT_HTTPHEADER => $req_headers,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_IPRESOLVE => isset($extra['ipresolve']) ? $extra['ipresolve'] : CURL_IPRESOLVE_V4
	));
	if(defined('CURLOPT_SSL_VERIFYHOST'))
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	if(isset($extra['cookie']))
		curl_setopt($ch, CURLOPT_COOKIE, $extra['cookie']);
	if(isset($extra['referer']))
		curl_setopt($ch, CURLOPT_REFERER, $extra['referer']);
	$ua = 'AnimeTosho Bot [https://animetosho.org/]';
	if(isset($extra['useragent'])) {
		if($extra['useragent'])
			$ua = $extra['useragent'];
		else
			$ua = null;
	}
	if($ua) curl_setopt($ch, CURLOPT_USERAGENT, $ua);
	if(isset($extra['post'])) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $extra['post']);
	}
	
	if(!isset($headers) && !@$extra['noredir']) {
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
	}
	if(isset($proxy)) {
		if(@$proxy['tunnel'])
			curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
		curl_setopt($ch, CURLOPT_PROXY, $proxy['proxy']);
		if(@$proxy['socks'])
			curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
		if(@$proxy['auth']) {
			curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
			curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['auth']);
		}
	} else {
		// lame fix for CloudFlare issues
		if(isset($cf_domains[$url_host])) {
			if($cf_domains[$url_host] == 2) {
				if(isset($extra['cookie']))
					curl_setopt($ch, CURLOPT_COOKIE, $extra['cookie'].'; '.CLOUDFLARE_CAPTCHA_COOKIE);
				else
					curl_setopt($ch, CURLOPT_COOKIE, CLOUDFLARE_CAPTCHA_COOKIE);
				curl_setopt($ch, CURLOPT_USERAGENT, CLOUDFLARE_CAPTCHA_UA);
			} else {
				curl_setopt($ch, CURLOPT_PROXY, '127.0.0.1:8880');
				
				// hack for SSL, as our CloudScraper server doesn't support proxy CONNECT
				if(substr($url, 0, 8) == 'https://') {
					$_cfHeaders = isset($extra['headers']) ? $extra['headers'] : array();
					$_cfHeaders[] = 'X-CF-Use-HTTPS: 1';
					curl_setopt($ch, CURLOPT_HTTPHEADER, $_cfHeaders);
					curl_setopt($ch, CURLOPT_URL, 'http://'.substr($url, 8));
				}
			}
		}
	}
	
	$data = curl_exec($ch);
	// ugly hack for returning errors
	$curl_errno = $GLOBALS['CURL_ERRNO'] = curl_errno($ch);
	if($curl_errno)
		info('('.$curl_errno.') '.curl_error($ch).' ['.$url.']', 'curl', true);
	$curl_info = $GLOBALS['CURL_INFO'] = curl_getinfo($ch);
	curl_close($ch);
	
	$regex = '~^http/1\.[10] \d{3} [a-z0-9_ \-]+'."\r\n".'~i';
	
	if(isset($headers) || preg_match($regex, $data)) {
		$p = strpos($data, "\r\n\r\n");
		if($p) {
			$head = explode("\r\n", substr($data, 0, $p));
			$data = substr($data, $p+4);
			$headers = array();
			foreach($head as &$h) {
				$h = trim($h);
				$p = strpos($h, ':');
				if(!$p) continue; // eg HTTP/1.1 ... headers
				$hdr = strtolower(trim(substr($h, 0, $p)));
				$val = trim(substr($h, $p+1));
				if(isset($headers[$hdr])) {
					if(!is_array($headers[$hdr])) $headers[$hdr] = array($headers[$hdr]);
					$headers[$hdr][] = $val;
				} else
					$headers[$hdr] = $val;
			}
		}
	}
	// for some weird reason, some proxies stick the headers in the body?
	if(preg_match($regex, $data)) {
		$p = strpos($data, "\r\n\r\n");
		if($p) {
			$data = substr($data, $p+4);
		}
	}
	
	// check for CloudFlare stupidity...
	if($curl_info['http_code'] == 503 && stripos($data, '<title>Just a moment...</title>') && stripos($data, '>Please turn JavaScript on and reload the page.<') && stripos($data, '>DDoS protection by CloudFlare<') && isset($url_host) && !isset($cf_domains[$url_host])) {
		$cf_domains[$url_host] = 1;
		info('CloudFlare blocked page - redirecting '.$url_host.' through cloudscraper', 'curl', true);
		return send_request($url, $headers, $extra, $proxy);
	}
	elseif($curl_info['http_code'] == 403 && stripos($data, '<title>Attention Required! | Cloudflare</title>') && stripos($data, '<iframe src="https://www.google.com/recaptcha/api/') && isset($url_host) && !isset($cf_domains[$url_host])) {
		// URGH, CloudFlare captcha
		if(defined('CLOUDFLARE_CAPTCHA_COOKIE')) {
			$cf_domains[$url_host] = 2;
			info('CloudFlare captcha page - redirecting '.$url_host.' through cloudscraper with cookie', 'curl', true);
			return send_request($url, $headers, $extra, $proxy);
		} else {
			info('CloudFlare captcha page on '.$url_host.'; giving up', 'curl', true);
		}
	}
	
	if(!isset($extra['ignoreerror']) && $curl_info['http_code'] >= 400) {
		info('Got HTTP code '.$curl_info['http_code'].' ['.$url.']', 'curl', true);
		$data = false;
	}
	
	if(!$data && isset($extra['autoretry']) && $extra['autoretry']) {
		info('Request to '.$url.' failed, retrying in 10 seconds... ('.$extra['autoretry'].' attempt(s) remaining)', 'curl', true);
		--$extra['autoretry'];
		sleep(10);
		return send_request($url, $headers, $extra, $proxy);
	}
	
	return $data;
}

// simple grabs ALL cookies
function get_cookies_from_headers($headers) {
	$hsc =& $headers['set-cookie'];
	if(!isset($hsc)) return '';
	
	$cookies = array();
	if(is_array($hsc))
		$c =& $hsc;
	else
		$c = array($hsc);
	
	foreach($c as &$sc) {
		if($p = strpos($sc, ';')) $sc = substr($sc, 0, $p);
		list($key, $value) = explode('=', $sc, 2);
		$cookies[$key] = $value;
	}
	$ret = '';
	foreach($cookies as $key => $value) {
		$ret .= ($ret?'; ':'').$key.'='.$value;
	}
	return $ret;
}

function _cf_email_decode($s) {
	$s = hex2bin($s);
	$c = ord($s[0]);
	$r = '';
	for($i=1, $l=strlen($s); $i<$l; ++$i) {
		$r .= chr($c ^ ord($s[$i]));
	}
	return $r;
}
function fix_cf_emails($data) {
	$data = preg_replace_callback('~(\<a [^>]*href=")/cdn-cgi/l/email-protection#([a-f0-9]+)("[^>]*\>)~', function($m) {
		return $m[1].htmlspecialchars(_cf_email_decode($m[2])).$m[3];
	}, $data);
	// stripping whitespace before the email breaks stuff like "The iDOLM@STER"
	$data = preg_replace_callback('~\<(a|span) [^>]*class="__cf_email__" [^>]*data-cfemail="([a-f0-9]+)"\>\[email[^<>]+protected\]\</\\1\>(?:\<script [^>]*\>.+?\</script\>)?~s', function($m) {
		return htmlspecialchars(_cf_email_decode($m[2]));
	}, $data);
	return $data;
}

function parse_feed($url, $opts=[]) {
	$null = null;
	// Feedburner doesn't like a User-Agent being sent through
	$rawdata = $data = send_request($url, $null, array_merge(array('useragent' => ''), $opts));
	
	// strip random extra data if present
	if(($p = strpos($data, '<')) !== 0)
		$data = substr($data, $p);
	if(($p = strrpos($data, '>')+1) !== strlen($data))
		$data = substr($data, 0, $p);
	
	// fix for some PHP errors outputting before feed
	$p = strpos($data, '<?xml');
	if($p !== 0 && $p !== false)
		$data = substr($data, $p);
	
	if(!$data) {
		info('No valid data found ('.$url.')'.log_dump_data($rawdata, 'feedparser'), 'feed');
		return false;
	}
	
	// as this often happens - check if this looks like HTML (eg a 404 page)
	// this heuristic just checks for data starting with a doctype html tag, or if the beginning of a html tag occurs in the first 1KB of data
	$datacmp = strtolower(substr($data, 0, 1024));
	if(strtolower(substr($datacmp, 0, 14)) == '<!doctype html' || strpos($datacmp, '<html ') !== false || strpos($datacmp, '<html>') !== false) {
		info('Feed appears to be a HTML page ('.$url.')'.log_dump_data($rawdata, 'feedparser'), 'feed');
		return false;
	}
	
	// we'll also check that RSS/FEED tags exist
	if(strpos($datacmp, '<rss ') === false && strpos($datacmp, '<rss>') === false && strpos($datacmp, '<feed ') === false && strpos($datacmp, '<feed>') === false) {
		info('No feed markers found ('.$url.')'.log_dump_data($rawdata, 'feedparser'), 'feed');
		return false;
	}
	
	// parse xml
	$xdata = xml_parse_data($data);
	if(empty($xdata)) {
		$xdata = xml_parse_data(fix_xml($data));
		if(!empty($xdata)) {
			info('Fixed bad feed XML.  Url = '.$url.log_dump_data($rawdata, 'feedparser_fixed'));
		}
	}
	if(empty($xdata)) {
		warning('[FeedParser] Unable to parse XML.  Url = '.$url.log_dump_data($rawdata, 'feedparser'));
		return false;
	}
	unset($data);
	
	// assume first element is main thing (should be the case with proper XML)
	if(count($xdata) != 1) {
		warning('[FeedParser] Bad XML.  Url = '.$url.log_dump_data($rawdata, 'feed_badxml'));
		return false;
	}
	if($xdata[0]['tag'] == 'RSS')
		$is_rss = true;
	elseif($xdata[0]['tag'] == 'FEED')
		$is_rss = false;
	else {
		warning('[FeedParser] Unrecognised feed type.  Url = '.$url);
		return false;
	}
	$xdata = $xdata[0]['children'];
	
	if($is_rss) {
		// only allow one channel
		foreach($xdata as &$tag) {
			if($tag['tag'] != 'CHANNEL') continue;
			if(isset($channel)) {
				warning('[FeedParser] Multiple channels found in RSS.  Url = '.$url);
				break;
			}
			$channel =& $tag['children'];
		}
		if(!isset($channel)) {
			warning('[FeedParser] Can\'t find channel in RSS.  Url = '.$url);
			return false;
		}
	} else
		$channel =& $xdata;
	
	$items = array();
	$il = ($is_rss ? 2:1); // dirty hack (only loop if RSS)
	$itemtagname = ($is_rss ? 'ITEM':'ENTRY');
	for($i=0; $i<$il; ++$i) {
		// search both the channel and parent entry for items
		if($i == 0)
			$itemcontainer =& $channel;
		else
			$itemcontainer =& $xdata;
		
		foreach($itemcontainer as &$tag) {
			if($tag['tag'] != $itemtagname || empty($tag['children'])) continue;
			$item = array();
			foreach($tag['children'] as &$itemtag) {
				if(isset($itemtag['value']))
					$item[strtolower($itemtag['tag'])] = $itemtag['value'];
				elseif(!$is_rss && $itemtag['tag'] == 'LINK' && strtolower(@$itemtag['attributes']['REL']) == 'alternate')
					$item['link'] = $itemtag['attributes']['HREF'];
			}
			if($is_rss) {
				if(isset($item['dc:date']) && !isset($item['pubdate']))
					$item['pubdate'] =& $item['dc:date'];
				if(isset($item['pubdate'])) {
					// convert dates
					$item['pubdate'] = trim($item['pubdate']);
					$time = strtotime($item['pubdate']);
					if($time === false || $time === -1) {
						if(preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+\d{2}:\d{2}$~', $item['pubdate'])) {
							$time = strtotime(strtr($item['pubdate'], array('T' => ' ', '+' => ' +')));
						}
					}
					if($time && $time > 0) {
						$item['time'] = $time;
					}
				}
			} else {
				if(!isset($item['published']) && isset($item['updated']))
					$item['published'] =& $item['updated'];
				if(isset($item['published'])) {
					// convert date
					$item['time'] = @strtotime($item['published']);
				}
			}
			// RSS thing
			if(isset($item['content:encoded']) && !isset($item['content']))
				$item['content'] =& $item['content:encoded'];
			// ATOM thing
			if(isset($item['summary']) && !isset($item['description']))
				$item['description'] =& $item['summary'];
			
			$items[] = $item;
		}
		if(!empty($items)) break;
	}
	
	if(empty($items)) { // failed
		info('Got empty feed ('.$url.')', 'feed');
		return false;
	}
	
	return $items;
}

function get_extension($file) {
	$ext = '';
	if($p = strrpos($file, '.')) {
		$ext = strtolower(substr($file, $p+1));
	}
	return $ext;
}

function id2hash($id, $len=8) {
	return str_pad(base_convert($id, 10, 16), $len, '0', STR_PAD_LEFT);
}

function strtolower_keys($a) {
	$ret = array();
	foreach($a as $k => $v)
		$ret[strtolower($k)] = $v;
	return $ret;
}


function get_transmission_rpc() {
	require_once ROOT_DIR.'3rdparty/TransmissionRPC.class.php';
	try {
		$obj = new TransmissionRPC('http://127.0.0.1:9881/transmission/rpc', 'anito', TRANSMISSION_PASSWORD);
		$obj->GetSessionID(); // make sure it's alive
		return $obj;
	} catch(Exception $ex) {
		return null;
	}
}

function unhtmlspecialchars($s) {
	return preg_replace_callback('~&#?[a-zA-Z0-9]{1,10};~', function($m) {
		return mb_convert_encoding($m[0], 'UTF-8', 'HTML-ENTITIES');
	}, $s);
}

function make_temp_dir() {
	$tmpsdir = TEMP_DIR.'toto_temp_'.uniqid().'/';
	@mkdir($tmpsdir);
	return $tmpsdir;
}

// recursive directory delete
//  do NOT include the trailing slash for $dirname!
// $keep_toplevel -> if true, the folder is emptied instead of deleted
function rmdir_r($dir)
{
	if(!($d = opendir($dir))) return;
	while(false !== ($f = readdir($d))) {
		if($f == '.' || $f == '..') continue;
		$ff = $dir.'/'.$f;
		if(is_dir($ff)) {
			rmdir_r($ff);
		} else {
			unlink($ff);
		}
	}
	closedir($d);
	rmdir($dir);
}

function escapeshellfile($fn) {
	if($fn[0] == '-')
		$fn = './'.$fn;
	return escapeshellarg($fn);
}

// strips script tags properly
function strip_tags_ex($str) {
	return strip_tags(preg_replace('~\<script(?: [^>]*)?\>(.*?)\</script\>~si', '', $str));
}

function link_or_copy($src, $dest) {
	if(!@link($src, $dest)) {
		// cross-device link?
		copy($src, $dest);
	}
}

function low_disk_space() {
	// TODO: pause all torrents if >95% disk full
	return disk_free_space(TORRENT_DIR) < 8*1024*1024*1024;
}

// opts ->
//        cwd
//        env
//        retries (only relevant for retrying if process times out)
//        stderr (if set, stderr will be fed to this var, so make sure to assign by ref)
//        stdout (stdout captured unless stderr set; set a boolean here to override default behaviour)
function timeout_exec($cmd, $timeout=10, &$stdout=null, $opts=array()) {
	$retries = 0;
	if(isset($opts['retries']))
		$retries = $opts['retries'];
	$env = null;
	if(isset($opts['env']))
		$env = $opts['env'];
	elseif(isset($opts['env+']))
		$env = array_merge($_ENV, $opts['env+']);
	
	$nocap = array('file', '/dev/null', 'w');
	$yescap = array('pipe', 'w');
	
	$get_stderr = array_key_exists('stderr', $opts);
	if(isset($opts['stdout'])) $get_stdout = $opts['stdout'];
	else $get_stdout = !$get_stderr;
	$proc_cap = array(
		0 => array('file', '/dev/null', 'r'),
		1 => $get_stdout ? $yescap : $nocap,
		2 => $get_stderr ? $yescap : $nocap
	);
	
	do {
		$retry = false;
		
		$proc = proc_open($cmd, $proc_cap, $pipes, @$opts['cwd'], $env);
		if(!$proc) {
			error('Failed to proc_open process! '.$cmd);
			return -1;
		}
		$cap_pipe = [];
		$out_data = [];
		if($get_stdout) {
			$cap_pipe[1] = $pipes[1];
			$out_data[1] =& $stdout;
		}
		if($get_stderr) {
			$cap_pipe[2] = $pipes[2];
			$out_data[2] =& $opts['stderr'];
		}
		unset($pipes);
		foreach($cap_pipe as $k => &$pipe) {
			$out_data[$k] = '';
			stream_set_blocking($pipe, 0);
		}
		
		$to = $timeout;
		while(1) {
			$status = proc_get_status($proc);
			if(!$status['running']) {
				$ret = $status['exitcode'];
				break;
			}
			foreach($cap_pipe as $k => &$pipe) while(1) {
				$chunk = fread($pipe, 262144); // 256K
				if($chunk !== false && $chunk !== '') {
					$out_data[$k] .= $chunk;
					if(strlen($out_data[$k]) > 96*1048576) { // 16M was too small for some mkvmerge --identify-verbose, have seen 32MB also blow out for `mkvmerge --identify`
						error('Process generated too much output! '.$cmd, 'timeout_exec');
						foreach($cap_pipe as &$_pipe)
							fclose($_pipe);
						_kill_proc($status['pid'], $proc);
						return -1;
					}
				}
				else break;
			}
			if($to-- <= 0) {
				// timeout
				foreach($cap_pipe as &$pipe)
					fclose($pipe);
				_kill_proc($status['pid'], $proc);
				
				if($retries) {
					info('Process timed out and killed after '.$timeout.'s! '.$cmd, 'timeout_exec');
					$retry = true;
					--$retries;
					sleep(15);
					break;
				} else {
					error('Process timed out and killed after '.$timeout.'s! '.$cmd, 'timeout_exec');
					return -1;
				}
			}
			sleep(1);
		}
	} while($retry);
	
	foreach($cap_pipe as $k => &$pipe) {
		$out_data[$k] .= stream_get_contents($pipe);
		fclose($pipe);
	}
	$retc = proc_close($proc);
	return isset($ret) ? $ret:$retc;
}
function _kill_proc($pid, $proc) {
	// graceful termination first
	exec('pkill -INT -P '.$pid);
	@proc_terminate($proc, 2); // SIGINT
	for($i=0; $i<10; ++$i) {
		$stat = proc_get_status($proc);
		if(!$stat['running']) break;
		sleep(1);
	}
	if($stat['running']) {
		exec('pkill -P '.$pid);
		@proc_terminate($proc);
	}
}
