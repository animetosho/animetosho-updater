<?php

if(!defined('DEBUG_MODE')) define('DEBUG_MODE', 0);

require_once __DIR__.'/filehandler.php';

abstract class uploader {
	protected $sitename = 'undefined';
	protected $cookie='';
	protected $ch = null;
	
	// for CPUs with AES-NI (particularly Silvermont), prefer AES128-GCM
	protected $ssl_ciphers = 'TLS_AES_128_GCM_SHA256:AES128-GCM-SHA256:ECDH-RSA-AES128-GCM-SHA256:ECDH-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES128-GCM-SHA256:DHE-DSS-AES128-GCM-SHA256:DHE-RSA-AES128-GCM-SHA256:AES128-SHA:PSK-AES128-CBC-SHA:ECDH-RSA-AES128-SHA:ECDH-ECDSA-AES128-SHA:ECDHE-RSA-AES128-SHA:ECDHE-ECDSA-AES128-SHA:DHE-RSA-AES128-SHA:DHE-DSS-AES128-SHA';
	protected $silence_error = false;
	
	private $haserror = null;
	protected $referer = null;
	private $copy_failed = false;
	
	private $db = null;
	
	// hooks
	public $ul_beforefile_func = null;
	public $ul_partdone_func = null;
	
	// number of per-part retries for upload_sockets
	public $upload_sockets_retries = 3;
	// number of retries for subchunks (enable if site supports subchunk resubmission)
	protected $upload_sockets_subchunk_retries = 0;
	// don't continue uploading file if a part breaks
	public $upload_sockets_break_on_failure = false;
	// send upload_sockets errors to a different info log (originally sent to delay log)
	public $upload_sockets_error_delay = '';
	// hack to allow file handler generation
	public $upload_sockets_file_wrapper = null;
	// hack to allow upload speed measurements
	public $upload_sockets_speed_rpt = null;
	
	// max subchunk size; 0 = disable
	protected $upload_sockets_subchunk_size = 0;
	
	
	const TIMEOUT = 14400; // 4 hours
	const USERAGENT = 'AnimeTosho Bot [https://animetosho.org/]';
	protected $SOCKETS_IGNORE_EMPTY_BODY = false;
	protected $SOCKETS_IGNORE_REPLY = false;
	protected $SOCKETS_END_TIMEOUT = 90;
	protected $SOCKETS_TIMEOUT = 60;
	protected $SOCKETS_OPTS = [
		'ssl' => [
			'verify_peer' => false,
			'verify_peer_name' => false,
			'disable_compression' => true
		]
	];
	
	// !! if the files are going to be split, there should be no duplicate file NAMES (not including path) in the $files array
	public function &upload($files) {
		@set_time_limit(self::TIMEOUT);
		$this->ch = $this->initCh();
		if(!$this->ch) return $this->ch;
		if(!is_array($files))
			$files = array($files => '');
		$ret = $this->_upload($files);
		$this->curl_close();
		return $ret;
	}
	
	abstract protected function _upload(&$files);
	
	protected function refreshCh() {
		if(!isset($this->ch) || !is_resource($this->ch))
			$this->ch = $this->initCh();
	}
	
	protected function &initCh($post=true) {
		$ch = curl_init();
		if(!$ch) return $this->error('Failed to initialise cURL.');
		curl_setopt($ch, CURLOPT_HEADER, false);
		// as we only use cURL for small fetches, put low timeouts
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		//curl_setopt($ch, CURLOPT_TIMEOUT, 3600*4);
		//curl_setopt($ch, CURLOPT_MUTE, true);
		//curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		//curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 64);
		//curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 45);
		//curl_setopt($ch, CURLOPT_ENCODING, '');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		if(defined('CURLOPT_SSL_VERIFYHOST'))
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, $this->ssl_ciphers);
		
		if($this->cookie)
			curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
		
		//curl_setopt($ch, CURLOPT_REFERER, substr($url, 0, strpos($url, '/', 8)+1));
		curl_setopt($ch, CURLOPT_USERAGENT, self::USERAGENT);
		
		//curl_setopt($ch, CURLOPT_COOKIEFILE, 'mfcookie.txt');
		//curl_setopt($ch, CURLOPT_COOKIEJAR, 'mfcookie.txt');
		
		curl_setopt($ch, CURLOPT_POST, $post);
		curl_setopt($ch, CURLOPT_NOPROGRESS, true);
		return $ch;
	}
	
	protected function error($msg, $l='') {
		$this->haserror = $msg;
		$this->curl_close();
		$this->ch = $this->initCh();
		if(!$this->silence_error) {
			// HACK: error redirection to location
			$GLOBALS['error_log_to'] = $l;
			trigger_error('Uploader: '.$msg, E_USER_WARNING);
			$GLOBALS['error_log_to'] = '';
		}
		return false;
	}
	public function getLastError() {
		$lasterror = $this->haserror;
		$this->haserror = null;
		return $lasterror;
	}
	protected function log_dump_data($data, $prefix='') {
		if(function_exists('log_dump_data'))
			return log_dump_data($data, $prefix);
		else {
			if(!$prefix) $prefix = get_class($this);
			$dumpfn = $prefix.'_dump_'.uniqid().'.html';
			if(is_string($data))
				file_put_contents($dumpfn, $data);
			else
				file_put_contents($dumpfn, serialize($data));
			return '  Data dumped to '.$dumpfn;
		}
	}
	protected static function info($s, $l='uploader') {
		if(function_exists('info')) info($s, $l, true);
		else echo 'Info: ', $s, "\n";
	}
	protected static function warning($s, $l='') {
		if(function_exists('warning')) warning($s, $l);
		else echo 'Warning: ', $s, "\n";
	}
	protected function uploader_error($s) {
		if($this->upload_sockets_error_delay)
			self::info($s, $this->upload_sockets_error_delay);
		else
			$this->error($s, 'uploader');
	}
	protected static function log_event($s) {
		if(function_exists('log_event')) log_event($s);
		if(DEBUG_MODE) echo "LOG: ",$s,"\n";
	}
	
	private function getReferer($url) {
		if(isset($this->referer)) return $this->referer;
		return substr($url, 0, strpos($url, '/', 8)+1);
	}
	
	// assumes $this->ch init'd
	protected function &curl_exec($url, $referer='') {
		curl_setopt($this->ch, CURLOPT_URL, $url);
		if($referer)
			curl_setopt($this->ch, CURLOPT_REFERER, $referer);
		else
			curl_setopt($this->ch, CURLOPT_REFERER, $this->getReferer($url));
		//curl_setopt($this->ch, CURLOPT_TIMEOUT, self::TIMEOUT);
		$data = curl_exec($this->ch);
		if(curl_errno($this->ch)) {
			$this->error('cURL error: '.curl_error($this->ch), 'curl');
			//$this->refreshCh();
		}
		return $data;
	}
	
	protected function curl_close() {
		if($this->ch === null) return;
		curl_close($this->ch);
		$this->ch = null;
	}
	
	// simple file_get_contents using cURL
	protected function &curl_get_contents($url, $header=false, $setfunc=null) {
		$this->curl_close();
		$this->ch = $this->initCh(false);
		if($header) {
			curl_setopt($this->ch, CURLOPT_HEADER, true);
			curl_setopt($this->ch, CURLOPT_AUTOREFERER, false);
			curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, false);
		}
		$this->silence_error = true;
		if(is_callable($setfunc)) {
			$referer = $setfunc($this->ch);
		}
		if(!@$referer) $referer = '';
		if(DEBUG_MODE) echo "REQUEST: $url\n";
		$data = $this->curl_exec($url, $referer);
		$this->silence_error = false;
		$this->curl_close();
		$this->refreshCh();
		if(!$data && $data !== '0') $data = '';
		if(DEBUG_MODE) echo "RESPONSE: ",substr($data, 0, 4096),"\n";
		return $data;
	}
	
	protected function get_filehandler($file) {
		if(is_string($file)) {
			if(isset($this->upload_sockets_file_wrapper)) {
				$func = $this->upload_sockets_file_wrapper;
				$tfile = $func($file);
				if(is_object($tfile))
					$file = $tfile;
				else {
					$file = new FileHandler($file);
					$file->set_readahead(1048576*32, 1048576*2);
				}
			}
			else {
				$file = new FileHandler($file);
				$file->set_readahead(1048576*32, 1048576*2);
			}
		}
		return $file;
	}
	
	protected function upload_sockets_sgl_wrapper($url, $file, $filefields, $splitsize, $postfields=array()) {
		self::log_event('Begin sockets uploading to '.$url);
		$this->getLastError(); // clear last error
		$file = $this->get_filehandler($file);
		$data = $this->upload_sockets($url, $file, $filefields, $splitsize, $postfields);
		self::log_event('Sockets uploading complete');
		if(empty($data)) {
			$this->uploader_error('Upload failed!');
			return false;
		}
		return $data;
	}
	
	private static function sockwrite($fp, $data) {
		if(DEBUG_MODE) echo "WRITE:\n", $data, "\n\n";
		return fwrite($fp, $data);
	}
	
	protected static function parse_url($url) {
		$purl = @parse_url($url);
		if(!isset($purl['path']) || $purl['path'] === '')
			$purl['path'] = '/';
		if(@$purl['query'])
			$purl['path'] .= '?'.@$purl['query'];
		if(!isset($purl['port'])) $purl['port'] = ($purl['scheme'] == 'https'?443:80);
		return $purl;
	}
	
	// Optimal socket-stream based uploader which allows on-the-fly file splitting
	// NOTE: returned data includes headers!
	// NOTE: if SOCKETS_IGNORE_EMPTY_BODY=false, this function will automatically error if empty body is returned (in other words, it assumes a body will always be returned; this is the case of MultiUpload sometimes not returning a body for some reason)
	// if $filefields is a string, this function will not use a typical POST multipart request, rather, will embed the file in the content-body; the string refers to the header name to put the filename into; the $postfields array is ignored in this instance
	private function upload_sockets($url, $file, $filefields, $split=0, $postfields=array()) {
		$purl = self::parse_url($url);
		
		
		$boundary = '--'.'----------------------------'.substr(md5(mt_rand()), 0, 12); // all instances of this need a preceeding two --'s
		$base_headers = array(
			'POST '.$purl['path'].' HTTP/1.1',
			'Host: '.$purl['host'],
			'User-Agent: '.self::USERAGENT,
			'Accept: */*',
			'Referrer: '.$this->getReferer($url),
			'Connection: close',
		);
		if(is_array($filefields))
			$base_headers[] = 'Content-Type: multipart/form-data; boundary='.substr($boundary, 2);
		else
			$base_headers[] = 'Content-Type: application/octet-stream';
		
		if($this->cookie)
			$base_headers[] = 'Cookie: '.$this->cookie;
		
		// quick dirty hack to reset variables (that can be affected by upload_sockets_setfields) on each retry loop
		$base_filefields = $filefields;
		$base_postfields = $postfields;
		$base_purl = $purl;
		
		bcscale(0);
		$ret = array();
		
		$fs = $file->size();
		$fn = $file->basename;
		$ffn = $file->name();
		if($split) {
			$parts = (int)bcdiv($fs, $split) +(bcmod($fs, $split) ? 1:0);
			// make parts relatively even in size
			$split = (int)bcdiv($fs, $parts) + (bcmod($fs, $parts) ? 1:0);
		} else {
			$parts = 1;
		}
		if($file->is_error()) return $this->error('Failed to open file '.$ffn);
		$this->upload_sockets_hook_beforefile($file, $parts);
		for($part=0; $part<$parts; ++$part) {
			self::log_event('Sockets uploading '.$ffn.' (part'.($part+1).' of '.$parts.')');
			$tries = $this->upload_sockets_retries;
			$cur_fpos = bcmul($split, $part);
			if($parts > 1)
				$base_fn_ext = $fn.'.'.str_pad($part+1, 3, '0', STR_PAD_LEFT);
			else
				$base_fn_ext = $fn;
			
			$chunk_size = 0;
			if($split) {
				if($part == $parts-1 && ($mod = bcmod($fs, $split))) // last part, use modulus
					// if modulus==0, implies that split fits perfectly in filesize, so add full split
					$chunk_size = $mod;
				else
					$chunk_size = $split;
			} else
				$chunk_size = $fs;
			
			
			while(true) { // retry loop
				$headers = $base_headers;
				$filefields = $base_filefields;
				$postfields = $base_postfields;
				$purl = $base_purl;
				$fn_ext = $base_fn_ext;
				
				$result = $this->upload_sockets_setfields($headers, $filefields, $postfields, $purl, $fn_ext, $chunk_size, $file, compact('part'));
				if(!$result) {
					unset($file);
					return $result;
				}
				// allow special wrappers for parts
				if(is_object($result))
					$filep = $result;
				else
					$filep = null;
				
				$file_mime = @$file->mime ?: 'application/octet-stream';
				
				if(is_array($filefields)) {
					// calculate stuff
					$body = '';
					foreach($postfields as $key => $value) {
						if(!is_array($value)) $value = array($value);
						foreach($value as $value_value)
							$body .= $boundary."\r\n"
									.'Content-Disposition: form-data; name="'.$key.'"'
									."\r\n\r\n"
									.$value_value."\r\n";
					} unset($value);
					$bodylen = strlen($body);
					
					// calculate overhead (header) length per file part
					$file_header_len = 0;
					foreach($filefields as $field => $value) {
						$field_i = str_replace('{i}', '0', $field);
						if($value === null)
							$file_header_len += strlen($boundary."\r\nContent-Disposition: form-data; name=\"{$field_i}\"; filename=\"\"\r\nContent-Type: {$file_mime}\r\n\r\n") +2 /*2 for trailing newline*/;
						else
							$file_header_len += strlen($boundary."\r\nContent-Disposition: form-data; name=\"{$field_i}\"\r\n\r\n".$value."\r\n");
						
					} unset($value);
					$content_length = $bodylen + $file_header_len + strlen($fn_ext) + strlen($boundary."--\r\n") /*footer*/;
				}
				else {
					if($filefields)
						$headers[] = $filefields.': '.rawurlencode($fn_ext);
					$content_length = 0;
					$body = '';
				}
				
				// insert server into our server list
				if(isset($this->db)) {
					$db_server = $purl['host'];
					if(isset($purl['port']) && $purl['port'] != ($purl['scheme'] == 'https'?443:80))
						$db_server .= ':'.$purl['port'];
					$this->db->insert('ulservers', array(
						'server' => $db_server,
						'host' => $this->sitename,
						'added' => time(),
					), false, true);
				}
				
				$real_chunk_size = $filep ? $filep->size() : $chunk_size;
				if(!$filep) $filep = $file; // simplify subsequent refs to be the same; past this point, there's no need to distinguish between the two
				$fr = null;
				try {
					$subchunks = 1;
					if($this->upload_sockets_subchunk_size)
						$subchunks = (int)bcdiv(bcadd($real_chunk_size, $this->upload_sockets_subchunk_size-1), $this->upload_sockets_subchunk_size);
					$sc_tries = $this->upload_sockets_subchunk_retries;
					for($subchunk=0; $subchunk<$subchunks; ++$subchunk) {
						$upload_size = $real_chunk_size;
						if($subchunks > 1) {
							$upload_size = $this->upload_sockets_subchunk_size;
							if($subchunk+1 == $subchunks)
								$upload_size = bcsub($real_chunk_size, bcmul($subchunk, $this->upload_sockets_subchunk_size));
						}
						$sc_fpos = $filep->tell();
						
						try {
							$sc_headers = $headers;
							$sc_purl = $purl;
							$result_err = $this->upload_sockets_pre_subchunk($sc_headers, $sc_purl, compact('part', 'fn_ext', 'chunk_size', 'upload_size', 'subchunk', 'subchunks', 'sc_fpos', 'cur_fpos'));
							if($result_err) throw new Exception('Failed pre-subchunk: '.$result_err);
							
							if($subchunks > 1) self::log_event('Sending subchunk '.$subchunk.'/'.$subchunks);
							
							$sockhost = ($sc_purl['scheme']=='https'?'tls://':'tcp://').$sc_purl['host'];
							self::log_event('Opening socket to '.$sockhost.':'.$sc_purl['port']);
							$context = stream_context_create($this->SOCKETS_OPTS);
							stream_context_set_option($context, 'ssl', 'ciphers', $this->ssl_ciphers);
							if(defined('STREAM_CRYPTO_METHOD_ANY_CLIENT'))
								stream_context_set_option($context, 'ssl', 'crypto_method', STREAM_CRYPTO_METHOD_ANY_CLIENT);
							if(!($fr = @stream_socket_client($sockhost.':'.$sc_purl['port'], $errno, $errstr, $this->SOCKETS_TIMEOUT, STREAM_CLIENT_CONNECT, $context))) {
								throw new Exception('Sockets error ('.$errno.'): '.$errstr);
							}
							
							@stream_set_timeout($fr, 90);
							if(function_exists('stream_set_chunk_size')) @stream_set_chunk_size($fr, 16384); // better than 8K
							
							$sc_headers[] = 'Content-Length: '.bcadd($content_length, $upload_size);
							$sc_headers[] = "\r\n";
							
							if(!@self::sockwrite($fr, implode("\r\n", $sc_headers) . $body)) {
								throw new Exception('Could not write to socket');
								//fclose($fr);
								//return $this->error('Could not write to socket.');
							}
							if(!@fflush($fr)) {
								//throw new Exception('Could not flush write to socket');
							}
							// write upload content
							// (we'll assume all write commands succeed)
							//@stream_set_timeout($fr, self::TIMEOUT);
							@stream_set_timeout($fr, 120);
							self::log_event('Headers sent, sending main data...');
							
							// write file content
							if(is_array($filefields)) {
								foreach($filefields as $field => $value) {
									$field_i = str_replace('{i}', 0, $field);
									if($value === null) {
										self::sockwrite($fr, $boundary."\r\nContent-Disposition: form-data; name=\"{$field_i}\"; filename=\"{$fn_ext}\"\r\nContent-Type: {$file_mime}\r\n\r\n");
										// just because PHP doesn't return refs for ?: operator...
										$this->upload_sockets_writecontent($upload_size, $filep, $fr);
										self::sockwrite($fr, "\r\n");
									} else {
										self::sockwrite($fr, $boundary."\r\nContent-Disposition: form-data; name=\"{$field_i}\"\r\n\r\n{$value}\r\n");
									}
								} unset($value);
								self::sockwrite($fr, $boundary."--\r\n");
							} else {
								$this->upload_sockets_writecontent($upload_size, $filep, $fr);
							}
							
							if($this->SOCKETS_IGNORE_REPLY) {
								self::log_event('All upload data sent');
								fflush($fr);
								self::log_event('[flushed]');
								@stream_set_timeout($fr, $this->SOCKETS_END_TIMEOUT);
								$data = @stream_get_contents($fr);
								self::log_event('Read response');
								fclose($fr); $fr = null;
								if(DEBUG_MODE) echo "RECV:\n", $data, "\n\n";
								$p = strpos($data, "\r\n\r\n");
							} else {
								self::log_event('All upload data sent, awaiting reply...');
								fflush($fr);
								self::log_event('[flushed]');
								@stream_set_timeout($fr, $this->SOCKETS_END_TIMEOUT);
								$data = @stream_get_contents($fr);
								self::log_event('Read response');
								if(empty($data)) throw new Exception('Received empty reply');
								fclose($fr); $fr = null;
								if(DEBUG_MODE) echo "RECV:\n", $data, "\n\n";
								// parse headers for empty response
								$errmsg = null;
								if(!($p = strpos($data, "\r\n\r\n")))
									$errmsg = 'Header endpoint not found';
								elseif(!$this->SOCKETS_IGNORE_EMPTY_BODY && (!isset($data[$p+4]) || stripos(substr($data, 0, $p+2), "\r\ncontent-length: 0\r\n")))
									$errmsg = 'Empty body returned';
								if(isset($errmsg)) {
									if(preg_match("~^HTTP/1\.[01] (5\d\d .+?)\r\n~", $data, $m))
										$errx = '; HTTP error: '.$m[1];
									elseif(preg_match("~^HTTP/1\.[01] (\d\d\d .+?)\n[a-zA-Z]~", $data, $m))
										$errx = ' (using Unix newlines); HTTP error: '.$m[1];
									else
										$errx = $this->log_dump_data($data, 'uploader_invhttpresp');
									throw new Exception($errmsg.$errx);
								}
							}
							
							if($verify_error = $this->upload_sockets_subchunk_verify($data, compact('filep', 'part', 'p', 'fn_ext', 'subchunk', 'subchunks')))
								throw new Exception('[subchunk-verification] '.$verify_error);
							
							$sc_tries = $this->upload_sockets_subchunk_retries;
						} catch(Exception $ex) {
							if(is_resource($fr)) fclose($fr);
							$fr = null;
							if($sc_tries-- >0) {
								self::info('Uploading '.$ffn.' (part'.($part+1).', subchunk'.$subchunk.', site='.$this->sitename.', host='.$purl['host'].') failed (error: '.$ex->getMessage().') - retrying...');
								$filep->seek($sc_fpos);
								--$subchunk;
								sleep(15 * ($this->upload_sockets_subchunk_retries-$sc_tries));
								continue;
							}
							throw $ex;
						}
					}
					if($verify_error = $this->upload_sockets_verify($data, $file, $part, $p, $fn_ext))
						throw new Exception('[verification] '.$verify_error);
					
					// record success to DB
					if(isset($this->db)) {
						$time = time();
						$ulupd = array(
							'lastused' => $time,
							'lastsuccess' => $time,
							'successes' => 'successes+1',
						);
						$this->db->update('ulservers', $ulupd, 'server='.$this->db->escape($db_server), true);
						$this->db->update('ulhosts', $ulupd, 'site='.$this->db->escape($this->sitename), true);
						// also clear out some failure entries
						$this->db->delete('ulserver_failures', 'host='.$this->db->escape($this->sitename).' AND server='.$this->db->escape($db_server), 2, 'dateline ASC');
					}
				} catch(Exception $ex) {
					if(is_resource($fr)) fclose($fr);
					// record failure to DB
					if(isset($this->db)) $this->record_server_failure($db_server);
					if($tries-- >0) {
						self::info('Uploading '.$ffn.' (part'.($part+1).', site='.$this->sitename.', host='.$purl['host'].') failed (error: '.$ex->getMessage().') - retrying...');
						$file->seek($cur_fpos);
						//--$part;
						sleep(15 * ($this->upload_sockets_retries-$tries));
						continue;
					}
					$this->uploader_error('Couldn\'t upload '.$fn_ext.' (part'.($part+1).', site='.$this->sitename.', host='.$purl['host'].')!  '.$ex->getMessage());
					$data = null;
					if($this->upload_sockets_break_on_failure)
						$failure_break = true;
				}
				$pn = ($parts==1 ? 0 : $part+1);
				$ret["$pn\0$ffn"] = $data;
				$this->upload_sockets_hook_partdone($file, $pn, $data);
				if(!DEBUG_MODE) sleep(5); // give some breathing room for the site?
				break;
			}
			if(isset($failure_break)) {
				// set remaining parts to be blank
				if($parts > 1) {
					++$part;
					while(++$part <= $parts)
						$ret["$part\0$ffn"] = null;
				}
				unset($failure_break); // don't carry this onto the next file
				break;
			}
		}
		unset($file);
		return $ret;
	}
	protected function upload_sockets_setfields(&$headers, &$filefields, &$postfields, &$purl, &$fn, $chunk_size, &$file) {
		return true;
	}
	protected function upload_sockets_pre_subchunk(&$headers, &$purl, $info) {
		return null;
	}
	protected function upload_sockets_subchunk_verify(&$data, $info) {
		return null;
	}
	protected function upload_sockets_verify(&$data, $file, $part, $header_pt, $fn) {
		return false;
	}
	protected function upload_sockets_hook_beforefile(&$file, $numparts) {
		if($this->ul_beforefile_func)
			call_user_func_array($this->ul_beforefile_func, array($file, $numparts));
	}
	protected function upload_sockets_hook_partdone(&$file, $part, &$data) {
		if($this->ul_partdone_func)
			call_user_func_array($this->ul_partdone_func, array($file, $pn, &$data));
	}
	protected function upload_sockets_writecontent($split, &$file, &$fr) {
		$this->copy_failed = false;
		// only way to detect errors
		if($split) {
			$cur_fpos = $file->tell();
			
			$copied = $this->stream_copy_to_stream_rate($file, $fr, $split, $cur_fpos);
			
			if(!$file->eof() && !$this->copy_failed) {
				$end_fpos = $file->tell();
				$cur_fpos = bcadd($cur_fpos, $split);
				if($cur_fpos != $end_fpos)
					$this->copy_failed = 'Not all input bytes copied to output.';
			}
		} else {
			$copied = $this->stream_copy_to_stream_rate($file, $fr);
			if(!$file->eof() && !$this->copy_failed) $this->copy_failed = 'Input file not at end of stream.';
		}
		// this place is most likely to break...?
		if(!$copied || $this->copy_failed) {
			throw new Exception('Connection broke; '.$this->copy_failed);
		}
	}
	public function upload_sockets_errhand($no, $str, $file=null, $line=0) {
		if(!error_reporting()) return false;
		$this->copy_failed = $str;
		return true;
	}
	
	// stream_copy_to_stream wrapper which detects errors and does min-rate checking
	// $offset is only used for reporting purposes
	private function stream_copy_to_stream_rate($file, $out, $limit=null, $offset=0) {
		$copied = 0;
		$chunksize = 1048576*2; // send 2M chunks, note PHP's mmap reverts to slow activity if size>4MB
		$chunktime = 120; // if 2M takes more than 120s to send ( <18KB/s ) -> consider slow
		$slowchunks = 10; // number of consecutive slow chunks before bailing (last 10M must be slow before we bail)
		$rptTime = 30; // send to reporting every 30s
		
		$speedRpt = @$this->upload_sockets_speed_rpt;
		$lastRptTime = 0;
		$lastchunk_slow = 0;
		$sendamt = $chunksize;
		$lastreadtime = 0;
		$lastTimeTaken = 0;
		while(1) {
			$readtime = microtime(true);
			if($lastreadtime) {
				$lastTimeTaken = $readtime-$lastreadtime;
				if(isset($speedRpt) && $lastTimeTaken && ($readtime - $lastRptTime) > $rptTime) {
					$speedRpt($sendamt / $lastTimeTaken, $offset);
					$offset = bcadd($offset, $sendamt);
					$lastRptTime = $readtime;
				}
			}
			if(isset($limit) && $limit < $sendamt) $sendamt = $limit;
			
			set_error_handler(array($this, 'upload_sockets_errhand'));
			$cur_copied = $file->copy_to($out, $sendamt);
			restore_error_handler();
			
			
			if(!$cur_copied) {
				if(!$this->copy_failed) $this->copy_failed = 'stream_copy_to_stream returned failure';
			} else {
				$meta = stream_get_meta_data($out);
				if(isset($meta['timed_out']) && $meta['timed_out']) {
					if(!$this->copy_failed) $this->copy_failed = 'stream_copy_to_stream timed out';
				}
			}
			
			if($lastTimeTaken > $chunktime && !$this->copy_failed) {
				if($lastchunk_slow >= $slowchunks)
					$this->copy_failed = 'send rate too slow - took '.$lastTimeTaken.'s to send last chunk';
				++$lastchunk_slow;
			} else
				$lastchunk_slow = 0;
			
			if($this->copy_failed) break;
			
			$copied += $cur_copied;
			if(isset($limit)) {
				$limit -= min($limit, $chunksize);
				if(!$limit) break;
			}
			if($file->eof()) break;
			$lastreadtime = $readtime;
		}
		return $copied;
	}
	
	// TODO: remove this function
	protected static function mb_basename($file) {
		$basefile = $file;
		$p = mb_strrpos($basefile, '/');
		if($p) $basefile = mb_substr($basefile, $p+1);
		return $basefile;
	}
	
	// TODO: remove this function
	protected static function filesize_big($file) {
		return filesize($file);
		/*
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
		*/
	}
	
	
	
	
	// commonly used stuff
	protected function merge_into_list(&$src, $fn, &$dest) {
		foreach($src as $partnum => &$links) {
			foreach($links as $service => &$link) {
				if($partnum) {
					if(!isset($dest[$fn][$service]))
						$dest[$fn][$service] = array();
					$dest[$fn][$service][$partnum] = $link;
				} else {
					// single file
					$dest[$fn][$service] = $link;
				}
			}
		}
	}
	
	protected static function sort_split_file_chunks(&$ret) {
		// sort split file chunks
		foreach($ret as &$links) {
			foreach($links as &$link) {
				if(is_array($link))
					ksort($link);
			}
		}
	}
	protected function _upload_simple(&$files, $split_size) {
		$ret = array();
		
		foreach($files as $file => &$desc) {
			self::log_event('Uploading file '.$file);
			$this->_upload_do_merge($ret, $file, $split_size, $desc);
		}
		
		self::sort_split_file_chunks($ret);
		return $ret;
	}
	protected function _upload_do_merge(&$ret, $file) {
		isset($ret[$file]) or $ret[$file] = array();
		$args = func_get_args();
		array_shift($args);
		if($data = call_user_func_array(array($this, 'do_upload'), $args)) {
			
			$r = array();
			foreach($data as $filprt => $data_s) {
				list($part, $fil) = explode("\0", $filprt);
				$result = $this->process_upload($data_s, $part ? $part-1 : 0);
				if(is_array($result))
					$r[$part] = $result;
				else {
					$this->uploader_error($result.' ('.$fil.')');
					return; // if uploader returns this, consider it to be a fatal error
				}
			}
			
			if(!empty($r)) $this->merge_into_list($r, $file, $ret);
		}
	}
	
	protected static function get_extension($fn) {
		if($p = strrpos($fn, '.'))
			return strtolower(substr($fn, $p+1));
		return '';
	}
	
	protected static function parse_setcookies_from_data($data) {
		$cookies = array();
		$data = preg_replace("~^HTTP/1\.[01] 100 Continue\r\n\r\n~", '', $data);
		if($p = strpos($data, "\r\n\r\n")) {
			$h = explode("\r\n", substr($data, 0, $p));
			array_shift($h);
			$headers = array();
			foreach($h as $header) {
				@list($name, $val) = array_map('trim', explode(':', $header, 2));
				if(strtolower($name) == 'set-cookie' && $val) {
					if($p = strpos($val, ';'))
						$val = trim(substr($val, 0, $p));
					unset($cv);
					@list($cn, $cv) = array_map('trim', explode('=', $val, 2));
					if(isset($cv))
						$cookies[$cn] = $cv;
				}
			}
		} else
			return null;
		return $cookies;
	}
	// the following probably doesn't work normally because cURL needs the cookie engine enabled (CURLOPT_COOKIEFILE="") for this to work
	protected function cookies_from_curl() {
		$ret = '';
		if($cookies = curl_getinfo($this->ch, CURLINFO_COOKIELIST)) {
			foreach($cookies as $cookieLine) {
				// Each line format: domain \t flag \t path \t secure \t expiration \t name \t value
				$parts = explode("\t", $cookieLine);
				$ret .= ($ret ? '; ':'') . $parts[5].'='.$parts[6];
			}
		}
		return $ret;
	}
	// set cookies from array
	protected function setCookies($cookies) {
		$this->cookie = '';
		foreach($cookies as $k=>$v)
			$this->cookie .= ($this->cookie ? '; ':'') . $k.'='.$v;
	}
	
	protected static function body_from_full_response($data) {
		if($p = strpos($data, "\r\n\r\n")) {
			return substr($data, $p+4);
		}
		return '';
	}
	
	
	public function setDb(&$db) {
		$this->db =& $db;
	}
	protected function select_server($default) {
		if(!isset($this->db)) return $default;
		
		$hostwhere = 'host='.$this->db->escape($this->sitename);
		// we only care about failures in the last 24 hrs
		$bad_servers = $this->db->selectGetAll('ulserver_failures', 'server', '('.$hostwhere.' AND dateline>='.(time() - 86400).')', 'server', array(
			'group' => 'server',
			'having' => 'COUNT(*)>3', // remembering that retries up the failure count
		));
		$dead_servers = $this->db->selectGetAll('ulservers', 'server', 'dead!=0 AND '.$hostwhere, 'server');
		$bad_servers = array_map('reset', array_merge($dead_servers, $bad_servers));
		// always prefer the default
		if($default && !in_array($default, $bad_servers)) {
			self::log_event('select_server using default of '.$default);
			return $default;
		}
		// otherwise, we need to find another suitable server - select the one used longest time ago
		// ($bad_servers is now guaranteed to be non-empty)
		$server = $this->db->selectGetField('ulservers', 'server', $hostwhere.' AND NOT server IN ('.implode(',', array_map(array($this->db, 'escape'), $bad_servers)).') AND dead=0', array('order' => 'lastused ASC'));
		
		if($server) {
			if($default)
				self::log_event('select_server replaced default '.$default.' with '.$server);
			else
				self::log_event('select_server selected '.$server);
		} else
			self::log_event('select_server forced to use default of '.$default);
		
		return ($server ?: $default); // fallback to default if we must
	}
	protected function record_server_failure($server) {
		if(!isset($this->db)) return;
		$time = time();
		$ulupd = array(
			'lastused' => $time,
			'lastfailure' => $time,
			'failures' => 'failures+1',
		);
		$this->db->update('ulservers', $ulupd, 'server='.$this->db->escape($server), true);
		$this->db->update('ulhosts', $ulupd, 'site='.$this->db->escape($this->sitename), true);
		// randomly prune ulserver_failures table
		if(!mt_rand(0,20))
			$this->db->delete('ulserver_failures', 'dateline<'.($time-86400));
		$this->db->insert('ulserver_failures', array(
			'server' => $server,
			'host' => $this->sitename,
			'dateline' => $time,
		));
		// TODO: consider when to mark as dead
	}
	protected function select_account() {
		// force selection of account to one only per instance of running script
		static $acc = null;
		if(!isset($acc)) {
			if(!isset($this->db)) return false;
			$acc = $this->db->selectGetArray('uploader_accounts', 'host='.$this->db->escape($this->sitename).' AND datestart<'.time(), 'cookie,info', array('order' => 'datestart DESC'));
			if(empty($acc)) {
				$acc = array('info' => false, 'cookie' => false);
				self::warning('Could not find valid account for '.$this->sitename);
			}
		}
		if($acc['cookie'])
			$this->cookie = $acc['cookie'];
		return $acc['info'];
	}
	
	// check whether a filesize matches that reported by a website
	// returns null if can't parse sizestr
	protected static function fuzzy_size_equal($size, $sizestr, $margin=0.05) {
		if(preg_match('~^([0-9.]+)\s*(b|bytes?|kb|mb|gb)$~i', $sizestr, $m)) {
			$sizecmp = (float)$m[1];
			switch(strtolower($m[2])) { // safe as long as we never exceed 2GB
				case 'gb': $sizecmp *= 1024;
				case 'mb': $sizecmp *= 1024;
				case 'kb': $sizecmp *= 1024;
			}
			if($sizecmp > 0) {
				return !(
					(($sizecmp < $size * (1-$margin)) || ($sizecmp > $size * (1+$margin))) &&
					abs($sizecmp-$size) > 1024 // always allow up to 1KB difference
				);
			} else
				return ($size < 1024 ? false : null); // we'll expect that sites at least will display 1KB
		}
		return null;
	}
	
	// handle simple chunked encoding issues
	protected static function json_from_resp($data) {
		if(preg_match('~\{.+\}~', $data, $m))
			return @json_decode($m[0]);
		return null;
	}
}

