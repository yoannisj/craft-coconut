<?php

$testsDir = dirname(__DIR__, 2);

return [
    'localJpgImageAsset' => [
        'volumeId' => '1000', // localUploads
        'folderId' => '1011', // localUploadsSamplesFolder
        'filename' => 'samples/image.jpg',
        'tempFilePath' => $testsDir.'/_craft/storage/runtime/temp/samples/image.jpg',
    ],

    'localMp4VideoAsset' => [
        'volumeId' => '1000', // localUploads
        'folderId' => '1011', // localUploadsSamplesFolder
        'filename' => 'samples/video-720p.mp4',
        'tempFilePath' => $testsDir.'/_craft/storage/runtime/temp/samples/video-720p.mp4',
    ],

    'localWebmVideoAsset' => [
        'volumeId' => '1000', // localUploads
        'folderId' => '1011', // localUploadsSamplesFolder
        'filename' => 'samples/video-720p.webm',
        'tempFilePath' => $testsDir.'/_craft/storage/runtime/temp/samples/video-720p.webm',
    ],

    'localAviVideoAsset' => [
        'volumeId' => '1000', // localUploads
        'folderId' => '1011', // localUploadsSamplesFolder
        'filename' => 'samples/video-720p.avi',
        'tempFilePath' => $testsDir.'/_craft/storage/runtime/temp/samples/video-720p.avi',
    ],

    'localFlvVideoAsset' => [
        'volumeId' => '1000', // localUploads
        'folderId' => '1011', // localUploadsSamplesFolder
        'filename' => 'samples/video-720p.flv',
        'tempFilePath' => $testsDir.'/_craft/storage/runtime/temp/samples/video-720p.flv',
    ],

    'localMovVideoAsset' => [
        'volumeId' => '1000', // localUploads
        'folderId' => '1011', // localUploadsSamplesFolder
        'filename' => 'samples/video-720p.mov',
        'tempFilePath' => $testsDir.'/_craft/storage/runtime/temp/samples/video-720p.mov',
    ],

    'localOggVideoAsset' => [
        'volumeId' => '1000', // localUploads
        'folderId' => '1011', // localUploadsSamplesFolder
        'filename' => 'samples/video-360p.ogg',
        'tempFilePath' => $testsDir.'/_craft/storage/runtime/temp/samples/video-360p.ogg',
    ],

    'local3gpVideoAsset' => [
        'volumeId' => '1000', // localUploads
        'folderId' => '1011', // localUploadsSamplesFolder
        'filename' => 'samples/video-240p.3gp',
        'tempFilePath' => $testsDir.'/_craft/storage/runtime/temp/samples/video-240p.3gp',
    ],
];
