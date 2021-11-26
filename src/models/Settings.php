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
use craft\base\VolumeInterface;
use craft\base\Model;
use craft\validators\HandleValidator;
use craft\volumes\Local as LocalVolume;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\helpers\App as AppHelper;
use craft\helpers\Component as ComponentHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Job;
use yoannisj\coconut\models\Storage;

/**
 * Model representing and validation Coconut plugin settings
 *
 * @property string $apiKey
 * @property Storage[] $storages
 * @property Storage|null $defaultStorage
 * @property VolumeInterface|null $defaultUploadVolume
 * @property Job[] $jobs
 * @property Job[] $volumeJobs
 */

class Settings extends Model
{
    // =Properties
    // =========================================================================

    /**
     * @var string The API key of the Coconut.co account used to convert videos.
     *
     * If this is not set, the plugin will check for an environment variable
     * named `COCONUT_API_KEY` (using `\craft\helper\App::env()`).
     *
     * @default null
     */

    private $_apiKey = null;

    /**
     * @var string|null The endpoint to use for Coconut API calls.
     *
     * @note: This will override the `region` setting.
     * @note: Coconut API Keys are bound to the Endpoint/Region, so if you change
     *  this you probably want to change the `apiKey` setting as well
     */

    private $_endpoint = null;

    /**
     * @var string|null The region of the Coconut.co cloud infrastructure to use
     *  for Coconut API calls.
     *
     * @note: This will have no effect if the `endpoint` setting is also set.
     * @note: Coconut API Keys are bound to the Endpoint/Region, so if you change
     *  this you probably want to change the `apiKey` setting as well
     */

    private $_region = null;

    /**
     * @var string Public URL to use as *base* for all URLs sent to the Coconut API
     * (i.e. local asset URLs and notification webhooks)
     */

    private $_publicBaseUrl;

    /**
     * @var integer
     *
     * Depending on your Coconut plan and the parameters you are using to transcode
     * your video, jobs can take a long time. To avoid jobs to fail with a timeout
     * error, this plugin sets a high `Time to Reserve` on the jobs it pushes to
     * Craft's queue.
     *
     * More info:
     *  https://craftcms.stackexchange.com/questions/25437/queue-exec-time/25452
     *
     * @default 900
     */

    public $transcodeJobTtr = 900;

    /**
     * @var array Named storage settings to use in Coconut transcoding jobs.
     *
     * Each key defines a named storage, and its value should be an array of
     * storage settings as defined here: https://docs.coconut.co/jobs/storage
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
     *
     * @default []
     */

    private $_storages = [];

    /**
     * @var string|array|\yoannisj\coconut\models\Storage
     *
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
     * @default null
     */

    private $_defaultStorage = null;

    /**
     * @var bool
     */

    protected $isNormalizedDefaultStorage;

    /**
     * @var string|\craft\models\Volume
     *
     * The default volume used to store output files when the `storage` parameter
     * was omitted and no asset volume could be determined (.e.g. if the `input`
     * parameter was a URL and not a Craft asset).
     *
     * @default 'coconut'
     */

    private $_defaultUploadVolume = 'coconut';

    /**
     * @var boolean
     */

    protected $isNormalizedDefaultUploadVolume;

    /**
     * @var string Format used to generate default path to output files in storages.
     *
     * Supports the following placeholder strings:
     * - '{path}' the input folder path, relative to the volume base path (asset input),
     *      or the URL path (external URL input)
     * - '{filename}' the input filename (without extension)
     * - '{hash}' a unique md5 hash based on the input URL
     * - '{shortHash}' a shortened version of the unique md5 hash
     * - '{key}' the outputs `key` parameter (a path-friendly version of it)
     * - '{ext}' the output file extension
     *
     * Note: to prevent outputs saved in asset volumes to end up in Craft's asset indexes,
     * the path will be prefixed with an '_' (if it is not already).
     *
     * @default '_coconut/{path}/{key}.{ext}'
     */

    public $defaultOutputPathFormat = '_coconut/{path}/{filename}--{key}.{ext}';

    /**
     * @var string|array|Notification|null Notification param to use if job
     * notifications are enabled but job's own notification param is not set.
     *
     * @Note: it is recommended not to change this setting
     *
     * @default Notification for plugin's 'coconut/jobs/notify' controller action
     */

    private $_defaultJobNotification = null;

    /**
     * @var array Named coconut job settings.
     *
     * Each key defines a named job, and its value should be an array setting
     * the 'storage' and 'outputs' parameters.
     *
     * The 'storage' parameter can be a string, which will be matched against
     * one of the named storages defined, or a volume handle.
     *
     * If the 'storage' parameter is omitted, to plugin wil try to generate store
     * settings for the input asset's volume, or fallback to use the HTTP upload method
     * to store files in the volume defined by the 'defaultUploadVolume' setting.
     *
     * The 'outputs' parameter can have indexed string items, in which case the string
     * will be used as `format` parameter, and the `path` parameter will be generated
     * based on the `defaultOutputPathFormat` setting.
     *
     * Note: to prevent outputs saved in asset volumes to end up in Craft's asset indexes,
     * their `path` parameter will be prefixed with an '_' (if it is not already).
     * This can be disabled if the storage is not a volume by adding the custom
     * 'isVolumeStorage' parameter, although it is not recommended.
     *
     * The 'input' and 'notification' parameters are not supported, as the plugin will
     * set those programatically.
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

    private $_jobs = [];

    /**
     * @var bool Whether `jobs` property was normalized or not (internal flag)
     */

    protected $isNormalizedJobs;

    /**
     * @var array Sets default job parameters for craft assets in given volumes.
     *
     * Each key should match the handle of a craft volume, and the its value should
     * be either a key from the `jobs` setting, or an array of parameters (in the
     * same format as the `jobs` setting).
     *
     * @var array
     */

    private $_volumeJobs = [];

    /**
     * @var bool Whether volumeJobs property was normalized or not (internal flag)
     */

    protected $isNormalizedVolumeJobs;

    /**
     * @var array List of input volumes handles, for which the plugin should
     *  automatically create a Coconut conversion job every time a video asset is
     *  added or updated.
     *
     * @default `[]`
     */

    public $watchVolumes = [];

    // @todo: add `fieldJobs` and `watchFields` settings to automatically
    // transcode video assets in asset fields when saving a Craft element.

    // =Public Methods
    // =========================================================================

    // =Properties
    // -------------------------------------------------------------------------

    /**
     * @param string|null $apiKey
     */

    public function setApiKey( string $apiKey = null )
    {
        $this->_apiKey = $apiKey;
    }

    /**
     * @return string
     */

    public function getApiKey(): string
    {
        if (!isset($this->_apiKey)) {
            $this->_apiKey = AppHelper::env('COCONUT_API_KEY');
        }

        if (empty($this->_apiKey)) {
            throw new InvalidConfigException("Missing required `apiKey` setting");
        }

        return $this->_apiKey;
    }

    /**
     * @param string|null $endpoint
     */

    public function setEndpoint( string $endpoint = null )
    {
        $this->_endpoint = $endpoint;
    }

    /**
     * @return string|null
     */

    public function getEndpoint()
    {
        if (!isset($this->_endpoint)) {
            $this->_endpoint = AppHelper::env('COCONUT_ENDPOINT');
        }

        return empty($this->_endpoint) ? null : $this->_endpoint;
    }

    /**
     * @param string|null $endpoint
     */

    public function setRegion( string $region = null )
    {
        $this->_region = $region;
    }

    /**
     * @return string|null
     */

    public function getRegion()
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
     */

    public function setPublicBaseUrl( string $url = null )
    {
        $this->_publicBaseUrl = $url;
    }

    /**
     * Getter method for parsed `publicBaseUrl` property
     *
     * @return string|null
     */

    public function getPublicBaseUrl()
    {
        if ($this->_publicBaseUrl) {
            return Craft::parseEnv($this->_publicBaseUrl);
        }

        return null;
    }

    /**
     * Setter method for normalized `storages` setting
     *
     * @param array $storages Map of names storages, where each key is a storage name
     */

    public function setStorages( array $storages )
    {
        $this->_storages = [];

        foreach ($storages as $name => $storage)
        {
            if (!is_string($name))
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

            $this->_storages[$name] = $storage;
        }
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
     */

    public function setDefaultStorage( $storage )
    {
        $this->_defaultStorage = $storage;
        $this->isNormalizedDefaultStorage = false;
    }

    /**
     * Getter method for normalized `defaultStorage` setting
     *
     * @return Storage|null
     */

    public function getDefaultStorage()
    {
        if (!$this->isNormalizedDefaultStorage)
        {
            $storage = $this->_defaultStorage;

            if ($storage)
            {
                if (is_string($storage)) {
                    $storage = Craft::parseEnv($storage);
                }

                $storage = Coconut::$plugin->getStorages()
                    ->parseStorage($storage);
            }

            $this->_defaultStorage = $storage;
            $this->isNormalizedDefaultStorage = true;
        }

        return $this->_defaultStorage;
    }

    /**
     * Setter method for normalized `defaultUploadVolume` property
     *
     * @param string|array|VolumeInterfece
     */

    public function setDefaultUploadVolume( $volume )
    {
        $this->_defaultUploadVolume = $volume;
        $this->isNormalizedDefaultUploadVolume = false;
    }

    /**
     * @param bool $createMissing Whether to create the volume if it does not exist
     *
     * @return VolumeInterface|null
     */

    public function getDefaultUploadVolume( $createMissing = false )
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

            else if (!$volume instanceof VolumeInterface)
            {
                throw new InvalidConfigException(
                    "Setting `defaultUploadVolume` must resolve to a model that implements ".VolumeInterface::class);
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
     */

    public function setDefaultJobNotification( $notification )
    {
        $this->_defaultJobNotification = $notification;
    }

    /**
     * Getter method for defaulted 'defaultJobNotification' property
     *
     * @return string|array|Notification
     */

    public function getDefaultJobNotification()
    {
        return $this->_defaultJobNotification ?? (new Notification([
            'type' => 'http',
            'url' => UrlHelper::actionUrl('coconut/jobs/notify'),
            'params' => [],
            'events'=> true,
            'metadata'=> true,
        ]));
    }

    /**
     * Setter method for normalized `jobs` setting
     *
     * @param array Map of named jobs where each key is a job name
     */

    public function setJobs( array $jobs )
    {
        $this->_jobs = $jobs;
        $this->isNormalizedJobs = false;
    }

    /**
     * Getter method for normalized `jobs` setting
     *
     * @return Job[]
     */

    public function getJobs()
    {
        if (!$this->isNormalizedJobs)
        {
            foreach ($this->_jobs as $name => $job)
            {
                if (!is_string($name))
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

                $this->_jobs[$name] = $job;
            }

            $this->isNormalizedJobs = true;
        }

        return $this->_jobs;
    }

    /**
     * Setter method for normalized `volumeJobs` setting
     *
     * @param array Map of volume jobs, where each key is a volume handle
     */

    public function setVolumeJobs( array $jobs )
    {
        $this->_volumeJobs = $jobs;
        $this->isNormalizedVolumeJobs = false;

    }

    /**
     * Getter method for normalized `volumeJobs` setting
     *
     * @return Job[]
     */

    public function getVolumeJobs()
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

            $this->isNormalizedJobs = true;
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

    public function rules()
    {
        $rules = parent::rules();

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
     */

    public function validateStorageMap( $attribute, array $params, InlineValidator $validator )
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
     * Validation method for maps of job parameters
     */

    public function validateJobsMap( $attribute, array $params = [], InlineValidator $validator )
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

    // =Operations
    // -------------------------------------------------------------------------

    // =Protected Methods
    // =========================================================================

    /**
     * @param
     *
     * @return \craft\base\VolumeInterface
     */

    protected function getVolumeModel( array $config = [], bool $createMissing = false )
    {
        $config = ComponentHelper::mergeSettings($config);
        $handle = $config['handle'] ?? 'coconut';

        $craftVolumes = Craft::$app->getVolumes();
        $volume = $craftVolumes->getVolumeByHandle($handle);

        if ($volume || !$createMissing) {
            return $volume;
        }

        // create missing volume
        $type = $config['type'] ?? $config['class'] ?? LocalVolume::class;

        $defaults = [
            'type' => $type,
            'handle' => $handle,
            'name'=> ($config['name'] ?? $this->humanizeHandle($handle)),
        ];

        if ($type == LocalVolume::class)
        {
            $slug = StringHelper::toKebabCase($handle);

            $defaults['hasUrls'] = true;
            $defaults['path'] = '@webroot/'.$slug;
            $defaults['url'] = '@web/'.$slug;
        }

        $config = array_merge($defaults, $config);
        $volume = $craftVolumes->createVolume($config);

        if ($craftVolumes->saveVolume($volume)) {
            return $volume;
        }

        return null;
    }

    /**
     *
     */

    protected function humanizeHandle( string $handle )
    {
        $sep = preg_replace('/([A-Z])/', ' $1', $handle);
        $fix = preg_replace('/\s([A-Z])\s([A-Z])\s/', ' $1$2', $sep);
        return ucfirst(ltrim($fix));
    }

    // =Private Methods
    // =========================================================================
}
