<?php

require_once dirname(__FILE__).'/baseclass.php';

class uploader_Image extends uploader {
	public $ul_imgur = false;
	public $ul_bayimg = false;
	public $ul_someimage = false;
	
	private $someimage_cookie = null;
	
	protected function _upload(&$files) {
		$ret = array();
		
		if(isset($this->error_imgur_limit) && $this->error_imgur_limit < time()-600) {
			unset($this->error_imgur_limit);
		}
		
		foreach($files as $file => &$desc) {
			$urls = array();
			
			if($this->ul_imgur && !isset($this->error_imgur_limit)) {
				$this->ch = $this->initCh();
				$url = $this->do_upload_imgur($file, $desc);
				$this->curl_close();
				if($url) $urls['Imgur'] = $url;
			}
			if($this->ul_bayimg) {
				$this->ch = $this->initCh();
				$url = $this->do_upload_bayimg($file);
				$this->curl_close();
				if($url) $urls['BayImg'] = $url;
			}
			if($this->ul_someimage) {
				$this->ch = $this->initCh();
				$url = $this->do_upload_someimage($file);
				$this->curl_close();
				if($url) $urls['SomeImage'] = $url;
			}
			if(empty($urls)) continue;
			$ret[$file] = $urls;
		}
		return $ret;
	}
	
	private static function curlFile($filename, $mimetype=null) {
		$fileext = self::get_extension($filename);
		if($mimetype === true)
			$mimetype = self::get_mime($fileext);
		if(class_exists('CURLFile')) {
			if($mimetype && !$fileext) {
				// temp fix for SomeImage, but may be useful as a fallback if there's no file extension
				return new CURLFile($filename, $mimetype, basename($filename).'.png');
			}
			return new CURLFile($filename, $mimetype);
		} else
			return '@'.$filename.(isset($mimetype) ? ';type='.$mimetype : '');
	}
	
	private static function imgur_error_from_data(&$data) {
		if(!$data)
			return 'No data returned!';
		if(preg_match('~\<title\>\s*imgur\: the simple overloaded page\s*\</title\>~i', $data) || preg_match('~\<title\>\s*Imgur overloaded\.\s+Send kittens\s*\</title\>~i', $data))
			return 'Imgur overloaded';
		if(preg_match('~\<(?:center|body)\>\<h1\>([3-5]\d\d) ([a-zA-Z0-9\-_ ]+)\</h1\>~', $data, $m))
			return 'Imgur '.$m[2].' ('.$m[1].')';
		if(preg_match('~\<div class\="textbox bigtext"\>\s*Sorry\! There was an error \(code\: \<span class\="green"\>(\d+)\</span\>\)~i', $data, $m))
			return 'Imgur error #'.$m[1];
		if(preg_match('~\<div class\="textbox bigtext"\>\s*Sorry\! We\'re doing some quick maintenance right now\.~i', $data))
			return 'Imgur under maintenance';
		if(strpos($data, '<h1>Scheduled maintenance</h1>'))
			return 'Imgur under maintenance';
		if(strpos($data, 'Sorry! We\'re down for maintenance right now.'))
			return 'Imgur under maintenance';
		if(strpos($data, '<h3>Your action has triggered the website\'s security system because of a phrase or content in your submission. Often automated bots will try to post spam campaigns (like ads for Rolex watches or medications) or injection attacks on websites. This website is taking precaution to stop these automated threats. If you are reading this, then you are likely a human trying to post a comment or log in to the website. Continue by entering the CAPTCHA.</h3>'))
			return 'CloudFlare anti-spam blocking Imgur';
		if(strpos($data, '<h1>Whoa! You\'re moving too fast.</h1>'))
			return 'Imgur says we are moving too fast';
		
		return false;
	}
	
	private function do_upload_imgur($file, $desc='') {
		for($tries=0; $tries<3; ++$tries) {
			$this->refreshCh();
			curl_setopt($this->ch, CURLOPT_POSTFIELDS, array(
				'key' => 'eb1f3cbd8bce23c958c230c70120c2bf', // API key stolen from Imgur uploader
				'image' => self::curlFile($file),
				'type' => 'file',
				'caption' => $desc,
			));
			curl_setopt($this->ch, CURLOPT_TIMEOUT, 90);
			$this->silence_error = true;
			$data = $this->curl_exec('http://api.imgur.com/2/upload.json');
			$this->silence_error = false;
			if(!self::imgur_error_from_data($data)) break;
			sleep(($tries+1)*10);
		}
		if($error = self::imgur_error_from_data($data)) {
			$this->info($error, 'uploader_image');
			return false;
		}
		$json = @json_decode($data, true);
		if(isset($json['error']) && isset($json['error']['message'])) {
			switch($json['error']['message']) {
				case 'API limits exceeded':
					$this->info('Imgur API limits exceeded', 'uploader_image');
					$this->error_imgur_limit = time();
				break;
				case 'Upload failed during the copy process. We have been notifed of this error.':
				case 'A fatal error occured. We have been notified of this error.':
				case 'Image failed to upload.':
					$this->info('Imgur f\'d up', 'uploader_image');
				break;
				default:
					return $this->uploader_error('Imgur error: '.$json['error']['message']);
			}
			return false;
		}
		if(!isset($json['upload']) || !isset($json['upload']['links']))
			return $this->uploader_error('No returned image (file='.$file.')'.$this->log_dump_data($data, 'imgur'));
		return $json['upload']['links']['original'];
	}
	
	
	
	private static function get_mime($ext) {
		switch($ext) {
			case 'jpe': case 'jpg': case 'jpeg': return 'image/jpeg';
			case 'gif': return 'image/gif';
			case 'png': return 'image/png';
			case 'bmp': return 'image/bmp';
			case 'tif': case 'tiff': return 'image/tiff';
			case 'ico': return 'image/x-icon';
			case 'wmf': return 'image/x-msmetafile';
		}
		return 'image/png'; // fallback to something
	}
	
	private function do_upload_bayimg($file) {
		for($tries=0; $tries<3; ++$tries) {
			$this->refreshCh();
			curl_setopt($this->ch, CURLOPT_POSTFIELDS, array(
				'file' => self::curlFile($file),
				'code' => 'removal_code_'.sha1(uniqid(mt_rand(), true)),
				'tags' => '',
			));
			curl_setopt($this->ch, CURLOPT_TIMEOUT, 90);
			$this->silence_error = true;
			$data = $this->curl_exec('http://upload.bayimg.com/upload');
			$this->silence_error = false;
			if(preg_match('~\<img src\="http\://thumbs\.bayimg\.com/([^"]+)"~i', $data, $m)) {
				if($tries) {
					self::info('[BayImg] Image upload succeeded after '.$tries.' retries. (file: '.$file.')', 'uploader_image');
				}
				return 'http://image.bayimg.com/'.html_entity_decode($m[1]);
			}
			sleep(10);
		}
		if(!$data) {
			$lasterror = $this->getLastError();
			if($lasterror) $lasterror = ' Last error: '.$lasterror;
			self::info('No data returned from BayImg (file='.$file.')'.$lasterror);
			return false;
		}
		
		if(substr($data,-17) == '<div id="extra2">') {
			return $this->uploader_error('BayImg failed to upload - no URL returned. (file='.$file.')');
		}
		
		return $this->error('Unexpected data returned. (file='.$file.')'.$this->log_dump_data($data, 'bayimg'));
	}

	private function do_upload_someimage($file) {
		$this->cookie = '';
		//if($this->someimage_cookie)
		//	$this->cookie = $this->someimage_cookie;
		//else {
			for($tries=0; $tries<3; ++$tries) {
				$data = $this->curl_get_contents('https://someimage.com/', true);
				$cookies = $this->parse_setcookies_from_data($data);
				if($cookies) break;
				sleep(10);
			}
			if(!$cookies) {
				self::info('SomeImage did not return cookie headers - using fallback (file='.$file.')'.$this->log_dump_data($data, 'someimage'));
				// use fallback
				$this->cookie = 'PHPSESSID=hgchcnp7ukvbbkjkggcclkskb6';
			} else {
				$this->setCookies($cookies);
				//$this->someimage_cookie = $this->cookie;
			}
		//}
		
		$curlFile = self::curlFile($file, true);
		for($tries=0; $tries<3; ++$tries) {
			$data = $this->curl_get_contents('https://someimage.com/upload.php', false, function($ch) use(&$file, &$curlFile) {
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, array(
					'name' => basename($file),
					'safe' => 1,
					'thumb' => 'w100',
					'gallery' => 0,
					'galleryname' => '',
					'file' => $curlFile,
				));
				curl_setopt($ch, CURLOPT_HTTPHEADER, array(
					'Cache-Control: no-cache',
					'Pragma: no-cache'
				));
				return 'https://someimage.com/';
			});
			if(preg_match('~\{.*\}~i', $data, $m)) {
				$jdata = @json_decode($m[0]);
				if(!$jdata->result && $jdata->id) break;
				$this->log_dump_data($data, 'someimage');
			} else {
				$this->log_dump_data($data, 'someimage');
			}
			sleep(10);
			$data = null;
		}
		if(!$data) {
			$lasterror = $this->getLastError();
			if($lasterror) $lasterror = ' Last error: '.$lasterror;
			self::info('Failed to upload to SomeImage (file='.$file.')'.$lasterror);
			$this->cookie = '';
			return false;
		}
		
		$data = $this->curl_get_contents('https://someimage.com/done', true);
		if(!preg_match("~\r\nLocation\:\s?(https?\://[^\r\n]*)\r\n~", $data, $m)) {
			if(preg_match('~\<span\>Direct Links\</span\>.*?\<textarea onclick\=\'this\.select\(\);\'\>(https?\://someimage\.com/[^'."\r\n".']+)~s', $data, $m)) {
				// sometimes SomeImage returns two copies of the same thing - we just take the first
				self::info('SomageImage returned more than one image (file='.$file.')', 'someimage');
			} elseif(strpos($data, 'Server Reboot, Back in a few')) {
				$this->cookie = '';
				return $this->uploader_error('SomeImage server rebooting (file='.$file.')');
			} else {
				$this->cookie = '';
				return $this->uploader_error('Failed to retrieve URL from SomeImage (file='.$file.')'.$this->log_dump_data($data, 'someimage_done'));
			}
		}
		
		$data = $this->curl_get_contents($redirurl = $m[1], true);
		if(!preg_match('~<img src=\'(https?://[^/]+/[^\']+)\' id=\'viewimage\'~', $data, $m)) {
			$this->cookie = '';
			if(strpos($data, "\r\nLocation: https://someimage.com/\r\n")) {
				return $this->uploader_error('SomeImage says image doesn\'t exist.');
			}
			if(strpos($data, 'Location: https://someimage.com/404'))
				return $this->uploader_error('SomeImage 404 error');
			
			return $this->uploader_error('Failed to retrieve actual image URL from SomeImage (file='.$file.', redirurl='.$redirurl.')'.$this->log_dump_data($data, 'someimage_page'));
		}
		return $m[1];
	}
	
	
	
	private static function &parse_xml($xml) {
		$xp = xml_parser_create();
		xml_parser_set_option($xp, XML_OPTION_SKIP_WHITE, true);
		$xml = str_replace('&', '&amp;', $xml); // work around for PHP's crappy parser
		xml_parse_into_struct($xp, $xml, $vals);
		xml_parser_free($xp);
		return $vals;
	}
}
