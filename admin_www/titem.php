<?php
define('THIS_SCRIPT', 'mod_www/'.basename(__FILE__));
define('DEFAULT_ERROR_HANDLER', 1);
require './_base.php';

require ROOT_DIR.'includes/miniwebcore.php';

// read ID
$id = (string)@$_REQUEST['id'];

// handle URL format
if($p = strpos($id, '#'))
	$id = substr($id, 0, $p);
if(preg_match('~^https?\://.+[\./]([a-z]?\d+)$~', $id, $m))
	$id = $m[1];


$idtype = 't';
if(ctype_alpha(@$id[0])) {
	$idtype = $id[0];
	$id = substr($id, 1);
}
$id = (int)$id;
if(!$id)
	err('Invalid ID');


$where = '1=0'; // this should never occur, but, meh
switch($idtype) {
	case 'n':
		$where = '`toto`.`nyaa_id`='.$id.' AND `toto`.`nyaa_subdom`=""';
		$disp_id = 'n'.$id;
	break;
	case 's':
		$where = '`toto`.`nyaa_id`='.$id.' AND `toto`.`nyaa_subdom`="sukebei"';
		$disp_id = 's'.$id;
	break;
	case 'd':
		$where = '`toto`.`anidex_id`='.$id;
		$disp_id = 'd'.$id;
	break;
	case 'k':
		$where = '`toto`.`nekobt_id`='.$id;
		$disp_id = 'k'.$id;
	break;
	case 'a':
		$where = '`toto`.`id`='.$id;
		$disp_id = 'a'.$id;
	break;
	case 't':
	default:
		$idtype = 't';
		$where = '`toto`.`tosho_id`='.$id;
		$disp_id = $id;
}
unset($id); // to prevent mistakes

// fetch toto
$toto = $db->selectGetArray('toto', $where);
if(empty($toto)) err('Could not find specified entry');
$url = AT::viewUrl($toto);


$article_types = array('', 'none', 'blogentry', 'feedentry', 'forumthread', 'torrentlisting', 'other',
	/*'blog', 'fileshare', 'imghost', 'unrelated', 'forum'*/);



if($_SERVER['REQUEST_METHOD'] == 'POST') {
	// TODO: basic csrf check
	$redir_url = '?id='.$disp_id;
	switch(@$_GET['do']) {
		case 'delete': {
			if($toto['deleted'])
				err('This torrent has already been deleted');
			// we won't care about potential duplicate handling, just force mark deleted
			$db->update('toto', array('deleted' => -1), 'id='.$toto['id']);
			redirect('Torrent deleted');
		} break;
		case 'push': {
			
			if($toto['ulcomplete'] >= 0)
				err('This torrent is already being processed');
			if($toto['deleted'])
				err('This torrent has already been deleted');
			// these should never be true, but pedantically check them
			if($db->selectGetField('torrents', 'toto_id', 'toto_id='.$toto['id'])
			|| $db->selectGetField('ulqueue', 'toto_id', 'toto_id='.$toto['id'])
			|| $db->selectGetAll('files', 'id', 'toto_id='.$toto['id'])
			|| $db->selectGetField('fiqueue', 'COUNT(*)', 'fid IN (SELECT id FROM toto_repl.toto_files WHERE toto_id='.$toto['id'].')')
			|| $db->selectGetField('finfo', 'COUNT(*)', 'fid IN (SELECT id FROM toto_repl.toto_files WHERE toto_id='.$toto['id'].')')
			)
				err('Invalid internal state detected!');
			
			@ini_set('memory_limit', '384M');
			
			global $transmission; // unnecessary at the moment
			$transmission = get_transmission_rpc();
			if(!$transmission) {
				err('Failed to connect with torrent client');
			}
			
			require ROOT_DIR.'includes/releasesrc.php';
			$update = array();
			
			$btih = bin2hex($toto['btih']);
			$tfile = TOTO_STORAGE_PATH.'torrents/'.substr($btih, 0, 3).'/'.substr($btih, 3).'.torrent';
			if(!$toto['stored_torrent'] || !file_exists($tfile) || !($tinfo = releasesrc_get_torrent('file', $tfile, $error))) {
				if(!($tinfo = releasesrc_get_torrent('link', $toto['link'], $error, false))) {
					if(!empty($toto['magnet'])) {
						if(!($tinfo = releasesrc_get_torrent('magnet', $toto['magnet'], $error2, false))) {
							err('Torrent fetch failed: '.$error.'. Fetching from magnet also failed: '.$error2);
						}
					} else
						err('Torrent fetch failed: '.$error);
				}
			}
			// TODO: allow changing priority?
			if(!releasesrc_add_torrent($tinfo['torrentdata'], $toto['id'], 'toto_', $toto['totalsize'], ['bandwidthPriority'=>-1]))
				err('Failed to add torrent');
			
			$update = array(
				'ulcomplete' => 0,
				'added_date' => time(), // added_date is already set, but we use added_date to indicate when we started
			);
			if(isset($tinfo['btih']))
				$update['btih'] = $tinfo['btih'];
			if(isset($tinfo['btih_sha256']))
				$update['btih_sha256'] = $tinfo['btih_sha256'];
			if(@$tinfo['magnetlink'])
				$update['magnet'] = $tinfo['magnetlink'];
			releasesrc_save_track_torrent($update, $toto['id'], $tinfo);
			$db->update('toto', $update, 'id='.$toto['id']);
			$db->delete('adb_resolve_queue', 'toto_id='.$toto['id']);
			
			redirect($redir_url, 'Torrent pushed successfully');
			
		} break;
		case 'tag': {
			err('Not implemented');
			// check what has been supplied
			// TODO: 
			// if marked resolve, remove from resolvequeue?
			// - problem is that srcinfo won't get resolved
			// - also need to check if resolveapproved implies cron-adb will not attempt to resolve it
		} break;
		case 'srcinfo': {
			err('Not implemented');
			// basic validation
			if(isset($_POST['srcurltype']) || !in_array($_POST['srcurltype'], $article_types))
				err('Invalid article type');
			if(isset($_POST['srcurl']) && $_POST['srcurl'] !== '' && !preg_match('~^https?\://~', $_POST['srcurl']))
				err('Invalid article URL');
			
			// simply update info
			$update = array();
			foreach(array('srcurl', 'srctitle', 'srcurltype') as $i) {
				if(isset($_POST[$i]) && $_POST[$i] != $toto[$i])
					$update[$i] = (string)$_POST[$i];
			}
			if(empty($update)) err('Nothing to update');
			$db->update('toto', $update, 'id='.$toto['id']);
			
		} break;
		case 'refresh': {
			err('Not implemented');
			
		} break;
		// TODO: change priority
		// mark link dead?
		// add dl link?
	}
}


$ulc_map = array(
	-3 => 'Unknown error',
	-2 => 'Error',
	-1 => 'Skipped',
	 0 => 'Processing...',
	 1 => 'Processed',
	 2 => 'Processed (partial)',
);


function fmtDate($d) {
	return date('r', $d);
}
function btn($action, $text) {
	return '<form class="inline" method="post" action="?do='.$action.'" onsubmit="return confirm(\'Are you sure you want to do this? This could result in your couch exploding, your cat flying away, World War IV (WW3 is too cliche) or Madoka ridding the world of witches, sooooo... are you still sure?\');"><input type="hidden" name="id" value="'.htmlspecialchars($GLOBALS['disp_id']).'" /><script type="text/javascript">document.write(\'<input type="submit" value="'.$text.'" />\');</script></form>';
}

pHead('Item Details');

?>
<style type="text/css">
form.inline{display:inline;}
input.id{width:4em;}
input.ids{width:10em;}
input.article{width:40em;}
td.submit{padding-left:2em;}
</style>
<h2><a href="<?=htmlspecialchars($url)?>"><?=htmlspecialchars($toto['name'])?></a></h2>
<table>
<tr><th>Internal ID</th><td><?=$toto['id']?></td></tr>
<tr><th>Status</th><td><?=$ulc_map[$toto['ulcomplete']]?>
	<?php if($toto['deleted'])
		echo ' + Deleted ', $toto['deleted'] == -1 ? '(manually)' : '';
	else {
		if($toto['ulcomplete'] <0) echo btn('push', 'Push'), ' ';
		echo btn('delete', 'Delete this Torrent');
	} ?>
</td></tr>
<tr><th>Added</th><td><?=fmtDate($toto['added_date'])?></td></tr>
<?php if($toto['completed_date']) { ?> <tr><th>Completed</th><td><?=fmtDate($toto['completed_date'])?></td></tr><?php } ?>
<!-- <tr><th>Last Update</th><td><?=fmtDate($toto['lastchecked']) ?: '-'?> <?=btn('refresh', 'Refresh Details from Source')?></td></tr> -->
<!-- <tr><th>Auto-tagging State</th><td></td></tr> -->
</table>

<?php /*

<h3>Tagging (AniDB)</h3>
<p>For file/episode/anime ID, the less specific details are resolved from the more specific. For example, if you have a file ID, please just enter that in, as the episode and anime will automatically be filled in from that. For group IDs, separate them with commas.</p>
<form action="?do=tag" method="post">
<table>
<tr><th>AniDB File ID</th><td>http://anidb.net/perl-bin/animedb.pl?show=file&amp;fid=<input class="id" type="text" name="fid" value="<?=$toto['fid']?>" /></td></tr>
<tr><th>AniDB Episode ID</th><td>http://anidb.net/perl-bin/animedb.pl?show=ep&amp;eid=<input class="id" type="text" name="eid" value="<?=$toto['eid']?>" /></td></tr>
<tr><th>AniDB Anime ID</th><td>http://anidb.net/perl-bin/animedb.pl?show=anime&amp;aid=<input class="id" type="text" name="aid" value="<?=$toto['aid']?>" /></td></tr>
<tr><th>AniDB Group IDs</th><td>http://anidb.net/perl-bin/animedb.pl?show=group&amp;gid=<input class="ids" type="text" name="gids" value="<?=$toto['gids']?>" /></td></tr>
<tr><th>Verification</th><td><select name="resolveapproved">
	<option value="0"<?=($toto['resolveapproved']==0)?' selected="selected"':''?>>Not verified</option>
	<?php if($toto['resolveapproved']==1) { ?><option value="1" selected="selected">Verified (automatic)</option><?php } ?>
	<option value="2"<?=($toto['resolveapproved']==2)?' selected="selected"':''?>>Verified (manual)</option>
</select></td></tr>
<tr><td colspan="2" class="submit"><input type="submit" value="Update" /></td></tr>
</table>
</form>


<h3>Source Article</h3>
<form action="?do=srcinfo" method="post">
<table>
<!-- TODO: we need srcurl approval! -->
<tr><th>URL</th><td><input class="article" type="text" name="srcurl" value="<?=htmlspecialchars($toto['srcurl'])?>" /></td></tr>
<tr><th>Title</th><td><input class="article" type="text" name="srctitle" value="<?=htmlspecialchars($toto['srctitle'])?>" /></td></tr>
<tr><th>Type</th><td><select name="srcurltype">
	<?php $set = false;
	foreach($article_types as $t) {
		$chk = '';
		if($toto['srcurltype'] == $t) {
			$set = true;
			$chk = ' selected="selected"';
		}
		?><option value="<?=$t?>"<?=$chk?>><?=$t?></option><?php
	}
	if(!$set) {
		$chk = htmlspecialchars($toto['srcurltype']);
		echo '<option value="'.$chk.'" selected="selected">'.$chk.'</option>';
	}
	?>
</select></td></tr>
<?php if(isset($toto['srcverified'])) { ?><tr><th>Verified</th><td><input type="checkbox" name="srcverified" value="1" <?=$toto['srcverified']?'checked="checked"':''?> /></td></tr><?php } ?>
<tr><td colspan="2" class="submit"><input type="submit" value="Update" /></td></tr>
</table>
</form>
<!-- TODO: isdupe? -->

<?php

*/

pFoot();
