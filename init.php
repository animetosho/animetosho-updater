<?php

if(!defined('ROOT_DIR'))
	define('ROOT_DIR', dirname(__FILE__).'/');

$curtime = time();
//error_reporting(E_ALL);

mb_internal_encoding('UTF-8');
$locale = setlocale(LC_ALL, 'UTF-8', 'UTF8', 'en_US.UTF-8', 'en_US.utf8'); // for escapeshellarg()
putenv('LC_ALL='.$locale);

// load core stuff
@set_time_limit(0);
// fix annoying PHP date issues in strict mode...
date_default_timezone_set('UTC');
require_once ROOT_DIR.'includes/config.php';
require_once ROOT_DIR.'includes/db-toto.php';
function loadDb() {
	global $db, $config;
	$db = new toto_db($config['db']);
	if(!$db->testConnection()) {
		// don't need to explicitly put warnings - db class does it already
		exit;
	}
}


require_once ROOT_DIR.'funcs.php';


if(!function_exists('mb_basename')) {
	function mb_basename($file) {
		$basefile = $file;
		$p = mb_strrpos($basefile, '/');
		if($p) $basefile = mb_substr($basefile, $p+1);
		return $basefile;
	}
}
if(!function_exists('filesize_big')) {
	if(PHP_INT_MAX <= 2147483647) {
		function filesize_big($file) {
			$res = trim(@shell_exec('stat -c%s -- '.escapeshellarg($file)));
			if($res === '') {
				// weird issue where execution sometimes fails (out of memory?)
				// just try one more time
				sleep(5);
				$res = trim(shell_exec('stat -c%s -- '.escapeshellarg($file)));
				if($res === '')
					$res = filesize($file); // fallback
			}
			return $res;
		}
	} else {
		function filesize_big($file) {
			return filesize($file);
		}
	}
}

$error_log_to = '';
if(!defined('DEFAULT_ERROR_HANDLER')) {
	function my_error_handler($num, $str, $file=null, $line=0) {
		if(!(error_reporting() & $num)) {
			// suppressed error
			return false;
		}
		static $errtypes = null;
		if(!isset($errtypes)) {
			$errtypes = array(
				E_ERROR              => 'Error',
				E_WARNING            => 'Warning',
				E_PARSE              => 'Parsing Error',
				E_NOTICE             => 'Notice',
				E_CORE_ERROR         => 'Core Error',
				E_CORE_WARNING       => 'Core Warning',
				E_COMPILE_ERROR      => 'Compile Error',
				E_COMPILE_WARNING    => 'Compile Warning',
				E_DEPRECATED		 => 'Deprecated Warning',
				E_USER_ERROR         => 'User Error',
				E_USER_WARNING       => 'User Warning',
				E_USER_NOTICE        => 'User Notice',
				E_STRICT             => 'Runtime Notice',
				E_RECOVERABLE_ERROR  => 'Catchable Fatal Error',
			);
		}
		
		$bt = debug_backtrace();
		unset($bt[0]);
		if(count($bt) > 100) {
			$bt = array_merge(array_slice($bt, 0, 50), array('---'), array_slice($bt, -50));
		}
		$bts = $delim = '';
		foreach($bt as &$bti) {
			if($bti === '---') { // separator if backtrace is too big
				$bts .= ' ===>> ';
				continue;
			}
			$bts .= $delim;
			if(isset($bti['file']) && isset($bti['line']))
				$bts .= basename($bti['file']).'['.$bti['line'].'] ';
			$bts .= (isset($bti['class']) ? $bti['class'].'.':'').$bti['function'].'('.args_export($bti['args']).')';
			$delim = ' --> ';
		}
		
		log_error('PHP Error: '.$errtypes[$num].': '.$str.' in '.$file.' on line '.$line.')'."\n    Backtrace: $bts\n", $GLOBALS['error_log_to'], true);
		if(isset($GLOBALS['error_hook']) && $GLOBALS['error_hook'])
			$GLOBALS['error_hook']($num, $str);
		return false;
	}
	function args_export(&$a, $doarray=true) {
		$r = '';
		if(is_array($a)) foreach($a as &$v) {
			if($r) $r .= ', ';
			$r .= my_var_export($v, $doarray);
		}
		return $r;
	}
	function my_var_export(&$v, $doarray=true) {
		switch(gettype($v)) {
			case 'string':
				if(isset($v[30]))
					$r = substr($v, 0, 30).'...';
				else
					$r = $v;
				return "'".strtr($r, array('\\' => '\\\\', '\'' => '\\\''))."'";
			case 'boolean':
				return $v ? 'true' : 'false';
				break;
			case 'null': case 'NULL';
				return 'null';
				break;
			case 'object':
				return '(object)'.get_class($v);
				break;
			case 'array':
				if($doarray)
					return simple_array_export($v);
				else
					return 'array(...)';
			case 'resource':
			default: // ints, floats etc
				return (string)$v;
		}
	}
	function simple_array_export(&$a) {
		$r = '';
		foreach($a as $k => $v) {
			if($r) $r .= ', ';
			$r .= my_var_export($k) . '=>' . my_var_export($v, false);
		}
		return 'array('.$r.')';
	}
	set_error_handler('my_error_handler');
}

