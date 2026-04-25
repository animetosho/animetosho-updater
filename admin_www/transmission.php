<?php
$scriptName = basename(__FILE__);
define('THIS_SCRIPT', 'mod_www/'.$scriptName);
define('DEFAULT_ERROR_HANDLER', 1);
require './_base.php';


function apiErr($msg) {
	header('HTTP/1.1 400 Bad Request');
	die($msg);
}
function rpcErr($msg) {
	die(json_encode(array(
		'result' => $msg
	)));
}

$httpOpt = array(
	'user_agent' => 'AT Transmission Proxy',
	'ignore_errors' => true, // handle errors ourself
	'header' => 'Authorization: Basic '.base64_encode('anito:'.TRANSMISSION_PASSWORD)."\r\n",
	'follow_location' => 0
);
$httpOpt['method'] = $_SERVER['REQUEST_METHOD'];

if(strtoupper($_SERVER['REQUEST_METHOD']) == 'POST') {
	$input = file_get_contents('php://input');
}
if(@$_SERVER['HTTP_X_TRANSMISSION_SESSION_ID']) {
	$httpOpt['header'] .= 'X-Transmission-Session-Id: '.urlencode($_SERVER['HTTP_X_TRANSMISSION_SESSION_ID'])."\r\n";
}

// determine URL to proxy to
/*if(($p = strpos($_SERVER['REQUEST_URI'], '/'.$scriptName)) === false)
	apiErr('Internal error');
$url = substr($_SERVER['REQUEST_URI'], $p+strlen($scriptName)); // include forward slash
*/
if(substr($_SERVER['REQUEST_URI'], 0, 14) != '/transmission/')
	apiErr('Invalid request');
$url = $_SERVER['REQUEST_URI'];
//$url = (string)@$_GET['_url'];
//if(@$url[0] != '/') $url = '/'.$url;



if(substr($url, 0, 17) == '/transmission/rpc') {
	$isRPC = true;
	if(empty($input))
		rpcErr('No input sent to RPC!');
	
	// unpack request here
	$rpcIn = @json_decode(trim($input), true);
	if(empty($rpcIn))
		rpcErr('Invalid RPC request');
	if(!in_array($rpcIn['method'], array(
		'session-get', 'session-stats', // TODO: block session-stats?
		'torrent-start', 'torrent-start-now', 'torrent-stop', 'torrent-verify', 'torrent-reannounce',
		'queue-move-top', 'queue-move-up', 'queue-move-down', 'queue-move-bottom',
		'torrent-set', 'torrent-get',
		//'free-space' // ???
	))) {
		rpcErr('Method not implemented');
	}
	// block: torrent-add, torrent-remove, torrent-set-location, torrent-rename-path, session-set, blocklist-update, port-test, session-close
} else
	$isRPC = false;


$httpOpt['content'] = $input;



$ctx = stream_context_create(array('http' => $httpOpt));
if(!($fp = @fopen('http://127.0.0.1:9881'.$url, 'r', false, $ctx))) {
	apiErr('Unable to establish connection to Transmission!');
}

$data = stream_get_contents($fp);
$meta = stream_get_meta_data($fp);
fclose($fp);

if(@$meta['timed_out'])
	apiErr('Connection to Transmission timed out!');


// intercept stuff here (we only care about JSON results, stuff like 409's, just pass thru)
if($isRPC && preg_match('~^\s*\{.*\}\s*$~s', $data)) {
	
	$rpcOut = @json_decode(trim($data), true);
	if(empty($rpcOut))
		rpcErr('Unable to comprehend RPC response.');
	
	if($rpcOut['result'] == 'success' && !empty($rpcOut['arguments'])) {
		$arg =& $rpcOut['arguments'];
		switch($rpcIn['method']) {
			// hide stuff we don't want to show
			case 'session-get':
				unset($arg['config-dir'], $arg['download-dir'], $arg['incomplete-dir'], $arg['script-torrent-done-filename']);
			break;
			case 'torrent-get':
				unset($arg['location']);
			break;
		} unset($arg);
		$data = json_encode($rpcOut);
	}
}

foreach(@$meta['wrapper_data'] ?: array() as $header) {
	if(preg_match('~^(content-type|content-encoding|date|server|x-transmission-session-id)\:~i', $header) || strtolower(substr($header, 0, 5)) == 'http/')
		header($header);
}

echo $data;
