<?php

require_once dirname(__FILE__).'/baseclass.php';

trait ReuploadCommon {
	// this must be only used on a class that inherits the base uploader
	
	/* properties that the using class must define:
	protected $host = '';
	protected $find_server_suffix = '';
	protected $upload_script = '/cgi-bin/upload.cgi';
	protected $upload_proto = 'http';
	protected $reg_username = '';
	protected $reg_sess = '';
	*/
	protected function select_account() {
		$info = parent::select_account();
		if(!$info || !strpos($info, '|')) return false;
		list($this->reg_username, $this->reg_sess) = explode('|', $info);
		$this->cookie = 'lang=english; login='.$this->reg_username.'; xfss='.$this->reg_sess;
		
		return true;
	}
	
	protected function default_server() {
		return false;
	}
	
	protected function find_server() {
		for($i=0; $i<3; ++$i) {
			$data = $this->curl_get_contents($this->site_proto.'://'.$this->host.'/'.$this->find_server_suffix);
			if($data) break;
			sleep(($i+1)*10);
		}
		
		if($data && preg_match('~action\="(https?)\://([a-z0-9\\-.]+(?:\:\d+)?)((?:/[a-z0-9/\\-_]*?)?)'.preg_quote($this->upload_script).'\?upload_id\="~i', $data, $m)) {
			$this->upload_proto = strtolower($m[1]);
			$this->tmp_path = $m[3];
			return $this->select_server($m[2]);
		} elseif($data && preg_match('~action\="(https?)\://([a-z0-9\\-.]+(?:\:\d+)?)((?:/[a-z0-9/\\-_]+?)?)(/cgi-bin/[a-z0-9]+/upload\.cgi)\?upload_id\="~i', $data, $m)) { // 180upload likes to change its URL at times, so see if we can detect this
			$this->upload_script = $m[4];
			$this->upload_proto = strtolower($m[1]);
			if(preg_match('~\<input type\="hidden" name\="srv_tmp_url" value\="(https?)\://([a-z0-9\\-.]+(?:\:\d+)?(?:/[a-z0-9/\\-_]+?)?)(/[a-z0-9]+/tmp)"\>~', $data, $m2))
				$this->tmp_path = $m2[3];
			else
				$this->tmp_path = $m[3];
			return $this->select_server($m[2]);
		} else {
			if(preg_match('~\<title\>\s*(403 Forbidden|500 Internal Server Error|504 Gateway Time-out)\s*\</title\>~i', $data, $m))
				$this->info('HTTP '.$m[1], 'reupload');
			elseif(strpos($data, '<h1>Software error:</h1>') !== false)
				$this->info('Some Software error', 'reupload');
			elseif($data == 'setuid() failed!')
				$this->info('Reupload setuid() failed', 'reupload');
			elseif(strpos($data, 'Your IP address has been banned from accessing our site for uploading file(s) that violate our TOS.')) {
				$this->info('IP banned', 'reupload');
				return false;
			}
			elseif(strpos($data, 'action="http://jumbofiles.com/login.html"')) {
				$this->info('JumboFiles account removed?', 'reupload');
				return false;
			}
			elseif(strpos($data, 'We\'re sorry, due to capacity problems there are no servers available for upload at the moment.')) {
				$this->info('ZomgUpload out of capacity', 'reupload');
				return false;
			}
			elseif(strpos($data, '<h1>503 Service Unavailable</h1>')) // ShareBeast sends this
				$this->info('503 error', 'reupload');
			elseif(strpos($data, '<h1>502 Bad Gateway</h1>')) // ShareBeast sends this
				$this->info('502 error', 'reupload');
			elseif(strpos($data, '<h1>504 Gateway Time-out</h1>')) // ShareBeast sends this
				$this->info('504 error', 'reupload');
			elseif(strpos($data, '<div class="cf-error-footer cf-wrapper">') && preg_match('~\<title\>[^|]+ \| (5\d\d\: [^<]+)\</title\>~', $data, $m))
				$this->info('CloudFlare error: '.$m[1], 'reupload');
			elseif(strpos($data, 'We\'re sorry, there are no servers available for upload at the moment.<br>Refresh this page in some minutes.')) { // FileWinds & Uppit sends this
				$this->info('Servers unavailable', 'reupload');
				return false;
			}
			
			// weird UpToBox responses:
			elseif(strpos($data, '<head><title>302 Found</title></head>'))
				$this->info('UpToBox 302 response', 'reupload');
			elseif($data === "</p>\n")
				$this->info('UpToBox P response', 'reupload');
			elseif(strpos($data, 'Our website is currently undergoing maintenance and will be back online shortly !'))
				$this->info('UpToBox maintenance page', 'reupload');
			elseif(strpos($data, 'We\'re sorry, there are no servers available for upload at the moment.<br />Refresh this page in some minutes.')) {
				$this->info('UpToBox servers busy', 'reupload');
				return false;
			}
			
			// DailyUploads
			elseif($data == 'error code: 522')
				$this->info('DailyUploads 522', 'reupload');
			elseif($data == 'error code: 502')
				$this->info('Uppit 502', 'reupload');
			elseif(strpos($data, '<title>Just a moment...</title>'))
				$this->info('CloudFlare wait page', 'reupload');
			
			elseif($data)
				$this->info('Couldn\'t find server from home page [DEBUG: '.preg_match('~action\="(https?)\://([a-z0-9\\-.]+(?:\:\d+)?(?:/[a-z0-9/\\-_]*?)?)'.preg_quote($this->upload_script).'\?upload_id\="~i', $data).', '.$this->upload_script.']'.$this->log_dump_data($data, $this->sitename.'_init'), 'reupload_server');
			else
				$this->info('Couldn\'t get response from home page', 'reupload');
			return $this->default_server();
		}
	}
	
	private function initRegCookie() {
		if(!$this->cookie && $this->reg_username) {
			$this->cookie = 'lang=english; login='.$this->reg_username.'; xfss='.$this->reg_sess;
		}
	}
	
	protected function verify_upload_result(&$data) {
		if(stripos($data, '<BODY><b>ERROR: Server don\\\'t allow uploads at the moment</b></BODY>'))
			return 'Host not accepting uploads.';
		if(stripos($data, 'CGI open of tmpfile: No such file or directory') || stripos($data, 'CGI open of tmpfile: No space left on device'))
			return 'CGI tmpfile error.';
		if(stripos($data, 'Undefined subroutine &amp;XUpload::xmessage called at Modules/XUpload.pm'))
			return 'XUpload::xmessage undefined.';
		if(preg_match('~\<head\>\s*\<title\>([45][0-9][0-9] [^<]+)\</title\>\s*\</head\>.*?\<center\>\<h1\>\s*\\1\s*\</h1\>\</center\>~si', $data, $m))
			return 'HTTP '.$m[1];
		if(preg_match('~\<head\>\s*\<title\>([45][0-9][0-9] ([^<]+))\</title\>\s*\</head\>.*?\<h1\>\s*\\2\s*\</h1\>~si', $data, $m))
			return 'HTTP '.$m[1];
		if(preg_match('~^HTTP/1\.1 ([45]\d\d) ~', $data, $m)) {
			return 'HTTP '.$m[1];
		}
		
		if(preg_match('~\<textarea name\=\'fn\'\>([a-z0-9_\-+=]{8,16})\</textarea\>~i', $data))
			return false;
		if(preg_match('~\<textarea name\=\'fn\'\>([^<]+)\</textarea\>~i', $data)) {
			if(preg_match('~\<textarea name\=\'st\'>([^<]+)\</textarea\>~', $data, $m))
				return 'Reupload error: '.$m[1];
			return 'Weird DDLAnime result with file name and no ID';
		}
		if(strpos($data, '<textarea name=\'op\'>upload_result</textarea>'))
			return 'Weird ShareBeast result with no file info';
		if(strpos($data, '<b>ERROR: Max filesize limit exceeded! Filesize limit: 10 Mb</b>')) // sent by FileWinds and ShareBeast - it seems that only some servers send this (misconfiguration?)
			return 'Weird max size = 10MB error';
		if(strpos($data, "ERROR: Server don't allow uploads at the moment"))
			return 'DailyUploads temporarily rejecting uploads';
		
		return 'File ID not specified.'.$this->log_dump_data($data, $this->sitename);
	}
	protected function upload_get_url($data) {
		if(!preg_match('~\<textarea name\=\'fn\'\>([a-z0-9_\-+=]{8,16})\</textarea\>~i', $data, $match)) {
			return false;
		}
		return $this->site_proto.'://'.$this->host.'/'.$match[1];
	}
}


// TODO: sometimes we should use the default server if the custom one doesn't accept uploads
abstract class uploader_Reupload_Base extends uploader {
	use ReuploadCommon;
	protected $host = '';
	//protected $default_upload_subdomain = '';
	protected $find_server_suffix = '';
	protected $upload_script = '/cgi-bin/upload.cgi';
	protected $site_proto = 'http';
	protected $upload_proto = 'http';
	protected $tmp_path = '/tmp';
	protected $max_size = 0;
	protected $reg_username = '';
	protected $reg_sess = '';
	protected $hide_desc = false; // true if the description should not be set
	
	function __construct() {
		$this->initRegCookie();
	}
	
	protected function _upload(&$files) {
		return $this->_upload_simple($files, 0);
	}
	
	protected function upload_sockets_setfields(&$headers, &$filefields, &$postfields, &$purl, &$fn, $chunk_size, &$file) {
		//if($this->reg_username)
		//	$headers[] = 'Cookie: lang=english; login='.$this->reg_username.'; xfss='.$this->reg_sess;
		$server = $this->find_server();
		if(!$server)
			return $this->uploader_error('Cannot find a working reUpload server ('.$this->host.')');
		
		$postfields['srv_tmp_url'] = $this->upload_proto.'://'.$server.$this->tmp_path;
		$path = $this->upload_script.'?upload_id='.mt_rand(100000,999999).mt_rand(100000,999999).'&js_on=1&utype='.($this->reg_username ?'reg':'anon').'&upload_type=file';
		$headers[0] = 'POST '.$path.' HTTP/1.1';
		$purl['scheme'] = $this->upload_proto;
		if(preg_match('~^(.+)\:(\d+)$~', $server, $m)) {
			$purl['host'] = $m[1];
			$purl['port'] = (int)$m[2];
		} else {
			$purl['host'] = $server;
			if($purl['scheme'] == 'https' && $purl['port'] == 80)
				$purl['port'] = 443;
		}
		$headers[1] = 'Host: '.$server;
		return true;
	}
	
	protected function upload_sockets_verify(&$data, $file, $part, $header_pt, $fn) {
		return $this->verify_upload_result($data, $file, $part, $header_pt, $fn);
	}
	
	// interesting how this script appears to be very similar to ZomgUpload
	protected function do_upload($file, $splitsize=0, $desc='') {
		// host needs to be grabbed from the homepage
		return $this->upload_sockets_sgl_wrapper($this->upload_proto.'://'.$this->host.$this->upload_script.'?upload_id='.mt_rand(100000,999999).mt_rand(100000,999999).'&js_on=1&utype='.($this->reg_username ?'reg':'anon').'&upload_type=file', $file, array('file_0' => null), $this->max_size, array(
			'upload_type' => 'file',
			'sess_id' => $this->reg_sess,
			'srv_tmp_url' => $this->upload_proto.'://'.$this->host.$this->tmp_path,
			// 'file_1' => <some blank file>,
			'file_0_descr' => $this->hide_desc ? '' : $desc,
			'file_0_public' => '1', // only seems to be used by ShareBeast
			'link_rcpt' => '',
			'link_pass' => '',
			'tos' => '1',
			'submit_btn' => ' Upload! ',
		));
	}
	
	protected function process_upload(&$data) {
		if(!isset($data))
			// uploading of this part failed - put in dummy link
			return array('RU' => null);
		
		if(!($url = $this->upload_get_url($data))) {
			$this->uploader_error('Cannot find redirected ID in response!');
			return ['RU' => null];
		}
		return array('RU' => $url);
	}
}

