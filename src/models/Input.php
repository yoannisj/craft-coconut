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

use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\validators\InlineValidator;

use Craft;
use craft\base\Model;
use craft\db\Query;
use craft\elements\Asset;
use craft\validators\DateTimeValidator;
use craft\helpers\App as AppHelper;
use craft\helpers\ArrayHelper;
use craft\helpers\FileHelper;
use craft\helpers\Json as JsonHelper;


use craft\awss3\Volume as AwsS3Volume;
use fortrabbit\ObjectStorage\Volume as FortrabbitStorageVolume;
use yoannisj\coconut\helpers\JobHelper;

/**
 * Model representing and validation Coconut inputs
 *
 * @todo: support service inputs (e.g. files directly uploaded from an S3 bucket, etc.)
 */

class Input extends Model
{
    // =Static
    // =========================================================================

    const SCENARIO_DEFAULT = 'default';
    const SCENARIO_CONFIG = 'config';

    const STATUS_STARTING = 'input.starting';
    const STATUS_TRANSFERRING = 'input.transferring';
    const STATUS_TRANSFERRED = 'input.transferred';
    const STATUS_FAILED = 'input.failed';

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
     * @var string Latest input status from Coconut job
     * @see https://docs.coconut.co/jobs/api#job-status
     */

    public $status;

    /**
     * @var string Progress (in percentage) of the input handling by Coconut
     */

    public $progress = '0%';

    /**
     * @var array Metadata retrieved from Coconut API
     */

    private $_metadata;

    /**
     * @var Datetime|null
     */

    public $expires;

    /**
     * @var string Error message associated with this input
     * @note This is only relevant if input has failed `status`
     */

    public $error;

    // =Public Methods
    // =========================================================================

    // =Properties
    // -------------------------------------------------------------------------

    /**
     * Getter method for readonly `name` property
     */

    public function getName(): string
    {
        if (($asset = $this->getAsset())) {
            return $asset->title;
        } else if (($url = $this->getUrl())) {
            return basename($url);
        }

        return 'Undefined';
    }


    /**
     * Setter method for the resolved `asset` property
     *
     * @param Asset|null $asset
     */

    public function setAsset( Asset $asset = null )
    {
        $this->assetId = $asset ? $asset->id : null;
        $this->_asset = $asset;
        $this->_url = null;
        $this->_urlHash = null;
    }

    /**
     * Getter method for the resolved `asset` property
     *
     * @return \craft\elements\Asset|null
     */

    public function getAsset()
    {
        if ($this->assetId && !$this->_asset)
        {
            $asset = Craft::$app->getAssets()->getAssetById($this->assetId);
            $this->_asset = $asset;
        }

        if ($this->_asset && $this->_asset->kind != 'video')
        {
            throw new InvalidConfigException(
                "Property `asset` must be of kind 'video'");
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
        if ($url !== $this->getUrl())
        {
            $this->_url = $url;
            $this->_urlHash = null;
        }
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

        return $this->_url ?? '';
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

        return $this->_urlHash;
    }

    /**
     * Setter method for the normalized `metadata` property
     *
     * @param string|array|null $metadata
     */

    public function setMetadata( $metadata )
    {
        if (is_string($metadata)) {
            $metadata = JsonHelper::decodeIfJson($metadata) ?? [];
        }

        if ($metadata !== null && !is_array($metadata))
        {
            throw new InvalidConfigException(
                "Property `metadata` must be an array or a JSON string representing an array");
        }

        $this->_metadata = $metadata;
    }

    /**
     * Getter merhod for the normalized `metadata` property
     *
     * @return array|null
     */

    public function getMetadata()
    {
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

        $rules['attrDateTime'] = [ [
            'expires',
        ], DateTimeValidator::class ];

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

        $fields[] = 'name';

        // these are attributes, but should be extraFields
        $fields = ArrayHelper::withoutValue($fields, 'urlHash');
        $fields = ArrayHelper::withoutValue($fields, 'metadata');

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

    /**
     * Returns Coconut API params for this job input
     *
     * @return array
     */

    public function toParams(): array
    {
        // @todo Use volume adapters to get input params
        $asset = $this->getAsset();
        if ($asset)
        {
            $volume = $asset->getVolume();
            $assetPath = $asset->path;

            if ($volume instanceof AwsS3Volume)
            {
                // prefix asset path with volume subfolder
                if ($volume->addSubfolderToRootUrl && $volume->subfolder
                    && ($subfolder = rtrim(AppHelper::parseEnv($volume->subfolder), '/')) !== '')
                {
                    $assetPath = $subfolder.'/'.ltrim($assetPath, '/');
                }

                return [
                    'service' => 's3',
                    'region' => AppHelper::parseEnv($volume->region),
                    'credentials' => [
                        'access_key_id' => AppHelper::parseEnv($volume->keyId),
                        'secret_access_key' => AppHelper::parseEnv($volume->secret),
                    ],
                    'bucket' => AppHelper::parseEnv($volume->bucket),
                    'key' => $assetPath,
                ];
            }

            if ($volume instanceof FortrabbitStorageVolume)
            {
                if ($volume->subfolder
                    && ($subfolder = rtrim(Craft::parseEnv($this->subfolder), '/')) !== '')
                {
                    $assetPath = $subfolder.'/'.ltrim($assetPath, '/');
                }

                return [
                    'service' => 's3other',
                    'endpoint' => AppHelper::parseEnv($volume->endpoint),
                    'credentials' => [
                        'access_key_id' => AppHelper::parseEnv($volume->keyId),
                        'access_key_id' => AppHelper::parseEnv($volume->secret),
                    ],
                    'bucket' => AppHelper::parseEnv($volume->bucket),
                    'key' => $assetPath,
                ];
            }
        }

        return [
            'url' => JobHelper::publicUrl($this->getUrl()),
        ];
    }
}
