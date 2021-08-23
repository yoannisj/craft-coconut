<?php

use craft\volumes\Local as LocalVolume;
use craft\helpers\Json as JsonHelper;

return [

    'localUploads' => [
        'id' => '1000',
        'name' => 'Local Uploads',
        'handle' => 'localUploads',
        'type' => LocalVolume::class,
        'url' => null,
        'settings' => JsonHelper::encode([
            'path' => Craft::getAlias('@webroot/uploads'),
            'url' => Craft::getAlias('@web/uploads'),
        ]),
        'hasUrls' => true,
        'sortOrder' => 1,
        'uid' => 'volume-1000----------------------uid',
    ],

];
