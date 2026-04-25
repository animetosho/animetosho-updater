<?php
define('ON_MASTER', 1);
define('SKIP_ARCSCRAPE', 0);

if(ON_MASTER) {
	define('DBUSER', 'backup');
	define('DBPASS', '');
} else {
	define('DBUSER', 'root');
	define('DBPASS', '');
}

// connect to SQL
$db = mysqli_connect('localhost', DBUSER, DBPASS, null, 3336);
$db or die("Couldn't connect to DB\n");


function query($s) {
	global $db;
	echo "$s\n";
	$r = mysqli_query($db, $s);
	if(mysqli_errno($db))
		die(mysqli_error($db));
	return $r;
}
// lock tables
query('SET SESSION wait_timeout=3600');
if(ON_MASTER) {
	query('FLUSH TABLES WITH READ LOCK');
	// grab current pos
	$res = mysqli_fetch_array(query('SHOW MASTER STATUS'));
	file_put_contents('toto_repl_load.sql', 'CHANGE MASTER TO MASTER_LOG_FILE = "'.$res['File'].'", MASTER_LOG_POS = '.$res['Position']);
} else {
	query('STOP SLAVE');
	// grab current pos
	$res = mysqli_fetch_array(query('SHOW SLAVE STATUS'));
	file_put_contents('toto_repl_load.sql', 'CHANGE MASTER TO
		MASTER_HOST = "'.$res['Master_Host'].'",
		MASTER_PORT = '.$res['Master_Port'].',
		MASTER_USER = "'.$res['Master_User'].'",
		MASTER_PASSWORD = "9j324jh12q349un",
		MASTER_CONNECT_RETRY = '.$res['Connect_Retry'].',
		MASTER_USE_GTID = '.$res['Using_Gtid'].',
		MASTER_LOG_FILE = "'.$res['Master_Log_File'].'",
		MASTER_LOG_POS = '.$res['Read_Master_Log_Pos']);
}

@unlink('toto_repl.sql.zst');
echo "Dumping toto_repl...\n";
shell_exec('mysqldump -u'.DBUSER.' -p'.DBPASS.' --no-data --skip-triggers --hex-blob -Q toto_repl | zstd --long -8 > "schema-toto_repl.sql.zst"');
shell_exec('mysqldump -u'.DBUSER.' -p'.DBPASS.' -nt --skip-triggers --hex-blob -Q -c toto_repl | zstd --long -8 > "data-toto_repl.sql.zst"');
echo "Dumping anidb...\n";
shell_exec('mysqldump -u'.DBUSER.' -p'.DBPASS.' --no-data --skip-triggers --hex-blob -Q anidb | zstd --long -8 > "schema-anidb.sql.zst"');
shell_exec('mysqldump -u'.DBUSER.' -p'.DBPASS.' -nt --skip-triggers --hex-blob --ignore-table=anidb._anime_http --ignore-table=anidb._lastcheck --ignore-table=anidb.mylist --ignore-table=anidb.review --ignore-table=anidb.groupvote -Q anidb | zstd --long -8 > "data-anidb.sql.zst"');
echo "Dumping arcscrape...\n";
shell_exec('mysqldump -u'.DBUSER.' -p'.DBPASS.' --no-data --skip-triggers --hex-blob -Q arcscrape | zstd --long -8 > "schema-arcscrape.sql.zst"');
if(!SKIP_ARCSCRAPE) {
	shell_exec('mysqldump -u'.DBUSER.' -p'.DBPASS.' -nt --skip-triggers --hex-blob --ignore-table=arcscrape.tosho_torrents --ignore-table=arcscrape.anidex_torrents --ignore-table=arcscrape.anidex_users --ignore-table=arcscrape.anidex_torrent_comments --ignore-table=arcscrape.anidex_groups --ignore-table=arcscrape.anidex_group_members --ignore-table=arcscrape.anidex_group_comments --ignore-table=arcscrape.nekobt_torrents --ignore-table=arcscrape.nekobt_users --ignore-table=arcscrape.nekobt_groups -Q -c arcscrape | zstd --long -8 > "data-arcscrape.sql.zst"');
}

// unlock tables
query(ON_MASTER ? 'UNLOCK TABLES' : 'START SLAVE');

if(!file_exists('toto_repl.sql.zst'))
	die("Couldn't find dumped DB\n");

mysqli_close($db);
