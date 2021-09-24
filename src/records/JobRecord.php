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

use yii\base\InvalidConfigException;

use Craft;
use craft\db\ActiveRecord;
use craft\helpers\Json as JsonHelper;

use yoannisj\coconut\Coconut;

/**
 * Active record for Job rows in database
 *
 * @property integer id
 * @property string coconutId
 * @property string configParams
 * @property Config config
 * @property string status
 * @property string progress
 * @property integer inputAssetId
 * @property string inputUrl
 * @property string inputUrlHash
 * @property string inputStatus
 * @property string inputMetadata
 * @property Datetime inputExpires
 * @property string storageHandle
 * @property integer storageVolumeId
 * @property array storageParams
 * @property Datetime createdAt
 * @property Datetime completedAt
 * @property Datetime dateCreated
 * @property Datetime dateUpdated
 */

class JobRecord extends ActiveRecord
{
    // =Static
    // =========================================================================

    /**
     * @inheritdoc
     */

    public static function tableName(): string
    {
        return Coconut::TABLE_JOBS;
    }

    // =Public Methods
    // =========================================================================

    /**
     * Setter method for transformed `metadata` property
     *
     * @param string|array $metadata
     */

    public function setInputMetadata( $metadata )
    {
        if (is_array($metadata)) {
            $metadata = JsonHelper::encode($metadata);
        }

        $this->metadata = $metadata;
    }

    /**
     * Getter method for transformed `metadata` property
     *
     * @return array
     */

    public function getInputMetadata(): array
    {
        $metadata = $this->metadata ?? [];

        if (is_string($metadata)) {
            $metadata = JsonHelper::decode($metadata);
        }

        return $metadata;
    }

    /**
     * Setter method for transformed `storageParams` property
     *
     * @param string|array
     */

    public function setStorageParams( $settings )
    {
        if (is_array($settings)) {
            $settings = JsonHelper::encode($settings);
        }

        $this->storageParams = $settings;
    }

    /**
     * Getter method for transformed `storageParams` property
     *
     * @return array
     */

    public function getStorageParams(): array
    {
        $settings = $this->storageParams ?? [];

        if (is_string($settings)) {
            $settings = JsonHelper::decode($settings);
        }

        return $settings;
    }
}
