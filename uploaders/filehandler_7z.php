<?php

require_once __DIR__.'/filehandler.php';
if(!extension_loaded('atext'))
	@dl('atext.so');

class FileHandler_7z extends FileHandler {
	const SIGNATURE = "7z\xBC\xAF\x27\x1C\0\x02";
	
	const SIG_END = "\0";
	const SIG_HEADER = "\x01";
	const SIG_ARCPROP = "\x02"; // archive properties
	const SIG_ADDSTRMINFO = "\x03"; // additional stream info
	const SIG_STRMINFO = "\x04"; // main stream info
	const SIG_FILESINFO = "\x05";
	const SIG_PACKINFO = "\x06";
	const SIG_UNPACKINFO = "\x07";
	const SIG_SUBSTRMINFO = "\x08"; // substream info
	const SIG_SIZE = "\x09";
	const SIG_CRC = "\x0A";
	const SIG_FOLDER = "\x0B";
	const SIG_CODUNPACKSIZE = "\x0C"; // coders unpack size
	const SIG_NUMUNPACKSTRM = "\x0D"; // num unpack stream
	const SIG_EMPTYSTRM = "\x0E";
	const SIG_EMPTYFILE = "\x0F";
	const SIG_ANTI = "\x10";
	const SIG_NAME = "\x11";
	const SIG_CREATTIME = "\x12"; // creation time
	const SIG_LACCTIME = "\x13"; // last access time
	const SIG_LWRTTIME = "\x14"; // last write time
	const SIG_WINATTR = "\x15"; // windows attributes
	const SIG_COMMENT = "\x16";
	const SIG_ENCHEADER = "\x17"; // encoded header
	
	private $header_signature;
	private $header_pre;
	private $header_post;
	private $header_postdec;
	
	private $header_gen_cache = null;
	private $filters = array();
	private $delta_ctx = 0;
	private $swap4_ctx = '';
	private $aes_ctx = null;
	
	protected $wrapper_var_suf = '_7z';
	protected $filepos_7z = 0;
	protected $filesize_7z = 0;
	
	// 2nd parameter: if string, encrypt header, otherwise if true, do delta scrambling
	// if $prefix_header is non-empty, $encpwd cannot be false
	function __construct($fn, $encpwd=false, $offset=0, $size=0, $basename=null, $prefix_header='') {
		parent::__construct($fn, $offset, $size, $basename);
		
		$parent_basename = parent::basename();
		if(is_string($encpwd)) {
			require_once __DIR__.'/filehandler_7z_aesenc.php';
			$this->aes_ctx = new SevenZ_AES(sha1(sha1('h89gs923mklasd98g'.$parent_basename.'9bh90gh0340afb0892b3407gh'.parent::_size(), true), true)); // use a derived IV so that resuming works okay
			$cryptProps = $this->aes_ctx->setPassword(mb_convert_encoding($encpwd, 'UCS-2LE'));
		}
		
		// generate headers and stuff
		$unpack_size = self::uint64($this->filesize);
		$filename = mb_convert_encoding($basename ?: $parent_basename, 'UCS-2LE')."\0\0";
		$this->header_pre =
			 self::SIG_HEADER.
				self::SIG_STRMINFO.
					self::SIG_PACKINFO.
						"\0". // UINT64 PackPos
						"\x01". // UINT64 NumPackStreams
						self::SIG_SIZE . $unpack_size.
					self::SIG_END.
					self::SIG_UNPACKINFO.
						self::SIG_FOLDER.
						"\x01". // UINT64 NumFolders
						"\0". // BYTE External
						// following is the folder entry
						($encpwd!==false ? 
						/* for delta encoding
							"\x01". // UINT64 NumCoders
							"\x21". // DecompressionMethod.IDSize + 'has properties'
							"\x03". // BYTE DecompressionMethod.ID[DecompressionMethod.IDSize] (delta encoding)
							"\x01". // UINT64 PropertiesSize
							"\0" // delta grouping size (1 byte delta)
						*/
						// swap4 encoding
							"\x01". // UINT64 NumCoders
							"\x03". // DecompressionMethod.IDSize + 'has properties'
							"\x02\x03\x04" // BYTE DecompressionMethod.ID[DecompressionMethod.IDSize] (swap4 encoding)
						:
							"\x01". // UINT64 NumCoders
							"\x01". // DecompressionMethod.IDSize
							"\x00" // BYTE DecompressionMethod.ID[DecompressionMethod.IDSize] (no encoding)
						).
						self::SIG_CODUNPACKSIZE . $unpack_size.
					self::SIG_END.
					self::SIG_SUBSTRMINFO;
		$this->header_post = 
					self::SIG_END.
				self::SIG_END.
				self::SIG_FILESINFO.
					"\x01". // UINT64 NumFiles
					// file record
					self::SIG_NAME. // BYTE PropertyType
					self::uint64(strlen($filename) +1 /*for external byte*/). // UINT64 Size
					// filename info
					"\0". // BYTE External
					$filename.
				self::SIG_END.
			self::SIG_END;
		
		$unenc_header_len_packed = $unenc_header_len = strlen($this->header_pre) + 6 /*2 byte CRC marker + 4 byte CRC*/ + strlen($this->header_post);
		if($encpwd!==false) {
			if(isset($cryptProps))
				$unenc_header_len_packed = (int)$this->aes_ctx->size($unenc_header_len);
			$this->header_postdec = 
				self::SIG_ENCHEADER.
					self::SIG_PACKINFO.
						$unpack_size. // UINT64 PackPos
						"\x01". // UINT64 NumPackStreams
						self::SIG_SIZE . self::uint64($unenc_header_len_packed).
					self::SIG_END.
					self::SIG_UNPACKINFO.
						self::SIG_FOLDER.
						"\x01". // UINT64 NumFolders
						"\0". // BYTE External
						// following is the folder entry
						"\x01". // UINT64 NumCoders
						(isset($cryptProps) ?
							"\x24". // DecompressionMethod.IDSize + 'has properties'
							"\x06\xF1\x07\x01". // BYTE DecompressionMethod.ID[DecompressionMethod.IDSize]
							self::uint64(strlen($cryptProps)). // UINT64 PropertiesSize
							$cryptProps // encryption properties
						:
						/* delta encoding
							"\x21". // DecompressionMethod.IDSize + 'has properties'
							"\x03". // BYTE DecompressionMethod.ID[DecompressionMethod.IDSize] (delta encoding)
							"\x01". // UINT64 PropertiesSize
							"\0" // delta grouping size (1 byte delta)
						*/
						// swap4 encoding
							"\x03". // DecompressionMethod.IDSize + 'has properties'
							"\x02\x03\x04" // BYTE DecompressionMethod.ID[DecompressionMethod.IDSize] (swap4 encoding)
						).
						self::SIG_CODUNPACKSIZE . self::uint64($unenc_header_len).
					self::SIG_END.
				self::SIG_END;
			$startheader =
				self::pack64($this->filesize + $unenc_header_len_packed). // REAL_UINT64 NextHeaderOffset
				self::pack64(strlen($this->header_postdec)).
				pack('V', crc32($this->header_postdec));
			$this->header_signature = $prefix_header . self::SIGNATURE . pack('V', crc32($startheader)) . $startheader;
			//$this->filters[] = 'delta';
			$this->filters[] = 'swap4';
		} else {
			$this->header_postdec = '';
			$this->header_signature = $prefix_header . self::SIGNATURE . "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";
		}
		$this->filesize_7z = bcadd($this->filesize, strlen($this->header_signature) + $unenc_header_len_packed + strlen($this->header_postdec));
	}
	protected function basename() {
		return parent::basename().'.7z';
	}
	public function copy_to($stream, $amount=0) {
		// TODO: optimise perhaps?
		return parent::copy_to_slow($stream, $amount);
	}
	public function read($amount) {
		$siglen = strlen($this->header_signature); // 32
		$r = '';
		if(bccomp($this->filepos_7z, $siglen) < 0) {
			// still in signature - prepend it
			$r = substr($this->header_signature, $this->filepos_7z, $amount);
			$amount = bcsub($amount, strlen($r));
			$this->filepos_7z = bcadd($this->filepos_7z, strlen($r));
		}
		if($amount) {
			// check if we'll read any underlying data
			$endpos = bcadd($this->filesize, $siglen);
			if(bccomp($this->filepos_7z, $endpos) < 0) {
				// if swap4 encoding, empty swap4 buffer first
				$swap4 = in_array('swap4', $this->filters);
				if($swap4 && $this->swap4_ctx !== '') {
					$l = strlen($this->swap4_ctx);
					if(bccomp($amount, $l) < 0) {
						$r .= substr($this->swap4_ctx, 0, $amount);
						$this->swap4_ctx = substr($this->swap4_ctx, $amount);
						$this->filepos_7z = bcadd($this->filepos_7z, $amount);
						$amount = 0;
					} else {
						$r .= $this->swap4_ctx;
						$this->swap4_ctx = '';
						$this->filepos_7z = bcadd($this->filepos_7z, $l);
						$amount = bcsub($amount, $l);
					}
				}
			}
		}
		if($amount) {
			// check if we'll read any underlying data
			$endpos = bcadd($this->filesize, $siglen);
			if(bccomp($this->filepos_7z, $endpos) < 0) {
				// read underlying data
				$ramount = $amount;
				$s4_tobuffer = 0;
				// we need to check that it doesn't overflow the underlying stream
				if(bccomp(bcadd($this->filepos, $ramount), $this->filesize) >= 0) {
					// will overflow, so trim amount down
					$ramount = bcsub($this->filesize, $this->filepos);
				} elseif($swap4 && ($mod = (int)bcmod($ramount, '4'))) {
					// need to align read-to point to 4 byte boundary
					$ramount = bcadd($ramount, 4-$mod);
					// does this new read amount exceed file size?
					if(bccomp(bcadd($this->filepos, $ramount), $this->filesize) > 0)
						$ramount = bcsub($this->filesize, $this->filepos);
					// as the requested amount doesn't exceed filesize, buffer any difference between the real read amount and the requested amount
					$s4_tobuffer = bcsub($ramount, $amount);
				}
				
				$s = parent::read($ramount);
				foreach($this->filters as $filter) {
					if($filter == 'delta')
						$this->delta_encode($s, $this->delta_ctx);
					if($filter == 'swap4')
						$this->swap4_encode($s);
				}
				
				if($swap4 && $s4_tobuffer) {
					$r .= substr($s, 0, -$s4_tobuffer);
					$this->swap4_ctx = substr($s, -$s4_tobuffer);
					// buffering anything implies that we haven't read to the end, ie $amount is satisfied
					$ramount = $amount; // same as bcsub($ramount, $s4_tobuffer);
				} else {
					$r .= $s;
				}
				$amount = bcsub($amount, $ramount);
				$this->filepos_7z = bcadd($this->filepos_7z, $ramount);
			}
			
			if($amount) {
				// still here? we append the header
				$header = $this->get_header();
				$headerpos = bcsub($this->filepos_7z, $endpos);
				$r .= substr($header, $headerpos, $amount);
				$this->filepos_7z = bcadd($this->filepos_7z, $amount);
			}
		}
		return $r;
	}
	
	private function get_header() {
		if(isset($this->header_gen_cache))
			return $this->header_gen_cache;
		
		if(@$this->hashes['crc32']) {
			$crc = self::SIG_CRC ."\x01".strrev($this->hashes['crc32']);
			$header = $this->header_pre . $crc . $this->header_post;
		} else {
			if(function_exists('warning')) warning('[7z-wrapper] CRC32 value not set for file '.$this->name());
			// fallback by appending junk
			// the first (commented out) try doesn't work with encryption
			//$header = $this->header_pre . $this->header_post;
			//$this->header_postdec = "\0\0\0\0\0\0".$this->header_postdec;
			$header = $this->header_pre . $this->header_post."\0\0\0\0\0\0";
		}
		if(isset($this->aes_ctx)) {
			$header = $this->aes_ctx->enc($header) . $this->aes_ctx->end();
		} else
			foreach($this->filters as $filter) {
				if($filter == 'delta')
					$this->delta_encode($header);
				if($filter == 'swap4')
					$this->swap4_encode($header);
			}
		return ($this->header_gen_cache = $header . $this->header_postdec);
	}
	
	// slow PHP delta encoding function
	private function delta_encode(&$s, &$last=0) {
		$p = -1;
		while(isset($s[++$p])) {
			$n = ord($s[$p]);
			$s[$p] = pack('c', $n-$last);
			$last = $n;
		}
	}
	
	// always send data length divisible by 4 except for last chunk
	private static function swap4_encode(&$s) {
		static $use_atext=null;
		if(!isset($use_atext))
			$use_atext = function_exists('atext_swap4');
		
		if($use_atext) { // our super fast method
			$s = atext_swap4($s);
		} else { // fallback to slower methodologies
			$m = strlen($s) % 4;
			if($m)
				$s = self::_swap4_encode(substr($s, 0, -$m)) . substr($s, -$m);
			else
				$s = self::_swap4_encode($s);
		}
	}
	private static function _swap4_encode($s) {
		static $method=null;
		if(!isset($method)) {
			if(function_exists('recode_string'))
				$method = 'recode';
			// test with magic string that seems to fail on libiconv >=2.x
			elseif(@iconv('ucs-4be', 'ucs-4le', "\xff\xd8\xff\xe0") == "\xe0\xff\xd8\xff")
			// TODO: byte4le -> byte4be may work...
				$method = 'iconv';
			else
				$method = 'preg';
		}
		switch($method) {
			case 'recode': // relatively fast (for PHP), seems to always work, though breaks in some rare instances
				return recode_string('ucs4-le..ucs4-be', $s);
			case 'iconv': // fast iconv method; seems to work on libiconv v1.11, but not v2.x
				return iconv('ucs-4le', 'ucs-4be', $s);
			case 'preg': // is around 20x slower than iconv, but faster than anything else I can think of
				return preg_replace('~(.)(.)(.)(.)~s', '$4$3$2$1', $s);
		}
	}
	
	public function seek($pos) {
		$siglen = strlen($this->header_signature); // 32
		if(bccomp($pos, $siglen) < 0) {
			parent::seek(0);
			$this->delta_ctx = 0;
			$this->swap4_ctx = '';
		} elseif(bccomp(($ppos = bcsub($pos, $siglen)), $this->filesize) < 0) {
			// middle of file somewhere
			if(in_array('delta', $this->filters)) {
				if($ppos == '0') {
					$this->delta_ctx = 0;
					parent::seek(0);
				} else {
					// need to seek back 1 more byte for delta encoder
					parent::seek(bcsub($ppos, '1'));
					$this->delta_ctx = ord(parent::read(1));
				}
			} elseif(in_array('swap4', $this->filters)) { // TODO: this doesn't work if both delta & swap4 is active
				if($posmod = bcmod($ppos, '4')) {
					parent::seek(bcsub($ppos, $posmod));
					$chunk = parent::read(4);
					self::swap4_encode($chunk);
					$this->swap4_ctx = substr($chunk, $posmod);
				} else {
					// aligned to 4, simply seek
					$this->swap4_ctx = '';
					parent::seek($ppos);
				}
			} else {
				parent::seek($ppos);
			}
		}
		
		$this->filepos_7z = $pos;
	}
	
	// 7z uint64 encode
	private function uint64($n) {
		$n = (string)$n;
		$s = '';
		for($i=0; $i<8; ++$i) {
			if(bccomp($n, 0x80 >> $i) >= 0) {
				// shift out bottom byte
				$s .= pack('C', (int)bcmod($n, 256));
				$n = bcdiv($n, 256, 0);
			} else {
				// done, prepend top byte masked
				return pack('C', ((0xFF00 >> $i) & 0xFF) | (int)$n) . $s;
			}
		}
		// all 8 bytes used
		return "\xFF" . $s;
	}
	// pack for 64-bit uint, little endian
	private function pack64($n) {
		$n = (string)$n;
		$s = '';
		// break into 16-bit chunks, because handling 32-bit is somewhat hairy
		for($i=0; $i<4; ++$i) {
			$s .= pack('v', (int)bcmod($n, 65536));
			$n = bcdiv($n, 65536, 0);
		}
		return $s;
	}
}
