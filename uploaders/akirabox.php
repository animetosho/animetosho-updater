<?php

require_once dirname(__FILE__).'/baseclass.php';

class uploader_AkiraBox extends uploader {
	protected $sitename = 'akirabox';
	protected $upload_sockets_subchunk_size = 94371840; // 90MB - lifted from source code
	protected $SOCKETS_IGNORE_EMPTY_BODY = true;
	
	private $upload_id = null;
	private $current_chunksize = null;
	private $parts_list = null;
	private $ab_cookies = null;
	private $csrf_token = '';
	
	protected function _upload(&$files) {
		return $this->_upload_simple($files, 0);
	}
	
	protected function upload_sockets_setfields(&$headers, &$filefields, &$postfields, &$purl, &$fn, $chunk_size, &$file) {
		$this->setCookies($this->ab_cookies);
		$rawdata = $this->curl_get_contents('https://akirabox.com/get-upload?filename='.rawurlencode($fn).'&filetype=application%2Foctet-stream', true);
		$data = @json_decode(self::body_from_full_response($rawdata));
		if(empty($data->uploadId)) {
			if(strpos($rawdata, '<title>500 - Server Error</title>'))
				return $this->uploader_error('Could not get upload ID details from AkiraBox: HTTP 500');
			if(preg_match('~^HTTP/2 (5\d\d)~', $rawdata, $m))
				return $this->uploader_error('Could not get upload ID details from AkiraBox: HTTP '.$m[1]);
			if(isset($data->message) && substr($data->message, 0, 68) == 'Failed to initialize upload: Error executing "CreateMultipartUpload"')
				return $this->uploader_error('CreateMultipartUpload failed: '.$data->message);
			return $this->uploader_error('Could not get upload ID details from AkiraBox'.$this->log_dump_data($rawdata, 'akirabox_init'));
		}
		$this->upload_id = [
			'uploadId' => $data->uploadId,
			'key' => $data->key,
			'providerId' => $data->providerId,
			'bucket' => $data->bucket,
		];
		$this->current_chunksize = $chunk_size;
		$this->parts_list = [];
		$this->ab_cookies = $this->parse_setcookies_from_data($rawdata);
		
		// AkiraBox blocks braces in names
		$fn = strtr($fn, ['{' => '(', '}' => ')']);
		
		return true;
	}
	
	protected function upload_sockets_pre_subchunk(&$headers, &$purl, $vars) {
		$url = 'https://akirabox.com/upload-chunk?part-number='.($vars['subchunk']+1);
		foreach($this->upload_id as $k => $v)
			$url .= "&$k=".rawurlencode($v);
		$this->setCookies($this->ab_cookies);
		for($retries=3; ; --$retries) {
			$rawdata = $this->curl_get_contents($url, true);
			$this->cookie = '';
			$data = @json_decode(self::body_from_full_response($rawdata));
			if(substr($data, 0, 8) != 'https://') {
				if(preg_match('~^HTTP/2 (5\d\d)~', $rawdata, $m)) {
					if($retries) {
						sleep(5);
						continue;
					} else
						return 'Get subchunk data did not return URL: HTTP '.$m[1];
				}
				elseif(!$rawdata && $retries) {
					sleep(5);
					continue;
				}
				return 'Get subchunk data did not return URL'.$this->log_dump_data($rawdata, 'akirabox_initsc');
			}
			break;
		}
		
		$purl = self::parse_url($data);
		$headers[0] = 'PUT '.$purl['path'].' HTTP/1.1';
		$headers[1] = 'Host: '.$purl['host'];
		$this->ab_cookies = $this->parse_setcookies_from_data($rawdata);
		
		return false;
	}
	
	protected function upload_sockets_subchunk_verify(&$data, $info) {
		if(!strpos($data, " 200 OK\r\n")) {
			if(strpos($data, 'HTTP/1.1 404 Not Found') === 0)
				return 'HTTP 404';
			if(strpos($data, 'HTTP/1.1 500 Internal Server Error') === 0)
				return 'HTTP 500';
			if(strpos($data, 'HTTP/1.1 502 Bad Gateway') === 0)
				return 'HTTP 502';
			if(strpos($data, 'HTTP/1.1 503 Service Temporarily Unavailable') === 0)
				return 'HTTP 503';
			if(strpos($data, 'HTTP/1.1 413 Request Entity Too Large') === 0)
				return 'HTTP 413';
			if(strpos($data, 'HTTP/1.1 521 Origin Down') === 0)
				return 'HTTP 521';
			if(strpos($data, 'HTTP/1.1 522 Origin Connection Time-out') === 0)
				return 'HTTP 522';
			if(strpos($data, 'HTTP/1.1 524 Origin Time-out') === 0)
				return 'HTTP 524';
			if(strpos($data, 'HTTP/1.1 504 Gateway Timeout') === 0)
				return 'HTTP 504';
			if(strpos($data, 'HTTP/1.1 403 Forbidden') === 0)
				return 'HTTP 403';
			if(strpos($data, 'HTTP/1.1 408 Request Timeout') === 0)
				return 'HTTP 408';
			if(strpos($data, 'HTTP/1.1 405 Method Not Allowed') === 0)
				return 'HTTP 405';
			return 'Didn\'t get 200 OK response'.$this->log_dump_data($data, $this->sitename);
		}
		// 2025-10-06: doesn't seem to send checksums any more
		//if(!strpos($data, "\r\nx-amz-checksum-crc32:") && !strpos($data, "\r\nx-amz-checksum-crc64nvme:"))
		//	return 'Didn\'t get CRC in response'.$this->log_dump_data($data, $this->sitename);
		//// TODO: actually verify CRC?
		if(!preg_match('~\r\nETag: "([0-9a-f]{32})"~i', $data, $m))
			return 'ETag missing in response'.$this->log_dump_data($data, $this->sitename);
			
		$this->parts_list[] = [
			'PartNumber' => $info['subchunk']+1,
			'ETag' => $m[1]
		];
		
		return null;
	}
	
	protected function upload_sockets_verify(&$data, $file, $part, $header_pt, $fn) {
		$url = 'https://akirabox.com/finalize-upload';
		$d = '?';
		foreach($this->upload_id as $k => $v) {
			if($k == 'uploadId') continue;
			$url .= "$d$k=".rawurlencode($v);
			$d = '&';
		}
		$fn_parts = ['', $fn, ''];
		if(preg_match('~^(.+)\.(.+?)$~', $fn, $m))
			$fn_parts = $m;
		$reqBody = json_encode([
			'UploadId' => $this->upload_id['uploadId'],
			'filename' => $fn_parts[1],
			'visibility' => null,
			'password' => '',
			'description' => '',
			'folder' => null,
			'fileExtension' => $fn_parts[2],
			'filetype' => 'application/octet-stream',
			'filesize' => $this->current_chunksize,
			'MultipartUpload' => ['Parts' => $this->parts_list]
		]);
		$csrfToken = $this->csrf_token;
		
		$this->setCookies($this->ab_cookies);
		if(DEBUG_MODE) echo "COOKIE: $this->cookie\n";
		$joindata = $this->curl_get_contents($url, false,
			function(&$ch) use($reqBody, $csrfToken) {
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $reqBody);
				curl_setopt($ch, CURLOPT_HTTPHEADER, [
					'Content-Type: application/json;charset=UTF-8',
					'X-CSRF-TOKEN: '.$csrfToken
				]);
				if(DEBUG_MODE) echo "BODY: $reqBody\n";
			}
		);
		$this->cookie = '';
		$jdata = @json_decode($joindata);
		if(empty($jdata->download_link)) {
			$data = null;
			if(strpos($joindata, '<title>419 - Page Expired</title>'))
				return 'Session expired';
			elseif(!empty($jdata->message) && @$jdata->type == 'error')
				return 'Response Error: '.$jdata->message;
			elseif(preg_match('~^error code: (5\d\d)$~', $joindata, $m))
				return 'HTTP '.$m[1];
			else
				return 'No download link received'.$this->log_dump_data($joindata, 'akirabox_final');
		} else {
			$data = $jdata->download_link;
			return false;
		}
	}
	
	protected function do_upload($file, $splitsize=0, $desc='', $services=array()) {
		$this->cookie = '';
		for($i=0; $i<3; ++$i) {
			// home page throws CloudFlare page, but others don't, so use the API page instead
			$rawdata = $this->curl_get_contents('https://akirabox.com/api', true);
			if($rawdata && strpos($rawdata, '<title>500 - Server Error</title>') === false) break;
			sleep(5);
		}
		$this->ab_cookies = $this->parse_setcookies_from_data($rawdata);
		if(preg_match('~\<meta name="csrf-token" content="([^"]{40})">~i', $rawdata, $m)) {
			$this->csrf_token = $m[1];
			if(DEBUG_MODE) echo "CSRF_TOKEN: $this->csrf_token\n";
		} else {
			if(strpos($rawdata, '<title>500 - Server Error</title>') !== false || strpos($rawdata, '<title>500 - API Documentation</title>') !== false)
				$err = '. HTTP 500';
			elseif(strpos($rawdata, 'HTTP/2 522') === 0)
				$err = '. HTTP 522';
			else
				$err = $this->log_dump_data($rawdata, 'akirabox_home');
			self::warning('Could not get CSRF token'.$err, 'akirabox');
		}
		if(!$this->ab_cookies) $this->ab_cookies = [];
		return $this->upload_sockets_sgl_wrapper('https://akirabox.com/', $file, null, 2048*1048576);
	}
	
	protected function process_upload(&$data) {
		return ['AB' => $data];
	}
}
