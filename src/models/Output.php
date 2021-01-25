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

class Output extends Model
{
    // =Properties
    // =========================================================================

    /**
     * @var int | null
     */

    public $id;

    /**
     * @var int | null
     */

    public $volumeId;

    /**
     * @var \craft\base\VolumeInterface
     */

    private $_volume; 

    /**
     * @var int | null
     */

    private $_sourceAssetId;

    /**
     * @var \craft\elements\Asset
     */

    private $_sourceAsset;

    /**
     * @var string Url of output's source
     */

    private $_source;

    /**
     * @var string
     */

    public $format;

    /**
     * @var string
     */

    public $url;

    /**
     * @var string
     */

    private $_mimeType;

    /**
     * @var bool
     */

    public $inProgress;

    /**
     * @var int | null
     */

    public $coconutJobId;

    /**
     * @var \Datetime
     */

    public $dateCreated;

    /**
     * @var \Datetime
     */

    public $dateUpdated;

    /**
     * @var array | null
     */

    private $_metadata;

    // =Public Methods
    // =========================================================================

    // =Attributes
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function attributes()
    {
        $attributes = parent::attributes();

        $attributes[] = 'sourceAssetId';
        $attributes[] = 'source';
        $attributes[] = 'metadata';

        return $attributes;
    }

    /**
     * @param int | null
     */

    public function setSourceAssetId( $value )
    {
        $this->_sourceAssetId = $value;
        $this->_sourceAsset = null;
        $this->_source = null;
    }

    /**
     * @return int | null
     */

    public function getSourceAssetId()
    {
        return $this->_sourceAssetId;
    }

    /**
     * @param string | null $value
     */

    public function setSource( $value )
    {
        if (is_numeric($value))
        {
            $this->_sourceAssetId = (int)$value;
            $this->_sourceAsset = null;
            $this->_source = null;
        }

        else if (is_string($value))
        {
            // $this->_sourceAssetId = null;
            // $this->_sourceAsset = null;
            $this->_source = $value;
        }

        else if ($value instanceof Asset)
        {
            $this->_sourceAssetId = $value->id;
            $this->_sourceAsset = $value;
            $this->_source = $value->url;
        }
    }

    /**
     * @return string | null
     */

    public function getSource()
    {
        if (isset($this->_source)) {
            return $this->_source;
        }

        if (($asset = $this->getSourceAsset())) {
            return $asset->url;
        }

        return null;
    }

    /**
     * @param string | array | null $value
     */

    public function setMetadata( $value )
    {
        $this->_metadata = $value;
    }

    /**
     * @return array | null
     */

    public function getMetadata()
    {
        if ($this->_metadata && is_string($this->_metadata)) {
            $this->_metadata = JsonHelper::decodeIfJson($this->_metadata);
        }

        return $this->_metadata;
    }

    // =Validation
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function rules()
    {
        $rules = parent::rules();

        // =requirements and defaults
        $rules['attrsRequired'] = [ ['format', 'url'], 'required' ];
        $rules['sourceRequired'] = [ 'source', 'required', 'when' => function($model) {
            return !isset($model->sourceAssetId);
        }];
        $rules['coconutJobIdRequired'] = [ 'coconutJobId', 'required', 'when' => function($model) {
            return $model->inProgress;
        }];

        // =formatting
        $rules['attrsInteger'] = [ ['id', 'volumeId', 'sourceAssetId', 'coconutJobId'], 'integer' ];
        $rules['attrsUrl'] = [ ['url', 'source'], 'url' ];
        $rules['inProgressBoolean'] = [ 'inProgress', 'boolean' ];

        // =safe attributes
        $rules['attrsSafe'] = [ ['metadata'], 'safe' ];

        return $rules;
    }

    // =Fields
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function extraFields()
    {
        $fields = parent::extraFields();

        $fields[] = 'sourceAsset';
        $fields[] = 'volume';
        $fields[] = 'coconutJobInfo';
        $fields[] = 'mimeType';

        return $fields;
    }

    /**
     * @return \craft\elements\Asset | null
     */

    public function getSourceAsset()
    {
        if (!isset($this->_sourceAsset))
        {
            $asset = null;

            if (isset($this->_sourceAssetId))
            {
                $asset = Asset::find()
                    ->id($this->_sourceAssetId)
                    ->one();            
            }

            $this->_sourceAsset = $asset;
        }

        return $this->_sourceAsset;
    }

    /**
     * @return craft\base\VolumeInterface
     */

    public function getVolume()
    {
        if (!isset($this->_volume))
        {
            $volume = null;

            if (isset($this->volumeId)) {
                $volume = Craft::$app->getVolumes()->getVolumeById($this->volumeId);
            }

            else if (($asset = $this->getSourceAsset())) {
                $volume = $asset->getVolume();
            }

            if (!$volume) { // fallback to default volume
                $volume = Coconut::$plugin->getSettings()->getOutputVolume();
            }

            $this->_volume = $volume;
        }

        return $this->_volume;
    }

    /**
     * 
     */

    public function getMimeType(): string
    {
        if (!isset($this->_mimeType))
        {
            $file = parse_url($this->url, PHP_URL_PATH);
            $this->_mimeType = FileHelper::getMimeTypeByExtension($file);
        }

        return $this->_mimeType;
    }


    /**
     * @return array
     */

    public function getCoconutJobInfo()
    {
        if (!$this->inProgress || !isset($this->coconutJobId)) {
            return null;
        }

        return Coconut::get($this->coconutJobId);
    }

}