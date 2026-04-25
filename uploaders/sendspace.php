<?php

require_once dirname(__FILE__).'/baseclass.php';

class uploader_SendSpace extends uploader {
	protected $sitename = 'sendspace';
	protected $ssl_ciphers = 'ECDHE-RSA-AES256-GCM-SHA384:DHE-RSA-AES256-GCM-SHA384:ECDH-RSA-AES256-GCM-SHA384:AES128-SHA:PSK-AES128-CBC-SHA:ECDH-RSA-AES128-SHA:ECDH-ECDSA-AES128-SHA:ECDHE-RSA-AES128-SHA:ECDHE-ECDSA-AES128-SHA:DHE-RSA-AES128-SHA:DHE-DSS-AES128-SHA:AES256-SHA:PSK-AES256-CBC-SHA:DHE-RSA-AES256-SHA:DHE-DSS-AES256-SHA:ECDH-RSA-AES256-SHA:ECDH-ECDSA-AES256-SHA:ECDHE-RSA-AES256-SHA:ECDHE-ECDSA-AES256-SHA';
	
	protected function _upload(&$files) {
		return $this->_upload_simple($files, 0);
	}
	
	protected function upload_sockets_setfields(&$headers, &$filefields, &$postfields, &$purl, &$fn, $chunk_size, &$file) {
		for($i=0; $i<2; ++$i) {
			$data = $this->curl_get_contents('https://www.sendspace.com/');
			if(preg_match('~action\="(https?)\://([a-z0-9\-]+\.sendspace\.com)(/(?:upload\?[^"]+|processupload\.html))"(.+?)\</form\>~s', $data, $m)) break;
			sleep(10);
		}
		if(empty($m)) {
			if(strpos($data, 'Scheduled maintenance in progress, please try again in a few minutes...')
			|| strpos($data, 'Scheduled server upgrades in progress. Please try again in a few minutes.')
			|| strpos($data, '>Sendspace.com - Scheduled Maintenance<'))
				return $this->uploader_error('Scheduled maintenance in progress');
			if(strpos($data, '<title>502 Bad Gateway</title>'))
				return $this->uploader_error('HTTP 502');
			if($data == 'Scheduled maintenance in progress, please try again in a few minutes...')
				return $this->uploader_error('Maintenance');
			return $this->uploader_error('Unable to retrieve upload URL'.$this->log_dump_data($data, 'sendspace_init'));
		}
		
		$purl['scheme'] = $m[1];
		$purl['port'] = ($purl['scheme'] == 'https'?443:80);
		$headers[0] = 'POST '.$m[3].' HTTP/1.1';
		$headers[1] = 'Host: '.($purl['host'] = $this->select_server($m[2]));
		
		$haserror = false;
		$mfunc = function($nam, $regex, $ignore_err=false) use(&$haserror, &$postfields, $m) {
			if(preg_match('~\<input\s+type\="hidden"\s+name\="'.$nam.'"\s+value\="('.$regex.')"\s*/\>~', $m[4], $m2)) {
				$postfields[$nam] = $m2[1];
			} elseif(!$ignore_err) $haserror = true;
		};
		$mfunc('signature', '[0-9a-f]{32}');
		$mfunc('PROGRESS_URL', 'https?\://[^"]+');
		$mfunc('DESTINATION_DIR', '\d+', true);
		
		if($haserror)
			$this->warning('Unexpected SendSpace init data!'.$this->log_dump_data($data, 'sendspace_init'));
		
		return true;
	}
	protected function upload_sockets_verify(&$data, $file, $part, $header_pt, $fn) {
		if(!$this->get_url_from_data($data)) {
			if(preg_match('~^HTTP/1\.[01] 301 Moved Permanently.+Location\:\s*(https?\://[^'."\r\n".']+)~si', $data, $m)) {
				if(preg_match('~/uploadprocerr\.html\?e=(\d+)~', $m[1]))
					return 'Sendspace upload error URL: '.$m[1];
				
				if(!$header_pt) // hack to detect too much recursion
					return 'Sendspace redirect loop'.$this->log_dump_data($data, 'sendspace');
				$data = $this->curl_get_contents($m[1], true);
				return $this->upload_sockets_verify($data, $file, $part, 0, $fn);
			}
			if(preg_match('~^HTTP/1\.[01] (403 Forbidden(?: Request)?|502 Bad Gateway|503 Service Temporarily Unavailable|504 Gateway Time-out)~', $data, $m))
				return 'HTTP '.$m[1]; // 403 error happens often
			
			// maintenance redirect page
			if(preg_match('~^HTTP/1\.[01] 301 Moved Permanently.+'."\r\nLocation\: https?\://[a-z0-9]\.sendspace\.com/(upload|maintenance\.html)\r\n".'~s', $data))
				return 'HTTP 301 maintenace';
			if(strpos($data, '>Sendspace.com - Scheduled Maintenance<'))
				return 'Scheduled maintenance in progress';
			if(strpos($data, 'Scheduled maintenance in progress, please try again in a few minutes...<'))
				return 'Scheduled maintenance in progress';
			
			return 'Can\'t find upload URL'.$this->log_dump_data($data, 'sendspace');
		}
		return false;
	}
	
	private function get_url_from_data($data) {
		if(preg_match('~href\="(https?\://(www\.)?sendspace\.com/file/[a-z0-9A-Z_\-=+]{3,16})"~', $data, $m))
			return $m[1];
		return false;
	}
	
	protected function do_upload($file, $splitsize=0, $desc='', $services=array()) {
		// interestingly 'file' works as the name of the file input too!
		return $this->upload_sockets_sgl_wrapper('http://fs04u.sendspace.com/upload?', $file, array('upload_file[]'=>null), 300*1024*1024, array(
			'PROGRESS_URL'=>'', // to override
			//'DESTINATION_DIR'=>'', // to override
			'js_enabled'=>'1',
			'signature'=>'', // to override
			'upload_files'=>'',
			'terms'=>'1',
			'file[]'=>'1',
			'description[]'=>$desc,
			'recpemail_fcbkinput'=>'',
			'ownemail'=>'',
			'recpemail'=>'',
		));
	}
	protected function process_upload(&$data, $part) {
		if(!isset($data) || !($url = $this->get_url_from_data($data)))
			// uploading of this part failed - put in dummy link
			return array('SS' => null);
		
		return array('SS' => $url);
	}
}

