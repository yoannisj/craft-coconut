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
 * @property array storageSettings
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
     * Setter method for transformerd `config` property
     * 
     * @param string|array|Config|null $config
     */

    public function setConfig( Config $config = null )
    {
        if (!$config) {
            $this->_config = null;
            $this->configParams = null;
        }

        else {
            $this->_config = $config;
            $this->configParams = JsonHelper::encode($config->toArray());
        }
    }

    /**
     * Getter method for transformed `config` property
     * 
     * @return Config|null
     */

    public function getConfig()
    {
        if (!isset($this->_config))
        {
            $config = $this->configParams;

            if (is_string($config)) {
                $config = JsonHelper::decode($config);
            }

            if (is_array($config))
            {
                if (!array_key_exists('class', $config)) {
                    $config['class'] = Config::class;
                }

                $config = Craft::createObject($config);
            }

            if ($config && !$config instanceof Config)
            {
                $class = Config::class;
                throw new InvalidConfigException(
                    "Attribute `config` must be `null` or a $class representation (properties, JSON or instance)");
            }

            $this->_config = $config;
        }

        return $this->_config;
    }

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
     * Setter method for transformed `storageSettings` property
     * 
     * @param string|array
     */

    public function setStorageSettings( $settings )
    {
        if (is_array($settings)) {
            $settings = JsonHelper::encode($settings);
        }

        $this->storageSettings = $settings;
    }

    /**
     * Getter method for transformed `storageSettings` property
     * 
     * @return array
     */

    public function getStorageSettings(): array
    {
        $settings = $this->storageSettings ?? [];

        if (is_string($settings)) {
            $settings = JsonHelper::decode($settings);
        }

        return $settings;
    }
}