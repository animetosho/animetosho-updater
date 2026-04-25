<?php

require_once dirname(__FILE__).'/baseclass.php';

class uploader_GoFile extends uploader {
	protected $sitename = 'gofile';
	
	protected function _upload(&$files) {
		return $this->_upload_simple($files, 0);
	}
	
	private static function info_from_data($data) {
		if(!preg_match("~\r\n(\{.*\})~", $data, $m)) 
			return false;
		
		$jdata = json_decode($m[1]);
		if(empty($jdata) || $jdata->status != 'ok' || empty($jdata->data->downloadPage))
			return false;
		return $jdata->data;
	}
	protected function upload_sockets_verify(&$data, $file, $part, $header_pt, $fn) {
		$info = $this->info_from_data($data);
		if(!$info) {
			if(preg_match("~\r\n(\[.*\])~", $data, $m)) {
				$jdata = json_decode($m[1]);
				if(!empty($jdata->status) && $jdata->status == 'error-serverFreeSpace')
					return 'Free space error';
			}
			
			if(substr($data, 0, 34) == 'HTTP/1.1 500 Internal Server Error')
				return 'GoFile 500 error';
			if(substr($data, 0, 24) == 'HTTP/1.1 502 Bad Gateway')
				return 'GoFile 502 error';
			if(substr($data, 0, 29) == 'HTTP/1.1 504 Gateway Time-out')
				return 'GoFile 504 error';
			return 'Unexpected GoFile response'.$this->log_dump_data($data, 'gofile');
		}
		$received_fn = strtr($info->name ?? '', ['&#39;'=>"'", '&amp;'=>'&']);
		// GoFile seems to collapse double exclamation marks for some reason
		if(str_replace('!!', '!', $received_fn) != str_replace('!!', '!', $fn)) {
			// have seen GoFile point to the wrong file [https://animetosho.org/file/ember-date-live-v-02-mkv.1160437]
			// see if this can detect it
			// - Nope: https://animetosho.org/view/erai-raws-lv2-kara-cheat-datta-moto-yuusha.n1828476
			self::warning('Upload filename mismatch: got '.(@$info->name).' expected '.$fn, 'gofile');
		} else {
			// try to re-query GoFile for what it got
			
			static $account = null;
			if(!isset($account)) {
				$s_token_data = $this->curl_get_contents('https://api.gofile.io/accounts', false, function(&$ch) {
					curl_setopt($ch, CURLOPT_POST, true);
				});
				$token_data = @json_decode($s_token_data);
				if(isset($token_data->status) && $token_data->status == 'ok' && !empty($token_data->data)) {
					$account = $token_data->data;
				} else {
					if(strpos($s_token_data, '<title>502 Bad Gateway</title>'))
						self::warning('Failed to get account token - HTTP 502', 'gofile');
					else
						self::warning('Failed to get account token'.$this->log_dump_data($s_token_data, 'gofile_account'), 'gofile');
				}
			}
			if(isset($account)) {
				// the 'wt' parameter seems to be hard-coded, as is required, otherwise a 'notPremium' error gets returned
				for($i=0; $i<2; ++$i) {
					$folder_resp = $this->curl_get_contents('https://api.gofile.io/contents/'.$info->parentFolderCode.'?wt=4fd6sg89d7s6', false, function(&$ch) use(&$account) {
						curl_setopt($ch, CURLOPT_HTTPHEADER, [
							'Authorization: Bearer '.$account->token,
							'X-Website-Token: 4fd6sg89d7s6'
						]);
					});
					$folder_data = @json_decode($folder_resp);
					if(!empty($folder_data)) break;
					sleep(5);
				}
				if(empty($folder_data) || !isset($folder_data->status) || $folder_data->status != 'ok' || empty($folder_data->data) || empty($folder_data->data->children) || count((array)$folder_data->data->children) != 1) {
					if(!empty($folder_data) && @$folder_data->status == 'ok' && !empty($folder_data->data) && /*!@$folder_data->data->canAccess &&*/ @$folder_data->data->type == 'folder')
						return 'Upload file got mismatched to a folder; expected '.$fn;
					elseif(strpos($folder_resp, '"status":"error-wrongToken"'))
						self::warning('Got token rejected from folder request', 'gofile');
					elseif(strpos($folder_resp, '<title>502 Bad Gateway</title>'))
						self::warning('Got 502 from folder request', 'gofile');
					else
						self::warning('Unexpected folder data (expected '.$fn.') '.$this->log_dump_data($folder_resp, 'gofile_folder'), 'gofile');
				}
				else {
					$file_info = reset($folder_data->data->children);
					
					$received_fn = strtr($file_info->name ?? '', ['&#39;'=>"'", '&amp;'=>'&']);
					if(str_replace('!!', '!', $received_fn) != str_replace('!!', '!', $fn)) {
						return 'Upload filename mismatch (after re-fetch): got '.$file_info->name.' expected '.$fn;
					}
				}
			}
			
		}
		return false;
	}
	
	protected function do_upload($file) {
		// get upload server
		$server = 'store1';
		$data = $this->curl_get_contents('https://api.gofile.io/servers');
		$jdata = @json_decode($data);
		if(empty($jdata) || $jdata->status != 'ok' || empty($jdata->data->servers)) {
			$errMsg = '';
			if(!empty($jdata)) {
				if($jdata->status == 'noServer')
					$errMsg = 'GoFile noServer error';
			} elseif(trim($data) == '404 page not found')
				$errMsg = 'GoFile API 404';
			elseif(strpos($data, '<title>502 Bad Gateway</title>'))
				$errMsg = 'GoFile HTTP 502';
			if(!$errMsg)
				$errMsg = 'Unexpected GoFile init data!'.$this->log_dump_data($data, 'gofile_init');
			self::info($errMsg);
		} else {
			$servers = $jdata->data->servers;
			$servers = array_filter($servers, function($s) { return @$s->name; });
			if(!empty($servers))
				$server = $servers[mt_rand(0, count($servers)-1)]->name;
		}
		$this->server = $this->select_server($server.'.gofile.io');
		return $this->upload_sockets_sgl_wrapper('https://'.$this->server.'/uploadFile', $file, array('file' => null), 2048*1024*1024);
	}
	
	protected function process_upload(&$data, $part) {
		$url = self::info_from_data($data);
		return array('GF' => $url ? $url->downloadPage : null);
	}
}

