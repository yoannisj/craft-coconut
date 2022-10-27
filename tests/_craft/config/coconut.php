<?php

use craft\helpers\App as AppHelper;
use craft\helpers\UrlHelper;

$tunnelUrl = AppHelper::env('TUNNEL_URL') ?: null;

return [

    'apiKey' => AppHelper::env('COCONUT_API_KEY'),

    'publicBaseUrl' => $tunnelUrl,

    'storages' => [

        'coconutStorage' => [
            'service' => 'coconut',
        ],

        'httpUploadStorage' => [
            'url' => UrlHelper::actionUrl('coconut/jobs/upload', null, null, true),
        ],

    ],

    'defaultStorage' => null,

];
