CREATE TABLE IF NOT EXISTS `toto_torrents` (
	`id` int(10) unsigned not null,
	`name` varchar(255) not null default "",
	`hashString` binary(20) not null default "",
	`toto_id` int(10) unsigned not null,
	`folder` varchar(100) not null,
	`dateline` bigint(30) not null default 0,
	`lastreannounce` bigint(30) not null default 0,
	`totalsize` bigint(30) unsigned not null default 0,
	`status` tinyint(3) not null default 0, -- 0=nothing done, 1=processing, 2=main processing done (awaiting archive), 3=completed processing
	primary key (`hashString`),
	key (`status`,`hashString`),
	key (`dateline`),
	key (`totalsize`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_skip_fetch` (
	`link` varchar(750) not null,
	`dateline` int(11) not null default 0,
	primary key (`link`),
	key (`dateline`)
) ENGINE=Memory CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_toarchive` (
	`toto_id` int(10) unsigned not null,
	`torrentname` varchar(255) not null, -- duplicate info for simplicity
	`files` mediumtext not null,
	`xfiles` mediumtext not null, -- excluded files
	`opts` varchar(100) not null default "", -- 7z archiving options
	`basedir` varchar(1024) not null, -- base dir to chdir to before executing 7z
	
	`status` tinyint(3) not null default 0, -- 0=queued, 1=processing
	`priority` tinyint(3) not null default 0, -- 0=normal
	`dateline` bigint(30) not null default 0,
	
	primary key (`toto_id`),
	key (`status`, `priority`, `dateline`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_finfo` (
	`fid` int(10) unsigned not null,
	`filename` varchar(255) not null, -- duplicate info for simplicity
	
	`status` tinyint(3) not null default 0, -- 0=queued, 1=processing
	`priority` tinyint(3) not null default 0, -- 0=normal, 1=high
	`dateline` bigint(30) not null default 0,
	
	primary key (`fid`),
	key (`status`, `priority`, `dateline`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_ulqueue` (
	`id` int(10) unsigned not null auto_increment,
	`fid` int(10) unsigned not null,
	
	-- duplicate info from toto_files for simplicity
	`toto_id` int(10) unsigned not null default 0,
	`filename` varchar(255) not null,
	
	`status` tinyint(3) not null default 0, -- 0=queued, 1=processing, 2=done (waiting for resolve)
	`priority` tinyint(3) not null default 0, -- 0=normal
	`last_processor` char(10) not null default "", -- last script that processed this entry
	`dateline` bigint(30) not null default 0, -- if set in the future, this is a delayed retry
	
	`retry_sites` varchar(200) not null default "", -- comma separated list of sites to redo; blank for all ulqueue sites
	
	primary key (`id`),
	key (`fid`),
	key (`status`, `dateline`),
	key (`status`, `priority`, `dateline`),
	key (`toto_id`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_ulqueue_failures` ( -- failure log
	`id` int(10) unsigned not null auto_increment,
	`fid` int(10) unsigned not null,
	`site` char(50) not null default "",
	`dateline` bigint(30) not null default 0,
	primary key (`id`),
	key (`dateline`),
	key (`site`, `dateline`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_ulqueue_status` (
	`proc` char(10) not null,
	`ulq_id` int(10) not null default 0,
	`site` char(50) not null default "",
	`started` bigint(30) not null default 0,
	`speed` float NOT NULL DEFAULT -1,
	`uploaded` bigint(30) unsigned NOT NULL DEFAULT 0,
	primary key (`proc`)
) ENGINE=Memory CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_ulhosts` ( -- upload host stats
	`site` char(25) not null,
	-- these aren't used by the script, just collected for stats
	`lastused` bigint(30) not null default 0,
	`lastsuccess` bigint(30) not null default 0,
	`lastfailure` bigint(30) not null default 0,
	`successes` int(10) unsigned not null default 0,
	`failures` int(10) unsigned not null default 0,
	
	`defer` bigint(30) unsigned not null default 0, -- don't upload to this host until this timestamp
	primary key (`site`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_ulservers` ( -- upload server stats
	`server` varchar(100) not null default "", -- subdomain
	`host` char(25) not null default "",
	`added` bigint(30) not null default 0, -- date found, only just FYI
	`lastused` bigint(30) not null default 0,
	`lastsuccess` bigint(30) not null default 0,
	`lastfailure` bigint(30) not null default 0,
	`successes` int(10) unsigned not null default 0,
	`failures` int(10) unsigned not null default 0,
	`dead` tinyint(3) not null default 0, -- equivalent to a deleted flag
	primary key (`server`),
	key (`host`),
	key (`host`, `dead`, `server`),
	key (`host`, `lastfailure`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_ulserver_failures` ( -- failure log
	`id` int(10) unsigned not null auto_increment,
	`server` varchar(100) not null default "",
	`host` char(25) not null default "",
	`dateline` bigint(30) not null default 0,
	primary key (`id`),
	key (`dateline`),
	key (`server`),
	key (`host`, `dateline`)
) ENGINE=Aria CHARACTER SET utf8mb4;

-- no longer used
CREATE TABLE IF NOT EXISTS `toto_uploader_accounts` (
	`id` int(10) unsigned not null auto_increment,
	`host` char(25) not null default "",
	`cookie` varchar(2048) not null default "",
	`datestart` bigint(30) not null default 0,
	`added` bigint(30) not null default 0, -- for information purposes only
	`info` text not null, -- misc info like username etc
	primary key (`id`),
	key (`host`),
	key (`host`, `datestart`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_tracker_scrape_failures` ( -- failure log
	`id` int(10) unsigned not null auto_increment,
	`tracker_id` smallint(5) unsigned not null,
	`dateline` bigint(30) not null default 0,
	primary key (`id`),
	key (`dateline`),
	key (`tracker_id`, `dateline`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_adb_resolve_queue` (
	`toto_id` int(11) unsigned not null,
	`name` varchar(255) not null,
	`added` bigint(30) not null default 0,
	`priority` smallint(6) not null default 0,
	`dateline` bigint(30) not null default 0, -- last update time
	`filesize` bigint(30) unsigned not null default 0,
	`crc32` binary(4) default null,
	`ed2k` binary(16) default null,
	`video_duration` int(10) unsigned not null default 0,
	primary key (`toto_id`),
	key `process_order` (`priority`,`dateline`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_adb_aniname_map` (
	`nameid` varchar(200) not null,
	`noep` tinyint(2) not null default 0,
	`aid` int(10) unsigned not null,
	`autoadd` tinyint(2) not null default 0,
	`added`  timestamp not null default CURRENT_TIMESTAMP,
	primary key (`nameid`,`noep`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_adb_aniname_alias` (
	`aid` int(10) unsigned not null,
	`name` varchar(384) not null,
	primary key (`aid`,`name`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_fiqueue` (
	`id`  int UNSIGNED NOT NULL AUTO_INCREMENT ,
	`fid`  int UNSIGNED NOT NULL ,
	`type`  varchar(20) NOT NULL ,
	`status`  tinyint NOT NULL DEFAULT 0 , -- 1 = processing
	`dateline`  bigint NOT NULL ,
	`retries`  tinyint NOT NULL DEFAULT 0 ,
	`data`  text NULL ,
	PRIMARY KEY (`id`),
	UNIQUE INDEX (`fid`, `type`) ,
	INDEX (`status`, `dateline`) 
) ENGINE=Aria CHARACTER SET utf8mb4;


CREATE TABLE IF NOT EXISTS `toto_filelinks_active` (
	`id` int(10) unsigned not null auto_increment,
	`fid` int(10) unsigned not null,
	`site` varchar(30) not null,
	`part` smallint(5) unsigned not null default 0, -- 0=no multi-part
	
	`url` varchar(1024) not null,
	`status` tinyint(3) not null default 0, -- 0=active, 1=unresolved, 2=dead, 3=never resolved, -1=processing
	`encrypted` tinyint(3) not null default 0, -- for 'long term link' support - currently these links are just hidden
	
	`added` int(10) unsigned not null default 0,
	`resolvedate` int(10) unsigned not null default 0,
	`lastchecked` int(10) unsigned not null default 0,
	`lastrefreshed` int(10) unsigned not null default 0,
	`lasttouched` int(10) unsigned not null default 0, -- for handling errors - always updated even if failed
	
	`idx_displist` bit(1) AS ((part=0 OR part=1) AND status=0 AND encrypted=0) PERSISTENT,
	
	primary key (`id`),
	unique key (`fid`,`site`,`part`),
	key `check_key`(`status`,`lastchecked`),
	KEY `displist_key` (`idx_displist`,`fid`),
	KEY `fid_only` (`fid`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_newsqueue` (
	`id`  int UNSIGNED NOT NULL ,
	`status`  tinyint NOT NULL DEFAULT 0 , -- 1 = PAR2 creation, 2 = awaiting archive (needs to occur before PAR2 creation, but I've screwed the ordering), 3 = PAR2 done, 4 = uploading
	`dateline`  bigint NOT NULL,
	`retries`  tinyint NOT NULL DEFAULT 0,
	`is_partial`  tinyint NOT NULL DEFAULT 0, -- if files are skipped in the torrent or not
	`priority`  tinyint NOT NULL DEFAULT 0,
	PRIMARY KEY (`id`),
	INDEX (`status`, `dateline`) 
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_btih_seen` (
	`btih` binary(20) not null,
	`seen` int(11) unsigned not null,
	primary key (`btih`)
) ENGINE=Aria CHARACTER SET utf8mb4;


CREATE TABLE IF NOT EXISTS `toto_src_cache_init` (
	`url` varchar(80) not null, -- if longer, we won't cache
	`result` text not null,
	`dateline` bigint(30) not null default 0,
	primary key (`url`),
	key (`dateline`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_src_cache_articlecontent` (
	`url` varchar(250) not null,
	`content` text not null,
	`dateline` bigint(30) not null default 0,
	primary key (`url`),
	key (`dateline`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_src_cache_torlookup` (
	`url` varchar(250) not null,
	`content` mediumtext not null,
	`dateline` bigint(30) not null default 0,
	primary key (`url`),
	key (`dateline`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_src_cache_ddllookup` (
	`host` varchar(40) not null,
	`path` varchar(150) not null,
	`files` text not null,
	`dateline` bigint(30) not null default 0,
	primary key (`host`,`path`),
	key (`dateline`)
) ENGINE=Aria CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `toto_files_extra` (
	`fid` int(10) unsigned not null,
	`vidframes_info` blob,
	`torpc_sha1_16k` binary(20) default null,
	`torpc_sha1_32k` binary(20) default null,
	`torpc_sha1_64k` binary(20) default null,
	`torpc_sha1_128k` binary(20) default null,
	`torpc_sha1_256k` binary(20) default null,
	`torpc_sha1_512k` binary(20) default null,
	`torpc_sha1_1024k` binary(20) default null,
	`torpc_sha1_2048k` binary(20) default null,
	`torpc_sha1_4096k` binary(20) default null,
	`torpc_sha1_8192k` binary(20) default null,
	`torpc_sha1_16384k` binary(20) default null,
	primary key (`fid`),
	key (`torpc_sha1_16k`),
	key (`torpc_sha1_32k`),
	key (`torpc_sha1_64k`),
	key (`torpc_sha1_128k`),
	key (`torpc_sha1_256k`),
	key (`torpc_sha1_512k`),
	key (`torpc_sha1_1024k`),
	key (`torpc_sha1_2048k`),
	key (`torpc_sha1_4096k`),
	key (`torpc_sha1_8192k`),
	key (`torpc_sha1_16384k`)
) ENGINE=Aria CHARACTER SET utf8mb4;
