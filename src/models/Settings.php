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

use yii\base\InvalidConfigException;
use yii\validators\InlineValidator;

use Craft;
use craft\base\FsInterface;
use craft\base\Model;
use craft\models\Volume;
use craft\validators\HandleValidator;
use craft\fs\Local as LocalFs;
use craft\helpers\StringHelper;
use craft\helpers\ArrayHelper;
use craft\helpers\App as AppHelper;
use craft\helpers\Component as ComponentHelper;

use yoannisj\coconut\models\Job;
use yoannisj\coconut\models\Storage;
use yoannisj\coconut\helpers\JobHelper;

/**
 * Model containing and validating Coconut plugin settings
 *
 * @property string $apiKey
 * @property string $endpoint
 * @property string $region
 * @property string $publicBaseUrl
 * @property Storage[] $storages
 * @property Storage|null $defaultStorage
 * @property Volume|null $defaultUploadVolume
 * @property Notification $defaultJobNotification
 * @property Job[] $jobs
 * @property Job[] $volumeJobs
 * @property Volume[] $watchVolumes
 */
class Settings extends Model
{
    // =Properties
    // =========================================================================

    /**
     * The API key of the Coconut.co account used to convert videos.
     *
     * If this is not set, the plugin will check for an environment variable
     * named `COCONUT_API_KEY` (using `\craft\helper\App::env()`).
     *
     * @var string|null
     */
    private ?string $_apiKey = null;

    /**
     * The endpoint to use for Coconut API calls.
     *
     * @note: This will override the `region` setting.
     * @note: Coconut API Keys are bound to the Endpoint/Region, so if you change
     *  this you probably want to change the `apiKey` setting as well
     *
     * @var string|null
     */
    private ?string $_endpoint = null;

    /**
     * The region of the Coconut.co cloud infrastructure to use
     *  for Coconut API calls.
     *
     * @note: This will have no effect if the `endpoint` setting is also set.
     * @note: Coconut API Keys are bound to the Endpoint/Region, so if you change
     *  this you probably want to change the `apiKey` setting as well
     *
     * @var string|null
     */
    private ?string $_region = null;

    /**
     * Public URL to use as *base* for all URLs sent to the Coconut API
     * (i.e. local asset URLs and notification webhooks)
     *
     * @var string
     */
    private ?string $_publicBaseUrl;

    /**
     * Named storage settings to use in Coconut transcoding jobs.
     *
     * Each key defines a named storage, and its value should be an array of
     * storage settings as defined here: https://docs.coconut.co/jobs/storage
     *
     * @note For HTTP uploads, Coconut will give the outputs a URL based on the
     * upload URL by appending the output file path. This means that the same
     * URL needs to function for both uploading the asset file (POST) and serving
     * the outptut file (GET).
     * To achieve this , the Coconut plugin for Craft registers a custom route
     * `/coconut/outputs/<volume-handle>/<output-path>` which maps to:
     * - the 'coconut/jobs/upload' action for POST requests (saves file in volume)
     * - the 'coconut/jobs/output' action for GET requests (serves file from volume)
     *
     * @var Storage[]
     *
     * @example [
     *      'myS3Bucket' => [
     *          'service' => 's3',
     *          'region' => 'us-east-1',
     *          'bucket' => 'mybucket',
     *          'path' = '/coconut/outputs',
     *          'credentials' => [
     *              'access_key_id' => '...',
     *              'secret_access_key' = '...',
     *          ]
     *      ],
     *      'httpUpload' => [
     *          'url' => 'https://remote.server.com/coconut/upload',
     *      ],
     * ]
     */
    private array $_storages = [];

    /**
     * The storage name or settings used to store Coconut output files when none
     * is given in transcoding job parameters.
     *
     * This can be set to a string which must be either a key from the `storages`
     * setting, or a volume handle.
     *
     * If this is set to `null`, the plugin will try to generate storage settings
     * based on the input asset's volume, or fallback to use the HTTP upload method
     * to store files in the volume defined by the 'defaultUploadVolume' setting.
     *
     * @var string|array|Storage|null
     */
    private mixed $_defaultStorage = null;

    /**
     * @var bool
     */
    protected bool $isNormalizedDefaultStorage = false;

    /**
     * The default volume used to store output files when the `storage` parameter
     * was omitted and the input asset's volume could be determined (.e.g. if the
     * `input` parameter was a URL and not a Craft asset).
     *
     * @var string|Volume
     */
    private string|Volume $_defaultUploadVolume = 'coconut';

    /**
     * Whether `jobs` property was normalized or not (internal flag)
     *
     * @var bool
     */
    protected bool $isNormalizedDefaultUploadVolume = false;

    /**
     * Format used to generate default path for output files
     * saved in storages.
     *
     * Supports the following placeholder strings:
     * - '{path}' the input folder path, relative to the volume base path (asset input),
     *      or the URL path (external URL input)
     * - '{filename}' the input filename (without extension)
     * - '{hash}' a unique md5 hash based on the input URL
     * - '{shortHash}' a shortened version of the unique md5 hash
     * - '{key}' the output `key` parameter (a path-friendly version of it)
     * - '{ext}' the output file extension
     *
     * @tip To prevent outputs which are saved in asset volumes to end up in
     * Craft's asset indexes, the path will be prefixed with an '_' character
     * (if it is not already).
     *
     * @var string
     */
    public string $defaultOutputPathFormat = '_coconut/{path}/{filename}--{key}.{ext}';

    /**
     * Notification param to use if job notifications are enabled but job's own
     * notification param is not set.
     *
     * @Note: it is recommended not to change this setting
     *
     * @var string|array|Notification|null
     *
     * @default Notification settings for plugin's 'coconut/jobs/notify' controller action
     */
    private mixed $_defaultJobNotification = null;

    /**
     * Named coconut job settings.
     *
     * Each key defines a named job, and its value should be an array setting
     * the 'storage' and 'outputs' parameters.
     *
     * The 'storage' parameter can be a string, which will be matched against
     * one of the named storages defined in the `storages` setting, or a
     * volume handle.
     *
     * If the 'storage' parameter is omitted, the plugin will try to generate
     * storage settings for the input asset's volume, or fallback to use the
     * HTTP upload method to store files in the volume defined by the
     * `defaultUploadVolume` setting.
     *
     * The 'outputs' parameter can have indexed string items, in which case
     * the string will be used as the output’s `format` parameter, and the
     * output’s `path` parameter will be generated based on the
     * `defaultOutputPathFormat` setting.
     *
     * @tip To prevent outputs which are saved in asset volumes to end up in
     * Craft's asset indexes, their path parameter will be prefixed with an '_'
     * character (if it is not already).
     *
     * The 'input' and 'notification' parameters are not supported, as the plugin will
     * set those programatically.
     *
     * @var array
     *
     * @example [
     *      'videoSources' => [
     *          'storage' => 'coconut', // assuming there is a volume with handle 'coconut'
     *          'outputs' => [
     *              'webm', // will generate the output's `path` parameter based on `defaultOutputPathFormat`
     *              'mp4:360p',
     *              'mp4:720p',
     *              'mp4:1080p::quality=4' => [
     *                  'key' => 'mp4:1080p',
     *                  'if' => "{{ input.width }} >= 1920
     *              ]
     *          ],
     *      ],
     * ]
     *
     * @default []
     */
    private array $_jobs = [];

    /**
     * Whether `jobs` property was normalized or not (internal flag)
     *
     * @var bool
     */
    protected bool $isNormalizedJobs = false;

    /**
     * Sets default job parameters for craft assets in given volumes.
     *
     * Each key should match the handle of a craft volume, and the its value should
     * be either a key from the `jobs` setting, or an array of parameters (in the
     * same format as the `jobs` setting).
     *
     * @var array
     */
    private array $_volumeJobs = [];

    /**
     * Whether volumeJobs property was normalized or not (internal flag)
     *
     * @var bool
     */
    protected bool $isNormalizedVolumeJobs = false;

    /**
     * List of input volumes handles, for which the plugin should
     *  automatically create a Coconut conversion job every time a video asset is
     *  added or updated.
     *
     * @var string[]|Volume[]
     */
    public array $watchVolumes = [];

    // @todo: add `fieldJobs` and `watchFields` settings to automatically
    // transcode video Assets in specific fields when saving a Craft element.

    /**
     * Depending on your Coconut plan and the parameters you are using to transcode
     * your video, jobs can take a long time. To avoid jobs to fail with a timeout
     * error, this plugin sets a high `Time to Reserve` on the jobs it pushes to
     * Craft's queue.
     *
     * More info:
     *  https://craftcms.stackexchange.com/questions/25437/queue-exec-time/25452
     *
     * @var int
     */
    public int $transcodeJobTtr = 900;

    // =Public Methods
    // =========================================================================

    // =Properties
    // -------------------------------------------------------------------------

    /**
     * Setter method for dfaulted `apiKey` property
     *
     * @param string|null $apiKey
     *
     * @return static Back-reference for method chaining
     */
    public function setApiKey( string|null $apiKey ): static
    {
        $this->_apiKey = $apiKey;
        return $this;
    }

    /**
     * Getter method for defaulted `apiKey` property
     *
     * @return string
     *
     * @throws Error if coconut API key could not be determined
     */
    public function getApiKey(): string
    {
        if (!isset($this->_apiKey)) {
            $this->_apiKey = AppHelper::env('COCONUT_API_KEY') ?: null;
        }

        if (empty($this->_apiKey)) {
            throw new InvalidConfigException("Missing required `apiKey` setting");
        }

        return $this->_apiKey;
    }

    /**
     * Setter method for defaulted `endpoint` property
     *
     * @param string|null $endpoint
     *
     * @return static Back-reference for method chaining
     */
    public function setEndpoint( string|null $endpoint ): static
    {
        $this->_endpoint = $endpoint;
        return $this;
    }

    /**
     * Getter method for defaulted `endpoint` property
     *
     * @return string|null
     */
    public function getEndpoint(): ?string
    {
        if (!isset($this->_endpoint)) {
            $this->_endpoint = AppHelper::env('COCONUT_ENDPOINT');
        }

        return empty($this->_endpoint) ? null : $this->_endpoint;
    }

    /**
     * Setter method for defaulted `region` property
     *
     * @param string|null $region
     *
     * @return static Back-reference for method chaining
     */
    public function setRegion( string|null $region ): static
    {
        $this->_region = $region;
        return $this;
    }

    /**
     * Getter method for defaulted `region` property
     *
     * @return string|null
     */
    public function getRegion(): ?string
    {
        if (!isset($this->_region)) {
            $this->_region = AppHelper::env('COCONUT_REGION');
        }

        return empty($this->_region) ? null : $this->_region;
    }

    /**
     * Setter method for parsed `publicBaseUrl` property
     *
     * @param string|null $url
     *
     * @return static Back-reference for method chaining
     */
    public function setPublicBaseUrl( string|null $url ): static
    {
        $this->_publicBaseUrl = $url;
        return $this;
    }

    /**
     * Getter method for parsed `publicBaseUrl` property
     *
     * @return string|null
     */
    public function getPublicBaseUrl(): ?string
    {
        if ($this->_publicBaseUrl) {
            return AppHelper::env($this->_publicBaseUrl);
        }

        return null;
    }

    /**
     * Setter method for normalized `storages` setting
     *
     * @param array $storages Map of names storages, where each key is a storage name
     *
     * @return static Back-reference for method chaining
     */
    public function setStorages( array $storages ): static
    {
        $this->_storages = [];

        foreach ($storages as $handle => $storage)
        {
            if (!is_string($handle))
            {
                throw new InvalidConfigException(
                    "Setting `storages` must be an associative array where keys are storage names");
            }

            if (is_array($storage)) {
                $storage = new Storage($storage);
            }

            else if (!$storage instanceof Storage)
            {
                throw new InvalidConfigException(
                    'Setting `storages` must resolve to a list of'.Storage::class.' models');
            }

            // make sure the storage handle is set
            if ($storage && !isset($storage->handle)) {
                $storage->handle = $handle;
            }

            $this->_storages[$handle] = $storage;
        }

        return $this;
    }

    /**
     * Getter method for normalized `storages` setting
     *
     * @return Storage[]
     */
    public function getStorages(): array
    {
        return $this->_storages;
    }

    /**
     * Setter method for normalized `defaultStorage` setting
     *
     * @param string|array|Storage $storage
     *
     * @return static Back-reference for method chaining
     */
    public function setDefaultStorage(
        string|array|Storage|null $storage
    ): static
    {
        $this->_defaultStorage = $storage;
        $this->isNormalizedDefaultStorage = false;

        return $this;
    }

    /**
     * Getter method for normalized `defaultStorage` setting
     *
     * @return Storage|null
     */
    public function getDefaultStorage(): ?Storage
    {
        if (!$this->isNormalizedDefaultStorage)
        {
            $storage = $this->_defaultStorage;
            if ($storage)
            {
                if (is_string($storage)) {
                    $storage = AppHelper::parseEnv($storage);
                }

                $storage = JobHelper::resolveStorage($storage);
            }

            $this->_defaultStorage = $storage;
            $this->isNormalizedDefaultStorage = true;
        }

        return $this->_defaultStorage;
    }

    /**
     * Setter method for normalized `defaultUploadVolume` property
     *
     * @param string|array|Volume|unll $volume
     *
     * @return static Back-reference for method chaining
     */
    public function setDefaultUploadVolume(
        string|array|Volume|null $volume
    ): static
    {
        $this->_defaultUploadVolume = $volume;
        $this->isNormalizedDefaultUploadVolume = false;

        return $this;
    }

    /**
     * Getter method for defaulted and normalized `defaultUploadVolume` property
     *
     * @param bool $createMissing Whether to create the volume if it does not exist
     *
     * @return Volume|null
     */
    public function getDefaultUploadVolume( $createMissing = false ): ?Volume
    {
        if (!$this->isNormalizedDefaultUploadVolume)
        {
            $volume = $this->_defaultUploadVolume;

            if (!$volume) {
                $volume = null;
            }

            else if (is_array($volume)) {
                $volume = $this->getVolumeModel($volume, true);
            }

            else if (is_string($volume))
            {
                $volume = $this->getVolumeModel([
                    'handle' => $volume,
                ], true);
            }

            else if (!($volume instanceof Volume))
            {
                throw new InvalidConfigException(
                    "Setting `defaultUploadVolume` must resolve to an instance of ".Volume::class);
            }

            $this->_defaultUploadVolume = $volume;
            $this->isNormalizedDefaultUploadVolume = true;
        }

        return $this->_defaultUploadVolume;
    }

    /**
     * Setter method for defaulted 'defaultJobNotification' property
     *
     * @param string|array|Notification|null $notification
     *
     * @return static Back-reference for method chaining
     */
    public function setDefaultJobNotification(
        string|array|Notification|null $notification
    ): static
    {
        $this->_defaultJobNotification = $notification;

        return $this;
    }

    /**
     * Getter method for defaulted 'defaultJobNotification' property
     *
     * @return Notification
     */
    public function getDefaultJobNotification(): Notification
    {
        if (isset($this->_defaultJobNotification)) {
            return $this->_defaultJobNotification;
        }

        $notificationUrl = JobHelper::publicActionUrl(
            'coconut/jobs/notify', null, null, true);

        $environment = \craft\helpers\App::env('CRAFT_ENVIRONMENT');
        Craft::error('['.$environment.'] '.$notificationUrl, 'coconut-debug');

        // $baseCpUrl = UrlHelper::baseCpUrl();
        // $baseSiteUrl = UrlHelper::baseSiteUrl();
        // $publicBaseUrl = rtrim($this->getPublicBaseUrl(), '/').'/';

        // $notificationUrl = str_replace(
        //     [ $baseCpUrl, $baseSiteUrl, ],
        //     $publicBaseUrl,
        //     $notificationUrl
        // );

        return new Notification([
            'type' => 'http',
            'url' => $notificationUrl,
            'params' => [],
            'events'=> true,
            'metadata'=> true,
        ]);
    }

    /**
     * Setter method for normalized `jobs` setting
     *
     * @param array Map of named jobs where each key is a job name
     *
     * @return static Back-reference for method chaining
     */
    public function setJobs( array $jobs ): static
    {
        $this->_jobs = $jobs;
        $this->isNormalizedJobs = false;

        return $this;
    }

    /**
     * Getter method for normalized `jobs` setting
     *
     * @return Job[]
     */
    public function getJobs(): array
    {
        if (!$this->isNormalizedJobs)
        {
            foreach ($this->_jobs as $handle => $job)
            {
                if (!is_string($handle))
                {
                    throw new InvalidConfigException(
                        'Setting `jobs` must be an array where each key is a job name.');
                }

                if (is_array($job)) {
                    $job = Craft::configure(new Job(), $job);
                }

                else if (!$job instanceof Job)
                {
                    throw new InvalidConfigException(
                        'Setting `jobs` must resolve to a list of `'.Job::class.'` models');
                }

                if ($job && !isset($job->handle)) {
                    $job->handle = $handle;
                }

                $this->_jobs[$handle] = $job;
            }

            $this->isNormalizedJobs = true;
        }

        return $this->_jobs;
    }

    /**
     * Setter method for normalized `volumeJobs` setting
     *
     * @param array Map of volume jobs, where each key is a volume handle
     *
     * @return static Back-reference for method chaining
     */
    public function setVolumeJobs( array $jobs ): static
    {
        $this->_volumeJobs = $jobs;
        $this->isNormalizedVolumeJobs = false;

        return $this;
    }

    /**
     * Getter method for normalized `volumeJobs` setting
     *
     * @return Job[]
     */
    public function getVolumeJobs(): array
    {
        if (!$this->isNormalizedVolumeJobs)
        {
            foreach ($this->_volumeJobs as $handle => $job)
            {
                if (!is_string($handle))
                {
                    throw new InvalidConfigException(
                        "Setting `volumeJobs` must be an associative array"
                        ." where each key is a volume handle");
                }

                if (is_string($job))
                {
                    $jobs = $this->getJobs();

                    if (!array_key_exists($job, $jobs))
                    {
                        throw new InvalidConfigException(
                            "Could not find job named '$job'.");
                    }

                    $job = $jobs[$job];
                }

                if (is_array($job)) {
                    $job = new Job($job);
                }

                else if (!$job instanceof Job)
                {
                    throw new InvalidConfigException(
                        'Setting `volumeJobs` must resolve to a list of `'.Job::class.'` models');
                }

                $this->_volumeJobs[$handle] = $job;
            }

            $this->isNormalizedVolumeJobs = true;
        }

        return $this->_volumeJobs;
    }

    // =Attributes
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function attributes()
    {
        $attributes = parent::attributes();

        $attributes[] = 'apiKey';
        $attributes[] = 'publicBaseUrl';
        $attributes[] = 'storages';
        $attributes[] = 'defaultStorage';
        $attributes[] = 'defaultUploadVolume';
        $attributes[] = 'defaultJobNotification';
        $attributes[] = 'jobs';
        $attributes[] = 'volumeJobs';

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

        $rules['attrsRequired'] = [ ['apiKey', 'defaultUploadVolume', 'defaultOutputPathFormat'], 'required' ];
        $rules['attrsString'] = [ ['apiKey', 'defaultOutputPathFormat'], 'string' ];

        // $rules['storagesStorageMap'] = [ ['storages'], 'validateStorageMap' ];
        // $rules['configsConfigMap'] = [ ['configs'], 'validateJobsMap' ];
        // $rules['volumeJobsConfigMap'] = [ ['volumeJobs'], 'validateJobsMap', 'registryAttribute' => 'configs' ];

        $rules['wathVolumesEach'] = [ 'watchVolumes', 'each', 'rule' => HandleValidator::class ];

        return $rules;
    }

    /**
     * Validation method for maps of storage parameters
     *
     * @param string $attribute Attribute to validate
     * @param array $params Validation params
     * @param InlindeValidator $validator Yii validator class
     *
     * @return void
     */
    public function validateStorageMap(
        string $attribute,
        array $params,
        InlineValidator $validator
    ): void
    {
        $storages = $this->$attribute;
        $registryAttribute = $params['registryAttribute'] ?? null;

        if (!is_array($storages) && !ArrayHelper::isAssociative($storages))
        {
            $validator->addError($this, $attribute,
                "{attribute} must be an array mapping storage names to Coconut storage parameters");
            return; // no need to continue validation
        }

        foreach ($storages as $key => $storage)
        {
            if (is_string($storage) && $registryAttribute)
            {
                $name = $storage;
                $storage = $this->$registryAttribute[$name] ?? null;

                if (!$storage)
                {
                    $label = $this->getAttributeLabel($registryAttribute);
                    $validator->addError($this, $attribute,
                        "Could not find {attribute}'s named config '$name' in '$label'");
                    return; // no need to continue validation
                }
            }

            if (is_array($storage) && ArrayHelper::isAssociative($storage))
            {
                if (!array_key_exists('class', $storage)) $storage['class'] = Storage::class;
                $storage = Craft::createObject($storage);
            }

            if (!$storage instanceof Storage)
            {
                $class = Storage::class;
                $validator->addError($this, $attribute,
                    "Storage with key '$key' in {attribute} must resolve to a $class model");
                return; // no need to continue validation
            }

            else if (!$storage->validate())
            {
                $validator->addError($this, $attribute,
                    "Invalid storage with key '$key' in {attribute}");
            }
        }
    }

    /**
     * Validation method for maps of job parameters.
     *
     * @param string $attribute Attribute to validate
     * @param array $params Validation params
     * @param InlindeValidator $validator Yii validator class
     *
     * @return void
     */
    public function validateJobsMap(
        string $attribute,
        array $params = [],
        InlineValidator $validator
    ): void
    {
        $jobs = $this->$attribute;
        $registryAttribute = $params['registryAttribute'] ?? null;

        if (!is_array($jobs) || !ArrayHelper::isAssociative($jobs))
        {
            $validator->addError($this, $attribute,
                '{attribute} must be an array mapping volume handles to Coconut job parameters');
            return; // no need to continue validation
        }

        foreach ($jobs as $key => $job)
        {
            if (is_string($job) && $registryAttribute)
            {
                $name = $job;
                $job = $this->$registryAttribute[$name] ?? null;

                if (!$job)
                {
                    $label = $this->getAttributeLabel($registryAttribute);
                    $validator->addError($this, $attribute,
                        "Could not find {attribute}'s named job '$name' in '$label'");
                    return; // no need to continue validation
                }
            }

            if (is_array($job) && ArrayHelper::isAssociative($job))
            {
                if (!array_key_exists('class', $job)) $job['class'] = $job;
                $job = Craft::createObject($job);
            }

            if (!$job instanceof Job)
            {
                $validator->addError($this, $attribute,
                    "Job with key '$key' in {attribute} must resolve to a `".Job::class."` instance");
                return; // no need to continue validation
            }

            if (!$job->validate())
            {
                $validator->addError($this, $attribute,
                    "Invalid job with key '$key' in {attribute}");
            }
        }
    }

    // =Protected Methods
    // =========================================================================

    /**
     * Gets Volume model based on given config settings.
     *
     * @param array $config Volume configuration settings
     * @param bool $createMissing Whether to create volume if it is missing
     *
     * @return Volume|null
     *
     * @throws InvalidConfigArgument If volume's 'handle' is not a string
     */
    protected function getVolumeModel(
        array $config = [],
        bool $createMissing = false
    ): ?Volume
    {
        $config = ComponentHelper::mergeSettings($config);
        $handle = $config['handle'] ?? 'coconut';

        if (!is_string($handle)) {
            throw new InvalidConfigException(
                "Volume's 'handle' setting must be a string");
        }

        $craftVolumes = Craft::$app->getVolumes();
        $volume = $craftVolumes->getVolumeByHandle($handle);

        if ($volume || !$createMissing) {
            return $volume;
        }

        // create missing volume, and its file-system(s) if they are missing too
        $fsConfig = $config['fs'] ?? [];
        if (is_string($fsConfig)) $fsConfig = [ 'handle' => $fsConfig ];
        $fsHandle = $config['fsHandle'] ?? $fsConfig['handle'] ?? 'coconutFiles';

        $fs = $this->getFsModel($fsConfig, $fsHandle, true);

        if (!$fs) {
            throw new InvalidConfigException(
                "Could not find or create Volume's file-system model");
        }

        $config['fs'] = $fs->handle;
        ArrayHelper::remove($config, 'fsHandle');

        $transformFsConfig = $config['transformFs'] ?? [];
        if (is_string($transformFsConfig)) $transformFsConfig = [ 'handle' => $transformFsConfig ];
        $transformFsHandle = $config['transformFsHandle'] ?? $transformFsConfig['handle'] ?? null;

        if ($transformFsHandle)
        {
            $transformFs = $this->getFsModel($transformFsConfig, $transformFsHandle, true);

            $config['transformFs'] = $transformFs->handle;
            ArrayHelper::remove($config, 'transformFsHandle');
        }

        $config['handle'] = $handle;
        $name = $config['name'] ?? implode(' ', StringHelper::toWords($handle));
        $config['name'] = $name;

        $volume = $craftVolumes->createVolume($config);
        if ($craftVolumes->saveVolume($volume)) {
            return $volume;
        }

        return null;
    }

    /**
     * Gets Fs model based on given config settings.
     *
     * @param array $config Fs configuration settings
     * @param string|null $handle Handle of Fs to get or create
     * @param bool $createMissing Whether to create volume if it is missing
     *
     * @return FsInterface|null
     *
     * @throws InvalidConfigArgument If Fs's handle is not a string
     * @throws InvalidConfigArgument If Fs's handle could not be determined
     */
    protected function getFsModel(
        array $config,
        string|null $handle = null,
        bool $createMissing = true
    ): ?FsInterface
    {
        if (!$handle) {
            $handle = $config['handle'] ?? 'coconutFiles';
        }

        if (!$handle) {
            throw new InvalidConfigException(
                "Could not determine Volume's file-system handle");
        } else if (!is_string($handle)) {
            throw new InvalidConfigException(
                "Volume file-system's 'handle' setting must be a string");
        }

        if (!is_string($handle))

        $craftFilesystems = Craft::$app->getFs();
        $fs = $craftFilesystems->getFilesystemByHandle($handle);

        // filesystem with handle exists? use that!
        if ($fs) return $fs;

        $config['handle'] = $handle;

        $type = $config['type'] ?? $config['class'] ?? LocalFs::class;
        $name = $config['name'] ?? implode(' ', StringHelper::toWords($handle));

        $defaults = [
            'class' => $type,
            'name' => $name,
        ];

        if (is_a($type, LocalFs::class))
        {
            $slug = StringHelper::slugify(implode(' ', StringHelper::toWords($handle)));

            $defaults['hasUrls'] = true;
            $defaults['url'] = '@web/'.$slug;
            $defaults['path'] = '@webroot/'.$slug;
        }

        $config = array_merge($defaults, $config);

        $fs = $craftFilesystems->createFilesystem($config);
        if (!$craftFilesystems->saveFilesystem($fs)) {
            return null;
        }

        return $fs;
    }

    // =Private Methods
    // =========================================================================
}
