<?php

return array(
	'multiup' => array('multiargs' => [
		'MultiUp',
		['1fichier.com','turbobit.net','uppit.com','filerio.in','kbagi.com','filecloud.io','downace.com','uptobox.com','bdupload.info','indishare.me','filescdn.com'],
		array(
			'MU' => 'MultiUp',
			'1fichier.com' => 'MultiUp|Fichier',
			'bayfiles.net' => 'MultiUp|BayFiles',
			'dfiles.eu' => 'MultiUp|DepositFiles',
			'hugefiles.net' => 'MultiUp|HugeFiles',
			'mega.co.nz' => 'MultiUp|Mega',
			'rapidgator.net' => 'MultiUp|Rapidgator',
			'rapidshare.com' => 'MultiUp|Rapidshare',
			'streamupload.org' => 'MultiUp|StreamUpload',
			'turbobit.net' => 'MultiUp|Turbobit',
			'uploaded.net' => 'MultiUp|Uploaded',
			'uptobox.com' => 'MultiUp|UpToBox',
			'billionuploads.com' => 'MultiUp|BillionUploads',
			'uploadhero.com' => 'MultiUp|UploadHero',
			'4shared.com' => 'MultiUp|4shared',
			'filecloud.io' => 'MultiUp|FileCloud',
			'dl.free.fr' => 'MultiUp|Free',
			'firedrive.com' => 'MultiUp|FireDrive',
			'2shared.com' => 'MultiUp|2shared',
			'mediafire.com' => 'MultiUp|Mediafire',
			'zippyshare.com' => 'MultiUp|ZippyShare',
			'filesupload.org' => 'MultiUp|FilesUpload',
			'ryushare.com' => 'MultiUp|Ryushare',
			'nitroflare.com' => 'MultiUp|Nitroflare',
			'easybytez.com' => 'MultiUp|EasyBytez',
			'usersfiles.com' => 'MultiUp|Usersfiles',
			'clicknupload.com' => 'MultiUp|ClickNupload',
			'oboom.com' => 'MultiUp|Oboom',
			'fufox.net' => 'MultiUp|Fufox',
			'toutbox.fr' => 'MultiUp|ToutBox',
			'ezfile.ch' => 'MultiUp|Ezfile',
			'uplea.com' => 'MultiUp|Uplea',
			'userscloud.com' => 'MultiUp|Userscloud',
			'solidfiles.com' => 'MultiUp|Solidfiles',
			'uppit.com' => 'MultiUp|Uppit',
			'filerio.in' => 'MultiUp|Filerio',
			'uploadable.ch' => 'MultiUp|Uploadable',
			'warped.co' => 'MultiUp|Warped',
			'uploadbaz.com' => 'MultiUp|UploadBaz',
			'mightyupload.com' => 'MultiUp|Mightyupload',
			'rockfile.eu' => 'MultiUp|Rockfile',
			'tusfiles.net' => 'MultiUp|Tusfiles',
			'rutube.ru' => 'MultiUp|Rutube',
			'openload.co' => 'MultiUp|OpenLoad',
			'filefactory.com' => 'MultiUp|FileFactory',
			'nowdownload.to' => 'MultiUp|NowDownload',
			'youwatch.org' => 'MultiUp|YouWatch',
			'bigfile.to' => 'MultiUp|BigFile',
			'share-online.biz' => 'MultiUp|ShareOnline',
			'chomikuj.pl' => 'MultiUp|Chomikuj',
			'diskokosmiko.mx' => 'MultiUp|Diskokosmiko',
			'filescdn.com' => 'MultiUp|FilesCdn',
			'kbagi.com' => 'MultiUp|Kbagi',
			'minhateca.com.br' => 'MultiUp|Minhateca',
			'uploadboy.com' => 'MultiUp|UploadBoy',
			'sendspace.com' => 'MultiUp|Sendspace',
			'uploading.site' => 'MultiUp|Uploading',
			'dailyuploads.net' => 'MultiUp|DailyUploads',
			'uploadrocket.net' => 'MultiUp|UploadRocket',
			'downace.com' => 'MultiUp|DownAce',
			'bdupload.info' => 'MultiUp|BdUpload',
			'indishare.me' => 'MultiUp|IndiShare',
			'4shared.com' => 'MultiUp|4shared',
		),
		true, 'MultiUp'
	]),
	/*'sendspace' => array('maxsize' => 7450, 'singleargs' => // not often used; dropped to save upload bandwidth
		array('SendSpace', 'SS', 'Sendspace', true, array(2,4,10,'ulqueue_failed'), true)
	),*/
	// AF site not responding, so disabled for now
	/*'anonfiles' => array('maxsize' => 384, 'singleargs' => // AF does have upload limits - max 500file/50GB per hour or 5000files/100GB per day
		array('AnonFiles', 'AF', 'AnonFiles', true)
	),*/
	/*'pixeldrain' => array('singleargs' => // IP banned
		array('PixelDrain', 'PD', 'PixelDrain', true)
	),*/
	/*'downloadgg' => array('singleargs' => // possibly blocked our IP
		array('DownloadGG', 'DG', 'DownloadGG', true)
	),*/
	'krakenfiles' => array('singleargs' =>
		array('KrakenFiles', 'KF', 'KrakenFiles', true, [2,4,4,''], false, ['maxload' => 2])
	),
	'gofile' => array('singleargs' =>
		array('GoFile', 'GF', 'GoFile', true)
	),
	'mdiaload' => array('singleargs' =>
		array('MdiaLoad', 'ML', 'MdiaLoad', true)
	),
	/*'dailyuploads' => array('maxsize' => 300, 'singleargs' => // added maxsize/maxload because DU's slow upload is causing queues to grow; disabled due to slow speed
		array('DailyUploads', 'RU', 'DailyUploads', true, [2,2,3,''], false, ['maxload' => 2])
	),*/
	'buzzheavier' => array('singleargs' =>
		array('BuzzHeavier', 'BH', 'BuzzHeavier', true, [2,2,3,''], false, ['maxload' => 2])
	),
	'akirabox' => array('singleargs' =>
		array('AkiraBox', 'AB', 'AkiraBox', true)
	),
);
