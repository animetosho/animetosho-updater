CREATE TABLE IF NOT EXISTS `toto_toto` (
	`id` int(10) unsigned not null auto_increment,
	`tosho_id` int(10) unsigned not null default 0,
	`nyaa_id` int(10) unsigned not null default 0,
	`nyaa_subdom` enum("","sukebei") not null default "",
	`anidex_id` int(10) unsigned not null default 0,
	`nekobt_id` bigint(30) unsigned not null default 0,
	`name` varchar(255) not null default "",
	`link` varchar(512) not null default "",
	`magnet` varchar(2048) not null default "",
	`main_tracker_id` smallint(5) unsigned not null default 0,
	`cat` smallint(5) unsigned not null default 0,
	`website` varchar(255) not null default "",
	`totalsize` bigint(30) unsigned not null default 0,
	`dateline` bigint(30) not null default 0,
	`comment` text not null,
	`added_date` bigint(30) not null default 0,
	`completed_date` bigint(30) not null default 0,
	`torrentname` varchar(250) not null default "",
	`torrentfiles` smallint(5) unsigned not null default 0,
	-- `files` text not null,
	`stored_torrent` tinyint(3) not null default 0, -- 0=no, 1=yes, 2=yes but size is different
	`stored_nzb` tinyint(3) not null default 0, -- 0/1
	
	`nyaa_class` tinyint(3) not null default 0, -- 0=unknown, 1=remake, 2=none, 3=trusted, 4=a+, -1=hidden (is this used yet?)
	`nyaa_cat` char(4) character set ascii collate ascii_general_ci not null default "",
	
	`anidex_cat` tinyint(3) default null,
	`anidex_labels` tinyint(3) default null, -- 1=batch, 2=raw, 4=hentai, 8=reencode
	
	`nekobt_hide` tinyint(3) not null default 0,
	
	-- duplicate detection
	`btih` binary(20) null default null,
	`btih_sha256` binary(32) null default null, -- only set if v2 torrent detected, otherwise NULL
	`isdupe` tinyint(3) not null default 0,
	
	-- moderation related
	`deleted` tinyint(3) not null default 0, -- 1 = deleted from toto, -1 = force deleted, -10 = auto restored?
	`lastchecked` bigint(30) unsigned not null default 0,
	
	-- AniDB info
	`aid` int(11) unsigned not null default 0,
	`eid` int(11) unsigned not null default 0,
	`fid` int(11) unsigned not null default 0,
	`gids` varchar(255) not null default "",
	`resolveapproved` tinyint(3) not null default 0, -- 0=not approved, 1=(auto) fid found, 2=manual approved, 3=close match with fid found
	`sigfid` int(11) unsigned not null default 0, -- the main fid (if makes sense)
	
	-- source (article) info
	`srcurl` varchar(300) not null default "",
	`srcurltype` enum("","none","feedentry","unrelated","blogentry","forumthread","fileshare","imghost","torrentlisting","forum") not null default "",
	`srctitle` varchar(500) not null default "",
	`srccontent` text not null,
	
	-- only used by display script
	`ulcomplete` tinyint(1) not null default 0, -- 1=complete, 2=partial fetch, -1=skipped (or previously, possible error), -2=broken, -3=other error
	
	-- MariaDB virtual column for indexing
	`idx_dateline` bigint(30) AS (IF(ulcomplete!=-3 AND deleted=0 AND isdupe=0 AND nyaa_cat IN("","1_37","1_11","1_38") AND cat IN(1,10,11) AND nyaa_subdom="" AND (anidex_cat IS NULL OR anidex_cat IN(1,3)) AND (anidex_labels IS NULL OR anidex_labels&6=0) AND nekobt_hide=0, -dateline, NULL)) PERSISTENT,
	`idx_totalsize` bigint(30) AS (IF(ulcomplete!=-3 AND deleted=0 AND isdupe=0 AND nyaa_cat IN("","1_37","1_11","1_38") AND cat IN(1,10,11) AND nyaa_subdom="" AND (anidex_cat IS NULL OR anidex_cat IN(1,3)) AND (anidex_labels IS NULL OR anidex_labels&6=0) AND nekobt_hide=0, totalsize, NULL)) PERSISTENT,
	primary key (`id`),
	key (`idx_dateline`),
	key (`idx_totalsize`),
	key (`tosho_id`),
	key (`nyaa_id`,`nyaa_subdom`),
	key (`anidex_id`),
	key (`nekobt_id`),
	key `aid_filter` (`aid`,`idx_dateline`),
	key `eid_filter` (`eid`,`idx_dateline`),
	key `aid_filter_size` (`aid`,`idx_totalsize`),
	key (`btih`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_trackers` (
	`id` smallint(5) unsigned not null auto_increment,
	`url` varchar(250) not null, -- technically should be case sensitive, but a pain to deal with, and I doubt it's actually a problem anywhere; only becomes a problem if the first seen instance of the tracker has bad casing
	`dead` tinyint(3) not null default 0,
	`last_success` bigint(30) unsigned not null default 0,
	`last_failure` bigint(30) unsigned not null default 0,
	
	primary key (`id`),
	unique key (`url`)
) ENGINE=Aria CHARACTER SET utf8mb4;
CREATE TABLE IF NOT EXISTS `toto_tracker_stats` (
	`id` int(10) unsigned not null,
	`tracker_id` smallint(5) unsigned not null,
	`tier` tinyint(4) DEFAULT NULL,
	`last_queried` bigint(30) unsigned not null,
	
	`error` tinyint(3) default 0, -- 1=scrape failed, 2=no data returned, 3=missing data
	`updated` bigint(30) unsigned not null,
	`complete` int(10) unsigned not null default 0,
	`downloaded` int(10) unsigned not null default 0,
	`incomplete` int(10) unsigned not null default 0,
	
	primary key (`id`,`tracker_id`),
	key `tracker_queried`(`tracker_id`, `last_queried`)
) ENGINE=Aria CHARACTER SET utf8mb4;


CREATE TABLE IF NOT EXISTS `toto_files` (
	`id` int(10) unsigned not null auto_increment,
	`toto_id` int(10) unsigned not null default 0,
	
	`type` tinyint(3) not null default 0, -- 0=regular file, 1=archive
	
	`filename` varchar(1024) not null,
	`filethumbs` text not null,
	`filestore` text,
	`vidframes` varchar(100) not null default "",
	`audioextract` blob not null,
	
	`filesize` bigint(30) unsigned not null,
	`crc32` binary(4) default null,
	`md5` binary(16) default null,
	`sha1` binary(20) default null,
	`sha256` binary(32) default null,
	`tth` binary(24) default null,
	`ed2k` binary(16) default null,
	`bt2` binary(32) default null,
	
	`crc32k` binary(4) default null, -- CRC32 of the first 1KB - used for quick verification from hosts
	
	primary key (`id`),
	key (`toto_id`),
	key `size_crc`(`filesize`,`crc32`)
) ENGINE=Aria CHARACTER SET utf8mb4;


CREATE TABLE IF NOT EXISTS `toto_fileinfo` (
	`fid` int(10) unsigned not null,
	`type` varchar(10) not null,
	`info` mediumblob not null,
	primary key (`fid`,`type`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_filelinks` (
	`fid` int(10) unsigned not null,
	`links` blob not null,
	/*
	 {
	  "site": [{
	   "url": ...
	   "st": // 0=active, 1=unresolved, 2=dead, 3=never resolved, -1=processing
	   "enc": false
	  }]
	 }
	*/
	
	primary key (`fid`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_attachment_files` (
	`id` int(10) unsigned not null auto_increment,
	`hash` binary(20) not null,
	`filesize` int(10) unsigned not null,
	`packedsize` int(10) unsigned not null,
	`available` tinyint(3) not null default 0, -- whether file is available on static server or not (currently unused?)
	
	primary key (`id`),
	unique key (`hash`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_attachments` (
	`fid` int(10) unsigned not null,
	`attachments` blob not null,
	/*
	 [
	  [ // attachments
	   {
	    "name": "fontname.ttf",
	    "m": 1,
	    "_afid": 123
	   }
	  ],
	  [ // subtitles
	   {
	    "lang": "und",
	    "codec": "ASS",
	    "tracknum": 3,
	    "_afid": 456
	   }
	  ],
	  1234, // chapter attachment_files ID
	  5678  // tags
	 ]
	*/
	primary key (`fid`)
) ENGINE=Aria CHARACTER SET utf8mb4;


CREATE TABLE IF NOT EXISTS `toto_anidb_tvdb` (
  `anidbid` int(10) unsigned NOT NULL,
  `tvdbid` int(10) unsigned NOT NULL DEFAULT 0,
  `defaulttvdbseason` tinyint(3) DEFAULT NULL,
  `episodeoffset` smallint(3) NOT NULL DEFAULT 0,
  `tmdbids` varchar(100) NOT NULL DEFAULT "",
  `imdbids` varchar(200) NOT NULL DEFAULT "",
  `mapping-list` text DEFAULT NULL,
  `before` varchar(200) DEFAULT NULL,
  -- TODO: supplemental info?
  PRIMARY KEY (`anidbid`)
) ENGINE=Aria DEFAULT CHARSET=utf8mb4;
