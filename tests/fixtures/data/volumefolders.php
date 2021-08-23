<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

return [

    'localUploadsFolder' => [
        'id' => '1010',
        'parentId' => null,
        'volumeId' => '1000',
        'name' => 'Local Uploads',
        'path' => 'local-uploads',
        'uid' => 'volumefolder-1010----------------uid'

    ],

    'localUploadsSamplesFolder' => [
        'id' => '1011',
        'parentId' => '1010',
        'volumeId' => '1000', // localUploads
        'name' => 'Local Uploads Samples',
        'path' => 'samples',
        'uid' => 'volumefolder-1011----------------uid'
    ],
];
