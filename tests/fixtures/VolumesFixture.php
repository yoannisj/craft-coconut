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

class VolumesFixture extends ActiveFixture
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

    public $dataFile = __DIR__.'/data/volumes.php';

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

        // Create the volume directories
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
        // Remove the volume directories
        foreach ($this->getData() as $data) {
            $settings = JsonHelper::decodeIfJson($data['settings']);
            FileHelper::removeDirectory($settings['path']);
        }

        parent::unload();
    }

    /**
     *
     */

    // protected function getData()
    // {
    //     $data = parent::getData();

    //     var_dump($this->dataFile);
    //     var_dump($data);

    //     die();
    // }


    // =Protected Methods
    // ========================================================================

}
