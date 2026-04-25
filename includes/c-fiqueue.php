<?php

require ROOT_DIR.'init.php';

$fiQ_processor = substr(THIS_SCRIPT, 5, -4);
make_lock_file($fiQ_processor);

loadDb();
unset($config);


require ROOT_DIR.'includes/ulfuncs.php';
require_once ROOT_DIR.'includes/filelinks.php'; // only for mkaextract

@set_time_limit(60);

if(!isset($order) || !$order) $order = 'retries ASC, dateline ASC';




for($i=0; $i<20; ++$i) { // we'll limit to processing 20 files per process...
	
	// check to see if the ulqueue is busy - if so, defer fiqueue uploading
	$ulqCount = $db->selectGetField('ulqueue', 'COUNT(*)', 'status=1');
	if($ulqCount > 3) break;
	
	// get next thing to do
	$time = time();
	$task = $db->selectGetArray('fiqueue', 'status=0 AND dateline<='.$time, '*', array('order' => $order));
	if(empty($task)) break; // end of queue
	
	$hdlcls = 'handle_'.$task['type'];
	if(!class_exists($hdlcls)) {
		error('Missing handler for '.$task['type'].'!!!');
		continue;
	}
	
	if($db->update('fiqueue', array('status' => 1), 'id='.$task['id'].' AND status=0') != 1)
		continue; // race condition conflict, continue
	
	// get info
	$file = $db->selectGetArray('files', 'files.id='.$task['fid'], 'files.*, toto.deleted, toto.completed_date', array('joins' => array(
		array('inner', 'toto', 'toto_id', 'id')
	)));
	
	// file deleted recently? defer processing it for a bit
	if($file['deleted'] && $time - $file['completed_date'] <= 86400) {
		log_event('Deferring '.$task['type'].' for '.$file['filename']);
		$db->update('fiqueue', array(
			'dateline' => time() + 7200,
			'status' => 0
		), 'id='.$task['id']);
		continue;
	}
	
	$dir = TOTO_ULQUEUE_PATH.'fileinfo_'.$task['fid'].'/';
	$failed = false;
	
	log_event('Processing '.$task['type'].' for '.$file['filename']);
	
	$data = null;
	if($task['data'])
		$data = json_decode($task['data']);
	
	$handler = new $hdlcls($data, $file, $dir);
	
	// is this file marked as deleted? if so, don't actually process this
	if(!$file['deleted']) {
		// TODO: maybe have some failure detection backoff thingy?
		
		$update = array();
		$failed = $handler->exec($update);
		if(!empty($update))
			$db->update('files', $update, 'id='.$task['fid']);
	}
	
	if(!$failed || ($task['retries'] > 4 && !$handler->hold_failures)) {
		$db->delete('fiqueue', 'id='.$task['id']);
		$handler->cleanup($failed);
		if(!count(glob($dir.'*'))) {
			rmdir($dir);
		}
		if($failed)
			log_event('Processing failed and maxed out retries');
		elseif($file['deleted'])
			log_event('Processing skipped because file is deleted.');
		else
			log_event('Processing completed successfully');
	} elseif($task['retries'] > 4) {
		log_event('Processing failed, retries exhausted - placing item in hold state');
		$db->update('fiqueue', array(
			'status' => -1,
			'retries' => $task['retries']+1,
			'data' => json_encode($data)
		), 'id='.$task['id']);
	} else {
		log_event('Processing failed, queuing retry');
		// delayed retry
		$db->update('fiqueue', array(
			'dateline' => time() + 3600*($task['retries']+1),
			'status' => 0,
			'retries' => $task['retries']+1,
			'data' => json_encode($data)
		), 'id='.$task['id']);
	}
}

abstract class handler {
	public $hold_failures = false;
	protected $data;
	protected $file;
	protected $dir;
	function __construct(&$data, $file, $dir) {
		$this->data =& $data;
		$this->file = $file;
		$this->dir = $dir;
		$this->init();
	}
	protected function init() {}
	abstract public function exec(&$update);
	public function cleanup($failed) {}
}

class handle_sshot extends handler {
	protected function init() {
	}
	function cleanup($failed) {
		if($failed)
			warning('[sshot-upload] Failed to upload all screenshots for '.$this->file['filename'], 'fiqueue');
		foreach($this->data as $k => $img) {
			if(!preg_match('~^https?\://~', $img))
				@unlink($img);
		}
		// TODO: also clean up any stray files?
	}
	function exec(&$update) {
		static $uploaders = null;
		if(!isset($uploaders)) {
			if(!class_exists('uploader_PostImages')) {
				require_once ROOT_DIR.'uploaders/image-postimages.php';
			}
			$uploaders = [
				'postimages' => new uploader_PostImages()
			];
			foreach($uploaders as &$uploader)
				$uploader->upload_sockets_retries = 0;
			unset($uploader);
		}
		log_event('Uploading images...');
		
		$ssupdate = array();
		$retry = false;
		foreach($this->data as $k => $img) {
			if(preg_match('~^https?\://~', $img)) {
				// already uploaded - skip this
				$ssupdate[$k] = array($img);
			} elseif(file_exists($img)) {
				log_event('Uploading '.$img);
				
				$urls = null;
				foreach($uploaders as $ulsite => $uploader) {
					$urls = $uploader->upload(array($img => ''));
					if(!empty($urls) && !empty($urls[$img][$ulsite])) {
						log_event('Upload to '.$ulsite.' successful');
						break;
					} else {
						log_event('Upload to '.$ulsite.' failed');
						// TODO: perhaps skip this host for the other files as well?
					}
				}
				if(!empty($urls) && !empty($urls[$img][$ulsite])) {
					// success, store URL
					$url = $urls[$img][$ulsite];
					$ssupdate[$k] = array($url);
					$this->data->$k = $url;
					
					unlink($img);
				} else
					$retry = true;
			} else {
				warning('File '.$img.' no longer exists!');
				// kill this entry
				unset($this->data->$k);
			}
		}
		
		$update['filethumbs'] = serialize($ssupdate);
		if($retry)
			log_event('Processing ended with failures');
		else
			log_event('Processing successfully completed');
		
		return $retry;
	}
}

class handle_mkaextract extends handler {
	public $hold_failures = true;
	private $mkafile;
	protected function init() {
		if(empty($this->data)) {
			$this->data = (object)['gofile' => null, 'mdiaload' => null, 'buzzheavier' => null];
		}
		
		$files = glob($this->dir.'*.mka');
		if(count($files) == 1) $this->mkafile = reset($files);
	}
	static function getHost($host) {
		static $hosts = null;
		if(!isset($hosts)) {
			$hosts = include(ROOT_DIR.'includes/uploadhosts.php');
		}
		if(!isset($hosts[$host]['singleargs'])) return null;
		return $hosts[$host]['singleargs'];
	}
	function cleanup($failed) {
		if($failed)
			warning('[mkaextract-upload] Failed to upload to all hosts '.$this->file['filename'], 'fiqueue');
		if($this->mkafile) @unlink($this->mkafile);
	}
	function exec(&$update) {
		if(!$this->mkafile) {
			warning('[mkaextract-upload] Could not find file in '.$this->dir, 'fiqueue');
			return true;
		}
		log_event('Uploading audio extract...');
		
		$doUpdate = false;
		$retry = false;
		foreach($this->data as $host => $url) {
			if($url) continue; // already done
			
			$hostInfo = self::getHost($host);
			if(empty($hostInfo)) { // host disabled, remove it and continue
				//error('[mkaextract-upload] Missing host info for '.$host);
				unset($this->data->$host);
				continue;
			}
			
			log_event('Uploading to '.$hostInfo[0]);
			// set up uploader
			$class = 'uploader_'.$hostInfo[0];
			if(!class_exists($class)) {
				require_once ROOT_DIR.'uploaders/'.$host.'.php';
			}
			$uploader = new $class;
			$uploader->setDb($GLOBALS['db']);
			if(!empty($hostInfo[4])) {
				$uploader->upload_sockets_retries = $hostInfo[4][0];
				$uploader->upload_sockets_error_delay = $hostInfo[4][3];
			}
			$uploader->upload_sockets_break_on_failure = true;
			// TODO: integrate into ulqueue to get all its features?
			
			// upload
			$urls = $uploader->upload([$this->mkafile => '']);
			
			if(!empty($urls) && !empty($urls[$this->mkafile][$hostInfo[1]])) {
				log_event('Upload to '.$host.' successful');
				$doUpdate = true;
				// record link down
				$this->data->$host = ['url' => $urls[$this->mkafile][$hostInfo[1]], 'added' => time()];
			} else {
				log_event('Upload to '.$host.' failed');
				$retry = true;
			}
		}
		
		if($doUpdate) {
			$links = [];
			foreach($this->data as $host => $urlInfo) {
				if(!$urlInfo) continue;
				$hostInfo = self::getHost($host);
				if(!$hostInfo) continue;
				$links[$hostInfo[2]] = (array)$urlInfo;
			}
			$update['audioextract'] = filelinks_enc($links, false, 'audioextract');
			
			if($retry)
				log_event('Processing ended with failures');
			else
				log_event('Processing successfully completed');
		} else {
			log_event('All attempted uploads failed');
		}
		
		return $retry;
	}
}
