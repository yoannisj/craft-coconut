<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.github.io/license/
 */

namespace yoannisj\coconuttests\fixtures;

use Craft;
use craft\records\VolumeFolder as VolumeFolderRecord;
use craft\services\Volumes;
use craft\helpers\FileHelper;
use craft\test\ActiveFixture;

/**
 * Class VolumeFolderFixture.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @author Global Network Group | Giel Tettelaar <giel@yellowflash.net>
 * @since 3.2
 */

class VolumeFoldersFixture extends ActiveFixture
{
    // =Properties
    // ========================================================================

    /**
     * @inheritdoc
     */

    public $modelClass = VolumeFolderRecord::class;

    /**
     * @inheritdoc
     */

    public $dataFile = __DIR__. '/data/volumefolders.php';

    /**
     * @inheritdoc
     */

    public $depends = [
        VolumesFixture::class
    ];

    // =Public Methods
    // ========================================================================

    /**
     * @inheritdoc
     */

    public function beforeLoad()
    {
        parent::beforeLoad();

        // Create the temporary storage dirs
        $storagePath = rtrim(Craft::$app->getPath()->getStoragePath(), '/');

        foreach ($this->getData() as $data)
        {
            FileHelper::createDirectory("$storagePath/runtime/temp/".ltrim($data['path'], '/'));

            // $volume = Craft::$app->volumes->getVolumeById($data['volumeId']);
            // if (!$volume->folderExists($data['path'])) {
            //     $volume->createDir($data['path']);
            // }
        }
    }

    /**
     * @inheritdoc
     */

    public function load()
    {
        parent::load();

        // clear memoized data of Craft's `volumes` service
        Craft::$app->set('volumes', new Volumes());
    }

    // =Protected Methods
    // ========================================================================

}
