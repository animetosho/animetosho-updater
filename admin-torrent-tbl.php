<?php
// script to re-add torrent to torrents table
if(PHP_SAPI != 'cli') die;
if($argc!=2) die("Usage [app] toto_id\n");

// for dumb items that won't match normally
define('MATCH_LEFT', 0);

define('DEFAULT_ERROR_HANDLER', 1);
define('THIS_SCRIPT', basename(__FILE__));
define('ROOT_DIR', dirname(__FILE__).'/');

require ROOT_DIR.'init.php';
loadDb();
unset($config);



$toto = $db->selectGetArray('toto', 'id='.($id = (int)$argv[1]));
if(empty($toto)) {
    die("Invalid ID $id\n");
}
if($db->selectGetField('torrents', 'id', 'toto_id='.$id))
        die("Already exists in torrent table\n");

$transmission = get_transmission_rpc();
if(!$transmission) {
	die("failed to connect to transmission\n");
}
$torrents = $transmission->get(array(), array('id', 'name', 'hashString', 'downloadDir'));
if(empty($torrents)) die("Transmission didn't return anything\n");

$torrents = $torrents->arguments->torrents;
if(empty($torrents)) die("Tranmission returned no torrents\n");

$cmpname = preg_replace('~[^a-z0-9_]~', '', strtolower($toto['name']));
if(MATCH_LEFT)
	$cmpname = substr($cmpname, 0, MATCH_LEFT);

// try to find torrent in transmission
foreach($torrents as $k => &$torrent) {
	$test = preg_replace('~[^a-z0-9_]~', '', strtolower($torrent->name));
	if(MATCH_LEFT)
		$test = substr($test, 0, MATCH_LEFT);
    if($test != $cmpname) {
        unset($torrents[$k]);
    }
}

$found = count($torrents);
if($found == 0) die("No matched torrents found for pattern: $cmpname\n");
if($found > 1) {
    echo "Multiple matches found!\n";
    foreach($torrents as &$torrent)
        echo "  {$torrent->name}\n";
    exit;
}

// match found, add to DB
$torrent = reset($torrents);
//...but we need to match the dir too...
if(!is_dir($torrent->downloadDir))
	die("Torrent directory $torrent->downloadDir not found!\n");
if(!preg_match('~^'.preg_quote(TORRENT_DIR).'/?((?:nyaa_|tosho_|toto_)\d+)/?$~', $torrent->downloadDir, $m))
	die("Unexpected torrent directory $torrent->downloadDir\n");
$dir = $m[1];

echo "Found match: {$torrent->name}\n";
$db->insert('torrents', array(
    'id' => $torrent->id,
    'name' => $torrent->name,
    'hashString' => pack('H*', $torrent->hashString),
    'folder' => $dir,
    'toto_id' => $id,
    'dateline' => time(),
    'totalsize' => $toto['totalsize'],
));
$db->update('toto', array('ulcomplete' => 0), 'id='.$id.' AND ulcomplete=-3');
