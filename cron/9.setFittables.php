<?php

require_once '../init.php';

$time = date('Hi');
if ($time != '1315') {
    exit();
}

$json = CrestTools::getJSON('https://public-crest.eveonline.com/inventory/categories/7/');

foreach ($json['groups'] as $group) {
    $href = $group['href'];
    $types = CrestTools::getJSON($href);
    if ($types != null && $types['types'] != null) {
        foreach ($types['types'] as $type) {
            $typeID = getTypeID($type['href']);
            $mdb->set('information', ['type' => 'typeID', 'id' => $typeID], ['fittable' => true]);
        }
    }
    sleep(1);
}

function getTypeID($href)
{
    $ex = explode('/', $href);

    return (int) $ex[4];
}
