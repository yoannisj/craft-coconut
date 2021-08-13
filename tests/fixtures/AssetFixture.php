<?php

/**
 * Coconut plugin for Craft
 *
 * @author Yoannis Jamar
 * @copyright Copyright (c) 2020 Yoannis Jamar
 * @link https://github.com/yoannisj/
 * @package craft-coconut
 *
 */

namespace yoannisj\coconuttests\fixtures;

use Craft;
use craft\test\fixtures\elements\AssetFixture as BaseAssetFixture;

/**
 *
 */

class AssetFixture extends BaseAssetFixture
{
    // =Properties
    // ========================================================================

    /**
     * @inheritdoc
     */

    public $depends = [
        \yoannisj\coconuttests\fixtures\VolumeFixture::class,
    ];

    // =Public Methods
    // ========================================================================

    // =Protected Methods
    // ========================================================================

    /**
     * @inheritdoc
     */

    protected function getData()
    {
        $testsDir = dirname(__DIR__);

        return [
            'jpgImageAsset' => [
                'volumeId' => $this->volumeIds['images'],
                'folderId' => $this->folderIds['samples'],
                'filename' => 'samples/image.jpg',
                'tempFilePath' => $testsDir.'/_craft/storage/runtime/temp/samples/image.jpg',
            ],

            'mp4VideoAsset' => [
                'volumeId' => $this->volumeIds['videos'],
                'folderId' => $this->folderIds['samples'],
                'filename' => 'samples/video.mp4',
                'tempFilePath' => $testsDir.'/_craft/storage/runtime/temp/samples/video-720p.mp4',
            ],

            'webmVideoAsset' => [
                'volumeId' => $this->volumeIds['videos'],
                'folderId' => $this->folderIds['samples'],
                'filename' => 'samples/video.webm',
                'tempFilePath' => $testsDir.'/_craft/storage/runtime/temp/samples/video-720p.webm',
            ],

            'aviVideoAsset' => [
                'volumeId' => $this->volumeIds['videos'],
                'folderId' => $this->folderIds['samples'],
                'filename' => 'samples/video.avi',
                'tempFilePath' => $testsDir.'/_craft/storage/runtime/temp/samples/video-720p.avi',
            ],

            'flvVideoAsset' => [
                'volumeId' => $this->volumeIds['videos'],
                'folderId' => $this->folderIds['samples'],
                'filename' => 'samples/video.flv',
                'tempFilePath' => $testsDir.'/_craft/storage/runtime/temp/samples/video-720p.flv',
            ],

            'movVideoAsset' => [
                'volumeId' => $this->volumeIds['videos'],
                'folderId' => $this->folderIds['samples'],
                'filename' => 'samples/video.mov',
                'tempFilePath' => $testsDir.'/_craft/storage/runtime/temp/samples/video-720p.mov',
            ],

            'oggVideoAsset' => [
                'volumeId' => $this->volumeIds['videos'],
                'folderId' => $this->folderIds['samples'],
                'filename' => 'samples/video.ogg',
                'tempFilePath' => $testsDir.'/_craft/storage/runtime/temp/samples/video-720p.ogg',
            ],

            '3gpVideoAsset' => [
                'volumeId' => $this->volumeIds['videos'],
                'folderId' => $this->folderIds['samples'],
                'filename' => 'samples/video.3gp',
                'tempFilePath' => $testsDir.'/_craft/storage/runtime/temp/samples/video-240p.3gp',
            ],
        ];
    }
}
