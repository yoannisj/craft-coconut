<?php

use craft\helpers\Json as JsonHelper;
use craft\helpers\Db as DbHelper;

use yoannisj\coconut\models\Job;

$now = DbHelper::prepareDateForDb('now');

return [

    'externalInputJob' => [
        'id' => 100,
        'coconutId' => 'job-100------cid',
        'inputUrl' => 'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerEscapes.mp4',
        'storageParams' => JsonHelper::encode([ 'service' => 'coconut' ]),
        'status' => Job::STATUS_STARTING,
        'progress' => '0%',
        'createdAt' => $now,
        'uid' => 'job-100--------------------------uid',
    ],

];
