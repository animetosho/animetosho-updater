<?php

require_once dirname(__FILE__).'/baseclass.php';

class uploader_BuzzHeavier extends uploader {
	protected $sitename = 'buzzheavier';
	protected $SOCKETS_IGNORE_EMPTY_BODY = true;
	private $uploadId = null;
	
	protected function _upload(&$files) {
		return $this->_upload_simple($files, 0);
	}
	
	protected function upload_sockets_setfields(&$headers, &$filefields, &$postfields, &$purl, &$fn, $chunk_size, &$file) {
		$fn = strtr($fn, [';' => '_', '#' => '_', '|' => '_']); // BuzzHeavier doesn't like these characters in names
		$headers[0] = 'PUT /'.rawurlencode($fn).' HTTP/1.1';
		return true;
	}
	
	private static function url_from_resp($data) {
		$p = strpos($data, "\r\n\r\n");
		if(!$p) return false;
		$body = substr($data, $p + 4);
		$jdata = json_decode(trim($body));
		
		if(isset($jdata->data->id))
			return 'https://buzzheavier.com/'.$jdata->data->id;
		return false;
	}
	
	protected function upload_sockets_verify(&$data, $file, $part, $header_pt, $fn) {
		if(!$data) return 'Empty response';
		// strip headers
		if(!$this->url_from_resp($data)) {
			if(stripos($data, 'HTTP/1.1 413 Request Entity Too Large') === 0)
				return 'CloudFlare 413 error';
			if(stripos($data, 'HTTP/1.1 503 Service Unavailable') === 0)
				return 'HTTP 503 error';
			return 'Location ID not returned'.$this->log_dump_data($data, 'buzzheavier_resp');
		}
		return false;
	}
	
	protected function do_upload($file, $splitsize=0, $desc='', $services=array()) {
		return $this->upload_sockets_sgl_wrapper('https://w.buzzheavier.com/file_name', $file, null, 2048*1048576);
	}
	
	protected function process_upload(&$data) {
		$ret = null;
		if(isset($data))
			$ret = $this->url_from_resp($data);

		if($ret)
			return ['BH' => $ret];
		return ['BH' => null];
	}
}
