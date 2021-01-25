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

namespace yoannisj\coconut\records;

use yii\db\ActiveQueryInterface;

use Craft;
use craft\db\ActiveRecord;
use craft\records\Volume as VolumeRecord;
use craft\records\Asset as AssetRecord;
use craft\errors\VolumeException;

use yoannisj\coconut\Coconut;

/**
 * @property $id
 * @property $volumeId
 * @property $sourceAssetId
 * @property $source
 * @property $format
 * @property $url
 * @property $inProgress
 * @property $coconutJobId
 * @property $metadata
 * @property $dateCreated
 * @property $dateUpdated
 * @property $uid
 */

class OutputRecord extends ActiveRecord
{
    // =Static
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string
     */

    public static function tableName(): string
    {
        return Coconut::TABLE_OUTPUTS;
    }

    // =Properties
    // =========================================================================

    // =Public Methods
    // =========================================================================

    // =Relations
    // -------------------------------------------------------------------------

    /**
     * Returns the output volume.
     *
     * @return ActiveQueryInterface The relational query object.
     */

    public function getVolume(): ActiveQueryInterface
    {
        return $this->hasOne(VolumeRecord::class, ['id' => 'volumeId']);
    }

    /**
     * Returns the source asset.
     *
     * @return ActiveQueryInterface The relational query object.
     */

    public function getSourceAsset(): ActiveQueryInterface
    {
        return $this->hasOne(AssetRecord::class, ['id' => 'sourceAssetId']);
    }

    // =Events
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function afterDelete()
    {
        $volume = Craft::$app->getVolumes()->getVolumeById($this->volumeId);

        if ($volume)
        {
            $volumeUrl = $volume->getRootUrl();
            $url = $this->url;
            $path = trim(str_replace($volumeUrl, '', $url), '/');

            try {
                $volume->deleteFile($path);
            } catch (VolumeException $e) {
                Craft::warning($e->getMessage());
            }
        }

        parent::afterDelete();
    }

    // =Protected Methodss
    // =========================================================================

    // =Private Methodss
    // =========================================================================

}
