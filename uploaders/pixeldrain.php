<?php

require_once dirname(__FILE__).'/baseclass.php';

class uploader_PixelDrain extends uploader {
	protected $sitename = 'pixeldrain';
	protected function _upload(&$files) {
		return $this->_upload_simple($files, 0);
	}
	
	private function check_response($data) {
		if(!$data) return 'Empty response';
		
		// strip headers
		$p = strpos($data, "\r\n\r\n");
		if(!$p) return 'Header endpoint not found';
		
		if(strtoupper(substr($data, 0, 13)) == 'HTTP/1.1 500 ')
			return 'PixelDrain HTTP 500 response';
		if(strtoupper(substr($data, 0, 13)) == 'HTTP/1.1 504 ')
			return 'PixelDrain HTTP 504 response';
		if(strtoupper(substr($data, 0, 13)) == 'HTTP/1.1 502 ')
			return 'PixelDrain HTTP 502 response';
		
		$jdata = substr($data, $p+4);
		if(preg_match('~\{.*\}~s', $jdata, $m))
			$jdata = $m[0];
		$jdata = json_decode($jdata);
		if(empty($jdata) || !isset($jdata->success) || (@$jdata->success && !$jdata->id)) {
			return 'Invalid response'.$this->log_dump_data($data, 'pixeldrain');
		}
		if(!$jdata->success)
			return 'PixelDrain error: '.$jdata->message;
		
		return ['url' => 'https://pixeldrain.com/u/'.$jdata->id];
	}
	
	protected function upload_sockets_verify(&$data, $file, $part, $header_pt, $fn) {
		$error = $this->check_response($data);
		if(!is_array($error))
			return $error;
		return false;
	}
	
	protected function upload_sockets_setfields(&$headers, &$filefields, &$postfields, &$purl, &$fn, $chunk_size, &$file) {
		$postfields['name'] = $fn;
		return true;
	}
	
	protected function do_upload($file, $splitsize=0, $desc='') {
		return $this->upload_sockets_sgl_wrapper('https://pixeldrain.com/api/file', $file, array('file' => null), 1000*1000*1000, array('name' => '' /*filled in later*/));
	}
	
	protected function process_upload(&$data) {
		$ret = null;
		if(isset($data))
			$ret = $this->check_response($data);
		if(is_array($ret))
			return ['PD' => $ret['url']];
		return ['PD' => null];
	}
}

