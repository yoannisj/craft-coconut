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

use DateTime;

use yii\base\InvalidConfigException;

use Craft;
use craft\base\Model;
use craft\elements\Asset;
use craft\validators\DateTimeValidator;
use craft\helpers\App as AppHelper;
use craft\helpers\ArrayHelper;
use craft\helpers\Json as JsonHelper;

use craft\awss3\Fs as AwsS3Fs;
use fortrabbit\ObjectStorage\Fs as FortrabbitFs;

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

    /**
     * @var string
     */
    const SCENARIO_DEFAULT = 'default';

    /**
     * @var string
     */
    const SCENARIO_CONFIG = 'config';

    /**
     * @var string
     */
    const STATUS_STARTING = 'input.starting';

    /**
     * @var string
     */
    const STATUS_TRANSFERRING = 'input.transferring';

    /**
     * @var string
     */
    const STATUS_TRANSFERRED = 'input.transferred';

    /**
     * @var string
     */
    const STATUS_FAILED = 'input.failed';

    // =Properties
    // =========================================================================

    /**
     * Input ID in Craft database
     *
     * @var int|null
     */
    public ?int $id = null;

    /**
     * ID of input's Craft Asset element
     * @var int|null
     */
    public ?int $assetId = null;

    /**
     * The input Craft Asset element model
     *
     * @var Asset
     */
    private ?Asset $_asset = null;

    /**
     * URL to input file
     *
     * @var string|null
     */
    private ?string $_url = null;

    /**
     * Hash of input file URL (used for database indexes)
     *
     * @var string|null
     */
    private ?string $_urlHash = null;

    /**
     * Latest input status from Coconut job
     * @see https://docs.coconut.co/jobs/api#job-status
     *
     * @var string|null
     */
    public ?string $status = null;

    /**
     * Progress (in percentage) of the input handling by Coconut
     *
     * @var string
     */
    public string $progress = '0%';

    /**
     * Metadata retrieved from Coconut API
     *
     * @var array|null
     */
    private ?array $_metadata = null;

    /**
     * Date at which the input expires (e.g. HTTP cache-expiry for external inputs)
     *
     * @var Datetime|null
     */
    public ?DateTime $expires = null;

    /**
     * Error message associated with this input.
     * This is only relevant if input has failed `status`
     *
     * @var string|null
     */
    public ?string $error = null;

    // =Public Methods
    // =========================================================================

    // =Properties
    // -------------------------------------------------------------------------

    /**
     * Getter method for readonly `name` property.
     *
     * @return string
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
     * Setter method for the resolved `asset` property.
     *
     * @param Asset|null $asset
     *
     * @return static Back-reference for method chaining
     */
    public function setAsset( Asset|null $asset ): static
    {
        $this->assetId = $asset ? $asset->id : null;
        $this->_asset = $asset;

        return $this;
    }

    /**
     * Getter method for the resolved `asset` property.
     *
     * @return Asset|null
     */
    public function getAsset(): ?Asset
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
     * Setter for normalizd `url` property.
     *
     * @param string|null $url
     *
     * @return static Back-reference for method chaining
     */
    public function setUrl( string|null $url ): static
    {
        if ($url !== $this->getUrl())
        {
            $this->_url = $url;
            $this->_urlHash = null;
        }

        return $this;
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
     *
     * @return static Back-reference for method chaining
     */
    public function setMetadata( string|array|null $metadata ): static
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

        return $this;
    }

    /**
     * Getter merhod for the normalized `metadata` property
     *
     * @return array|null
     */
    public function getMetadata(): ?array
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
    public function defineRules(): array
    {
        $rules = parent::defineRules();

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
    public function fields(): array
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
    public function extraFields(): array
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
            $fs = $asset->getVolume()->getFs();
            $assetPath = $asset->path;

            if ($fs instanceof AwsS3Fs)
            {
                // prefix asset path with volume subfolder
                if ($fs->addSubfolderToRootUrl && $fs->subfolder
                    && ($subfolder = rtrim(AppHelper::parseEnv($fs->subfolder), '/')) !== '')
                {
                    $assetPath = $subfolder.'/'.ltrim($assetPath, '/');
                }

                return [
                    'service' => 's3',
                    'region' => AppHelper::parseEnv($fs->region),
                    'credentials' => [
                        'access_key_id' => AppHelper::parseEnv($fs->keyId),
                        'secret_access_key' => AppHelper::parseEnv($fs->secret),
                    ],
                    'bucket' => AppHelper::parseEnv($fs->bucket),
                    'key' => $assetPath,
                ];
            }

            if ($fs instanceof FortrabbitFs)
            {
                if ($fs->subfolder
                    && ($subfolder = rtrim(AppHelper::parseEnv($fs->subfolder), '/')) !== '')
                {
                    $assetPath = $subfolder.'/'.ltrim($assetPath, '/');
                }

                // get full https endpoint
                $endpoint = AppHelper::parseEnv($fs->endpoint);
                if (!str_contains($endpoint, 'https')) {
                    $endpoint = 'https://' .  $endpoint;
                }

                return [
                    'service' => 's3other',
                    'endpoint' => $endpoint,
                    'region' => AppHelper::parseEnv($fs->region),
                    'credentials' => [
                        'access_key_id' => AppHelper::parseEnv($fs->keyId),
                        'secret_access_key' => AppHelper::parseEnv($fs->secret),
                    ],
                    'bucket' => AppHelper::parseEnv($fs->bucket),
                    'key' => $assetPath,
                ];
            }
        }

        return [
            'url' => JobHelper::publicUrl($this->getUrl()),
        ];
    }
}
