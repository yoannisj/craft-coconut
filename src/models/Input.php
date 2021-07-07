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

namespace yoannisj\coconut\models;

use Coconut\Job as CoconutJob;

use yii\base\InvalidArgumentException;
use yii\validators\InlineValidator;
use yii\queue\Job;

use Craft;
use craft\base\Model;
use craft\base\VolumeInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\ArrayHelper;
use craft\helpers\FileHelper;
use craft\helpers\Json as JsonHelper;

use yoannisj\coconut\Coconut;

/**
 *
 */

class Input extends Model
{
    // =Properties
    // =========================================================================

    /**
     * @var integer|null Input ID in Craft database
     */

    public $id;

    /**
     * @var integer|null ID of input's Craft Asset element
     */

    public $assetId;

    /**
     * @var \craft\elements\Asset The input Craft Asset element model
     */

    private $_asset;

    /**
     * @var string URL to input file
     */

    private $_url;

    /**
     * @var string|null Hash of input file URL (used for database indexes)
     */

    private $_urlHash;

    /**
     * @var array Metadata retrieved from Coconut API
     */

    private $_metadata;

    /**
     * @var string Latest input status from Coconut job
     * @see https://docs.coconut.co/jobs/api#job-status
     */

    public $status;

    /**
     * @var Datetime|null
     */

    public $expires;

    // =Public Methods
    // =========================================================================

    // =Properties
    // -------------------------------------------------------------------------

    /**
     * Setter method for the resolved `asset` property
     * 
     * @param Asset|null $asset
     */

    public function setAsset( Asset $asset = null )
    {
        if ($asset) $this->assetId = $asset->id;
        $this->_asset = $asset;
    }

    /**
     * Getter method for the resolved `asset` property
     * 
     * @return \craft\elements\Asset|null
     */

    public function getAsset()
    {
        if (isset($this->assetId) && !isset($this->_asset))
        {
            $this->_asset = Craft::$app->getAssets()
                ->getAssetById($this->_assetId);
        }

        return $this->_asset;
    }

    /**
     * Setter for normalizd `url` property
     * 
     * @param string|null $url
     */

    public function setUrl( string $url = null )
    {
        $this->_url = $url ?? '';
    }

    /**
     * Getter for normalizd `url` property
     * 
     * @return string
     */

    public function getUrl(): string
    {
        if (!isset($this->_url) && isset($this->assetId))
        {
            $asset = $this->getAsset();
            $this->_url = $asset ? $asset->url : '';
        }

        return $this->_url;
    }

    /**
     * Getter method for readonly `urlHash` property
     * 
     * @return string
     */

    public function getUrlHash(): string
    {
        if (!isset($this->_urlHash))
        {
            $url = $this->getUrl();
            $this->_urlHash = $url ? md5($url) : '';
        }
    }

    /**
     * Setter method for the normalized `metadata` property
     * 
     * @param string|array|null $value
     */

    public function setMetadata( $value )
    {
        $this->_metadata = $value;
    }

    /**
     * Getter merhod for the normalized `metadata` property
     * 
     * @return array|null
     */

    public function getMetadata()
    {
        if ($this->_metadata && is_string($this->_metadata)) {
            $this->_metadata = JsonHelper::decodeIfJson($this->_metadata);
        }

        return $this->_metadata;
    }

    // =Attributes
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function attributes()
    {
        $attributes = parent::attributes();

        $attributes[] = 'url';
        $attributes[] = 'metadata';

        return $attributes;
    }

    // =Validation
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function rules()
    {
        $rules = parent::rules();

        return $rules;
    }

    // =Fields
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function fields()
    {
        $fields = parent::fields();

        unset($fields['metadata']); // this is an attribute, but should be an extraField

        return $fields;
    }

    /**
     * @inheritdoc
     */

    public function extraFields()
    {
        $fields = parent::extraFields();

        $fields[] = 'asset';
        $fields[] = 'urlHash';
        $fields[] = 'metadata';

        return $fields;
    }
}