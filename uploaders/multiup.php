<?php



require_once dirname(__FILE__).'/baseclass.php';

class uploader_MultiUp extends uploader {
	protected $sitename = 'multiup';
	private $services = null;
	private $cur_services = null; // temp variable for passing used services from do_upload to process_upload
	public $return_servicelinks = true;
	public $return_linkpage = false;
	
	protected function _upload(&$files) {
		if(!isset($this->services)) {
			$this->setServices(null);
		}
		
		if(empty($this->services))
			return array();
		
		$ret = array();
		
		// file splitting decision?
		
		foreach($files as $file => &$desc) {
			self::log_event('Uploading file '.$file);
			// originally split size was 1000MB, but reduce to 500MB for SolidFiles to work
			$this->_upload_do_merge($ret, $file, 500*1024*1024-1, $desc, $this->services);
		}
		
		self::sort_split_file_chunks($ret);
		return $ret;
	}
	
	protected function upload_sockets_setfields(&$headers, &$filefields, &$postfields, &$purl, &$fn, $chunk_size, &$file) {
		// max length appears to be somewhere between 217 and 219 -> but we'll use 216 as limit
		if(strlen($fn) > 216 && preg_match('~^(.*?)((?:\.[a-zA-Z0-9_]{1,10}){0,2})$~', $fn, $m))
			$fn = substr($m[1], 0, 216 - strlen($m[2])) . $m[2];
		return true;
	}
	
	protected function upload_sockets_verify(&$data, $file, $part, $header_pt, $fn) {
		$data_s = $data;
		return $this->_upload_sockets_verify($data_s);
	}
	private function _upload_sockets_verify(&$data, &$url='') {
		
		$p = strpos($data, "\r\n\r\n");
		if(!$p) return 'Cannot find header point.';
		$d = trim(substr($data, $p));
		if(preg_match('~\{.+\}~', $d, $m))
			$d = @json_decode($m[0]);
		else
			$d = false;
		if(empty($d)) {
			if(stripos($data, 'HTTP/1.1 404 Not Found') === 0)
				return 'MultiUp HTTP 404 error';
			if(stripos($data, 'HTTP/1.1 504 Gateway Time-out') === 0)
				return 'MultiUp HTTP 504 error';
			if(stripos($data, 'HTTP/1.1 502 Bad Gateway') === 0)
				return 'MultiUp HTTP 502 error';
			return 'Cannot decode JSON '.$this->log_dump_data($data, 'multiup_response');
		}
		
		if(!empty($d->files))
			$u =& $d->files[0]->url;
		else
			$u =& $d->url;
		
		if(!preg_match('~^https?\://(www\.)?multiup\.(?:org|eu|io)/~', @$u)) {
			$error = (isset($d->files[0]->error) ? $d->files[0]->error : @$d->error);
			if($error == 'The uploaded file was only partially uploaded')
				return 'MultiUp partial upload error';
			elseif($error == 'Failed to write file to disk')
				return 'MultiUp disk fail';
			elseif($error == 'abort')
				return 'MultiUp transfer aborted';
			elseif($error == 'Missing a temporary folder')
				return 'MultiUp temp folder issue';
			elseif($error == 'File upload aborted')
				return 'MultiUp upload aborted';
			elseif(strpos($data, '{"files":[]}'))
				return 'MultiUp returned no files';
			return 'MultiUp didnt return URL'.$this->log_dump_data($data, 'multiup_response');
		}
		
		$url = $u;
		
		return false; // no problems
	}
	
	protected function do_upload($file, $splitsize=524287999 /*500*1024*1024-1*/, $desc='', $services=array()) {
		$this->cur_url = array();
		
		// server selection
		$server = 'toqa.multiup.org';
		$url = '/upload/index.php'; // does this ever change?
		$rawdata = $this->curl_get_contents('https://multiup.io/api/get-fastest-server');
		if(preg_match('~\{.+\}~', $rawdata, $m))
			$data = @json_decode($m[0], true);
		if(empty($data) || !@$data['server']) {
			// check $data['error'] ?
			if(strpos($rawdata, '<title>500 - Internal Server Error</title>') || strpos($rawdata, '<title>500 Internal Server Error</title>'))
				self::info('MultiUp HTTP 500');
			elseif(strpos($rawdata, '<title>www.multiup.eu | 502: Bad gateway</title>') || strpos($rawdata, '<title>502 Bad Gateway</title>'))
				self::info('MultiUp HTTP 502');
			elseif(strpos($rawdata, '<title>www.multiup.eu | 522: Connection timed out</title>') || strpos($rawdata, '<title>522 Origin Connection Time-out</title>'))
				self::info('MultiUp HTTP 522');
			elseif(strpos($rawdata, '<title>An Error Occurred: Internal Server Error</title>'))
				self::info('MultiUp HTTP 500');
			elseif(strpos($rawdata, '<title>503 Service Temporarily Unavailable</title>'))
				self::info('MultiUp HTTP 503');
			elseif(strpos($rawdata, '<title>520 Origin Error</title>'))
				self::info('MultiUp HTTP 520');
			elseif(strpos($rawdata, '<span class="cf-footer-item">CloudFlare Ray ID:'))
				self::info('MultiUp CF error');
			elseif($rawdata == 'File not found.')
				self::info('MultiUp "File not found"');
			elseif($rawdata == '{"error":"no server available"}')
				self::info('MultiUp "no server available"');
			elseif(preg_match('~^error code: (5\d\d)$~', $rawdata, $m))
				self::info('MultiUp HTTP '.$m[1]);
			else
				$this->log_dump_data($rawdata, 'multiup_init');
		}
		else {
			if(!preg_match('~^https?\://([a-z0-9\\-]+\.(?:multiup\.(?:org|eu|io)|streamupload\.org))(/.*)$~i', $data['server'], $m)) {
				if(!strpos($rawdata, '<title>500 - Internal Server Error</title>'))
					$this->log_dump_data($rawdata, 'multiup_init');
			} else {
				$server = $m[1];
				$url = $m[2];
			}
		}
		
		$this->cur_services =& $services;
		$postfields = array();
		foreach($services as $svc)
			$postfields[$svc] = 'true';
		// TODO: select_server (it's probably fine, but recording failure stats probably won't be so nice)
		return $this->upload_sockets_sgl_wrapper('https://'.$this->select_server($server).$url, $file, array('files[]' => null), $splitsize, $postfields);
	}
	protected function process_upload(&$data, $part) {
		$links = array();
		if(!isset($data)) {
			// uploading of this part failed - put in dummy links
			if($this->return_servicelinks)
				foreach($this->cur_services as $svcname) {
					$links[$svcname] = null;
				}
			if($this->return_linkpage)
				$links['MU'] = null;
			
		} else {
			$data_s = $data;
			if($error = $this->_upload_sockets_verify($data_s, $url)) {
				return $error;
			}
			
			if($this->return_servicelinks)
				foreach($this->cur_services as $svcname) {
					$links[$svcname] = preg_replace('~^https?\://(?:www\.)?multiup\.(org|eu|io)/download/(.+)$~', 'https://www.multiup.$1/en/mirror/$2', $url);
				}
			if($this->return_linkpage)
				$links['MU'] = $url;
			
		}
		return $links;
	}
	
	public function check_status($id, $site='') {
		static $donedata = array();
		$data =& $donedata[$id];
		if(!isset($data)) {
			if(function_exists('send_request')) { // bypasses annoying CF crap
				$null = null;
				sleep(10); // try not to trigger CF too much...
				$data = send_request('https://www.multiup.eu/en/mirror/'.$id, $null, [
					'referer' => 'https://www.multiup.eu/download/'.$id
				]);
			} else {
				$data = $this->curl_get_contents('https://www.multiup.eu/en/mirror/'.$id, false, function(&$ch) use($id) {
					return 'https://www.multiup.eu/download/'.$id;
				});
			}
			if(!$data)
				return false;
			
			$services = array_flip(self::serviceList());
			preg_match_all('~\<a\s+[^>]*?nameHost\="([^"]+)"\s+[^>]*?link\="(https?\://[^"]+)"(?:\s+[^>]*?validity\="([^"]+)")?~s', $data, $done);
			foreach($done[1] as &$rawSite) {
				$t = @$services[strtolower($rawSite)];
				if($t) $rawSite = $t;
			}
			// TODO: how to detect in-progress?
			// it seems that MultiUp just doesn't send back data on incomplete files...
			// failed links don't appear to get displayed
			$data = array('done' => array_combine($done[1], $done[2]), 'notdone' => array(), 'failed' => array());
		}
		if(empty($data)) return false;
		
		$ret = array();
		$site = strtolower($site);
		foreach($data['done'] as $h => $url) {
			if(!$site || $site == strtolower($h)) {
				$ret[$h] = $url;
			}
		}
		foreach($data['notdone'] as $h) {
			$ret[$h] = false;
		}
		foreach($data['failed'] as $h) { // sometimes Jheberg fixes these up
			$ret[$h] = null;
		}
		
		if($site && !isset($ret[$site])) $ret[$site] = false; // TODO: change this to null
		return $ret;
	}
	
	public function id_from_unresolved_url($url) {
		if(preg_match('~^https?\://(?:www\.)?multiup\.(?:org|eu|io)/(?:download|en/mirror)/(.+)$~i', $url, $m))
			return $m[1];
		return false;
	}
	
	private static function serviceList() {
		// max 12 hosts
		// limits are 4-5GB unless otherwise specified
		// NOTE: keys here are only used for our internal purposes - they need to match the displayed value in ulqueue; MultiUp only cares about the value
		return array( // sizes in MB; * = account required
			'fichier' => '1fichier.com', // 102400
			//'bayfiles' => 'bayfiles.net',
			//'depositfiles' => 'dfiles.eu',
			'filesupload' => 'filesupload.org', // 102400
			//'hugefiles' => 'hugefiles.net',
			//'ryushare' => 'ryushare.com',
			'mega' => 'mega.co.nz', // 102400; *
			'rapidgator' => 'rapidgator.net', // probably *
			//'rapidshare' => 'rapidshare.com', // probably *
			'turbobit' => 'turbobit.net', // 102400
			'uploaded' => 'uploaded.net', // 5120; *
			'uptobox' => 'uptobox.com', // 102400
			//'billionuploads' => 'billionuploads.com',
			//'uploadhero' => 'uploadhero.com',
			'filecloud' => 'filecloud.io', // 10000; probably *
			'free' => 'dl.free.fr', // 10240
			//'firedrive' => 'firedrive.com', // probably * (though small files seem to work without account?)
			'2shared' => '2shared.com', // 200MB; *
			'mediafire' => 'mediafire.com', // 2048; *
			'zippyshare' => 'zippyshare.com', // 200MB
			
			// x = haven't seen succeed
			'nitroflare' => 'nitroflare.com', // 10240; *
			//'easybytez' => 'easybytez.com', // x
			//'usersfiles' => 'usersfiles.com',
			'clicknupload' => 'clicknupload.com', // 2GB
			'oboom' => 'oboom.com', // maybe *
			//'fufox' => 'fufox.net',
			//'toutbox' => 'toutbox.fr', // 1GB
			//'ezfile' => 'ezfile.ch', // x
			//'uplea' => 'uplea.com',
			'userscloud' => 'userscloud.com', // 102400; *
			'solidfiles' => 'solidfiles.com', // 512
			'uppit' => 'uppit.com', // 1GB
			'filerio' => 'filerio.in',
			//'uploadable' => 'uploadable.ch',
			//'warped' => 'warped.co', // x
			//'uploadbaz' => 'uploadbaz.com',
			//'mightyupload' => 'mightyupload.com', // 512MB
			'rockfile' => 'rockfile.eu', // 6144; *
			'tusfiles' => 'tusfiles.net', // 102400; *
			'openload' => 'openload.co', // 10240
			'filefactory' => 'filefactory.com', // 5120; *
			//'nowdownload' => 'nowdownload.to', // x 2GB
			'rutube' => 'rutube.ru', // * (streaming)
			//'youwatch' => 'youwatch.org', // x (streaming)
			'bigfile' => 'bigfile.to', // 10240
			'shareonline' => 'share-online.biz', // 2048
			'chomikuj' => 'chomikuj.pl', // 102400
			'diskokosmiko' => 'diskokosmiko.mx', // 102400
			'filescdn' => 'filescdn.com', // 102400
			'kbagi' => 'kbagi.com', // 102400
			'minhateca' => 'minhateca.com.br', // 102400
			'uploadboy' => 'uploadboy.com', // 20480
			'sendspace' => 'sendspace.com', // 10240
			'uploading' => 'uploading.site', // 9216
			'dailyuploads' => 'dailyuploads.net', // 6144
			'uploadrocket' => 'uploadrocket.net', // 6144
			'downace' => 'downace.com', // 5120
			'bdupload' => 'bdupload.info', // 2048
			'indishare' => 'indishare.me', // 2048
			'4shared' => '4shared.com', // 2048; *
		);
	}
	public function setServices($s) {
		$services = array_values(self::serviceList());
		if(empty($s)) $s = $services;
		$this->services = array_intersect($services, $s);
	}
}

