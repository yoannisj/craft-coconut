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
use craft\helpers\App as AppHelper;
use craft\helpers\Component as ComponentHelper;

use yoannisj\coconut\models\Config;
use yoannisj\coconut\models\Storage;
use yoannisj\coconut\helpers\ConfigHelper;

/**
 * Model representing and validation Coconut plugin settings
 * 
 * @property Storage[] $storages
 * @property Storage|null $defaultStorage
 * @property VolumeInterface|null $defaultUploadVolume
 * @property Config[] $configs
 * @property Config[] $volumeConfigs
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

    public $apiKey = null;

    /**
     * @var boolean Whether transcoding videos should default to using the queue,
     * or run synchonously. It is highly recommended to use the queue whenever
     * possible, but if your craft environment is not running queued jobs in the
     * background, you may want to default to running jobs synchronously.
     *
     * More info on how to run queued jobs in the background:
     *  https://nystudio107.com/blog/robust-queue-job-handling-in-craft-cms
     * 
     * @default true
     */

    public $preferQueue = true;

    /**
     * @var integer
     *
     * Depending on your Coconut plan and the config you are using to transcode
     * your video, Transcoding jobs can take a long time. To avoid jobs to fail
     * with a timeout error, this plugin sets a high `Time to Reserve` on the
     * jobs it pushes to Craft's queue.
     *
     * More info:
     *  https://craftcms.stackexchange.com/questions/25437/queue-exec-time/25452
     * 
     * @default 900
     */

    public $transcodeJobTtr = 900;

    /**
     * @var array Named storage settings to use in Coconut transcoding configs.
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
     * @var array Container for normalized storages
     */

    protected $isNormalizedStorages;

    /**
     * @var string|array|\yoannisj\coconut\models\StorageSettings
     * 
     * The storage name or settings used to store Coconut output files when none
     * is given in transcoding job config parameters.
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
     * @var boolean Whether the `defaultStorage` setting has already been normalized.
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
     * @var boolean Whether the `defaultUploadVolume` setting has alreay been normalized.
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
     * - '{key}' the outputs `key` parameter (a path-friendly version of it)
     * - '{ext}' the output file extension
     * 
     * Note: to prevent outputs saved in asset volumes to end up in Craft's asset indexes,
     * the path will be prefixed with an '_' (if it is not already).
     * 
     * @default '_coconut/{path}/{key}.{ext}'
     */

    public $defaultPathFormat = '_coconut/{path}/{key}.{ext}';

    /**
     * @var array Named coconut job config settings.
     * 
     * Each key defines a named config, and its value should be an array setting
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
     * based on the `defaultPathFormat` setting.
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
     *              'webm', // will generate the output's `path` parameter based on `defaultPathFormat`
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

    private $_configs = [];

    /**
     * @var array Container for normalized configs
     */

    private $_normalizedConfigs = [];

    /**
     * @var array Sets default config parameters for craft assets in given volumes.
     * 
     * Each key should match the handle of a craft volume, and the its value should
     * be either a key from the `configs` setting, or an array of parameters (in the
     * same format as the `configs` setting).
     * 
     * @var array
     */

    private $_volumeConfigs = [];

    /**
     * @var array Container for normalized volume configs
     */

    private $_normalizedVolumeConfigs = [];

    /**
     * @var array List of input volumes handles, for which the plugin should
     *  automatically create a Coconut conversion job every time a video asset is
     *  added or updated.
     * 
     * @default `[]`
     */

    public $watchVolumes = [];

    // @todo: add `fieldConfigs` and `watchFields` settings to automatically
    // transcode video assets in asset fields when saving a Craft element.

    // =Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */

    public function init()
    {
        if (!isset($this->apiKey)) {
            $this->apiKey = AppHelper::env('COCONUT_API_KEY');
        }

        if (empty($this->apiKey)) {
            throw new InvalidConfigException("Missing required `apiKey` config setting");
        }

        parent::init();
    }

    // =Properties
    // -------------------------------------------------------------------------

    /**
     * Setter method for normalized `storages` setting
     * 
     * @param array $storages Map of names storages, where each key is a storage name
     */

    public function setStorages( array $storages )
    {
        $this->_storages = $storages;
        $this->isNormalizedStorages = false;
    }

    /**
     * Getter method for normalized `storages` setting
     * 
     * @return Storage[]
     */

    public function getStorages(): array
    {
        if (!$this->isNormalizedStorages)
        {
            foreach ($this->_storages as $name => $storage)
            {
                if (!is_string($name))
                {
                    throw new InvalidConfigException(
                        "Setting `storages` must be an associative array"
                        ." where keys are storage names");
                }

                if (is_array($storage))
                {
                    if (!array_key_exists('class', $storage)) {
                        $storage['class'] = Storage::class;
                    }

                    $storage = Craft::createObject($storage);
                }

                else if (!$storage instanceof Storage)
                {
                    $class = Storage::class;
                    throw new InvalidConfigException(
                        "Setting `storages` must resolve to a list of $class models");
                }

                $this->_storages[$name] = $storage;
            }

            $this->isNormalizedStorages = true;
        }

        return $this->_storages;
    }

    /**
     * Setter method for normalized `defaultStorage` setting
     * 
     * @param string|array|Storage $storage
     */

    public function setDefaultStorage( $storage )
    {
        $this->_storage = $storage;
        $this->isNormalizedDefaultStorage = false;
    }

    /**
     * Getter method for normalized `defaultStorage` setting
     * 
     * @return Storage|null
     */

    public function getDefaultStorage()
    {
        if (!isset($this->_defaultStorage)) {
            return null;
        }

        if (!$this->isNormalizedDefaultStorage)
        {
            $storage = Craft::parseEnv($this->_defaultStorage);

            $this->_defaultStorage = ConfigHelper::parseStorage($storage);
            $this->isNormalizedDefaultStorage = true;
        }

        return $this->_defaultStorage;
    }

    /**
     * Setter method for normalized `defaultUploadVolume` property
     * 
     * @param string|array|Volume
     */

    public function setDefaultUploadVolume( $volume )
    {
        $this->_defaultUploadVolume = null;
        $this->isNormalizedDefaultUploadVolume = false;
    }

    /**
     * @param bool $createMissing Whether to create missing volume based on config settings
     * 
     * @return Volume|null
     */

    public function getDefaultUploadVolume( bool $createMissing = false )
    {
        if (!isset($this->_defaultUploadVolume)) {
            return null;
        }

        if (!$this->isNormalizedDefaultUploadVolume)
        {
            $volume = $this->_defaultUploadVolume;

            if (is_string($volume))
            {
                $volume = $this->getVolumeModel([
                    'handle' => $volume,
                ], $createMissing);
            }

            if (is_array($volume)) {
                $volume = $this->getVolumeModel($volume, $createMissing);
            }

            else if (!$volume instanceof VolumeInterface)
            {
                $class = VolumeInterface::class;
                throw new InvalidConfigException(
                    "Setting `defaultUploadVolume` must resolve to a model that implements $class");
            }

            $this->_defaultUploadVolume = $volume;
            $this->isNormalizedDefaultUploadVolume = true;
        }

        return $this->_defaultUploadVolume;
    }

    /**
     * Setter method for normalized `configs` setting
     * 
     * @param array Map of named configs, where each key is a config name
     */

    public function setConfigs( array $configs )
    {
        $this->_configs = $configs;
        $this->isNormalizedConfigs = false;
    }

    /**
     * Getter method for normalized `configs` setting
     * 
     * @return Config[]
     */

    public function getConfigs()
    {
        if (!$this->isNormalizedConfigs)
        {
            foreach ($this->_configs as $name => $config)
            {
                if (!is_string($name))
                {
                    throw new InvalidConfigException(
                        "Setting `configs` must be an array "
                        ." where each key is a config name.");
                }

                if (is_array($config)) {
                    $config = Craft::configure(new Config(), $config);
                }

                else if (!$config instanceof Config)
                {
                    $class = Config::class;
                    throw new InvalidConfigException(
                        "Setting `configs` must resolve to a list of `$class` models");
                }

                $this->_configs[$name] = $config;
            }

            $this->isNormalizedConfigs = true;
        }

        return $this->_configs;
    }

    /**
     * Setter method for normalized `volumeConfigs` setting
     * 
     * @param array Map of volume configs, where each key is a volume handle
     */

    public function setVolumeConfigs( array $configs )
    {
        $this->_volumeConfigs = $configs;
        $this->isNormalizedVolumeConfigs = false;

    }

    /**
     * Getter method for normalized `volumeConfigs` setting
     * 
     * @return Config[]
     */

    public function getVolumeConfigs()
    {
        if (!$this->isNormalizedVolumeConfigs)
        {
            foreach ($this->_volumeConfigs as $handle => $config)
            {
                if (!is_string($handle))
                {
                    throw new InvalidConfigException(
                        "Setting `volumeConfigs` must be an associative array"
                        ." where each key is a volume handle");
                }

                if (is_string($config))
                {
                    $configs = $this->getConfigs();

                    if (!array_key_exists($config, $configs))
                    {
                        throw new InvalidConfigException(
                            "Could not find config named '$config'.");
                    }

                    $config = $configs[$config];
                }

                if (is_array($config)) {
                    $config = Craft::configure(new Config(), $config);
                }

                else if (!$config instanceof Config)
                {
                    $class = Config::class;
                    throw new InvalidConfigException(
                        "Setting `volumeConfigs` must resolve to a list of `$class` models");
                }

                $this->_volumeConfigs[$name] = $config;
            }

            $this->isNormalizedConfigs = true;
        }

        return $this->_volumeConfigs;
    }

    // =Attributes
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function attributes()
    {
        $attributes = parent::attributes();

        $attributes[] = 'storages';
        $attributes[] = 'defaultStorage';
        $attributes[] = 'defaultUploadVolume';
        $attributes[] = 'configs';
        $attributes[] = 'volumeConfigs';

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

        $rules['attrsRequired'] = [ ['apiKey', 'defaultUploadVolume', 'defaultPathFormat'], 'required' ];
        $rules['attrsString'] = [ ['apiKey', 'defaultPathFormat'], 'string' ];

        // $rules['storagesStorageMap'] = [ ['storages'], 'validateStorageMap' ];
        // $rules['configsConfigMap'] = [ ['configs'], 'validateConfigMap' ];
        // $rules['volumeConfigsConfigMap'] = [ ['volumeConfigs'], 'validateConfigMap', 'registryAttribute' => 'configs' ];
        
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
     * Validation method for maps of config parameters
     */

    public function validateConfigMap( $attribute, array $params = [], InlineValidator $validator )
    {
        $configs = $this->$attribute;
        $registryAttribute = $params['registryAttribute'] ?? null;

        if (!is_array($configs) || !ArrayHelper::isAssociative($configs))
        {
            $validator->addError($this, $attribute,
                '{attribute} must be an array mapping volume handles to Coconut config parameters');
            return; // no need to continue validation
        }

        foreach ($configs as $key => $config)
        {
            if (is_string($config) && $registryAttribute)
            {
                $name = $config;
                $config = $this->$registryAttribute[$name] ?? null;
                 
                if (!$config)
                {
                    $label = $this->getAttributeLabel($registryAttribute);
                    $validator->addError($this, $attribute,
                        "Could not find {attribute}'s named config '$name' in '$label'");
                    return; // no need to continue validation
                }
            }

            if (is_array($config) && ArrayHelper::isAssociative($config))
            {
                if (!array_key_exists('class', $config)) $config['class'] = $config;
                $config = Craft::createObject($config);
            }

            if (!$config instanceof Config)
            {
                $class = Config::class;
                $validator->addError($this, $attribute,
                    "Config with key '$key' in {attribute} must resolve to a $class model");
                return; // no need to continue validation
            }

            if (!$config->validate())
            {
                $validator->addError($this, $attribute,
                    "Invalid config with key '$key' in {attribute}");
            }
        }
    }

    // =Operations
    // -------------------------------------------------------------------------

    /**
     * Returns Coconut storage model with given name
     * 
     * @return Storage|null
     */

    public function getNamedStorage( string $name )
    {
        $storages = $this->getStorages();
        return $storages[$name] ?? null;
    }

    /**
     * Returns Coconut config model with given name
     * 
     * @return Config|null
     */

    public function getNamedConfig( string $name )
    {
        $configs = $this->getConfigs();
        return $configs[$name] ?? null;
    }

    /**
     * Returns Coconut config model for given assets volume
     * 
     * @param VolumeInterface|string $volume
     * 
     * @return Config|null
     */

    public function getVolumeConfig( $volume )
    {
        if ($volume instanceof VolumeInterface) {
            $volume = $volume->handle;
        }

        $volumeConfigs = $this->getVolumeConfigs();
        return $volumeConfigs[$volume] ?? null;
    }

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
            'name'=> $config['name'] ?? $this->humanizeHandle($handle),
        ];

        if ($type == LocalVolume::class)
        {
            $slug = StringHelper::toKebabCase($handle);

            $defaults['hasUrl'] = true;
            $defaults['url'] = '@web/'.$slug;
            $defaults['path'] = '@webroot/'.$slug;
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