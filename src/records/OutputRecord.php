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
use craft\errors\VolumeException;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\records\InputRecord;

/**
 * @property $id
 * @property $inputId
 * @property $coconutJobId
 * @property $format
 * @property $url
 * @property $metadata
 * @property $volumeId
 * @property $status
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
     * Returns the coconut input
     *
     * @return ActiveQueryInterface The relational query object
     */

    public function getInput(): ActiveQueryInterface
    {
        return $this->hasOne(InputRecord::class, ['id' => 'inputId']);
    }

    /**
     * Setter method for memoized `volume` property
     * 
     * @param \craft\models\Volume|string|null $volume
     */

    public function setVolume( $volume )
    {
        if (is_string($volume)) {
            $volume = Craft::$app->getVolumes()->getVolumeByHandle($volume);
        }

        if ($volume instanceof Volume) {
            $this->_volume = $volume;
            $this->volumeId = $volume->id;
        }

        else if (is_null($volume)) {
            $this->_volume = null;
        }
    }

    /**
     * Returns the output volume (for storage)
     *
     * @return ActiveQueryInterface The relational query object
     */

    public function getVolume(): ActiveQueryInterface
    {
        return $this->hasOne(VolumeRecord::class, ['id' => 'volumeId']);
    }

    // =Events
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function afterDelete()
    {
        if (isset($this->volumeId))
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
                    // Fail silently (log a warning instead of throwing an error)
                    Craft::warning($e->getMessage());
                }
            }
        }

        parent::afterDelete();
    }

    // =Protected Methodss
    // =========================================================================

    // =Private Methodss
    // =========================================================================

}
