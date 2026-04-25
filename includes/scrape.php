<?php
if(!function_exists('SCRAPE_DBG')) {
	function SCRAPE_DBG($msg, $data=null) {}
}

function proto_err($msg, $tracker, $lvl='warning') {
	$lvl($msg.' (url='.$tracker.')', 'scrape');
	return false;
}
require_once ROOT_DIR.'3rdparty/BDecode.php';
function scrape($tracker, $hashes) {
	$purl = parse_url($tracker);
	
	if(!isset($purl['scheme']))
		return proto_err('No scheme supplied', $tracker);
	
	if($purl['scheme'] == 'http' || $purl['scheme'] == 'https') {
		$url = preg_replace('~/announce([^/]*)$~', '/scrape$1', $tracker);
		if($url == $tracker)
			// scrape not supported; TODO: fallback to announce?
			return proto_err('HTTP tracker does not support scrape', $tracker);
		
		$data = send_request($url.'?info_hash='.implode('&info_hash=', array_map('rawurlencode', $hashes)));
		if(!$data) {
			info('Scrape to '.$tracker.' returned no data', 'scrape', true);
			return false;
		}
		
		$ret = BDecode($data);
		if(!isset($ret)) {
			SCRAPE_DBG('Response:', $data);
			return proto_err('HTTP tracker no BEncoded response', $tracker);
		} else
			SCRAPE_DBG('Decoded response:', $ret);
		if(empty($ret['files'])) {
			if(isset($ret['failure reason']))
				warning('HTTP tracker error (url='.$tracker.'): '.$ret['failure reason'], 'scrape');
			else
				warning('Unexpected result format from HTTP tracker', 'scrape');
			return false;
		}
		if(!is_array($ret['files'])) return array(); // Nyaa tracker returns blank array if none are valid
		$bad_hash = false;
		foreach(array_keys($ret['files']) as $hash)
			if(strlen($hash) != 20) {
				// have seen a tracker decide to URL encode hashes
				if(strlen($hash) > 20 && strpos($hash, '%') !== false) {
					$fixed_hash = rawurldecode($hash);
					if(strlen($fixed_hash) == 20) {
						$ret['files'][$fixed_hash] = $ret['files'][$hash];
						unset($ret['files'][$hash]);
						continue;
					}
				}
				$bad_hash = true;
				break;
			}
		if($bad_hash) {
			warning('Bad hash lengths found in scrape response (url='.$tracker.')'.log_dump_data($data, 'scrape'), 'scrape');
		}
		return $ret['files'];
	}
	elseif($purl['scheme'] == 'udp') {
		if(!@$purl['host']) return false;
		if(!@$purl['port'])
			warning('UDP tracker does not supply port, defaulting to 80: '.$tracker, 'scrape');
		$purl['port'] = @$purl['port'] ?: 80; // TODO: should port 80 be default?
		
		// can check if scrape is supported from URL??
		
		
		$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		if(!$socket) return proto_err('Failed to create socket', $tracker);
		
		socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 60, 'usec' => 0));
		socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 60, 'usec' => 0));
		
		$send = function($buf) use(&$socket, $purl) {
			return @socket_sendto($socket, $buf, strlen($buf), 0, $purl['host'], $purl['port']);
		};
		$recv = function() use(&$socket) {
			$buf = '';
			if(!socket_recv($socket, $buf, 4096, MSG_WAITALL)) return false;
			return $buf;
		};
		
		// handshake
		$trans_id = openssl_random_pseudo_bytes(4);
		if(!$send(hex2bin('000004172710198000000000').$trans_id)) return proto_err('Failed to send connect packet', $tracker, 'info');
		$ret = $recv();
		if(!$ret || strlen($ret) < 16) return proto_err('Failed to receive connect response', $tracker, 'info');
		if(substr($ret, 0, 8) != "\0\0\0\0".$trans_id) return proto_err('Invalid connect response', $tracker); // protocol violation
		$conn_id = substr($ret, 8, 8);
		
		// scrape req
		$trans_id = openssl_random_pseudo_bytes(4);
		if(!$send($conn_id."\0\0\0\x2".$trans_id.implode('', $hashes))) return proto_err('Failed to send scrape packet', $tracker);
		$ret = $recv();
		if(!$ret || strlen($ret) < 8) return proto_err('Failed to receive scrape response', $tracker);
		if(substr($ret, 0, 8) == "\0\0\0\x2".$trans_id) {
			// parse response
			$len = count($hashes) * 12;
			if(strlen($ret) < $len) return proto_err('Scrape response invalid length', $tracker);
			
			$vals = unpack('N*', substr($ret, 8, $len));
			$ret = array();
			$i = 0;
			foreach($hashes as $hash) {
				$ret[$hash] = array(
					'complete' => $vals[++$i],
					'downloaded' => $vals[++$i],
					'incomplete' => $vals[++$i]
				);
			}
			return $ret;
		} elseif(substr($ret, 0, 8) == "\0\0\0\x3".$trans_id) {
			warning('UDP tracker error (url='.$tracker.'): '. substr($ret, 8), 'scrape');
			return false;
		} else
			return proto_err('Scrape response invalid', $tracker);
		
	}
	elseif($purl['scheme'] == 'ws' || $purl['scheme'] == 'wss') {
		// TODO: support Webtorrent
		return proto_err('Unspported protocol', $tracker);
	}
	else {
		// unsupported protocol
		return proto_err('Unspported protocol', $tracker);
	}
}
