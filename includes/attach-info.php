<?php

define('ATTACHMENT_OTHER', 0);
define('ATTACHMENT_SUBTITLE', 1);
define('ATTACHMENT_CHAPTERS', 2);
define('ATTACHMENT_TAGS', 3);

if(!function_exists('get_extension')) {
	function get_extension($file) {
		$ext = '';
		if($p = strrpos($file, '.')) {
			$ext = strtolower(substr($file, $p+1));
		}
		return $ext;
	}
}

// basic mapping
function mime_from_filename($name) {
	$ext = rtrim(get_extension($name));
	switch($ext) {
		// from https://stackoverflow.com/a/43322082
		case 'ttf': case 'sfnt': case 'otf': case 'woff': case 'woff2':
			return 'font/'.$ext;
		case 'ttc':
			return 'font/collection';
		case 'pfb': case 'pfm':
			return 'application/x-font-type1';
		case 'txt':
			return 'text/plain';
		case 'log':
		case 'nfo':
			return 'text/x-'.$ext;
		case 'py':
			return 'text/x-python';
		case 'rb':
			return 'text/x-ruby';
		case 'xml':
			// also 'text/xml'
			return 'application/xml';
		case 'vtt':
			return 'text/vtt';
		case 'htm': case 'html':
			return 'text/html';
		case 'jpg': case 'jpeg':
			return 'image/jpeg';
		case 'png':
		case 'webp':
		case 'avif':
		case 'bmp':
		case 'tiff':
			return 'image/'.$ext;
		case 'heic':
			return 'image/heif';
		case 'mkv':
			return 'video/x-matroska';
		case 'mp4':
			return 'video/mp4';
		case 'pdf':
			return 'application/pdf';
		case 'zip':
			return 'application/zip';
		case 'rar':
			return 'application/x-rar';
		case '7z':
			return 'application/x-7z-compressed';
		case 'srt':
			return 'application/x-subrip';
		case 'torrent':
			return 'application/x-bittorrent';
		case 'bat':
			return 'text/x-msdos-batch';
		default: return null; //'application/octet-stream';
	}
}

function _mime_map_known() {
	return [
		'', // reserve index 0
		'application/x-truetype-font',
		'application/x-font-opentype',
		'font/otf',
		'font/ttf',
		'application/x-font-ttf',
		'application/x-font-otf',
		'font/opentype',
		'font/truetype',
		'font/collection',
		'application/x-truetype-collection',
		'application/x-font',
		'application/octet-stream',
		'binary',
		'fontotf',
		'fontttf',
		'application/vnd.oasis.opendocument.formula-template',
		'application/vnd.ms-opentype',
		'application/font-sfnt',
		'application/font',
		'font/sfnt',
		'font/woff',
		'font/woff2',
		'application/zip',
		'application/rar',
		'application/x-rar',
		'application/vnd.rar',
		'application/x-7z-compressed',
		'application/x-stuffit',
		'text/plain',
		'text/x-log',
		'text/x-nfo',
		'text/x-python',
		'application/x-ruby',
		'application/xml',
		'text/xml',
		'text/html',
		'application/x-subrip',
		'image/jpeg',
		'image/png',
		'image/webp',
		'image/heif',
		'image/avif',
		'image/x-win-bitmap',
		'video/x-matroska',
		'video/mp4',
		'application/pdf',
		'application/x-dosexec',
		'application/x-ms-dos-executable',
		'application/x-ms-ne-executable',
		'text/vtt',
		'text/x-ssa',
		'application/x-true',
		'application/x-truetype',
		'application/x-truetyp',
		'application/x-font-truetype',
		'application/x-trutype-font',
		'application/x-truetye-font',
		'applicationx-truetype-font',
		'application/x-truetype-fon',
		'application/x-opentype-font',
		'font/x-truetype-font',
		'application/zlib',
		'application/xml-dtd',
		'application/x-bittorrent',
		'application/x-font-type1',
		'audio/x-hx-aac-adts',
		'application/x-wine-extension-ini',
		'text/x-script.python',
		'application/xhtml+xml',
		'text/x-msdos-batch',
		'image/tiff',
		'image/bmp',
		'image/x-ms-bmp',
		'audio/vnd.dolby.dd-raw',
		'video/mpeg',
		'image/gif',
		'text/rtf',
		'audio/midi',
		'video/mp2p',
		
		// not seen, but include in case [taken from https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/MIME_types/Common_types]
		'audio/aac',
		'image/apng',
		'video/x-msvideo',
		'application/vnd.amazon.ebook',
		'application/x-bzip',
		'application/x-bzip2',
		'application/x-cdf',
		'text/css',
		'text/csv',
		'application/msword',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.ms-fontobject',
		'application/epub+zip',
		'application/gzip',
		'application/x-gzip',
		'image/vnd.microsoft.icon',
		'text/javascript',
		'application/json',
		'audio/x-midi',
		'audio/mpeg',
		'application/vnd.oasis.opendocument.presentation',
		'application/vnd.oasis.opendocument.spreadsheet',
		'application/vnd.oasis.opendocument.text',
		'audio/ogg',
		'video/ogg',
		'application/ogg',
		'application/vnd.ms-powerpoint',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'application/rtf',
		'application/x-sh',
		'image/svg+xml',
		'application/x-tar',
		'video/mp2t',
		'application/vnd.visio',
		'audio/wav',
		'audio/webm',
		'video/webm',
		'application/vnd.ms-excel',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	];
}
function _mime_map_cat() {
	return [
		'A' => 'application/x-', 'a' => 'application',
		'F' => 'font/x-', 'f' => 'font',
		'I' => 'image/x-', 'i' => 'image',
		'T' => 'text/x-', 't' => 'text',
		'U' => 'audio/x-', 'u' => 'audio',
		'V' => 'video/x-', 'v' => 'video'
	];
}
function mime_unpack($mc) {
	if(is_int($mc)) {
		$knownmap = _mime_map_known();
		if($mc && isset($knownmap[$mc]))
			return $knownmap[$mc];
	} else {
		$catmap = _mime_map_cat();
		if(isset($catmap[$mc[0]]))
			return $catmap[$mc[0]].substr($mc, 1);
	}
	return null;
}
function mime_pack($mime) {
	if(empty($mime)) return null;
	$knownmap = array_flip(_mime_map_known());
	if(isset($knownmap[$mime]))
		return $knownmap[$mime];
	foreach(_mime_map_cat() as $k=>$v) {
		if(substr($mime, 0, strlen($v)) == $v)
			return $k.substr($mime, strlen($v));
	}
	return null; // can't pack
}

function unpack_attachment_info($type, $attach) {
	if(empty($attach)) return null;
	
	if($type == ATTACHMENT_OTHER && !isset($attach['mime'])) {
		if(isset($attach['m'])) {
			$attach['mime'] = mime_unpack($attach['m']);
			unset($attach['m']);
		} else
			$attach['mime'] = mime_from_filename($attach['name']);
	}
	return $attach;
}

function attachment_type_str($typeid) {
	static $attach_types = [
		ATTACHMENT_OTHER=>'other',
		ATTACHMENT_SUBTITLE=>'subtitle',
		ATTACHMENT_CHAPTERS=>'chapters',
		ATTACHMENT_TAGS=>'tags',
	];
	return @$attach_types[$typeid] ?: 'unknown';
}
