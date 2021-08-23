<?php

use craft\helpers\App as AppHelper;
use craft\helpers\UrlHelper;

return [

    'apiKey' => AppHelper::env('COCONUT_API_KEY'),

    'storages' => [

        'coconutStorage' => [
            'service' => 'coconut',
        ],

        'httpUploadStorage' => [
            'url' => UrlHelper::actionUrl('coconut/jobs/upload'),
        ],

    ],
];
