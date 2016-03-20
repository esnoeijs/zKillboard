<?php

global $baseDir, $mdb;

$systemID = (int) $system;
$relatedTime = (int) $time;
const MAX_GROUPS = 5;

$json_options = json_decode($options, true);
for ($i=1;$i<=MAX_GROUPS;$i++) {
	if (!isset($json_options[$i])) {
		$json_options[$i] = array();
	}
}

$redirect = false;
if (isset($_GET['left'])) {
	$entity = $_GET['left'];
	$pos    = $_GET['pos'];

	// Don't assign
	if ($pos >= 0) {
		if (in_array($entity, $json_options[$pos])) {
			unset($json_options[$pos][$entity]);
		}

		// Add selected entity to the group before it's current one.
		$json_options[--$pos][$entity] = $entity;

		$redirect = true;
	}
}
if (isset($_GET['right'])) {
	$entity = $_GET['right'];
	$pos    = $_GET['pos'];

	// Don't shift entities up beyond group 5.
	if ($pos < MAX_GROUPS) {
		if (in_array($entity, $json_options[$pos])) {
			unset($json_options[$pos][$entity]);
		}

		// Add selected entity to the group after it's current one.
		$json_options[++$pos][$entity] = $entity;
		$redirect = true;
	}
}

if ($redirect) {
	$json = urlencode(json_encode($json_options));
	$url = "/related/$systemID/$relatedTime/o/$json/";
	$app->redirect($url, 302);
	die();
}

$systemInfo = $mdb->findDoc('information', ['cacheTime' => 3600, 'type' => 'solarSystemID', 'id' => $systemID]);
$systemName = $systemInfo['name'];
$regionInfo = $mdb->findDoc('information', ['cacheTime' => 3600, 'type' => 'regionID', 'id' => $systemInfo['regionID']]);
$regionName = $regionInfo['name'];
$unixTime = strtotime($relatedTime);
$time = date('Y-m-d H:i', $unixTime);

$exHours = 1;
if (((int) $exHours) < 1 || ((int) $exHours > 12)) {
	$exHours = 1;
}

$key = md5("br:$systemID:$relatedTime:$exHours:".json_encode($json_options) . (isset($battleID) ? ":$battleID" : ""));
$mc = RedisCache::get($key);
if ($mc == null) {
	$parameters = array('solarSystemID' => $systemID, 'relatedTime' => $relatedTime, 'exHours' => $exHours, 'nolimit' => true);
	$kills = Kills::getKills($parameters);
	$summary = Related::buildSummary($kills, $json_options);
	$mc = array('summary' => $summary, 'systemName' => $systemName, 'regionName' => $regionName, 'time' => $time, 'exHours' => $exHours, 'solarSystemID' => $systemID, 'relatedTime' => $relatedTime, 'options' => json_encode($json_options));

	if (isset($battleID) && $battleID > 0) {

		foreach ($summary as $teamName => $team) {
			$totals = $team['totals'];
			unset($totals['groupIDs']);
			$mdb->set("battles", ['battleID' => $battleID], [$teamName => $team]);
		}
	}

	RedisCache::set($key, $mc, 300);
}
$summary = $mc['summary'];
$mc['cols'] = ceil(12/count($summary));
$app->render('related.html', $mc);
