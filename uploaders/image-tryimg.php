<?php

require_once dirname(__FILE__).'/baseclass.php';

// !!! Tryimg handles duplicate uploads very poorly !!!

class uploader_Tryimg extends uploader {
	protected $sitename = 'tryimg';
	
	protected function _upload(&$files) {
		return $this->_upload_simple($files, 0);
	}
	
	protected function upload_sockets_setfields(&$headers, &$filefields, &$postfields, &$purl, &$fn, $chunk_size, &$file) {
		switch($ext = strtolower(preg_replace('~^.+\.([a-z]+)$~i', '$1', $file->basename))) {
			case 'jpg':
			case 'jpeg':
				$file->mime = 'image/jpeg'; break;
			case 'png':
				$file->mime = 'image/png'; break;
			default:
				$file->mime = 'image/'.$ext; break;
		}
		return true;
	}
	protected function upload_sockets_verify(&$data, $file, $part, $header_pt, $fn) {
		$json = [];
		if(!$this->get_url_from_data($data, $json)) {
			if(!empty($json)) {
				if(isset($json->error->message) && $json->error->message == 'Request denied')
					return 'TryIMG denied our request';
			}
			return 'Can\'t find upload URL'.$this->log_dump_data($data, 'tryimg');
		}
		return false;
	}
	
	private function get_url_from_data($data, &$json=null) {
		if(!preg_match('~\{.*\}~s', $data, $m)) return false;
		$jdata = @json_decode($m[0]);
		if(!empty($json)) $json = $jdata;
		if(isset($jdata->image->url))
			return $jdata->image->url;
		return false;
	}
	
	// TODO: indicate that multi-part is not supported
	protected function do_upload($file, $splitsize=0, $desc='', $services=array()) {
		$m = null;
		for($i=0; $i<3; ++$i) {
			$data = @$this->curl_get_contents('http://tryimg.com/', true);
			if($data && preg_match('~\<input type\="hidden" name\="auth_token" value\="([a-f0-9]{40})"~i', $data, $m)) break;
			sleep(($i+1)*15);
		}
		if(!$m) {
			// TODO: spit out error
			return false;
		}
		
		$cookies = self::parse_setcookies_from_data($data);
		if(empty($cookies) || empty($cookies['PHPSESSID'])) {
			if(!$this->cookie)
				self::warning('Could not retrieve cookies from request'.$this->log_dump_data($data, 'tryimg_init'));
		} else
			$this->cookie = 'PHPSESSID='.@$cookies['PHPSESSID'];
		
		$auth_token = $m[1];
		return $this->upload_sockets_sgl_wrapper('http://tryimg.com/json', $file, array(
			'source' => null,
			'type' => 'file',
			'action' => 'upload',
			'privacy' => 'public',
			'timestamp' => time()*1000,
			'auth_token' => $auth_token,
			'category_id' => 'null',
			'nsfw' => 0
		), 10*1024*1024-1);
	}
	protected function process_upload(&$data, $part) {
		if(!isset($data) || !($url = $this->get_url_from_data($data)))
			// uploading of this part failed - put in dummy link
			return array('tryimg' => null);
		
		return array('tryimg' => $url);
	}
}

