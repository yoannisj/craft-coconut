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

use yii\base\ErrorException;
use yii\base\Exception;

use Craft;
use craft\records\Volume as VolumeRecord;
use craft\volumes\Local as LocalVolume;
use craft\services\Volumes;
use craft\test\ActiveFixture;
use craft\helpers\FileHelper;
use craft\helpers\Json as JsonHelper;
use craft\helpers\App as AppHelper;

/**
 *
 */

class VolumeFixture extends ActiveFixture
{
    // =Properties
    // ========================================================================

    /**
     * @inheritdoc
     */

    public $modelClass = VolumeRecord::class;

    /**
     * @inheritdoc
     */

    // public $depends = [
    //     FieldLayoutFixture::class
    // ];

    // =Public Methods
    // ========================================================================

    /**
     * @inheritdoc
     * @throws Exception
     */

    public function load()
    {
        parent::load();

        // Create the dirs
        foreach ($this->getData() as $data) {
            $settings = JsonHelper::decodeIfJson($data['settings']);
            FileHelper::createDirectory($settings['path']);
        }

        // clear memoized data of Craft's `volumes` service
        Craft::$app->set('volumes', new Volumes());
    }

    /**
     * @inheritdoc
     * @throws ErrorException
     */

    public function unload()
    {
        // Remove the dirs
        foreach ($this->getData() as $data) {
            $settings = JsonHelper::decodeIfJson($data['settings']);
            FileHelper::removeDirectory($settings['path']);
        }

        parent::unload();
    }

    // =Protected Methods
    // ========================================================================

    /**
     * @inheritdoc
     */

    protected function getData()
    {
        $localUploadsPath = rtrim(AppHelper::env('WEB_ROOT'), '/').'/uploads';
        $localUploadsUrl = rtrim(AppHelper::env('WEB_URL'), '/').'/uploads';

        return [
            'localImagesVolume' => [
                'name' => 'Local Images Volume',
                'handle' => 'localImagesVolume',
                'type' => LocalVolume::class,
                'url' => null,
                'settings' => JsonHelper::encode([
                    'path' => $localUploadsPath,
                    'url' => $localUploadsUrl,
                ]),
                'hasUrls' => true,
                'sortOrder' => 1,
                // 'uid' => 'volume-1001----------------------uid',
            ],

            'localVideosVolume' => [
                'name' => 'Local Images Volume',
                'handle' => 'localImagesVolume',
                'type' => LocalVolume::class,
                'url' => null,
                'settings' => JsonHelper::encode([
                    'path' => $localUploadsPath.'/videos',
                    'url' => $localUploadsUrl.'/videos',
                ]),
                'hasUrls' => true,
                'sortOrder' => 1,
            ],
        ];
    }
}
