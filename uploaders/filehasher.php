<?php

if(!extension_loaded('openssl_incr'))
	@dl('openssl_incr.so');

class FileHasher {
	private $algos;
	private $f_add;
	private $f_end;
	function __construct($algos) {
		$this->algos = array_flip($algos);
		$this->f_add = $this->f_end = array();
		$use_openssl = function_exists('openssl_digest_init');
		foreach($this->algos as $algo => &$ctx) {
			$cls = 'Hashstream_'.$algo;
			if(class_exists($cls)) {
				$ctx = new $cls;
				$this->f_add[$algo] = array($this, 'class_add');
				$this->f_end[$algo] = array($this, 'class_end');
			}
			elseif(preg_match('~^torpc_sha1_(\d+)k$~', $algo, $m)) {
				$ctx = new Hashstream_torsha((int)$m[1] * 1024);
				$this->f_add[$algo] = array($this, 'class_add');
				$this->f_end[$algo] = array($this, 'class_end');
			}
			elseif($use_openssl && in_array($algo, array('md4','md5','sha1','sha224','sha256','sha384','sha512'))) {
				// OpenSSL algos
				$ctx = openssl_digest_init($algo);
				$this->f_add[$algo] = 'openssl_digest_update';
				$this->f_end[$algo] = 'openssl_digest_final';
			}
			else {
				// special case for crc32/crc32b (switch them)
				if($algo == 'crc32')
					$ctx = hash_init('crc32b');
				elseif($algo == 'crc32b')
					$ctx = hash_init('crc32');
				else
					$ctx = hash_init($algo);
				$this->f_add[$algo] = 'hash_update';
				$this->f_end[$algo] = 'hash_final';
			}
		}
	}
	
	private function class_add($ctx, $data) {
		$ctx->add($data);
	}
	private function class_end($ctx) {
		return $ctx->end();
	}
	
	public function add($data) {
		foreach($this->algos as $algo => &$ctx) {
			call_user_func($this->f_add[$algo], $ctx, $data);
		}
	}
	
	public function end() {
		$ret = array();
		foreach($this->algos as $algo => &$ctx) {
			$ret[$algo] = call_user_func($this->f_end[$algo], $ctx, true);
		}
		if(isset($ret['crc32']) && hash('crc32b', 'abc') == 'c2412435') // CRC32 fix
			$ret['crc32'] = strrev($ret['crc32']);
		return $ret;
	}
}
function file_hashes($fn, $algos) {
	if(!($fp = fopen($fn, 'rb'))) return false;
	
	$fh = new FileHasher($algos);
	while(!feof($fp)) {
		// TODO: add semaphore
		$fh->add(fread($fp, 16*1024*1024)); // read 16MB chunks
	}
	fclose($fp);
	
	return $fh->end();
}

class Hashstream_tth {
	const blocksize = 1024; // TTH blocksize
	private $doSwp = false; // fix bug in PHP < 5.4.0
	
	private $buffer = '';
	private $hashtree = array();
	public function add($data) {
		$len = strlen($data);
		$pos = 0;
		// try to fill buffer
		if($this->buffer !== '') {
			$buflen = strlen($this->buffer);
			if($buflen + $len >= self::blocksize) {
				// we can hash
				$pos = self::blocksize - $buflen;
				$this->buffer .= substr($data, 0, $pos);
				$this->appendHash($this->hash("\0".$this->buffer));
				$this->buffer = '';
			} else {
				// won't fill buffer, just append
				$this->buffer .= $data;
				return;
			}
		}
		for(; $pos+self::blocksize <= $len; $pos += self::blocksize) {
			$this->appendHash($this->hash("\0".substr($data, $pos, self::blocksize)));
		}
		if($pos < $len)
			$this->buffer = substr($data, $pos);
	}
	
	public function _end_fold($a, $b) {
		if(!isset($a)) return $b; // initial state
		return $this->hash("\x1".$b.$a);
	}
	public function end() {
		if($this->buffer !== '')
			$this->appendHash($this->hash("\0".$this->buffer));
		
		if(empty($this->hashtree))
			return $this->hash("\0");
		
		return array_reduce(array_filter($this->hashtree), array($this, '_end_fold'));
	}
	
	private function appendHash($h) {
		$i = -1;
		while(isset($this->hashtree[++$i])) {
			$h = $this->hash("\x1".$this->hashtree[$i].$h);
			$this->hashtree[$i] = null;
		}
		$this->hashtree[$i] = $h;
	}
	function __construct() {
		$this->doSwp = (hash('tiger192,3', '') == '24f0130c63ac933216166e76b1bb925ff373de2d49584e7a');
	}
	private function hash($s) {
		$r = hash('tiger192,3', $s, true);
		if($this->doSwp)
			$r = strrev(substr($r, 0, 8)) . strrev(substr($r, 8, 8)) . strrev(substr($r, 16, 8));
		return $r;
	}
}

if(function_exists('openssl_digest_init')) {
	class Hashstream_ed2k {
		const blocksize = 9728000; // ED2K MD4 blocksize
		
		private $ctx;
		private $buflen = 0;
		private $hashstream = null;
		private $hashed = 0;
		function __construct() {
			$this->hashstream = openssl_digest_init('md4');
		}
		public function add($data) {
			$len = strlen($data);
			$this->hashed += $len;
			$pos = 0;
			// try to fill buffer
			if($this->buflen) {
				if($this->buflen + $len >= self::blocksize) {
					// we can hash
					$pos = self::blocksize - $this->buflen;
					openssl_digest_update($this->ctx, substr($data, 0, $pos));
					openssl_digest_update($this->hashstream, openssl_digest_final($this->ctx, true));
					$this->buflen = 0;
				} else {
					// won't fill buffer, just append
					openssl_digest_update($this->ctx, $data);
					$this->buflen += $len;
					return;
				}
			}
			for(; $pos+self::blocksize <= $len; $pos += self::blocksize) {
				openssl_digest_update($this->hashstream, openssl_digest(substr($data, $pos, self::blocksize), 'md4', true));
			}
			if($pos < $len) {
				$this->ctx = openssl_digest_init('md4');
				openssl_digest_update($this->ctx, substr($data, $pos));
				$this->buflen = $len-$pos;
			}
		}
		public function end() {
			if($this->buflen)
				$final_hash = openssl_digest_final($this->ctx, true);
			else
				$final_hash = openssl_digest('', 'md4', true);
			if($this->hashed < self::blocksize) {
				// special case of no double-hashing
				openssl_digest_final($this->hashstream, true);
				return $final_hash;
			}
			// we need to append 0-byte chunk if necessary, so hash buffer even if empty
			openssl_digest_update($this->hashstream, $final_hash);
			return openssl_digest_final($this->hashstream, true);
		}
	}
	class Hashstream_bt2 {
		const blocksize = 16384; // BTv2 blocksize
		
		private $ctx;
		private $buflen = 0;
		private $hashtree = array();
		public function add($data) {
			$len = strlen($data);
			$pos = 0;
			// try to fill buffer
			if($this->buflen) {
				if($this->buflen + $len >= self::blocksize) {
					// we can hash
					$pos = self::blocksize - $this->buflen;
					openssl_digest_update($this->ctx, substr($data, 0, $pos));
					$this->appendHash(openssl_digest_final($this->ctx, true));
					$this->buflen = 0;
				} else {
					// won't fill buffer, just append
					openssl_digest_update($this->ctx, $data);
					$this->buflen += $len;
					return;
				}
			}
			for(; $pos+self::blocksize <= $len; $pos += self::blocksize) {
				$this->appendHash(openssl_digest(substr($data, $pos, self::blocksize), 'sha256', true));
			}
			if($pos < $len) {
				$this->ctx = openssl_digest_init('sha256');
				openssl_digest_update($this->ctx, substr($data, $pos));
				$this->buflen = $len-$pos;
			}
		}
		
		public function end() {
			if($this->buflen)
				$this->appendHash(openssl_digest_final($this->ctx, true));
			
			if(empty($this->hashtree))
				return ''; // empty files not allowed
			
			// if tree is balanced, we've already got the hash
			if(count(array_filter($this->hashtree)) == 1)
				return end($this->hashtree);
			
			// otherwise, need to balance tree
			$emptyHash = str_repeat("\0", 32);
			$h = $emptyHash;
			foreach($this->hashtree as $leaf) {
				if(isset($leaf))
					$h = openssl_digest($leaf.$h, 'sha256', true);
				else
					$h = openssl_digest($h.$emptyHash, 'sha256', true);
				$emptyHash = openssl_digest($emptyHash.$emptyHash, 'sha256', true);
			}
			return $h;
		}
		
		private function appendHash($h) {
			$i = -1;
			while(isset($this->hashtree[++$i])) {
				$h = openssl_digest($this->hashtree[$i].$h, 'sha256', true);
				$this->hashtree[$i] = null;
			}
			$this->hashtree[$i] = $h;
		}
	}
	class Hashstream_torsha {
		private $blocksize;
		private $ctx;
		private $buflen = 0;
		private $hashstream = null;
		private $hashed = 0;
		function __construct($blocksize) {
			$this->hashstream = openssl_digest_init('sha1');
			$this->blocksize = $blocksize;
		}
		public function add($data) {
			$len = strlen($data);
			$this->hashed += $len;
			$pos = 0;
			// try to fill buffer
			if($this->buflen) {
				if($this->buflen + $len >= $this->blocksize) {
					// we can hash
					$pos = $this->blocksize - $this->buflen;
					openssl_digest_update($this->ctx, substr($data, 0, $pos));
					openssl_digest_update($this->hashstream, openssl_digest_final($this->ctx, true));
					$this->buflen = 0;
				} else {
					// won't fill buffer, just append
					openssl_digest_update($this->ctx, $data);
					$this->buflen += $len;
					return;
				}
			}
			for(; $pos+$this->blocksize <= $len; $pos += $this->blocksize) {
				openssl_digest_update($this->hashstream, openssl_digest(substr($data, $pos, $this->blocksize), 'sha1', true));
			}
			if($pos < $len) {
				$this->ctx = openssl_digest_init('sha1');
				openssl_digest_update($this->ctx, substr($data, $pos));
				$this->buflen = $len-$pos;
			}
		}
		public function end() {
			if($this->hashed < $this->blocksize) return ''; // no hash if file is too small
			return openssl_digest_final($this->hashstream, true);
		}
	}
} else {
	class Hashstream_ed2k {
		const blocksize = 9728000; // ED2K MD4 blocksize
		
		private $buffer = '';
		private $hashstream = '';
		public function add($data) {
			$len = strlen($data);
			$pos = 0;
			// try to fill buffer
			if($this->buffer !== '') {
				$buflen = strlen($this->buffer);
				if($buflen + $len >= self::blocksize) {
					// we can hash
					$pos = self::blocksize - $buflen;
					$this->buffer .= substr($data, 0, $pos);
					$this->hashstream .= hash('md4', $this->buffer, true);
					$this->buffer = '';
				} else {
					// won't fill buffer, just append
					$this->buffer .= $data;
					return;
				}
			}
			for(; $pos+self::blocksize <= $len; $pos += self::blocksize) {
				$this->hashstream .= hash('md4', substr($data, $pos, self::blocksize), true);
			}
			if($pos < $len)
				$this->buffer = substr($data, $pos);
		}
		
		public function end() {
			if($this->hashstream === '') {
				// special case of no double-hashing
				return hash('md4', $this->buffer, true);
			}
			// we need to append 0-byte chunk if necessary, so hash buffer even if empty
			return hash('md4', $this->hashstream.hash('md4', $this->buffer, true), true);
		}
	}
	class Hashstream_bt2 {
		const blocksize = 16384; // BTv2 blocksize
		
		private $buffer = '';
		private $hashtree = array();
		public function add($data) {
			$len = strlen($data);
			$pos = 0;
			// try to fill buffer
			if($this->buffer !== '') {
				$buflen = strlen($this->buffer);
				if($buflen + $len >= self::blocksize) {
					// we can hash
					$pos = self::blocksize - $buflen;
					$this->buffer .= substr($data, 0, $pos);
					$this->appendHash($this->hash($this->buffer));
					$this->buffer = '';
				} else {
					// won't fill buffer, just append
					$this->buffer .= $data;
					return;
				}
			}
			for(; $pos+self::blocksize <= $len; $pos += self::blocksize) {
				$this->appendHash($this->hash(substr($data, $pos, self::blocksize)));
			}
			if($pos < $len)
				$this->buffer = substr($data, $pos);
		}
		
		public function end() {
			if($this->buffer !== '')
				$this->appendHash($this->hash($this->buffer));
			
			if(empty($this->hashtree))
				return ''; // empty files not allowed
			
			// if tree is balanced, we've already got the hash
			if(count(array_filter($this->hashtree)) == 1)
				return end($this->hashtree);
			
			// otherwise, need to balance tree
			$emptyHash = str_repeat("\0", 32);
			$h = $emptyHash;
			foreach($this->hashtree as $leaf) {
				if(isset($leaf))
					$h = $this->hash($leaf.$h);
				else
					$h = $this->hash($h.$emptyHash);
				$emptyHash = $this->hash($emptyHash.$emptyHash);
			}
			return $h;
		}
		
		private function appendHash($h) {
			$i = -1;
			while(isset($this->hashtree[++$i])) {
				$h = $this->hash($this->hashtree[$i].$h);
				$this->hashtree[$i] = null;
			}
			$this->hashtree[$i] = $h;
		}
		private function hash($s) {
			return hash('sha256', $s, true);
		}
	}
	class Hashstream_torsha {
		private $blocksize;
		private $buffer = '';
		private $hashstream = '';
		function __construct($blocksize) {
			$this->blocksize = $blocksize;
		}
		public function add($data) {
			$len = strlen($data);
			$pos = 0;
			// try to fill buffer
			if($this->buffer !== '') {
				$buflen = strlen($this->buffer);
				if($buflen + $len >= $this->blocksize) {
					// we can hash
					$pos = $this->blocksize - $buflen;
					$this->buffer .= substr($data, 0, $pos);
					$this->hashstream .= hash('sha1', $this->buffer, true);
					$this->buffer = '';
				} else {
					// won't fill buffer, just append
					$this->buffer .= $data;
					return;
				}
			}
			for(; $pos+$this->blocksize <= $len; $pos += $this->blocksize) {
				$this->hashstream .= hash('sha1', substr($data, $pos, $this->blocksize), true);
			}
			if($pos < $len)
				$this->buffer = substr($data, $pos);
		}
		public function end() {
			if($this->hashstream === '') return '';
			// we need to append 0-byte chunk if necessary, so hash buffer even if empty
			return hash('sha1', $this->hashstream, true);
		}
	}
}

