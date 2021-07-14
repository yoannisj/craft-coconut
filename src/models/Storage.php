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
