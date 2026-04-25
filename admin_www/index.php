<?php
define('THIS_SCRIPT', 'mod_www/'.basename(__FILE__));
define('DEFAULT_ERROR_HANDLER', 1);
require './_base.php';

require ROOT_DIR.'includes/miniwebcore.php';

header('Expires: '.gmdate('D, d M Y H:i:s', time()+86400));
header('Cache-Control: private');

pHead('Index');

?>
<h1>AnimeTosho Administration Utilities</h1>
<p>A very crude set of utilities to manage stuff.</p>

<h2><a href="activity.php">Server Activity</a></h2>
<p>Shows queued torrent/upload activity on the server.</p>

<h2><a href="./transmission/web/">Transmission Web UI</a></h2>
<p>The web interface to the Bittorrent client (Transmission).  Isn't fully developed yet, and some functions won't work.</p>

<h2>Torrent Info/Edit</h2>
<p>Paste in an AnimeTosho torrent view URL (or ID) below to see info or change aspects of the torrent.</p>
<form action="titem.php" method="get">
<input type="text" name="id" value="<?=HOME_URL?>/view/" style="width: 40em;" />
<input type="submit" value="Go" />
</form>

<h2>Server Info</a></h2>
<ul>
	<li><a href="./vnstat/">Network Traffic Stats</a>: recent network traffic usage</li>
</ul>

<?php
pFoot();
