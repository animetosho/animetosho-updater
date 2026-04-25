<?php
if(!defined('THIS_SCRIPT')) die;

define('ROOT_DIR', dirname(dirname(__FILE__)).'/');

require ROOT_DIR.'init.php';
loadDb();

function pHead($title) {
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
<title>AnimeTosho Administration - <?php echo $title; ?></title>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<style type="text/css">
body {font-family: Tahoma, sans serif;}
th {text-align: left; white-space: nowrap;}
table thead tr th {vertical-align: middle;}
table tr th {vertical-align: top;}
</style>
</head>
<body>
<?php
}

function pFoot() {
?>
</body>
</html>
<?php
}

function redirect($url, $msg='You may be redirected, but if not, click below') {
	header('Pragma: no-cache');
	header('Cache-Control: no-cache, must-revalidate');
	pHead('Redirecting...');
	echo $msg, '<br /><a href="'.htmlspecialchars($url).'">Go Back</a>';
	pFoot();
	exit;
}
function err($msg) {
	@header('Content-Type: text/plain; charset=UTF-8');
	header('Pragma: no-cache');
	header('Cache-Control: no-cache, must-revalidate');
	die($msg);
}
