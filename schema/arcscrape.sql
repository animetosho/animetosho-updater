CREATE TABLE `_state` (
  `k` enum('nyaasi_upto','nyaasis_upto','nyaasi_upto_upd1','nyaasi_upto_upd2','nyaasi_upto_upd3','nyaasi_upto_upd4','nyaasis_upto_upd1','nyaasis_upto_upd2','nyaasis_upto_upd3','nyaasis_upto_upd4','anidex_upto','anidex_upto_upd1','anidex_upto_upd2','anidex_upto_upd3','anidex_upto_upd4','anidex_user_upto','anidex_user_upto_upd1','anidex_user_upto_upd2','anidex_user_upto_upd3','anidex_user_upto_upd4','anidex_group_upto','anidex_group_upto_upd1','anidex_group_upto_upd2','anidex_group_upto_upd3','anidex_group_upto_upd4','tosho_upto','tosho_upto_upd1','tosho_upto_upd2','tosho_upto_upd3','tosho_upto_upd4','nekobt_upto','nekobt_upto_upd1','nekobt_upto_upd2','nekobt_upto_upd3','nekobt_upto_upd4') NOT NULL,
  `v` bigint(30) unsigned NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1;


--------------------
-- Anidex
--------------------

CREATE TABLE `anidex_torrent_comments` (
`_id`  int UNSIGNED NOT NULL AUTO_INCREMENT ,
`torrent_id`  int UNSIGNED NOT NULL ,
`user_id`  int UNSIGNED NOT NULL ,
`date`  bigint NOT NULL ,
`message`  text NOT NULL ,
`deleted`  tinyint NOT NULL DEFAULT 0 ,
PRIMARY KEY (`_id`),
UNIQUE INDEX (`torrent_id`, `date`, `user_id`) -- as we don't have the true ID for the comment, simulate one using what we have; we hope that it's unlikely this will ever duplicate
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1;

CREATE TABLE `anidex_torrents` (
`id`  int UNSIGNED NOT NULL ,
`filename`  varchar(255) NOT NULL ,
`category`  tinyint NOT NULL ,
`language`  tinyint NOT NULL ,
`labels`  tinyint NOT NULL DEFAULT 0 ,
`uploader_id`  int UNSIGNED NOT NULL ,
`group_id`  int UNSIGNED NOT NULL DEFAULT 0 ,
`date`  bigint NOT NULL ,
`filesize`  bigint UNSIGNED NOT NULL DEFAULT 0 ,
`info_hash`  binary(20) NOT NULL ,
`xdcc`  varchar(255) DEFAULT NULL ,
`torrent_info`  varchar(10000) NOT NULL COMMENT 'What is this for?' ,
`description`  text NOT NULL ,
`likes` int UNSIGNED NOT NULL DEFAULT 0 ,
`updated` bigint UNSIGNED NOT NULL ,
`deleted`  tinyint NOT NULL DEFAULT 0 ,
PRIMARY KEY (`id`),
KEY `uploader_date` (`uploader_id`,`date`)
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1;

CREATE TABLE `anidex_users` (
`id`  int UNSIGNED NOT NULL ,
`username`  varchar(100) NOT NULL ,
`language`  tinyint NOT NULL ,
`user_level`  tinyint NOT NULL ,
`joined`  bigint NOT NULL ,
-- `last_online`  bigint NOT NULL ,
`avatar`  varchar(255) NOT NULL DEFAULT '' ,
`updated` bigint UNSIGNED NOT NULL ,
`deleted`  tinyint NOT NULL DEFAULT 0 ,
PRIMARY KEY (`id`)
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1;

CREATE TABLE `anidex_groups` (
`id`  int NOT NULL ,
`group_name`  varchar(255) NOT NULL ,
`tags`  varchar(255) NOT NULL ,
`founded`  bigint NOT NULL ,
`language`  tinyint NOT NULL ,
`banner`  varchar(255) NULL DEFAULT '',
`website`  varchar(511) NULL ,
`irc`  varchar(255) NULL ,
`email`  varchar(511) NULL ,
`discord`  varchar(255) NULL ,
`leader_id`  int UNSIGNED NOT NULL ,
`likes`  int UNSIGNED NOT NULL DEFAULT 0 ,
`updated`  bigint NOT NULL ,
`deleted`  tinyint NOT NULL DEFAULT 0 ,
PRIMARY KEY (`id`)
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1;

CREATE TABLE `anidex_group_comments` (
`_id`  int UNSIGNED NOT NULL AUTO_INCREMENT ,
`group_id`  int UNSIGNED NOT NULL ,
`user_id`  int UNSIGNED NOT NULL ,
`date`  bigint NOT NULL ,
`message`  text NOT NULL ,
`deleted`  tinyint NOT NULL DEFAULT 0 ,
PRIMARY KEY (`_id`),
UNIQUE INDEX (`group_id`, `date`, `user_id`) -- simulate unique ID
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1;

CREATE TABLE `anidex_group_members` (
`group_id` int NOT NULL,
`user_id` int NOT NULL,
PRIMARY KEY (`group_id`,`user_id`)
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1;


--------------------
-- nekoBT
--------------------

CREATE TABLE `nekobt_torrents` (
  `id` bigint(30) unsigned NOT NULL,
  `title` varchar(500) NOT NULL,
  `description` text NULL,
  `mediainfo` text NULL,
  `category` tinyint(3) NOT NULL, -- 1=Anime
  `deleted` tinyint(3) NOT NULL DEFAULT 0, -- -1=API returning not found error, 1=API returning torrent with deleted status
  `hidden` tinyint(3) NOT NULL DEFAULT 0,
  `otl` tinyint(3) NOT NULL DEFAULT 0,
  `hardsub` tinyint(3) NOT NULL DEFAULT 0,
  `level` tinyint(3) NULL,
  `mtl` tinyint(3) NOT NULL DEFAULT 0,
  `filesize` bigint(30) unsigned NOT NULL,
  `media_id_type` char(1) CHARACTER SET ascii NOT NULL DEFAULT " ",
  `media_id` int(10) unsigned NOT NULL DEFAULT 0,
  `media_episode_ids` varbinary(255) NOT NULL DEFAULT "", -- custom packed format
  `audio_lang` varchar(255) CHARACTER SET ascii NOT NULL DEFAULT "",
  `sub_lang` varchar(1000) CHARACTER SET ascii NOT NULL DEFAULT "",
  `fsub_lang` varchar(255) CHARACTER SET ascii NOT NULL DEFAULT "",
  `video_codec` tinyint(3) NOT NULL,
  `video_type` tinyint(3) NOT NULL,
  `infohash` binary(20) NOT NULL,
  `uploader` bigint(30) unsigned NULL, -- NULL if anonymous
  `uploading_group` bigint(30) unsigned NULL,
  `secondary_groups` varchar(255) CHARACTER SET ascii NOT NULL DEFAULT "", -- comma separated IDs
  `imported` int(10) unsigned NULL,
  `upgraded` bigint(30) unsigned NULL,
  `can_edit` tinyint(3) NOT NULL DEFAULT 0,
  `waiting_approve` tinyint(3) NOT NULL DEFAULT 0,
  `disable_comments` tinyint(3) NOT NULL DEFAULT 0,
  `lock_comments` tinyint(3) NOT NULL DEFAULT 0,
  `disable_edits` tinyint(3) NOT NULL DEFAULT 0,
  `nyaa_upload_time` bigint(30) NULL,
  
  `updated_time` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `updated_time` (`updated_time`)
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1;

CREATE TABLE `nekobt_users` (
  `id` bigint(30) unsigned NOT NULL,
  `username` varchar(255) NOT NULL,
  `display_name` varchar(255) NOT NULL,
  `pfp_hash` binary(32) NULL, -- SHA256 of file
  `updated_time` int(10) unsigned NOT NULL,
  `deleted` tinyint(3) NOT NULL DEFAULT 0, -- never updated at the moment
  PRIMARY KEY (`id`),
  KEY `updated_time` (`updated_time`)
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1;

CREATE TABLE `nekobt_groups` (
  `id` bigint(30) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `display_name` varchar(255) NOT NULL,
  `pfp_hash` binary(32) NULL,
  `tagline` varchar(255) NOT NULL DEFAULT "",
  `members` text NOT NULL, -- JSON: [{"id":..., "invite":false, "role":..., "weight":1}]
  `updated_time` int(10) unsigned NOT NULL,
  `deleted` tinyint(3) NOT NULL DEFAULT 0, -- never updated at the moment
  PRIMARY KEY (`id`),
  KEY `updated_time` (`updated_time`)
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1;


--------------------
-- Nyaa.si
--------------------

CREATE TABLE `nyaasi_torrents` (
  `id` int(10) unsigned NOT NULL,
  `info_hash` binary(20) NOT NULL,
  `display_name` varchar(255) NOT NULL,
  `torrent_name` varchar(511) NOT NULL DEFAULT '',
  `information` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `filesize` bigint(20) unsigned NOT NULL DEFAULT '0',
  `flags` tinyint(4) unsigned NOT NULL DEFAULT '0' COMMENT 'NONE = 0, ANONYMOUS = 1, HIDDEN = 2, TRUSTED = 4, REMAKE = 8, COMPLETE = 16, DELETED = 32, BANNED = 64, COMMENT_LOCKED = 128',
  `uploader_name` varchar(32) CHARACTER SET ascii DEFAULT NULL,
  `created_time` int(10) unsigned NOT NULL,
  `updated_time` int(10) unsigned NOT NULL,
  `main_category_id` tinyint(4) NOT NULL,
  `sub_category_id` tinyint(4) NOT NULL,
  `redirect` int(10) unsigned DEFAULT NULL COMMENT 'No found usage in code',
  `idx_class` tinyint(4) AS (if(flags & 34 > 0, -1, IF(flags&8>0, 0, if(flags&4>0, 2, 1)))) PERSISTENT COMMENT 'We can''t determine if an entry is both trusted and a remake (not shown in HTML), so just merge it',
  /*`idx_search` varchar(512) AS (
	if(flags & 34 > 0, "",
		CONCAT("__index_cat_", main_category_id, " __index_subcat_", sub_category_id, " ", IF(flags&8>0, "", "__index_notremake "), IF(flags&4>0, "", "__index_trusted "), display_name)
	)) PERSISTENT,*/
  PRIMARY KEY (`id`),
  KEY `sub_category_index` (`idx_class`,`main_category_id`,`sub_category_id`,`created_time`),
  KEY listing_check_index(`created_time`),
  KEY uploader_check_index(`uploader_name`, `created_time`)
  /*KEY `main_index` (`idx_class`,`created_time`),
  KEY `category_index` (`idx_class`,`main_category_id`,`created_time`),
  KEY `uploader_index` (`idx_class`,`uploader_name`,`created_time`),
  KEY `info_hash` (`info_hash`),
  FULLTEXT KEY `idx_search` (`idx_search`)*/
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1;

CREATE TABLE `nyaasi_torrent_comments` (
  `id` int(10) unsigned NOT NULL,
  `torrent_id` int(10) unsigned NOT NULL,
  `created_time` int(10) unsigned NOT NULL DEFAULT '0',
  `edited_time` int(10) unsigned NOT NULL DEFAULT '0',
  `username` varchar(255) CHARACTER SET ascii NOT NULL,
  `text` text NOT NULL,
  -- deprecated field
  `md5` binary(20) DEFAULT NULL COMMENT 'Actually a user property; MD5 of user''s email',
  PRIMARY KEY (`id`),
  KEY `torrent_id` (`torrent_id`,`created_time`)
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1;

CREATE TABLE `nyaasi_users` (
  `name` varchar(255) CHARACTER SET ascii NOT NULL,
  `level` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0=REGULAR, 1=TRUSTED, 2=MODERATOR, 3=SUPERADMIN, -1=BANNED',
  -- `md5` binary(20) DEFAULT NULL COMMENT 'MD5 of user''s email; not updated, so historical info only',
  `ava_stamp` int(10) unsigned NULL DEFAULT NULL COMMENT 'Interpreted by base64url decoding the slug and interpreting as big-endian 32b integer; appears to be some sort of timestamp',
  `updated_time` int(10) unsigned NOT NULL,
  PRIMARY KEY (`name`),
  KEY `updated_time` (`updated_time`)
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1;


CREATE TABLE `nyaasis_torrents` (
  `id` int(10) unsigned NOT NULL,
  `info_hash` binary(20) NOT NULL,
  `display_name` varchar(255) NOT NULL,
  `torrent_name` varchar(511) NOT NULL DEFAULT '',
  `information` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `filesize` bigint(20) unsigned NOT NULL DEFAULT '0',
  `flags` tinyint(4) unsigned NOT NULL DEFAULT '0' COMMENT 'NONE = 0, ANONYMOUS = 1, HIDDEN = 2, TRUSTED = 4, REMAKE = 8, COMPLETE = 16, DELETED = 32, BANNED = 64, COMMENT_LOCKED = 128',
  `uploader_name` varchar(32) CHARACTER SET ascii DEFAULT NULL,
  `created_time` int(10) unsigned NOT NULL,
  `updated_time` int(10) unsigned NOT NULL,
  `main_category_id` tinyint(4) NOT NULL,
  `sub_category_id` tinyint(4) NOT NULL,
  `redirect` int(10) unsigned DEFAULT NULL COMMENT 'No found usage in code',
  `idx_class` tinyint(4) AS (if(flags & 34 > 0, -1, IF(flags&8>0, 0, if(flags&4>0, 2, 1)))) PERSISTENT COMMENT 'We can''t determine if an entry is both trusted and a remake (not shown in HTML), so just merge it',
  /*`idx_search` varchar(512) AS (
	if(flags & 34 > 0, "",
		CONCAT("__index_cat_", main_category_id, " __index_subcat_", sub_category_id, " ", IF(flags&8>0, "", "__index_notremake "), IF(flags&4>0, "", "__index_trusted "), display_name)
	)) PERSISTENT,*/
  PRIMARY KEY (`id`),
  KEY `sub_category_index` (`idx_class`,`main_category_id`,`sub_category_id`,`created_time`),
  KEY listing_check_index(`created_time`),
  KEY uploader_check_index(`uploader_name`, `created_time`)
  /*KEY `main_index` (`idx_class`,`created_time`),
  KEY `category_index` (`idx_class`,`main_category_id`,`created_time`),
  KEY `uploader_index` (`idx_class`,`uploader_name`,`created_time`),
  KEY `info_hash` (`info_hash`),
  FULLTEXT KEY `idx_search` (`idx_search`)*/
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1;

CREATE TABLE `nyaasis_torrent_comments` (
  `id` int(10) unsigned NOT NULL,
  `torrent_id` int(10) unsigned NOT NULL,
  `created_time` int(10) unsigned NOT NULL DEFAULT '0',
  `edited_time` int(10) unsigned NOT NULL DEFAULT '0',
  `username` varchar(255) CHARACTER SET ascii NOT NULL,
  `text` text NOT NULL,
  `md5` binary(20) DEFAULT NULL COMMENT 'Actually a user property; MD5 of user''s email',
  PRIMARY KEY (`id`),
  KEY `torrent_id` (`torrent_id`,`created_time`)
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1;


--------------------
-- Tokyo Tosho
--------------------

CREATE TABLE `tosho_torrents` (
`id`  int UNSIGNED NOT NULL ,
`name`  varchar(255) NOT NULL ,
`type`  tinyint NOT NULL , -- category
`submitted`  bigint NOT NULL ,
`size`  varchar(10) CHARACTER SET ascii NOT NULL ,
`link`  varchar(1500) NOT NULL ,
`website`  varchar(1000) NOT NULL ,
`comment`  varchar(511) NOT NULL ,
`tracker_main`  varchar(255) NOT NULL ,
`tracker_extra`  text NOT NULL COMMENT 'Additional trackers, newline separated' ,
`files`  mediumtext DEFAULT NULL COMMENT 'Files in torrent, JSON encoded as an array of filename/size arrays' ,
`info_hash`  binary(20) NOT NULL ,
`submitter_hash`  binary(20) NOT NULL ,
`submitter`  varchar(255) NOT NULL ,
`authorized`  tinyint NOT NULL ,
`updated`  bigint NOT NULL ,
`deleted`  tinyint NOT NULL DEFAULT 0 ,
PRIMARY KEY (`id`),
KEY (`type`, `submitted`),
-- KEY (`submitter`, `submitted`), -- key too long
KEY (`updated`)
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1;

