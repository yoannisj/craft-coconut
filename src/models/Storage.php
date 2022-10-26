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
use craft\helpers\StringHelper;

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

    const SCENARIO_DEFAULT = 'default';
    const SCENARIO_CONFIG = 'config';

    // =Properties
    // =========================================================================

    /**
     * @var string Base URL on which to store output URLs (via HTTP(S) or (S)FTP)
     */

    public $url;

    /**
     * @var string Handle of storage service
     */

    public $service;

    /**
     * @var array Credentials used to connect to storage service
     */

    private $_credentials;

    /**
     * @var string Name of storage service bucket/volume
     */

    public $bucket;

    /**
     * @var string Name of storage container (for Rackspace service only)
     */

    public $container;

    /**
     * @var string Region of storage service bucket/volume
     */

    public $region;

    /**
     * @var string The absolute path where you want the output files to be uploaded.
     */

    public $path;

    /**
     * @var bool Whether stored output URLs should use the `https://` scheme
     */

    public $secure;

    /**
     * @var string Access Control List policy to use for stored output files
     */

    public $acl;

    /**
     * @var string Storage class for stored output files
     */

    public $storageClass;

    /**
     * @var string Expires header value for stored output files
     */

    public $expires;

    /**
     * @var string CacheControl header value for stored output files
     */

    public $cacheControl;

    /**
     * @var string Endpoint URL for AWS S3-compatible storage service
     */

    public $endpoint;

    /**
     * @var bool Whether to always use path style stored output file URLs
     *  (required by some services).
     */

    public $forcePathStyle;

    /**
     * @param string|null Handle for named storage or Craft volume storage
     */

    private $_handle;

    /**
     * @param int Id of Craft volume for storage
     */

    private $_volumeId;

    /**
     * @param VolumeInterface|null Craft volume for storage
     */

    private $_volume;

    // =Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */

    public function behaviors()
    {
        $behaviors = parent::behaviors();

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
     */

    public function setCredentials( $credentials = null )
    {
        $this->_credentials = $credentials;
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
     */

    public function setHandle( string $handle = null )
    {
        $this->_handle = $handle;
        $this->_volumeId = null;

        if ($this->_volume && $this->_volume->handle != $handle) {
            $this->_volume = null;
        }
    }

    /**
     * @return string|null
     */

    public function getHandle()
    {
        if (!isset($this->_handle)
            && ($volume = $this->getVolume()))
        {
            $this->_handle = $volume->handle;
        }

        return $this->_handle;
    }

    /**
     * @param integer|null $volumeId
     */

    public function setVolumeId( int $volumeId = null )
    {
        $this->_volumeId = $volumeId;

        if ($this->_volume && $this->_volumeId != $volumeId) {
            $this->_volume = null;
        }

        if ($volumeId) {
            $this->_handle = null;
        }
    }

    /**
     * @return integer|null
     */

    public function getVolumeId()
    {
        if (!isset($this->_volumeId) && $this->_volume)
        {
            $this->_volumeId = $this->_volume->id;
        }

        return $this->_volumeId;
    }

    /**
     * @param VolumeInterface|null $volume
     */

    public function setVolume( VolumeInterface $volume = null )
    {
        $this->_volume = $volume;

        if ($volume) {
            $this->_handle = $volume->handle;
            $this->_volumeId = $volume->id;
        } else {
            $this->_volumeId = null;
        }
    }

    /**
     * @return VolumeInterface|null
     */

    public function getVolume()
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

    public function rules()
    {
        $rules = parent::rules();

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
     */

    public function validateCredentials( string $attribute, array $params, InlineValidator $validator )
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

    public function extraFields()
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
