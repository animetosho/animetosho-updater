<?php

require_once dirname(__FILE__).'/baseclass.php';
require_once dirname(__FILE__).'/filehandler_7z.php';

// NOTE: anonfiles now does some form of de-dup and will error if a dupe is uploaded
class uploader_AnonFiles extends uploader {
	protected $sitename = 'anonfiles';
	protected function _upload(&$files) {
		return $this->_upload_simple($files, 0);
	}
	
	private function check_response($data) {
		if(!$data) return 'Empty response';
		
		// strip headers
		$p = strpos($data, "\r\n\r\n");
		if(!$p) return 'Header endpoint not found';
		
		if(strtoupper(substr($data, 0, 13)) == 'HTTP/1.1 500 ')
			return 'AnonFiles HTTP 500 response';
		if(strtoupper(substr($data, 0, 13)) == 'HTTP/1.1 504 ')
			return 'AnonFiles HTTP 504 response';
		if(strtoupper(substr($data, 0, 13)) == 'HTTP/1.1 502 ')
			return 'AnonFiles HTTP 502 response';
		if(strtoupper(substr($data, 0, 13)) == 'HTTP/1.1 503 ')
			return 'AnonFiles HTTP 503 response';
		if(strtoupper(substr($data, 0, 13)) == 'HTTP/1.1 405 ')
			return 'AnonFiles HTTP 405 response';
		
		$jdata = substr($data, $p+4);
		if(preg_match('~\{.*\}~s', $jdata, $m))
			$jdata = $m[0];
		$jdata = json_decode($jdata);
		if(empty($jdata) || !@$jdata->status || !isset($jdata->data->file->url->full)) {
			if(substr($data, 0, 32) == 'HTTP/1.1 503 Service Unavailable')
				return 'HTTP 503';
			if(substr($data, 0, 24) == 'HTTP/1.1 400 Bad Request' && !empty($jdata->type)) {
				if($jdata->type == 'ERROR_USER_MAX_BYTES_PER_DAY_REACHED')
					return 'Max uploaded size per day exceeded';
				if($jdata->type == 'ERROR_USER_MAX_BYTES_PER_HOUR_REACHED')
					return 'Max uploaded size per hour exceeded';
				if($jdata->type == 'ERROR_SYSTEM_FAILURE')
					return 'System error';
				if($jdata->type == 'ERROR_FILE_NOT_PROVIDED')
					return 'File not provided?';
			}
			return 'Invalid response'.$this->log_dump_data($data, 'anonfiles');
		}
		
		return ['url' => $jdata->data->file->url->full];
	}
	
	protected function upload_sockets_verify(&$data, $file, $part, $header_pt, $fn) {
		$error = $this->check_response($data);
		if(!is_array($error))
			return $error;
		return false;
	}
	
	protected function do_upload($file, $splitsize=0, $desc='') {
		// 2021-10-18: can't connect via IPv6 (includes other servers)
		$this->SOCKETS_OPTS['socket'] = ['bindto' => '0.0.0.0:0'];
		return $this->upload_sockets_sgl_wrapper('https://api.anonfiles.com/upload', $file, array('file' => null), 2048*1024*1024);
	}
	
	protected function process_upload(&$data) {
		$ret = null;
		if(isset($data))
			$ret = $this->check_response($data);
		if(is_array($ret))
			return ['AF' => $ret['url']];
		return ['AF' => null];
	}
}

