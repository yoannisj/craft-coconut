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

use yoannisj\coconut\models\StorageCredentials;

/**
 * Model representing and validating settings for Cococnut storage method
 * 
 * @property StorageCredentials $credentials Credentials used for storage service
 * @property string $bucket_id Alias for 'bucket' property
 * @property string $container Alias for 'bucket' property
 * 
 */

class StorageSettings extends Model
{
    // =Static
    // =========================================================================

    const SERVICE_COCONUT = 'coconut';
    const SERVICE_S3 = 's3';
    const SERVICE_GCS = 'gcs';
    const SERVICE_DOSPACES = 'dospaces';
    const SERVICE_LINODE = 'linode';
    const SERVICE_WASABI = 'wasabi';
    const SERVICE_S3OTHER = 's3other';
    const SERVICE_BACKBLAZE = 'backblaze';
    const SERVICE_RACKSPACE = 'rackspace';
    const SERVICE_AZURE = 'azure';

    const SUPPORTED_SERVICES = [
        self::SERVICE_COCONUT,
        self::SERVICE_S3,
        self::SERVICE_GCS,
        self::SERVICE_DOSPACES,
        self::SERVICE_LINODE,
        self::SERVICE_WASABI,
        self::SERVICE_S3OTHER,
        self::SERVICE_BACKBLAZE,
        self::SERVICE_AZURE,
    ];

    const S3_COMPATIBLE_SERVICES = [
        self::SERVICE_S3,
        self::SERVICE_GCS,
        self::SERVICE_DOSPACES,
        self::SERVICE_LINODE,
        self::SERVICE_WASABI,
        self::SERVICE_S3OTHER,
    ];

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

    // =Public Methods
    // =========================================================================

    /**
     * @todo Move camel-casing of attribute names into a behavior Class
     * @body Both `StorageSettings` and `StorageCredentials` models automatically 
     *  camel-case snake-cased attribute names
     */

    /**
     * @inheritdoc
     */

    public function canSetProperty( $name, $checkVars = true, $checkBehaviors = true )
    {
        if (strpos($name, '_') !== false) {
            $name = StringHelper::camelCase($name);
        }

        return parent::canSetProperty($name, $checkVars, $checkBehaviors);
    }

    /**
     * @inheritdoc
     */

    public function canGetProperty( $name )
    {
        if (strpos($name, '_') !== false) {
            $name = StringHelper::camelCase($name);
        }

        return parent::canGetProperty($name, $checkVars, $checkBehaviors);
    }

    /**
     * @inheritdoc
     */

    public function setProperty( $name, $value )
    {
        if (strpos($name, '_') !== false) {
            $name = StringHelper::camelCase($name);
        }

        return parent::setProperty($name, $value);
    }

    /**
     * @inheritdoc
     */

    public function getProperty( $name, $value )
    {
        if (strpos($name, '_') !== false) {
            $name = StringHelper::camelCase($name);
        }

        return parent::getProperty($name, $value);
    }

    // =Computed
    // -------------------------------------------------------------------------

    /**
     * Setter method for normalized `credentials` property
     * 
     * @param array|StorageCredentials|null $credentials
     */

    public function setCredentials( $credentials = null )
    {
        $this->_credentials = $credentials;
    }

    /**
     * Getter method for normalized `credentials` property
     * 
     * @return StorageCredentials|null
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
            $this->_credentials = new StorageCredentials($credentials);
        }

        return $this->_credentials;
    }

    // =Aliases
    // -------------------------------------------------------------------------

    /**
     * Setter method for the `bucketId` property alias (maps to `bucket`)
     * 
     * @param string|null $bucketId
     */

    public function setBucketId( string $bucketId = null )
    {
        $this->bucket = $bucketId;
    }

    /**
     * Getter method for the `bucketId` property alias (maps to `bucket`)
     * 
     * @return string|null
     */

    public function getBucketId( string $bucketId = null )
    {
        return $bucketId;
    }

    /**
     * Setter method for the `container` property alias (maps to `bucket`)
     * 
     * @param string|null $container
     */

    public function setContainer( string $container = null )
    {
        $this->bucket = $container;;
    }

    /**
     * Getter method for the `bucketId` property alias (maps to `bucket`)
     * 
     * @return string|null
     */

    public function getContainer()
    {
        return $this->bucket;
    }

    // =Validation
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function rules()
    {
        $rules = parent::rules();

        // =required attributes
        // no service? only `url` property is required
        if (empty($this->service)) {
            $rules['attrRequired'] = [ 'url', 'required' ];
        }

        else if ($this->service != 'coconut')
        {
            $rules['attrRequired'] = [ ['service', 'credentials', 'bucket'], 'required' ];
            $rules['serviceSupported'] = [ 'service', 'in', 'range' => self::SUPPORTED_SERVICES ];

            if (in_array($this->service, self::S3_COMPATIBLE_SERVICES))
            {
                if ($this->service != self::SERVICE_GCS
                    && $this->service != self::SERVICE_DOSPACES
                    && $this->service != self::SERVICE_S3OTHER
                ) {
                    $rules['regionRequired'] = [ 'region', 'required' ];
                }

                if ($this->service == self::SERVICE_S3OTHER) {
                    $rules['endpointRequired'] = [ 'endpoint', 'required' ];
                }
            }
        }

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
     * @param  InlineValidator $validator
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

    public function fields()
    {
        // no service? use HTTP, (S)FTP protocol with `url` only
        if (empty($this->service)) {
            return [ 'url' ];
        }

        // =common
        $fields = [
            'service',
            'credentials',
            'path',
        ];

        switch ($this->service)
        {
            // =s3 & s3-compatible
            case 's3':
            case 'gcs':
            case 'dospaces':
            case 'linode':
            case 'wasabi':
            case 's3other':
                $fields[] = 'bucket';
                $fields[] = 'acl';
            case 's3':
            case 'dospaces':
            case 'linode':
            case 'wasabi':
            case 's3other':
                $fields[] = 'region';
            case 's3':
                $fields[] = 'expires';
                $fields[] = 'cache_control';
                break;
            case 's3other':
                $fields[] = 'endpoint';
                $fieldS[] = 'force_path_style';
                break;
            // =rackspace
            // =azure
            case 'rackspace':
            case 'azure':
                $fields[] = 'container';
                break;
            // =backblaze
            case 'backblaze':
                $fields[] = 'bucket_id';
                break;
        }

        return $fields;
    }
}