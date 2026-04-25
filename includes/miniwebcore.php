<?php

define('HOME_URL', 'https://animetosho.org');

class AT
{
	public static function buildUrl($action='', $subaction='', $ex_args=array(), $htmlspecialchar=true) {
		$url = '/';
		if($action=='home') $action = ''; // direct all links to home to the same URL
		if($action !== '') {
			$url .= rawurlencode($action);
			if($subaction !== '') {
				$sa = rawurlencode($subaction);
				// for some reason, mod_rewrite chokes on %2f
				$sa = strtr($sa, array('%2f' => '/', '%2F' => '/'));
				$url .= '/'.$sa;
			}
		}
		if(!empty($ex_args)) {
			$a = '';
			foreach($ex_args as $k => $v) {
				// paging hack
				if($k == 'page') {
					if($v == 1) continue;
					if($v == -1) // force page 1 to display (unnecessary for multipage though)
						$v = 1;
				}
				$a .= '&'. rawurlencode($k) .'='. rawurlencode($v);
			}
			if($a) $a[0] = '?';
			$url .= $a;
		}
		if($htmlspecialchar)
			$url = htmlspecialchars($url);
		return HOME_URL.$url;
	}
	
	private static function totoId($toto) {
		if($toto['tosho_id']) return $toto['tosho_id'];
		if($toto['nyaa_id']) return (@$toto['nyaa_subdom']?'s':'n').$toto['nyaa_id'];
		if($toto['anidex_id']) return 'd'.$toto['anidex_id'];
		if($toto['nekobt_id']) return 'k'.$toto['nekobt_id'];
		if(isset($toto['toto_id'])) // ugly workaround
			return 'a'.$toto['toto_id'];
		return 'a'.$toto['id'];
	}
	
	public static function viewUrl($toto, $args=array(), $perma=false) {
		$id = self::totoId($toto);
		if(!$perma)
			$id = self::seoPageSubaction($id, $toto['name']);
		return self::buildUrl('view', $id, $args);
	}
	public static function viewSubaction($toto) {
		$id = self::seoPageSubaction(self::totoId($toto), $toto['name']);
		return $id;
	}
	
	// grabs the subaction for a thing - only used for view.php in stdMultipageHandler
	public static function seoPageSubaction($id, $title) {
		$title = self::urlTitle($title);
		if($title !== '')
			return $title.'.'.$id;
		return $id;
	}
	
	private static function urlTitle($title)
	{
		if(!defined('AT_SEO_MAX_URL_KEYWORDS'))
			define('AT_SEO_MAX_URL_KEYWORDS', 8);
		if(!defined('AT_SEO_MAX_URL_LEN'))
			define('AT_SEO_MAX_URL_LEN', 60);
		$title = preg_replace('#&([0-9a-zA-Z]{2,8}?);#', '', $title); // remove entites
		$title = str_replace(array('\'', '!', '"', '#', '$', '%', '*', '?', '`'), '', $title);
		$title = str_replace(array(' ', '&', '(', ')', '+', ',', '.', '/', ':', ';', '<', '=', '>', '@', '[', '\\', ']', '^', '{', '|', '}', '~'), '-', $title);
		// remove any other undesirable chars + double spacing
		$title = preg_replace(array('#[^0-9a-zA-Z\-_]#', '#-{2,}#'), array('', '-'), $title);
		if($title === '') return '';
		// remove spaces at beginning or end
		if($title[0] == '-') $title = substr($title, 1);
		if(substr($title, -1) == '-') $title = substr($title, 0, -1);
		$title = self::stripCommonWords($title, '-');
		if($title === '') return '';
		if(AT_SEO_MAX_URL_KEYWORDS)
			if(substr_count($title, '-')-1 > AT_SEO_MAX_URL_KEYWORDS)
			{
				$kw = array_unique(explode('-', $title));
				$kw = array_slice($kw, 0, AT_SEO_MAX_URL_KEYWORDS);
				$title = implode('-', $kw);
			}
		if(AT_SEO_MAX_URL_LEN && strlen($title) > AT_SEO_MAX_URL_LEN)
		{
			$kw = array_unique(explode('-', $title));
			$newtitle = $delim = '';
			foreach($kw as &$w) {
				if(strlen($newtitle) + strlen($w) + strlen($delim) <= AT_SEO_MAX_URL_LEN)
					$newtitle .= $delim.$w;
				else
					break;
				if(!$delim) $delim = '-';
			}
			if($newtitle)
				$title = $newtitle;
			else
				$title = substr($title, 0, AT_SEO_MAX_URL_LEN);
		}
		$title = strtolower($title);
		return $title;
	}
	
	private static function stripCommonWords($str, $delim = ' ')
	{
		$splitstr = explode($delim, $str);
		$common_words = array(
			'the' => 1,		'of' => 1,		'to' => 1,		'and' => 1,		'this' => 1,
			'in' => 1,		'is' => 1,		'it' => 1,		'you' => 1,		'that' => 1,
			'was' => 1,		'for' => 1,		'on' => 1,		'are' => 1,		'with' => 1,
			'as' => 1,		'i' => 1,		'be' => 1,		'at' => 1,		'have' => 1,
			'a' => 1,		'or' => 1,		'had' => 1,		'by' => 1,		'but' => 1,
			'what' => 1,	'some' => 1,	'we' => 1,		'can' => 1,		'were' => 1,
			'there' => 1,	'how' => 1,		'an' => 1,		'do' => 1,		'if' => 1,
			'would' => 1,	'so' => 1,
		);
		foreach($splitstr as $i => &$word)
			if($word && isset($common_words[strtolower($word)]))
				unset($splitstr[$i]);
		
		return implode($delim, $splitstr);
	}
	
	
}
