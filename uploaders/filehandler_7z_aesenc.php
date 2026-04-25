<?php

// NOTE: requires PHP >= 5.4.0

class SevenZ_AES {
	const hashRounds = 10; // 1024 rounds
	const blockSize = 16; // 256-bit AES still uses 128-bit blocks
	const ivSize = 8; // 7zip uses 64-bit IVs by default
	
	private $key;
	private $buffer='';
	private $iv;
	
	function __construct($iv=null) {
		if(isset($iv)) {
			if($iv !== '')
				$this->iv = substr(str_repeat($iv, self::ivSize), 0, self::ivSize);
			else
				$this->iv = '';
		}
		else
			// generate IV
			$this->iv = openssl_random_pseudo_bytes(self::ivSize);
	}
	// MUST call this function or load_state() after construction
	function setPassword($pwd, $rounds=self::hashRounds) {
		// hash pwd to get key
		$pwdstrm = '';
		for($i=0; $i<pow(2,$rounds); ++$i) {
			$pwdstrm .= $pwd.pack('V', $i)."\0\0\0\0";
		}
		$this->key = openssl_digest($pwdstrm, 'sha256', true);
		unset($pwdstrm);
		
		if($this->iv === '') // no IV used
			return chr($rounds);
		return
			 chr($rounds | 0x40) /*IV size specified*/
			.chr(strlen($this->iv) -1)
			.$this->iv
			;
	}
	
	function get_state() {
		return array(
			$this->key,
			$this->buffer,
			$this->iv,
		);
	}
	function load_state($state) {
		$this->key = $state[0];
		$this->buffer = $state[1];
		$this->iv = $state[2];
	}
	
	function size($rawlen) {
		if($m = bcmod($rawlen, self::blockSize)) {
			// factor in padding
			return bcadd($rawlen, self::blockSize - (int)$m);
		}
		return $rawlen;
	}
	function enc($data) {
		$this->buffer .= $data;
		$l = strlen($this->buffer);
		if($l >= self::blockSize) {
			// perform encryption
			$size = $l - ($l % self::blockSize);
			$ret = openssl_encrypt(substr($this->buffer, 0, $size), 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING, $this->getIv());
			$this->buffer = substr($this->buffer, $size);
			
			// last block = new IV in CBC
			$this->iv = substr($ret, -self::blockSize);
			return $ret;
		}
		return '';
	}
	function end() {
		if($l = strlen($this->buffer)) {
			$ret = openssl_encrypt($this->buffer . str_repeat("\0", 16 - ($l & 0xf)), 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING, $this->getIv());
			return $ret;
		}
		return '';
	}
	
	private function getIv() {
		if($this->iv === '')
			return str_repeat("\0", self::blockSize);
		else
			return str_pad($this->iv, self::blockSize, "\0");
	}
}
