<?php

define('ROOT_DIR', dirname(__FILE__).'/');
require ROOT_DIR.'funcs.php';
require ROOT_DIR.'uploaders/filehasher.php';

if($argc < 2) die("Syntax: php test-calc-hashes.php [filename]\n");

$hashes = file_hashes($argv[1], [
	'crc32', 'md5', 'sha1', 'ed2k', 'sha256', 'bt2',
	//'tth',
	'torpc_sha1_16k',
	'torpc_sha1_32k',
	'torpc_sha1_64k',
	'torpc_sha1_128k',
	'torpc_sha1_256k',
	'torpc_sha1_512k',
	'torpc_sha1_1024k',
	'torpc_sha1_2048k',
	'torpc_sha1_4096k',
	'torpc_sha1_8192k',
	'torpc_sha1_16384k',
]);

$hashes = array_map('bin2hex', $hashes);
var_dump($hashes);
