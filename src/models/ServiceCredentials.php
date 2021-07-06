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

/**
 * Model representing and validating settings for Cococnut storage method
 * 
 * @property ServiceCredentials $credentials Credentials used for storage service
 * @property string $bucket_id Alias for 'bucket' property
 * @property string $container Alias for 'bucket' property
 * 
 */

class ServiceCredentials extends Model
{
    // =Static
    // =========================================================================

    // =Properties
    // =========================================================================

    /**
     * @var string Name of the service these credentials are for
     */

    public $service;

    /**
     * @var string Id or name of service account (required by some services)
     */

    public $accountId;

    /**
     * @var string Key Id or API key used to connect to the service
     */

    public $keyId;

    /**
     * @var string Secret key used to connect ot the service
     */

    public $secretKey;

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
            'camelCasePropertyAliases' => true, // e.g. `$this->app_key_id => $this->appKeyId`
            'propertyAliases' => [
                // e.g. `$this->account => $this->accountId`
                'accountId' => [ 'account', 'username' ],
                'keyId' => [ 'accessKeyId', 'appKeyId', 'apiKey' ],
                'secretKey' => [ 'secretAccessKey', 'appKey' ],
            ],
        ];

        return $behaviors;
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

        if (in_array($this->service, Coconut::S3_COMPATIBLE_SERVICES)) {
            $requiredAttr[] = 'accessKeyId'; // => `keyId` alias
            $requiredAttr[] = 'secretAccessKey'; // => `secretKey` alias
        }

        else if ($this->service == Coconut::SERVICE_BACKBLAZE) {
            $requiredAttr[] = 'accountId';
            $requiredAttr[] = 'appKeyId'; // => `keyId` alias
            $requiredAttr[] = 'appKey'; // => `secretKey` alias
        }

        else if ($this->service == Coconut::SERVICE_RACKSPACE) {
            $requiredAttr[] = 'username'; // => `accountId` alias
            $requiredAttr[] = 'apiKey'; // => `keyId` alias
        }

        else if ($this->service == Coconut::SERVICE_AZURE) {
            $requiredAttr[] = 'account'; // => `accountId` alias
            $requiredAttr[] = 'apiKey'; // => `keyId` alias
        }

        $rules['attrRequired'] = [ $requiredAttr, 'required' ];

        // =format attributes
        $rules['attrString'] = [ [
            'service',
            'accountId',
            'keyId',
            'secretKey',
        ], 'string' ];

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

        if (in_array($this->service, Coconut::S3_COMPATIBLE_SERVICES)) {
            $fields[] = 'access_key_id'; // `keyId` alias
            $fields[] = 'secret_access_key'; // `secretKey` alias
        }

        else if ($this->service == Coconut::SERVICE_BACKBLAZE) {
            $fields[] = 'account_id'; // `accountId` alias
            $fields[] = 'app_key_id'; // `keyId` alias
            $fields[] = 'app_key'; // `secretKey` alias
        }

        else if ($this->service == Coconut::SERVICE_RACKSPACE) {
            $fields[] = 'username'; // `accountId` alias
            $fields[] = 'api_key'; // `keyId` alias
        }

        else if ($this->service == Coconut::SERVICE_AZURE) {
            $fields[] = 'account'; // `accountId` alias
            $fields[] = 'api_key'; // `keyId` alias
        }

        return $fields;
    }

    // =Protected Methods
    // =========================================================================
}