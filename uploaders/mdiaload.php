<?php

require_once dirname(__FILE__).'/baseclass.php';

class uploader_MdiaLoad extends uploader {
	protected $sitename = 'mdiaload';
	
	protected function _upload(&$files) {
		return $this->_upload_simple($files, 0);
	}
	
	private static function url_from_data($data) {
		if(!preg_match("~\r\n(\[.*\])~", $data, $m)) 
			return false;
		
		$jdata = json_decode($m[1]);
		if(empty($jdata[0]))
			return false;
		$jdata = $jdata[0];
		if($jdata->file_status != 'OK' || empty($jdata->file_code))
			return false;
		return 'https://down.mdiaload.com/'.$jdata->file_code;
	}
	protected function upload_sockets_verify(&$data, $file, $part, $header_pt, $fn) {
		if(!$this->url_from_data($data)) {
			if(preg_match("~\r\n(\[.*\])~", $data, $m)) {
				$jdata = json_decode($m[1]);
				if(!empty($jdata[0])) {
					$jdata = $jdata[0];
					if(isset($jdata->file_status) && preg_match('~^failed while requesting fs\.cgi~', $jdata->file_status))
						return 'Internal request error';
				}
			}
			if(strpos($data, '<title>500 Internal Server Error</title>'))
				return 'HTTP 500';
			if(strpos($data, '<title>504 Gateway Timeout</title>'))
				return 'HTTP 504';
			
			return 'Unexpected MdiaLoad response'.$this->log_dump_data($data, 'mdiaload');
		}
		return false;
	}
	
	protected function upload_sockets_setfields(&$headers, &$filefields, &$postfields, &$purl, &$fn, $chunk_size, &$file) {
		if(strtolower(substr($fn, -4)) == '.exe') {
			// extension banned by MdiaLoad
			$fn .= '.extension_blocked';
		}
		return true;
	}
	
	protected function do_upload($file) {
		// get upload server
		$server = 'sr10.mdiaload.com';
		$data = $this->curl_get_contents('https://down.mdiaload.com/');
		if(preg_match('~\<form id\="uploadfile" action\="https?\://([a-z0-9]+\.mdiaload\.com)/cgi-bin/~i', $data, $m)) {
			$server = $m[1];
		} else {
			if(
				!strpos($data, '<title>500 Internal Server Error</title>')
				&& !strpos($data, '<title>Just a moment...</title>') // CF verification page?
				&& $data != 'error code: 521'
				&& $data != 'error code: 522'
				&& !strpos($data, '>The website is under maintenance.<')
			)
				self::info('Could not find upload server for MdiaLoad'.$this->log_dump_data($data, 'mdiaload_init'));
		}
		
		$this->server = $this->select_server($server);
		return $this->upload_sockets_sgl_wrapper('https://'.$this->server.'/cgi-bin/upload.cgi?upload_type=file&utype=anon', $file, array('file_0' => null), 2048*1024*1024, array(
			'sess_id' => '',
			'file_public' => '1',
			'link_rcpt' => '',
			'link_pass' => '',
			'to_folder' => '',
			'keepalive' => '1',
		));
	}
	
	protected function process_upload(&$data, $part) {
		$url = self::url_from_data($data);
		return array('ML' => $url ?: null);
	}
}

