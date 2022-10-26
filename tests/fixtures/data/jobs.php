<?php

use craft\helpers\Json as JsonHelper;
use craft\helpers\Db as DbHelper;

use yoannisj\coconut\models\Job;

$coconutSampleInput = 'https://s3.amazonaws.com/coconut.co/samples/1min.mp4';
$coconutSampleInputHash = md5($coconutSampleInput);

$now = new DateTime('now');
$dbNow = DbHelper::prepareDateForDb($now);

$yesterday = new DateTime('yesterday');
$dbYesterday = DbHelper::prepareDateForDb($yesterday);

return [

    'coconutSampleJobCompleted' => [
        'id' => 100,
        'coconutId' => 'job-100----cid',
        'status' => Job::STATUS_COMPLETED,
        'progress' => '100%',
        'inputAssetId' => null,
        'inputUrl' => $coconutSampleInput,
        'inputUrlHash' => $coconutSampleInputHash,
        'inputStatus' => Input::STATUS_TRANSFERRED,
        'inputMetadata' => null,
        'inputExpires' => null,
        'outputPathFormat' => null,
        'storageParams' => JsonHelper::encode([ 'service' => 'coconut' ]),
        'createdAt' => $dbYesterday,
        'completedAt' => unll,
        'dateCreated' => $dbYesterday,
        'dateUpdated' => $dbYesterday,
        'uid' => 'job-100--------------------------uid',
    ],

    'coconutSampleJobFailed' => [
        'id' => 101,
        'coconutId' => 'job-101----cid',
        'status' => Job::STATUS_FAILED,
        'progress' => '33%',
        'inputAssetId' => null,
        'inputUrl' => $coconutSampleInput,
        'inputUrlHash' => $coconutSampleInputHash,
        'inputStatus' => Input::STATUS_TRANSFERRED,
        'inputMetadata' => null,
        'inputExpires' => null,
        'outputPathFormat' => null,
        'storageParams' => JsonHelper::encode([ 'service' => 'coconut' ]),
        'createdAt' => $dbYesterday,
        'completedAt' => unll,
        'dateCreated' => $dbYesterday,
        'dateUpdated' => $dbYesterday,
        'uid' => 'job-101--------------------------uid',
    ],

    'coconutSampleJobStarting' => [
        'id' => 102,
        'coconutId' => 'job-102----cid',
        'status' => Job::STATUS_STARTING,
        'progress' => '0%',
        'inputAssetId' => null,
        'inputUrl' => $coconutSampleInput,
        'inputUrlHash' => $coconutSampleInputHash,
        'inputStatus' => Input::STATUS_STARTING,
        'inputMetadata' => null,
        'inputExpires' => null,
        'outputPathFormat' => null,
        'storageParams' => JsonHelper::encode([ 'service' => 'coconut' ]),
        'createdAt' => $dbNow,
        'completedAt' => unll,
        'dateCreated' => $dbNow,
        'dateUpdated' => $dbNow,
        'uid' => 'job-102--------------------------uid',
    ],

];
