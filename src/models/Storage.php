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

use yii\validators\InlineValidator;

use Craft;
use craft\base\Model;
use craft\models\Volume;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\behaviors\PropertyAliasBehavior;
use yoannisj\coconut\models\ServiceCredentials;
use yoannisj\coconut\helpers\JobHelper;

/**
 * Model representing and validating settings for Cococnut storage method
 *
 * @property ServiceCredentials $credentials Credentials used for storage service
 * @property string $bucket_id Alias for 'bucket' property
 * @property string $container Alias for 'bucket' property
 *
 */
class Storage extends Model
{
    // =Static
    // =========================================================================

    /**
     * @var string
     */
    const SCENARIO_DEFAULT = 'default';

    /**
     * @var string
     */
    const SCENARIO_CONFIG = 'config';

    // =Properties
    // =========================================================================

    /**
     * Base URL on which to store output URLs (via HTTP(S) or (S)FTP)
     *
     * @var string|null
     */
    public ?string $url = null;

    /**
     * Handle of storage service
     *
     * @var ?string
     */
    public ?string $service = null;

    /**
     * Credentials used to connect to storage service
     *
     * @var array|null
     */
    private ?array $_credentials = null;

    /**
     * Name of storage service bucket/volume
     *
     * @var string|null
     */
    public ?string $bucket = null;

    /**
     * Name of storage container (for Rackspace service only)
     *
     * @var string|null
     */
    public ?string $container = null;

    /**
     * Region of storage service bucket/volume
     *
     * @var string|null
     */
    public ?string $region = null;

    /**
     * The absolute path where you want the output files to be uploaded.
     *
     * @var string
     */
    public string $path = '';

    /**
     * Whether stored output URLs should use the `https://` scheme
     *
     * @var bool
     */
    public bool $secure = true;

    /**
     * Access Control List policy to use for stored output files
     *
     * @var string|null
     */
    public ?string $acl = null;

    /**
     * Storage class for stored output files
     *
     * @var string|null
     */
    public ?string $storageClass = null;

    /**
     * Expires header value for stored output files
     *
     * @var string|null
     */
    public ?string $expires = null;

    /**
     * CacheControl header value for stored output files
     *
     * @var ?string
     */
    public ?string $cacheControl = null;

    /**
     * Endpoint URL for AWS S3-compatible storage service
     *
     * @var string|null
     */
    public ?string $endpoint = null;

    /**
     * Whether to always use path style stored output file URLs
     *  (required by some services).
     *
     * @var bool
     */
    public bool $forcePathStyle = false;

    /**
     * Handle for named storage or Craft volume storage
     *
     * @param string|null
     */
    private ?string $_handle = null;

    /**
     * ID of Craft volume for storage
     *
     * @param int|null
     */
    private ?int $_volumeId = null;

    /**
     * Craft volume for storage
     *
     * @var Volume|null
     */
    private ?Volume $_volume = null;

    // =Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function defineBehaviors(): array
    {
        $behaviors = parent::defineBehaviors();

        $behaviors[] = [
            'class' => PropertyAliasBehavior::class,
            'camelCasePropertyAliases' => true, // e.g. `$this->cache_control => $this->cacheControl`
            'propertyAliases' => [
                // e.g. `$this->bucketId => $this->bucket`
                'bucket' => [ 'bucketId', 'container' ],
            ],
        ];

        return $behaviors;
    }

    // =Computed
    // -------------------------------------------------------------------------

    /**
     * Setter method for normalized `credentials` property
     *
     * @param array|ServiceCredentials|null $credentials
     *
     * @return static Back-reference for method chaining
     */
    public function setCredentials(
        array|ServiceCredentials|null $credentials
    ): static
    {
        $this->_credentials = $credentials;
        return $this;
    }

    /**
     * Getter method for normalized `credentials` property
     *
     * @return ServiceCredentials|null
     */
    public function getCredentials()
    {
        if (empty($this->service)) {
            return null;
        }

        $credentials = $this->_credentials;

        if (is_array($credentials))
        {
            $credentials['service'] = $this->service;
            $this->_credentials = new ServiceCredentials($credentials);
        }

        return $this->_credentials;
    }

    /**
     * @param string|null $handle
     *
     * @return static Back-reference for method chaining
     */
    public function setHandle( string|null $handle ): static
    {
        $this->_handle = $handle;
        $this->_volumeId = null;

        if ($this->_volume && $this->_volume->handle != $handle) {
            $this->_volume = null;
        }

        return $this;
    }

    /**
     * @return string|null
     */
    public function getHandle(): ?string
    {
        if (!isset($this->_handle)
            && ($volume = $this->getVolume()))
        {
            $this->_handle = $volume->handle;
        }

        return $this->_handle;
    }

    /**
     * @param int|null $volumeId
     * @return static Back-reference for method chaining
     */

    public function setVolumeId( int|null $volumeId ): static
    {
        $this->_volumeId = $volumeId;

        if ($this->_volume && $this->_volumeId != $volumeId) {
            $this->_volume = null;
        }

        if ($volumeId) {
            $this->_handle = null;
        }

        return $this;
    }

    /**
     * @return int|null
     */
    public function getVolumeId(): ?int
    {
        if (!isset($this->_volumeId) && $this->_volume)
        {
            $this->_volumeId = $this->_volume->id;
        }

        return $this->_volumeId;
    }

    /**
     * @param Volume|null $volume
     *
     * @return static Back-reference for method chaining
     */
    public function setVolume( Volume|null $volume ): static
    {
        $this->_volume = $volume;

        if ($volume) {
            $this->_handle = $volume->handle;
            $this->_volumeId = $volume->id;
        } else {
            $this->_volumeId = null;
        }

        return $this;
    }

    /**
     * @return Volume|null
     */
    public function getVolume(): ?Volume
    {
        if (!isset($this->_volume) && $this->_volumeId)
        {
            $this->_volume = Craft::$app->getVolumes()
                ->getVolumeById($this->_volumeId);
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

        $attributes[] = 'handle';
        $attributes[] = 'volumeId';

        return $attributes;
    }

    // =Validation
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();

        // =service supported
        $rules['serviceSupported'] = [ 'service', 'in', 'range' => Coconut::SUPPORTED_SERVICES ];

        // =required attributes
        // get list of required attributes based on service
        $requiredAttr = [];

        // no service? only `url` property is required
        if (empty($this->service) || $this->service == Coconut::SERVICE_COCONUT) {
            $requiredAttr[] = 'url';
        }

        else if (in_array($this->service, Coconut::S3_COMPATIBLE_SERVICES))
        {
            $requiredAttr[] = 'service';
            $requiredAttr[] = 'credentials';
            $requiredAttr[] = 'bucket';

            if ($this->service != Coconut::SERVICE_GCS
                && $this->service != Coconut::SERVICE_DOSPACES
                && $this->service != Coconut::SERVICE_S3OTHER
            ) {
                $requiredAttr[] = 'region';
            }

            if ($this->service == Coconut::SERVICE_S3OTHER) {
                $requiredAttr[] = 'endpoint';
            }
        }

        $rules['attrRequired'] = [ $requiredAttr, 'required' ];

        // =format attributes
        $rules['attrString'] = [ [
            'url',
            'service',
            'bucket',
            'path',
            'acl',
            'storageClass',
            'endpoint',
            'expires',
            'cacheControl',
        ], 'string' ];

        $rules['attrBool'] = [ ['secure'], 'boolean' ];
        $rules['credentialsValid'] = [ ['credentials'], 'validateCredentials' ];

        return $rules;
    }

    /**
     * Validation method for the `credentials` property
     *
     * @param string $attribute
     * @param array $params
     * @param InlineValidator $validator
     *
     * @return void
     */
    public function validateCredentials(
        string $attribute,
        array $params,
        InlineValidator $validator
    ): void
    {
        $credentials = $this->$attribute;

        if ($credentials && !$credentials->validate())
        {
            $validator->addError($this, $attribute,
                Craft::t('coconut', "The {attribute} attribute is not valid for this service") );
        }
    }

    // =Fields
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    public function extraFields(): array
    {
        $fields = parent::extraFields();

        $fields[] = 'volume';

        return $fields;
    }

    /**
     * Returns Coconut API params for this job output
     *
     * @return array
     */
    public function toParams(): array
    {
        $params = [];

        foreach ($this->paramFields() as $field)
        {
            $value = $this->$field;

            if ($field == 'url') {
                $params['url'] = JobHelper::publicUrl($value);
            } else if ($field == 'credentials') {
                $params['credentials'] = $value->toParams();
            } else if (is_string($value)) {
                $params[$field] = Craft::parseEnv($value);
            } else {
                $params[$field] = $value;
            }
        }

        return JobHelper::cleanParams($params);
    }

    // =Proteced Methods
    // ========================================================================

    /**
     * Returns parameter field names supported by the Coconut API
     *
     * @return array
     */
    protected function paramFields(): array
    {
        // no service? use HTTP, (S)FTP protocol with `url` only
        if (empty($this->service)) {
            return [ 'url' ];
        }

        // coconut test storage only supports the 'service' field
        else if ($this->service == 'coconut') {
            return [ 'service' ];
        }

        // =common to all services
        $fields = [
            'service',
            'credentials',
            'path',
        ];

        // s3 and s3-compatible
        if ($this->service == 's3'
            || $this->service == 'gcs'
            || $this->service == 'dospaces'
            || $this->service == 'linode'
            || $this->service == 'wasabi'
            || $this->service == 's3other')
        {
            $fields[] = 'bucket';
            $fields[] = 'acl';

            if ($this->service != 'gcs') {
                $fields[] = 'region';
            }

            if ($this->service == 's3') {
                $fields[] = 'expires';
                $fields[] = 'cache_control';
            }

            else if ($this->service == 's3other') {
                $fields[] = 'endpoint';
                $fieldS[] = 'force_path_style';
            }
        }

        // rackspace and azure
        else if ($this->service == 'rackspace'
            || $this->service == 'azure')
        {
            $fields[] = 'container';
        }

        // backblaze
        if ($this->service == 'backblaze') {
            $fields[] = 'bucket_id';
        }

        return $fields;
    }
}
