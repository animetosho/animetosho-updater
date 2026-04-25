<?php


class FileInfoCompressor {
	const ZSTD_MAGIC = "\x28\xb5\x2f\xfd";
	static private $dicts = [];
	static function get_dict($name) {
		if(isset($GLOBALS['AT']->cache))
			$dict = $GLOBALS['AT']->cache->get('zstd_dict_'.$name);
		else
			$dict =& self::$dicts[$name];
		if(empty($dict)) {
			$dict = file_get_contents(__DIR__.'/zstd-dict/'.$name.'.dict');
			if(empty($dict)) return null;
			if(isset($GLOBALS['AT']->cache))
				$GLOBALS['AT']->cache->set('zstd_dict_'.$name, $dict);
		}
		return $dict;
	}
	static function compress($type, $data, $check=false) {
		if($type == 'mediainfo')
			$pdata = self::mediainfo_pack($data);
		else
			$pdata = $data;
		
		$dict = self::get_dict($type);
		if(isset($dict)) {
			$cdata = zstd_compress_dict($pdata, $dict, 19);
			
			// strip magic
			if(substr($cdata, 0, 4) != self::ZSTD_MAGIC) {
				if(function_exists('warning')) warning('zstd data did not start with expected magic');
				return "\xff$pdata";
			}
			$cdata = substr($cdata, 4);
			
			if(strlen($cdata) < strlen($pdata)) {
				if(!$check || $data == self::decompress($type, $cdata))
					return $cdata;
				else {
					if(function_exists('warning')) warning('Check failed with zstd compressed data');
				}
			}
		}
		return "\xff$pdata";
	}
	static function decompress($type, $data) {
		if($data[0] == "\xff") // the zstd frame header will never have this set, so we can exploit this to indicate uncompressed data
			return substr($data, 1);
		$dict = self::get_dict($type);
		if(!isset($dict)) return false;
		
		$ddata = zstd_uncompress_dict(self::ZSTD_MAGIC.$data, $dict);
		if($type == 'mediainfo')
			$ddata = self::mediainfo_unpack($ddata);
		
		return $ddata;
	}
	
	static function compress_pack($type, $data, $check=false) {
		$pdata = self::mpack($data);
		$cdata = self::compress($type, $pdata, false);
		if($check && serialize($data) != serialize(self::decompress_unpack($type, $cdata))) {
			if(function_exists('warning')) warning('Check failed with zstd compress+packed data');
			return "\xff$data";
		}
		return $cdata;
	}
	static function decompress_unpack($type, $data) {
		return self::munpack(self::decompress($type, $data));
	}
	
	// gets the decompressed size of a zstd stream (without magic)
	// ref: https://github.com/facebook/zstd/blob/dev/doc/zstd_compression_format.md#frame_header
	static function decompressed_size($data) {
		if(!isset($data[0])) return null;
		$flags = ord($data[0]);
		if($flags & 8) return null; // Reserved_bit
		
		if(($flags & 224) == 0) return false; // unknown size (FCS_Field_Size==0)
		
		$p = 1;
		if(($flags & 32) == 0) ++$p; // skip Window_Descriptor
		if($flags & 3) $p += 1 << (($flags & 3) - 1); // skip Dictionary_ID
		
		// read Frame_Content_Size
		$szSz = 1 << ($flags >> 6);
		if(!isset($data[$p + $szSz -1])) return null;
		$packMap = ['C', 'v', 'V', 'P'];
		list(,$sz) = unpack($packMap[$flags >> 6], $data, $p);
		if($szSz == 2) $sz += 256;
		return $sz;
	}
	
	static function mpack($data) {
		$packer = new \MessagePack(false);
		if(is_object($data)) $data = json_decode(json_encode($data), true); // for whatever reason, MsgPack drops object keys when encoding as an array, but not array keys
		return $packer->pack($data);
	}
	static function munpack($data) {
		return msgpack_unpack($data);
	}
	
	static function mediainfo_pack($plainData) {
		$lines = explode("\n", $plainData);
		$packed = [];
		$keywidth = null;
		foreach($lines as $l) {
			if(!$l) continue;
			$p = strpos($l, ' :');
			if($p) {
				$packed[] = [rtrim(substr($l, 0, $p)), substr($l, $p+3)];
				if(!$keywidth) $keywidth = $p;
			} else {
				if(substr($l, -3) == '...') // likely truncated line
					$packed[] = [$l];
				else
					$packed[] = $l;
			}
		}
		array_unshift($packed, $keywidth ?: 40);
		return self::mpack($packed);
	}
	static function mediainfo_unpack($cdata) {
		$packed = self::munpack($cdata);
		$width = array_shift($packed);
		$lines = '';
		foreach($packed as $l) {
			if(is_array($l)) {
				if(isset($l[1])) {
					$lblLen = mb_strlen($l[0], 'utf8');
					$lines .= $l[0].str_repeat(' ', $width-$lblLen).' : '.$l[1] . "\n";
				} else // truncated line
					$lines .= $l[0] . "\n";
			} else {
				if($lines) $lines .= "\n";
				$lines .= $l . "\n";
			}
		}
		return rtrim($lines);
		/*
		if(substr($j, -1) == "\n")
			return substr($j, 0, -1); // strip trailing newline
		return $j; // this is possible if the output was truncated
		*/
	}
	
	
	/*
	// mkvinfo packing makes it larger - abandon this idea
	static function mkvinfo_pack($data) {
		$lvl = 0;
		$stack = [];
		foreach(explode("\n", $data) as $l) {
			$llvl = strpos($l, '+ '); // this caters for truncated info which could have '+...'
			if($llvl === false) $llvl = 0;
			else ++$llvl;
			if($llvl > $lvl+1) return null;
			$ltxt = $llvl > 0 ? substr($l, $llvl+1) : $l;
			
			if($llvl < $lvl) {
				// pop stack
				while($lvl > $llvl) {
					$stack[$lvl-1][] = $stack[$lvl];
					unset($stack[$lvl]);
					--$lvl;
				}
			} elseif($llvl > $lvl) {
				$stack[$llvl] = [];
			}
			
			$stack[$llvl][] = $ltxt;
			$lvl = $llvl;
		}
		// pop stack end
		while($lvl) {
			$stack[$lvl-1][] = $stack[$lvl];
			--$lvl;
		}
		return $stack[0];
	}

	static function mkvinfo_unpack($data, $lvl=0) {
		$str = '';
		foreach($data as $l) {
			if(is_array($l)) {
				$str .= mkvinfo_unpack($l, $lvl+1);
			} else {
				if($lvl > 0) {
					if($lvl > 1) $str .= str_pad('|', $lvl-1);
					$str .= '+ ';
				}
				$str .= $l."\n";
			}
		}
		if($lvl != 0) return $str;
		if(substr($str, -1) == "\n") return substr($str, 0, -1); // trim trailing newline
		return $str; // possible if output was truncated
	}
	*/
}

