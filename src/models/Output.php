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
use craft\helpers\Json as JsonHelper;
use craft\helpers\FileHelper;
use craft\helpers\Assets as AssetsHelper;

use yoannisj\coconut\Coconut;

/**
 * @property Job $job
 * @property array $format
 * @property string $formatString
 * @property string $type
 * @property string $mimeType
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
     * @var integer ID in Craft database of the Coconut job that created this output
     */

    public $jobId;

    /**
     * @var Job|null Coconut job model
     */

    private $_job;

    /**
     * @var string
     */

    private $_key;

    /**
     * @var array
     */

    private $_format;

    /**
     * @var string
     */

    private $_formatString;

    /**
     * @var string
     */

    private $_path;

    /**
     * @var string
     */

    private $_type;

    /**
     * @var string
     */

    private $_mimeType;

    /**
     * @var array | null
     */

    private $_metadata;

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
     * @param Job|null $job
     */

    public function setJob( Job $job = null )
    {
        if ($job instanceof Job) {
            $this->jobId = $job->id;
            $this->_job = $job;
        }

        else {
            $this->jobId = null;
            $this->_job = null;
        }
    }

    /**
     * 
     * 
     * @return Job|null
     */

    public function getJob()
    {
        if ($this->jobId) {
            return Coconut::$plugin->getJobs()->getJobById($this->jobId);
        }

        return null;
    }

    /**
     * 
     */

    public function setFormat( $format )
    {
        if (is_string($format)) {
            $format = JsonHelper::decodeIfJson($format);
        }

        if (is_string($format))
        {
            $this->_formatString = $format;
            $this->_format = ConfigHelper::parseFormat($format);
        }

        else if (is_array($format) || $format === null) {
            $this->_format = $format;
            $this->_formatString = null;
        }
    }

    /**
     * 
     */

    public function getFormat()
    {
        return $this->_format;
    }

    /**
     * 
     */

    public function setKey( string $key = null )
    {
        $this->_key = $key;
    }

    /**
     * 
     */

    public function getKey()
    {
        if (!isset($this->_key) && !empty($this->getFormat())) {
            $this->_key = $this->getFormatString();
        }

        return $this->_key;
    }

    /**
     * @param string|null $path
     */

    public function setPath( string $path = null )
    {
        if ($path) { // make sure this is a private path by prepending with '_'
            $path = preg_replace('/^(\.{0,2}\/)?([^_])/', '$1_$2', $path);
        }

        $this->_path = $path;

    }

    /**
     * @return string
     */

    public function getPath(): string
    {
        if (empty($this->_path))
        {
            if (($job = $this->getJob())
                && ($input = $job->getInput())
                && ($inputUrl = $input->getUrl())
            ) {
                $pathFormat = Coconut::$plugin->getSettings()->defaultPathFormat;
                $vars = [
                    'path' => parse_url($inputUrl, PHP_URL_PATH),
                    'filename' => pathinfo($path, PATHINFO_FILENAME),
                    'hash' => $input->getUrlHash(), // @todo: add support for '{shortHash}' in `defaultPathFormat`
                    'key' => ConfigHelper::keyAsPath($this->getKey()),
                    'ext' => ConfigHelper::formatExtension($this->format),
                ];

                $this->setPath(Craft::$app->getView()
                    ->renderObjectTemplate($pathFormat, $vars));
            }
        }

        return $this->_path;
    }

    /**
     * Setter method for the normalized `metadata` property
     * 
     * @param string|array|null $metadata
     */

    public function setMetadata( $metadata )
    {
        if (is_string($metadata)) {
            $metadata = JsonHelper::decodeIfJson($this->_metadata) ?? [];
        }

        $this->_metadata = $metadata;
    }

    /**
     * Getter method for the normalized `metadata` property
     * 
     * @return array|null
     */

    public function getMetadata()
    {
        return $this->_metadata;
    }

    /**
     * Getter for read-only `formatString` property
     * 
     * @return string|null
     */

    public function getFormatString()
    {
        if (!isset($this->_formatString)
            && ($format = $this->getFormat())
        ) {
            $this->_formatString = ConfigHelper::formatString($format);
        }

        return $this->_formatString;
    }

    /**
     * Getter method for the read-only `type` property
     * 
     * @return string|null
     */

    public function getType()
    {
        $path = $this->getPath();
        return $path ? AssetsHelper::getFileKindByExtension($path) : null;
    }

    /**
     * Getter method for the read-only `mimeType` property
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

    // =Attributes
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function attributes()
    {
        $attributes = parent::attributes();

        $attributes[] = 'key';

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