<?php

$config = array(
	
	'db' => array(
		'dbhost' => 'localhost',
		'dbuser' => 'toto',
		'dbpassword' => '',
		'dbname' => 'toto',
		
		'dbport' => 3306,
		'dbprefix' => 'toto_',
		
		'dbdebug' => 1,
	),
);

define('TRANSMISSION_PASSWORD', '');
define('TOTO_STORAGE_PATH', '/storage/storage/');

// must be full path!
define('TORRENT_DIR', '/atdata/torrents/');
define('TEMP_DIR', '/atdata/tmp/');

$SEMA_KEYS = [
	// values are [key, max_acquire]
	'uploader' => [0x581571, 6],
	'fileproc' => [0x581575, 1]
];

?>