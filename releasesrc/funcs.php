<?php

function markdown_to_desc($desc) {
	require_once ROOT_DIR.'3rdparty/Parsedown.php';
	$Parsedown = new Parsedown();
	$Parsedown->setSafeMode(true);
	// in Nyaa.si, it seems that all newlines are actually newlines, so fix that up
	$desc = preg_replace("~(?<!\n) *\n(?!\n)~", "  \n", str_replace("\r", '', $desc));
	// parse markdown
	$ret = $Parsedown->text($desc);
	// strip ignored spacing and newlines
	$ret = preg_replace('~\s+~', ' ', $ret);
	// add in requested newlines
	$ret = strtr($ret, ["\n"=>'',"\r"=>'','<br />'=>"\n",'<br>'=>"\n",'<br/>'=>"\n",'<p>'=>"\n\n",'</p>'=>'']);
	// headings are always on a new line
	$ret = preg_replace("~\n?\<h[1-6]\>~", "\n$0", $ret);
	// strip HTML/formatting
	$ret = trim(strip_tags($ret));
	// undo HTML escaping
	$ret = unhtmlspecialchars($ret);
	// grab just the first line
	if($p = strpos($ret, "\n"))
		$ret = substr($ret, 0, $p);
	// limit to 150 chars max
	if(isset($ret[150]))
		return '';
	return $ret;
}

function feed_change_preprocess($feed, $idstart, $tag='guid') {
	if(empty($feed)) return $feed;
	foreach($feed as $k => &$item) {
		$link = trim($item[$tag]);
		if(!preg_match('~^https?://[a-z.]+/(?:[a-z]+/|[a-z.]+\?id=)(\d+)(?:$|/)~', $link, $m))
			return false;
		$id = (int)$m[1];
		if($id >= $idstart)
			unset($feed[$k]);
		else {
			$item['id'] = $id;
			unset($item[$tag]);
		}
	}
	
	return $feed;
}
