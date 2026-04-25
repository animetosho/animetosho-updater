<?php

include_once __DIR__.'/filehasher.php';
if(!extension_loaded('atext'))
	@dl('atext.so');

define('_FILEHANDLER_FADVISE', function_exists('posix_fadvise'));
define('_LINUX_MAX_READAHEAD', 2*1048576); // Linux kernel limits read aheads to 2MB each

class FileHandler {
	//const buffersize = 8388608; // read 8MB chunks
	
	private $file;
	private $fp;
	protected $filepos = '0';
	protected $filehasher = null;
	private $hashpos = '0';
	public $hashes = null;
	public $basename;
	private $read_ahead = 0;
	private $ra_lowmark = 0;
	private $ra_buffer = 0;
	protected $filesize = '0';
	
	protected $wrapper_var_suf = ''; // overwrite in child classes to utilise some functions here, but with different variables
	
	private $lastUsedStream = null; // last stream copied to using copy_to
	private $copyMeth = 'stream_copy_to_stream';
	
	private $offset;
	
	function __construct($file, $offset=0, $size=0, $basename=null) {
		$this->file = $file;
		$this->fp = fopen($file, 'rb');
		if($offset) $this->_seek($offset);
		$this->offset = $offset;
		
		$this->filesize = $size ?: bcsub($this->_size(), $offset);
		$this->basename = $basename ?: $this->basename();
		
		if(_FILEHANDLER_FADVISE)
			posix_fadvise($this->fp, POSIX_FADV_SEQUENTIAL);
		//$this->set_filepos(0, true); // not much point in doing this because read_ahead won't be set
	}
	function __destruct() {
		fclose($this->fp);
	}
	public function is_error() {
		return !$this->fp;
	}
	
	// read ahead amount will be rounded up to nearest 2MB boundary
	public function set_readahead($ra, $req=null) {
		if(PHP_INT_MAX < 1e10) return; // currently 32-bit not supported
		$this->read_ahead = $ra;
		$this->ra_lowmark = (int)($req ?: ceil($ra/2));
	}
	
	// requires: $this->filepos == '0'
	public function init_hashes($algos) {
		$this->filehasher = new FileHasher($algos);
	}
	private function get_hashes() {
		if(!isset($this->filehasher)) return null;
		$this->hashes = $this->filehasher->end();
		$this->filehasher = null;
	}
	
	private function set_filepos($amount, $absolute=false) {
		if($absolute)
			$this->filepos = (string)$amount;
		else
			$this->filepos = bcadd($this->filepos, $amount);
		if($this->read_ahead && _FILEHANDLER_FADVISE) {
			if(!$absolute) $this->ra_buffer -= $amount;
			if($absolute || $this->ra_buffer < $this->ra_lowmark) {
				for($this->ra_buffer = 0; $this->ra_buffer < $this->read_ahead; $this->ra_buffer += _LINUX_MAX_READAHEAD) {
					$offs = $this->filepos+$this->ra_buffer;
					if($offs > $this->filesize) break;
					posix_fadvise($this->fp, POSIX_FADV_WILLNEED, $offs, _LINUX_MAX_READAHEAD);
				}
			}
		}
	}
	
	// warning: this function doesn't check the upper bound ($this->filesize)
	public function seek($pos) {
		if($this->offset)
			$this->_seek(bcadd($pos, $this->offset));
		else
			$this->_seek($pos);
		$this->set_filepos($pos, true);
	}
	// emulate large fseek($fp, $pos, SEEK_SET) on 32-bit system
	// requires bcmath
	private function _seek($pos) {
		if(bccomp((string)PHP_INT_MAX, $pos, 0) > -1) // small, do it natively
			fseek($this->fp, (int)$pos, SEEK_SET);
		else {
			fseek($this->fp, PHP_INT_MAX, SEEK_SET);
			fread($this->fp, 1); // get past fseek limitation
			$pos = bcsub(bcsub($pos, (string)PHP_INT_MAX, 0), 1, 0);
			
			$chunk = 8192-1; // get around weird 8KB limitation
			while($pos) {
				if(bccomp((string)$chunk, $pos, 0) > -1) {
					fseek($this->fp, (int)$pos, SEEK_CUR);
					break;
				}
				else {
					fseek($this->fp, $chunk, SEEK_CUR);
					fread($this->fp, 1);
					$pos = bcsub($pos, (string)($chunk+1), 0);
				}
			}
		}
	}
	public function size() {
		$var = 'filesize'.$this->wrapper_var_suf;
		return $this->$var;
	}
	public function tell() {
		$var = 'filepos'.$this->wrapper_var_suf;
		return $this->$var;
	}
	public function eof() {
		$var_sz = 'filesize'.$this->wrapper_var_suf;
		$var_ps = 'filepos'.$this->wrapper_var_suf;
		return bccomp($this->$var_ps, $this->$var_sz) >-1 /*feof($this->fp)*/;
	}
	public function copy_to($stream, $amount=0) {
		if(isset($this->filehasher)) 
			return $this->copy_to_slow($stream, $amount);
		
		// TODO: error detection?
		if($this->lastUsedStream !== $stream && function_exists('atext_sendfile')) {
			$this->copyMeth = atext_is_raw_stream($stream) ? 'atext_sendfile' : 'stream_copy_to_stream';
		}
		$f = $this->copyMeth;
		$ret = $f($this->fp, $stream, $amount);
		$this->set_filepos($ret);
		
		$this->lastUsedStream = $stream;
		return $ret;
	}
	protected function copy_to_slow($stream, $amount=0) {
		// TODO: buffered chunks?
		return fwrite($stream, $this->read($amount));
	}
	public function read($amount) {
		if(bccomp(bcadd($this->filepos, $amount), $this->filesize) > 0) {
			$amount = bcsub($this->filesize, $this->filepos);
		}
		$data = fread($this->fp, $amount);
		$newfilepos = bcadd($this->filepos, $amount);
		if(isset($this->filehasher)) {
			if((string)$this->filepos === (string)$this->hashpos) { // identity compare because PHP messes up numeric strings at times
				// can pump data directly through
				$this->filehasher->add($data);
				$this->hashpos = $newfilepos;
			} elseif(bccomp($this->hashpos, $this->filepos) >0 && bccomp($this->hashpos, $newfilepos) <0) {
				// we can only pump partial data through
				$this->filehasher->add(substr($data, (int)bcsub($this->hashpos, $this->filepos)));
				$this->hashpos = $newfilepos;
			} // only other cases are either ignored, or broken behaviour
			// if we're at the end, collect hashes
			if(bccomp($this->hashpos, $this->filesize) >= 0) {
				$this->get_hashes();
			}
		}
		$this->set_filepos($amount);
		return $data;
	}
	protected function basename() {
		$basefile = $this->name();
		$p = mb_strrpos($basefile, '/');
		if($p) $basefile = mb_substr($basefile, $p+1);
		return $basefile;
	}
	public function name() {
		return $this->file;
	}
	protected function _size() {
		return filesize($this->file);
		/*
		$res = trim(@shell_exec('stat -c%s -- '.escapeshellarg($this->file)));
		if($res === '') {
			// weird issue where execution sometimes fails (out of memory?)
			// just try one more time
			sleep(5);
			$res = trim(shell_exec('stat -c%s -- '.escapeshellarg($this->file)));
			if($res === '')
				$res = filesize($this->file); // fallback
		}
		return $res;
		*/
	}
}
