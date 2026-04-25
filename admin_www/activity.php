<?php
define('THIS_SCRIPT', 'mod_www/'.basename(__FILE__));
define('DEFAULT_ERROR_HANDLER', 1);
require './_base.php';

require ROOT_DIR.'includes/miniwebcore.php';

$time=time();

function fmtPriority($p) {
	$p = (int)$p;
	switch($p) {
		case 0: return 'Normal';
		case 1: return 'Above Normal';
		case 2: return 'High';
		case -1: return 'Below Normal';
		case -2: return 'Low';
	}
	if($p>0) return 'Very High';
	if($p<0) return 'Very Low';
	return 'Normal'; // pedantic
}

try {
	$transmission = get_transmission_rpc();
	if(!$transmission) die('Failed to connect to transmission.');
	
	$trans = $transmission->get(array(), array('id', 'name', 'status', 'addedDate', 'errorString', 'eta', 'hashString', 'peersGettingFromUs', 'peersSendingToUs', 'peersConnected', 'percentDone', 'rateDownload', 'rateUpload', 'sizeWhenDone', 'bandwidthPriority'));
} catch(TransmissionRPCException $e) {
	die('Failed to connect to transmission.');
}
$torrents = array();
if(!empty($trans->arguments->torrents)) {
	foreach($trans->arguments->torrents as $tor) {
		$torrenthash = pack('H*', $tor->hashString);
		$torrents[$torrenthash] = (array)$tor;
	}
	foreach(
		$db->selectGetAll('torrents', 'hashString', 'hashString IN ('.implode(',', array_map(array(&$db, 'escape'), array_keys($torrents))).')', 'torrents.id,torrents.hashString,torrents.status,toto_id,toto.tosho_id,toto.nyaa_id,toto.nyaa_subdom,toto.anidex_id,toto.nekobt_id,toto.name,toto.deleted', array('joins' => array(
			array('inner', 'toto', 'toto_id', 'id')
		)))
	as $th => $tor) {
		if(!@$torrents[$th]) continue; // unexpected error
		$t =& $torrents[$th];
		$t['processed'] = $tor['status'];
		$t['toto_id'] = $tor['toto_id'];
		$t['tosho_id'] = $tor['tosho_id'];
		$t['nyaa_id'] = $tor['nyaa_id'];
		$t['nyaa_subdom'] = $tor['nyaa_subdom'];
		$t['anidex_id'] = $tor['anidex_id'];
		$t['nekobt_id'] = $tor['nekobt_id'];
		$t['name'] = $tor['name'];
		$t['deleted'] = $tor['deleted'];
	} unset($t);
}
$statusmap = array(
	'' => 'paused',
	'0' => 'stopped',
	'1' => 'checking',
	'2' => 'checking',
	'3' => 'downloading',
	'4' => 'downloading',
	'5' => 'seeding',
	'6' => 'seeding',
);
$processedmap = array('' => 'UNKNOWN', '0' => 'No', '1' => 'Processing', '2' => 'Archiving', '3' => 'Complete');



$uls = $db->selectGetAll('ulqueue_status', 'ulq_id', '', 'ulqueue_status.*,ulqueue.fid,ulqueue.filename', array('order' => 'ulq_id ASC', 'joins' => array(
	array('inner', 'ulqueue', 'ulq_id', 'id'),
)));



$ulq = $db->selectGetAll('ulqueue', 'id', '', 'ulqueue.id,ulqueue.status,ulqueue.retry_sites,ulqueue.fid,ulqueue.dateline,ulqueue.priority,ulqueue.last_processor,files.filename,files.filesize', array('order' => 'id ASC', 'joins' => array(
	array('inner', 'files', 'fid', 'id')
)));
$qbinit = array('new_sz' => 0, 'new_cnt' => 0, 'cur_sz' => 0, 'cur_cnt' => 0, 'def_sz' => 0, 'def_cnt' => 0);
$qsize = array('Very High' => null, 'High' => null, 'Above Normal' => null, 'Normal' => null, 'Below Normal' => null, 'Low' => null, 'Very Low' => null, 'Total' => $qbinit);
$service_count = count(include(ROOT_DIR.'includes/uploadhosts.php'));
if(!empty($ulq)) foreach($ulq as &$ul) {
	$ul['url'] = AT::buildUrl('file', AT::seoPageSubaction($ul['fid'], $ul['filename']));
	$size = $ul['filesize'] / 1048576;
	$is_new = false;
	if(!$ul['retry_sites']) {
		$ul['retry_sites'] = '<em>(all)</em>';
		// make a rough guess
		$ulsize = $size*$service_count;
		$is_new = true;
	} else {
		$s = array();
		foreach(explode(',', $ul['retry_sites']) as $svc) {
			@list($service, ) = explode(':', $svc, 2);
			$s[] = $service;
		}
		$ulsize = $size*count($s);
		$ul['retry_sites'] = implode(', ', $s);
	}
	$qsize_bucket =& $qsize[fmtPriority($ul['priority'])];
	if(!isset($qsize_bucket)) $qsize_bucket = $qbinit;
	$curdef = ($ul['dateline']>$time && $ul['status'] != 1 ? 'def':'cur');
	$qsize_bucket[$curdef.'_sz'] += $ulsize;
	++$qsize_bucket[$curdef.'_cnt'];
	$qsize['Total'][$curdef.'_sz'] += $ulsize;
	++$qsize['Total'][$curdef.'_cnt'];
	if($is_new) {
		$qsize_bucket['new_sz'] += $ulsize/$service_count;
		++$qsize_bucket['new_cnt'];
		$qsize['Total']['new_sz'] += $ulsize/$service_count;
		++$qsize['Total']['new_cnt'];
	}
} unset($ul);
$qsize = array_filter($qsize);


$news_statusmap = array(
	'0' => 'pending',
	'2' => 'wait_archive',
	'1' => 'par2',
	'3' => 'ready',
	'4' => 'uploading',
);

$newsq = $db->selectGetAll('newsqueue', 'id', '', 'newsqueue.id,newsqueue.status,newsqueue.retries,newsqueue.dateline,newsqueue.priority,newsqueue.is_partial,toto.name,toto.deleted,toto.totalsize,toto.tosho_id,toto.nyaa_id,toto.nyaa_subdom,toto.anidex_id,toto.nekobt_id', array('order' => 'newsqueue.id ASC', 'joins' => array(
	array('inner', 'toto', 'id')
)));


header('Pragma: no-cache');
header('Cache-Control: no-cache, must-revalidate');
pHead('Server Activity');

?>
<details open="">
<summary><h2>Unprocessed Torrents</h2></summary>
<style type="text/css">
<!--
.tor_paused, .ulq_deferred, .nq_wait_archive{background: #D0D0D0;}
.tor_stopped, .ulq_stuck, .nq_pending{background: #FFC0C0;}
.tor_checking, .ulq_retry_queued, .nq_par2{background: #C0C0FF;}
.tor_downloading, .ulq_pending, .nq_ready{background: #FFFFB0;}
.tor_seeding, .ulq_processing, .nq_uploading{background: #C0FFC0;}
.legend span{margin-right: 0.1em;}
td.numfield{text-align: right;}
.leftline{border-left: 1px solid #606060;}
.invalid{background: #ffffff;}
.deleted{color: #B04040; text-decoration: none;}
.tor_count{width:3em; text-align: right; display: inline-block;}

h2{display: inline-block;}
-->
</style>
<div class="legend">Status Legend:<?php
foreach(array_keys(array_flip($statusmap)) as $s)
	echo ' <span class="tor_'.$s.'">'.$s.'</span>';
?> <span class="deleted">marked_as_deleted</span></div>
<table>
<thead><tr>
	<th>Name</th>
	<th>Size (MB)</th>
	<th>Done</th>
	<th title="Download speed">Download</th>
	<th title="Upload speed">Upload</th>
	<th title="aka (uploading) to/(downloading) from/total">Peers to/from/total</th>
	<th title="Bandwidth allocation">Priority</th>
	<th title="How long this torrent has been in the queue">Age (hr)</th>
</tr></thead>
<tbody>
<?php
foreach($torrents as $tor) {
	if(!@$tor['toto_id']) continue;
	if($tor['processed'] >= 2) continue; // skip done
	if(isset($tor['rateDownload']))
		$dl = round($tor['rateDownload']/1024,1).' KB/s';
	else
		$dl = '-';
	if(isset($tor['rateUpload']))
		$ul = round($tor['rateUpload']/1024,1).' KB/s';
	else
		$ul = '-';
	$url = AT::viewUrl($tor);
	?>
	<tr class="tor_<?=$statusmap[(string)@$tor['status']]?>">
		<td>
			<?=(@$tor['errorString'] ? '<strong title="'.htmlspecialchars($tor['errorString']).'" style="float:right;">[!]</strong>': '')?>
			<a href="<?=htmlspecialchars($url)?>"<?=($tor['deleted']?' class="deleted"':'')?>><?=htmlspecialchars($tor['name'])?></a>
		</td>
		<td class="numfield"><?=round($tor['sizeWhenDone'] / 1024/1024, 2)?></td>
		<td class="numfield"><?=(@$tor['percentDone']*100?:'0').'%'?></td>
		<td class="numfield"><?=$dl?></td>
		<td class="numfield"><?=$ul?></td>
		<td class="numfield"><?=(@$tor['peersGettingFromUs']?:'0').'/'.(@$tor['peersSendingToUs']?:'0').'/'.(@$tor['peersConnected']?:'0')?></td>
		<td><?=fmtPriority(@$tor['bandwidthPriority'] *2)?></td>
		<td class="numfield"><?=number_format(($time-$tor['addedDate'])/3600)?></td>
		<!--<td><?=$processedmap[(string)$tor['processed']]?></td>-->
	</tr>
	<?php
}
?></tbody>
</table>
</details>

<details open="">
<summary><h2>Upload Queue</h2></summary>
<?php
$show_deferred = (bool)$qsize['Total']['def_cnt'];
if(count($qsize) > 1) {
	if(count($qsize) == 2) unset($qsize['Total']);
?>
<h3>Queue length estimate</h3>
<table align="center" border="1" cellspacing="0">
<thead><tr>
	<th>Priority</th>
	<th title="Estimated amount of data queued to be uploaded">Queued</th>
	<th title="Time to upload current queue assuming a 24MB/s transfer rate">Queued ETA</th>
	<?php if($show_deferred) { ?>
	<th title="Amount of data to be uploaded that has been deferred" class="ulq_deferred">Deferred</th>
	<th title="Queued + Deferred" class="leftline">Total</th>
	<th title="Queued + Deferred">Total ETA</th>
	<?php } ?>
</tr></thead>
<tbody>
<?php
$ul_speed = 34;
foreach($qsize as $prio => $q) {
	if($prio == 'Total') $prio = '<b>Total</b>';
	echo '<tr><td>', $prio, '</td>',
	     '<td class="numfield" title="', $service_count, '*', round($q['new_sz'], 1), ' MB (', $q['new_cnt'], ') not started">', round($q['cur_sz'], 1), ' MB <span class="tor_count">(', $q['cur_cnt'], ')</span></td>',
	     '<td class="numfield">', round($q['cur_sz']/($ul_speed*60), 1), ' min</td>';
	if($show_deferred) {
		echo
	     '<td class="numfield ulq_deferred">', round($q['def_sz'], 1), ' MB <span class="tor_count">(', $q['def_cnt'], ')</span></td>',
	     '<td class="numfield leftline">', round($q['cur_sz']+$q['def_sz'], 1), ' MB <span class="tor_count">(', $q['cur_cnt']+$q['def_cnt'], ')</span></td>',
	     '<td class="numfield">', round(($q['cur_sz']+$q['def_sz'])/($ul_speed*60), 1), ' min</td>';
	}
	echo '</tr>';
}
?>
</tbody></table>
<p style="font-size: smaller; text-align: center; margin-top: -0.1em;">ETAs assume <?=$ul_speed?>MB/s upload</p>
<h3>Queue</h3>
<?php } ?>
<div class="legend">Status Legend:<?php
foreach(array('pending','processing','retry_queued','deferred','stuck') as $s)
	echo ' <span class="ulq_'.$s.'">'.$s.'</span>';
?></div>
<table>
<thead><tr>
	<th>File</th>
	<th>Size (MB)</th>
	<th>Priority</th>
	<th title="Hosts that still need to be uploaded to; if processing, the processing host is in bold">Hosts</th>
	<th title="Internal process identifier" class="leftline">ProcID</th>
	<th title="Time uploading this file to the host denoted in bold">Minutes</th>
	<th title="Current upload speed">KB/s</th>
	<th title="Percentage uploaded">Done</th>
</tr></thead>
<tbody>
<?php
foreach($uls?:array() as $id=>$ul) {
	$uli = $ulq[$id];
	unset($ulq[$id]);
	$age = $time - $ul['started'];
	?>
	<tr class="ulq_<?=($age>172800 ? 'stuck' : 'processing')?>">
		<td><a href="<?=htmlspecialchars($uli['url'])?>"><?=htmlspecialchars($ul['filename'])?></a></td>
		<td class="numfield"><?=round($uli['filesize']/1048576,1)?></td>
		<td><?=fmtPriority($uli['priority'])?></td>
		<td><?=preg_replace('~(^|\W)('.$ul['site'].')($|\W)~', '$1<b>$2</b>$3', $uli['retry_sites'])?></td>
		<td class="numfield leftline"><?=$ul['proc']?></td>
		<td class="numfield"><?=round($age/60,1)?></td>
		<td class="numfield"><?=($ul['speed'] == -1 ? '-' : $ul['speed'])?></td>
		<td class="numfield"><?=$uli['filesize'] ? round($ul['uploaded']/$uli['filesize']*100,1) : 'inf'?>%</td>
	</tr>
	<?php
}
foreach($ulq?:array() as $ul) {
	if($ul['status'] == 1)
		$status = 'stuck';
	elseif($ul['dateline']>$time)
		$status = 'deferred';
	elseif($ul['last_processor'])
		$status = 'retry_queued';
	else
		$status = 'pending';
	?>
	<tr class="ulq_<?=$status?>">
		<td><a href="<?=htmlspecialchars($ul['url'])?>"><?=htmlspecialchars($ul['filename'])?></a></td>
		<td class="numfield"><?=round($ul['filesize']/1048576,1)?></td>
		<td><?=fmtPriority($ul['priority'])?></td>
		<td><?=($ul['retry_sites'])?></td>
		<td colspan="4" class="invalid"></td>
	</tr>
	<?php
}
?>
</tbody>
</table>
</details>

<details open="">
<summary><h2>Usenet Queue</h2></summary>
<div class="legend">Status Legend:<?php
foreach(array_keys(array_flip($news_statusmap)) as $s)
	echo ' <span class="nq_'.$s.'">'.$s.'</span>';
?>
<table>
<thead><tr>
	<th>Name</th>
	<th>Size (MB)</th>
	<th>Priority</th>
	<th>Queue Age (min)</th>
	<th>Retry Count</th>
</tr></thead>
<tbody>
<?php
foreach($newsq as $ul) {
	$url = AT::viewUrl($ul);
	$status = $news_statusmap[$ul['status']];
	?>
	<tr class="nq_<?=$status?>">
		<td><a href="<?=htmlspecialchars($url)?>"<?=($ul['deleted']?' class="deleted"':'')?>><?=htmlspecialchars($ul['name'])?></a><?=($ul['is_partial']?' (partial)':'')?></td>
		<td class="numfield"><?=round($ul['totalsize']/1048576,1)?></td>
		<td><?=fmtPriority($ul['priority'])?></td>
		<td class="numfield"><?=number_format(($time-$ul['dateline'])/60)?></td>
		<td class="numfield"><?=($ul['retries'])?></td>
	</tr>
	<?php
}
?>
</tbody>
</table>

</details>
<?php

pFoot();
