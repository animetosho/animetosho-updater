<?php

require_once dirname(__FILE__).'/baseclass.php';


class uploader_KrakenFiles extends uploader {
	protected $sitename = 'krakenfiles';
	protected $upload_sockets_subchunk_size = 52428800; // 50MB
	protected function _upload(&$files) {
		return $this->_upload_simple($files, 0);
	}
	
	private function check_response($data) {
		if(!$data) return 'Empty response';
		
		// strip headers
		$p = strpos($data, "\r\n\r\n");
		if(!$p) return 'Header endpoint not found';
		
		$data = substr($data, $p+4);
		if(preg_match('~\{.*\}~s', $data, $m))
			$data = $m[0];
		$jdata = json_decode($data);
		if(empty($jdata) || empty($jdata->files) || count($jdata->files) != 1) {
			if($data == '[]' || $data == "2\r\n[]\r\n0")
				return 'Got chunk response instead of complete response';
			return 'Invalid response'.$this->log_dump_data($data, 'krakenfiles');
		}
		$file = $jdata->files[0];
		if(empty($file->url)) {
			if(!empty($file->error))
				return 'KrakenFiles error: '.$file->error;
			return 'Invalid response'.$this->log_dump_data($data, 'krakenfiles');
		}
		
		return ['url' => $file->url];
	}
	
	protected function upload_sockets_pre_subchunk(&$headers, &$purl, $info) {
		$pos = bcsub($info['sc_fpos'], $info['cur_fpos']);
		$headers[] = 'Content-Range: bytes '.$pos.'-'.bcsub(bcadd($pos, $info['upload_size']), 1).'/'.$info['chunk_size'];
		$headers[] = 'Content-Disposition: attachment; filename="'.$info['fn_ext'].'"';
		return null;
	}
	protected function upload_sockets_subchunk_verify(&$data, $info) {
		// we just verify the response is valid JSON - it'll either be a blank array, or a full response
		if(!$data) return 'Empty response';
		
		// strip headers
		$p = strpos($data, "\r\n\r\n");
		if(!$p) return 'Header endpoint not found';
		
		$jdata = trim(substr($data, $p+4));
		if($jdata == '[]' || $jdata == "2\r\n[]\r\n0") return null; // response from successful chunk
		if(preg_match('~\{.*\}~s', $jdata, $m))
			$jdata = $m[0];
		$jdata = json_decode($jdata);
		if(empty($jdata) || empty($jdata->files) || count($jdata->files) != 1) {
			if(strpos($data, 'HTTP/1.1 503 Service Temporarily Unavailable') === 0)
				return 'HTTP 503 response';
			if(strpos($data, 'HTTP/1.1 500 Internal Server Error') === 0)
				return 'HTTP 500 response';
			if(strpos($data, 'HTTP/1.1 502 Bad Gateway') === 0)
				return 'HTTP 502 response';
			if(strpos($data, 'HTTP/1.1 504 Gateway Time-out') === 0)
				return 'HTTP 504 response';
			if(preg_match('~error code: (5\d\d)$~', $data, $m))
				return 'HTTP '.$m[1];
			return 'Invalid response'.$this->log_dump_data($data, 'krakenfiles_chunk');
		}
		return null;
	}
	
	protected function upload_sockets_verify(&$data, $file, $part, $header_pt, $fn) {
		$error = $this->check_response($data);
		if(!is_array($error))
			return $error;
		return false;
	}
	
	protected function do_upload($file, $splitsize=0, $desc='') {
		$server = 's3.krakenfiles.com';
		$data = $this->curl_get_contents('https://krakenfiles.com/');
		if(preg_match('~url\:\s*"(?:https?:)?//((?:[a-z0-9]+\.)?kraken(?:files\.com|cloud\.net))/_uploader/gallery/upload~', $data, $m)) {
			$server = $m[1];
		} else {
			if(strpos($data, '>An Error Occurred: Internal Server Error<'))
				$reason = ' HTTP 500';
			elseif(strpos($data, '>503 Service Temporarily Unavailable<'))
				$reason = ' HTTP 503';
			elseif(preg_match('~^error code: (5\d\d)$~', $data, $m))
				$reason = ' HTTP '.$m[1];
			else
				$reason = $this->log_dump_data($data, 'krakenfiles_init');
			$this->info('[KrakenFiles] Could not find upload server.'.$reason);
		}
		
		return $this->upload_sockets_sgl_wrapper('https://'.$this->select_server($server).'/_uploader/gallery/upload', $file, array('files[]' => null), 1024*1024*1024);
	}
	
	protected function process_upload(&$data) {
		$ret = null;
		if(isset($data))
			$ret = $this->check_response($data);
		if(is_array($ret))
			return ['KF' => $ret['url']];
		return ['KF' => null];
	}
}

