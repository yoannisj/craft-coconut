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
     * @var integer|null
     */

    public $id;

    /**
     * @var integer ID of coconut input in Craft database
     */

    public $inputId;

    /**
     * @var \yoannisj\coconu\models\Input Coconut input model
     */

    private $_input;

    /**
     * @var string|null ID of coconut job that created this output
     */

    public $coconutJobId;

    /**
     * @var string
     */

    public $format;

    /**
     * @var string
     */

    private $_mimeType;

    /**
     * @var array | null
     */

    private $_metadata;

    /**
     * @var integer|null
     */

    public $volumeId;

    /**
     * @var \craft\base\VolumeInterface
     */

    private $_volume;

    /**
     * @var string Output file URL (on storage)
     */

    public $url;

    /**
     * @var string Latest output status from Coconut job
     * @see https://docs.coconut.co/jobs/api#job-status
     */

    public $status;

    /**
     * @var \Datetime
     */

    public $dateCreated;

    /**
     * @var \Datetime
     */

    public $dateUpdated;

    // =Public Methods
    // =========================================================================

    // =Properties
    // -------------------------------------------------------------------------

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
     * Getter method for the normalized `metadata` property
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

    /**
     * Getter method for the readonly `mimeType` property
     * 
     * @return string
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
     * Setter method for the resolved `volume` property
     * 
     * @param VolumeInterface|string|null
     */

    public function setVolume( $volume )
    {
        if (is_string($volume)) {
            $volume = Craft::$app->getVolumes()->getVolumeByHandle($volume);
        }

        if ($volume instanceof VolumeInterface) {
            $this->volumeId = $volume->id;
            $this->_volume = $volume;
        }

        else if ($volume === null) {
            $this->volumeId = null;
            $this->_volume = null;
        }
    }

    /**
     * Getter method for the resolved `volume` property
     * 
     * @return VolumeInterface|null
     */

    public function getVolume()
    {
        if (isset($this->volumeId) && !isset($this->_volume))
        {
            $this->_volume = Craft::$app->getVolumes()
                ->getVolumeById($this->volumeId);
        }

        return $this->_volume;
    }

    // =Attributes
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function attributes()
    {
        $attributes = parent::attributes();

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

        $fields[] = 'metadata';
        $fields[] = 'mimeType';
        $fields[] = 'volume';
        $fields[] = 'coconutJobInfo';

        return $fields;
    }

    /**
     * @return array|null
     */

    public function getCoconutJobInfo()
    {
        return null;
    }

}