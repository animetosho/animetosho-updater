<?php

define('FROM_SLAVE', 0);
define('SKIP_TRIGGERS', 0); // for storage/feed server without mirror
define('IMPORT_FORCE', ''); // if importing fails due to MariaDB issues, set to ' --force'

if(!@$argv[1]) die("Syntax: [script] [root pass]\n");

// !!!!!!!!!!
// TODO: disable manticore index rebuilds during import
// !!!!!!!!!!

if(!file_exists('toto_repl_load.sql')) die("File toto_repl_load.sql not found\n");

$load_repl = file_exists('data-toto_repl.sql.zst');
$load_anidb = file_exists('data-anidb.sql.zst');
$load_arcscrape = file_exists('data-arcscrape.sql.zst');

if(!$load_repl && !$load_anidb && !$load_arcscrape) die("No dumps to load\n");

// connect to SQL
function db_connect() {
	global $argv, $db;
	$db = mysqli_connect('localhost', 'root', $argv[1], 'toto_repl', 3336);
	$db or die("Couldn't connect to DB\n");
}
function query($s) {
	global $db;
	echo "$s\n";
	$r = mysqli_query($db, $s);
	if(mysqli_errno($db))
		die(mysqli_error($db));
	return $r;
}
function cmd($s) {
	echo "EXEC: $s\n";
	system($s, $rc);
	if($rc) die("Command failed\n");
}

db_connect();
query('STOP SLAVE');

if($load_repl) {
	mysqli_select_db($db, 'anito'); // prevent writing to relay log
	query('DROP TRIGGER IF EXISTS toto_repl.trig_ep_insert');
	query('DROP TRIGGER IF EXISTS toto_repl.trig_ep_update');
	query('DROP TRIGGER IF EXISTS toto_repl.trig_ep_delete');

	cmd('unzstd --stdout schema-toto_repl.sql.zst | mysql --defaults-file=/etc/mysql/debian.cnf'.IMPORT_FORCE.' toto_repl');
	if(!FROM_SLAVE) {
		query('DROP INDEX `tracker_queried` ON `toto_repl`.`toto_tracker_stats`;');
		query('ALTER TABLE `toto_repl`.`toto_files`
			DROP INDEX `size_crc`;');
		query('DROP INDEX `hash` ON `toto_repl`.`toto_attachment_files`;');
	}
	mysqli_close($db);
	cmd('unzstd --stdout data-toto_repl.sql.zst | mysql --defaults-file=/etc/mysql/debian.cnf --init-command "SET SESSION sql_mode=\'\'"'.IMPORT_FORCE.' toto_repl');


	db_connect();
	if(!FROM_SLAVE) {
		query('ALTER TABLE `toto_repl`.`toto_toto`
			ADD COLUMN `_updated`  timestamp NOT NULL ON UPDATE CURRENT_TIMESTAMP,
			ADD INDEX `sphinx_delta` (`_updated`);');
		if(!SKIP_TRIGGERS) {
			query('CREATE DEFINER=`root`@`localhost` TRIGGER trig_ep_insert
			AFTER INSERT ON toto_toto
			FOR EACH ROW BEGIN
				DECLARE aniep_dateline BIGINT;
				DECLARE anidb_aid INT;
				IF NEW.eid != 0 THEN
					REPLACE INTO anito.toto_ep_latest VALUES(NEW.eid, -(SELECT MAX(idx_dateline) FROM toto_toto WHERE eid=NEW.eid));
					SET aniep_dateline = (SELECT MIN(idx_dateline) FROM toto_toto WHERE eid=NEW.eid);
					SET anidb_aid = (SELECT aid FROM anidb.ep WHERE id=NEW.eid);
					IF anidb_aid IS NOT NULL THEN
						REPLACE INTO anito.toto_aniep_latest VALUES(NEW.eid, anidb_aid, NEW.eid, -aniep_dateline);
					END IF;
				ELSE
					IF NEW.aid = 0 THEN
						SET aniep_dateline = (SELECT MIN(idx_dateline) FROM toto_toto WHERE aid=0);
					ELSE
						SET aniep_dateline = (SELECT MIN(idx_dateline) FROM toto_toto USE INDEX(aid_filter) WHERE aid=NEW.aid AND eid=0);
					END IF;
					REPLACE INTO anito.toto_aniep_latest VALUES(-NEW.aid, NEW.aid, 0, -aniep_dateline);
				END IF;
				REPLACE INTO anito.toto_anime_latest VALUES(NEW.aid, -(SELECT MIN(idx_dateline) FROM toto_toto WHERE aid=NEW.aid));
			END;');
			query('CREATE DEFINER=`root`@`localhost` TRIGGER trig_ep_update
			AFTER UPDATE ON toto_toto
			FOR EACH ROW BEGIN
				DECLARE aniep_dateline BIGINT;
				DECLARE anidb_aid INT;
				IF OLD.eid != 0 THEN
					REPLACE INTO anito.toto_ep_latest VALUES(OLD.eid, -(SELECT MAX(idx_dateline) FROM toto_toto WHERE eid=OLD.eid));
					SET aniep_dateline = (SELECT MIN(idx_dateline) FROM toto_toto WHERE eid=OLD.eid);
					IF aniep_dateline IS NULL THEN
						DELETE FROM anito.toto_aniep_latest WHERE aeid=OLD.eid;
					ELSE
						SET anidb_aid = (SELECT aid FROM anidb.ep WHERE id=OLD.eid);
						IF anidb_aid IS NOT NULL THEN
							REPLACE INTO anito.toto_aniep_latest VALUES(OLD.eid, anidb_aid, OLD.eid, -aniep_dateline);
						END IF;
					END IF;
				ELSE
					IF OLD.aid = 0 THEN
						SET aniep_dateline = (SELECT MIN(idx_dateline) FROM toto_toto WHERE aid=0);
					ELSE
						SET aniep_dateline = (SELECT MIN(idx_dateline) FROM toto_toto USE INDEX(aid_filter) WHERE aid=OLD.aid AND eid=0);
					END IF;
					IF aniep_dateline IS NULL THEN
						DELETE FROM anito.toto_aniep_latest WHERE aeid=-OLD.aid;
					ELSE
						REPLACE INTO anito.toto_aniep_latest VALUES(-OLD.aid, OLD.aid, 0, -aniep_dateline);
					END IF;
				END IF;
				IF NEW.eid != OLD.eid AND NEW.eid != 0 THEN REPLACE INTO anito.toto_ep_latest VALUES(NEW.eid, -(SELECT MAX(idx_dateline) FROM toto_toto WHERE eid=NEW.eid));
				END IF;
				REPLACE INTO anito.toto_anime_latest VALUES(OLD.aid, -(SELECT MIN(idx_dateline) FROM toto_toto WHERE aid=OLD.aid));
				IF NEW.aid != OLD.aid THEN REPLACE INTO anito.toto_anime_latest VALUES(NEW.aid, -(SELECT MIN(idx_dateline) FROM toto_toto WHERE aid=NEW.aid));
				END IF;
				IF NEW.aid != OLD.aid OR NEW.eid != OLD.eid THEN
					IF NEW.eid != 0 THEN
						SET anidb_aid = (SELECT aid FROM anidb.ep WHERE id=NEW.eid);
						IF anidb_aid IS NOT NULL THEN
							REPLACE INTO anito.toto_aniep_latest VALUES(NEW.eid, anidb_aid, NEW.eid, -(SELECT MIN(idx_dateline) FROM toto_toto WHERE eid=NEW.eid));
						END IF;
					ELSEIF NEW.aid = 0 THEN
						REPLACE INTO anito.toto_aniep_latest VALUES(0, 0, 0, -(SELECT MIN(idx_dateline) FROM toto_toto WHERE aid=0));
					ELSE
						REPLACE INTO anito.toto_aniep_latest VALUES(-NEW.aid, NEW.aid, 0, -(SELECT MIN(idx_dateline) FROM toto_toto USE INDEX(aid_filter) WHERE aid=NEW.aid AND eid=0));
					END IF;
				END IF;
			END;');
			query('CREATE DEFINER=`root`@`localhost` TRIGGER trig_ep_delete
			AFTER DELETE ON toto_toto
			FOR EACH ROW BEGIN
				DECLARE aniep_dateline BIGINT;
				DECLARE anidb_aid INT;
				IF OLD.eid != 0 THEN
					REPLACE INTO anito.toto_ep_latest VALUES(OLD.eid, -(SELECT MAX(idx_dateline) FROM toto_toto WHERE eid=OLD.eid));
					SET aniep_dateline = (SELECT MIN(idx_dateline) FROM toto_toto WHERE eid=OLD.eid);
					IF aniep_dateline IS NULL THEN
						DELETE FROM anito.toto_aniep_latest WHERE aeid=OLD.eid;
					ELSE
						SET anidb_aid = (SELECT aid FROM anidb.ep WHERE id=OLD.eid);
						IF anidb_aid IS NOT NULL THEN
							REPLACE INTO anito.toto_aniep_latest VALUES(OLD.eid, anidb_aid, OLD.eid, -aniep_dateline);
						END IF;
					END IF;
				ELSE
					IF OLD.aid = 0 THEN
						SET aniep_dateline = (SELECT MIN(idx_dateline) FROM toto_toto WHERE aid=0);
					ELSE
						SET aniep_dateline = (SELECT MIN(idx_dateline) FROM toto_toto USE INDEX(aid_filter) WHERE aid=OLD.aid AND eid=0);
					END IF;
					IF aniep_dateline IS NULL THEN
						DELETE FROM anito.toto_aniep_latest WHERE aeid=-OLD.aid;
					ELSE
						REPLACE INTO anito.toto_aniep_latest VALUES(-OLD.aid, OLD.aid, 0, -aniep_dateline);
					END IF;
				END IF;
				REPLACE INTO anito.toto_anime_latest VALUES(OLD.aid, -(SELECT MIN(idx_dateline) FROM toto_toto WHERE aid=OLD.aid));
			END;');
		}
		
		$q = query('SELECT count(*) FROM information_schema.tables WHERE table_schema = "anito" AND table_name LIKE "toto_%_latest"');
		$c = mysqli_fetch_array($q, MYSQLI_NUM);
		mysqli_free_result($q);
		if($c[0] > 0) {
			query('TRUNCATE anito.toto_ep_latest;');
			query('TRUNCATE anito.toto_anime_latest;');
			query('TRUNCATE anito.toto_aniep_latest;');
			query('INSERT INTO anito.toto_ep_latest
				SELECT eid, -MAX(idx_dateline) AS dateline FROM toto_repl.toto_toto WHERE idx_dateline IS NOT NULL AND eid!=0 GROUP BY eid;');
			query('INSERT INTO anito.toto_anime_latest
				SELECT aid, -MIN(idx_dateline) AS dateline FROM toto_repl.toto_toto WHERE idx_dateline IS NOT NULL GROUP BY aid;');
			query('INSERT INTO anito.toto_aniep_latest
				SELECT aeid, aid, eid, MAX(dateline)
				FROM (
					SELECT IF(t.eid=0, -t.aid, t.eid) AS aeid, IF(t.eid=0, t.aid, e.aid) AS aid, t.eid, -MIN(t.idx_dateline) AS dateline
					FROM toto_repl.toto_toto t
					LEFT JOIN anidb.ep e ON t.eid=e.id
					WHERE t.idx_dateline IS NOT NULL
					GROUP BY t.aid, t.eid
				) aesrc
				GROUP BY aeid;');
		}
	}
}
mysqli_close($db);


if($load_anidb) {
	db_connect();
	cmd('unzstd --stdout schema-anidb.sql.zst | mysql --defaults-file=/etc/mysql/debian.cnf'.IMPORT_FORCE.' anidb');
	
	query('ALTER TABLE `anidb`.`_anime_http` ENGINE=BLACKHOLE;');
	query('ALTER TABLE `anidb`.`_lastcheck` ENGINE=BLACKHOLE;');
	query('ALTER TABLE `anidb`.`mylist` ENGINE=BLACKHOLE;');
	query('ALTER TABLE `anidb`.`review` ENGINE=BLACKHOLE;');
	query('ALTER TABLE `anidb`.`groupvote` ENGINE=BLACKHOLE;');
	query('ALTER TABLE `anidb`.`file` DROP INDEX `aid`, DROP INDEX `gid`, DROP INDEX `eid`, DROP INDEX `crc`, DROP INDEX `ed2k`;');
	mysqli_close($db);
	cmd('unzstd --stdout data-anidb.sql.zst | mysql --defaults-file=/etc/mysql/debian.cnf'.IMPORT_FORCE.' anidb');
}

function load_arcscrape() {
	cmd('unzstd --stdout schema-arcscrape.sql.zst | mysql --defaults-file=/etc/mysql/debian.cnf'.IMPORT_FORCE.' arcscrape');
	
	//query('ALTER TABLE arcscrape.tosho_torrents DROP INDEX `updated`;');
	query('ALTER TABLE arcscrape.tosho_torrents ENGINE=BLACKHOLE;');
	query('ALTER TABLE arcscrape.anidex_torrents ENGINE=BLACKHOLE;');
	query('ALTER TABLE arcscrape.anidex_users ENGINE=BLACKHOLE;');
	query('ALTER TABLE arcscrape.anidex_torrent_comments ENGINE=BLACKHOLE;');
	query('ALTER TABLE arcscrape.anidex_groups ENGINE=BLACKHOLE;');
	query('ALTER TABLE arcscrape.anidex_group_members ENGINE=BLACKHOLE;');
	query('ALTER TABLE arcscrape.anidex_group_comments ENGINE=BLACKHOLE;');
	query('ALTER TABLE arcscrape.nekobt_torrents ENGINE=BLACKHOLE;');
	query('ALTER TABLE arcscrape.nekobt_users ENGINE=BLACKHOLE;');
	query('ALTER TABLE arcscrape.nekobt_groups ENGINE=BLACKHOLE;');
}
if($load_arcscrape) {
	db_connect();
	load_arcscrape();
	
	/*query('ALTER TABLE arcscrape.nyaasi_torrents
		DROP INDEX listing_check_index,
		DROP INDEX uploader_check_index,
		ADD INDEX `main_index` (`idx_class`,`created_time`),
		ADD INDEX `category_index` (`idx_class`,`main_category_id`,`created_time`),
		ADD INDEX `uploader_index` (`idx_class`,`uploader_name`,`created_time`);
	');
	query('ALTER TABLE arcscrape.nyaasis_torrents
		DROP INDEX listing_check_index,
		DROP INDEX uploader_check_index,
		ADD INDEX `main_index` (`idx_class`,`created_time`),
		ADD INDEX `category_index` (`idx_class`,`main_category_id`,`created_time`),
		ADD INDEX `uploader_index` (`idx_class`,`uploader_name`,`created_time`);
	');*/
	query('ALTER TABLE arcscrape.nyaasi_torrents
		DROP INDEX sub_category_index,
		DROP INDEX listing_check_index,
		DROP INDEX uploader_check_index;
	');
	query('ALTER TABLE arcscrape.nyaasis_torrents
		DROP INDEX sub_category_index,
		DROP INDEX listing_check_index,
		DROP INDEX uploader_check_index;
	');
	mysqli_close($db);
	cmd('unzstd --stdout data-arcscrape.sql.zst | mysql --defaults-file=/etc/mysql/debian.cnf'.IMPORT_FORCE.' arcscrape');
	
	
	db_connect();
	query('ALTER TABLE arcscrape.nyaasi_torrents
		DROP COLUMN idx_class;
	');
	query('ALTER TABLE arcscrape.nyaasis_torrents
		DROP COLUMN idx_class;
	');
	mysqli_close($db);
}
elseif(file_exists('schema-arcscrape.sql.zst')) {
	// storage server doesn't need arcscrape data
	db_connect();
	load_arcscrape();
	query('ALTER TABLE arcscrape.nyaasi_torrents DROP COLUMN idx_class;');
	query('ALTER TABLE arcscrape.nyaasis_torrents DROP COLUMN idx_class;');
	
	query('ALTER TABLE arcscrape.nyaasi_torrent_comments ENGINE=BLACKHOLE;');
	query('ALTER TABLE arcscrape.nyaasi_torrents ENGINE=BLACKHOLE;');
	query('ALTER TABLE arcscrape.nyaasi_users ENGINE=BLACKHOLE;');
	query('ALTER TABLE arcscrape.nyaasis_torrent_comments ENGINE=BLACKHOLE;');
	query('ALTER TABLE arcscrape.nyaasis_torrents ENGINE=BLACKHOLE;');
	mysqli_close($db);
}

db_connect();
query('RESET SLAVE');
query(file_get_contents('toto_repl_load.sql'));
query('START SLAVE');

// done
echo "Done, delete toto_repl* files + rebuild sphinx index\n";
mysqli_close($db);
