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

use yoannisj\coconut\models\Config;

/**
 * 
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
     *          'url' => \craft\helpers\UrlHelper::actionUrl('coconut/jobs/upload', [
     *              'volume' => 'localVolumeHandle'
     *          ]),
     *      ],
     * ]
     * 
     * @default []
     */

    public $storages = [];

    /**
     * @var string|array|\yoannisj\coconut\models\StorageSettings
     * 
     * The storage name or settings used to store Coconut output files when none
     * is given in transcoding job config parameters.
     * 
     * This can be set to a string which must be either a key from the `storages`
     * setting, or a volume handle.
     * 
     * If this is set to `null`, it will try to generate storage settings for
     * the input asset's volume, or fallback to use the HTTP upload method to
     * store files in the volume defined by the 'defaultUploadVolume' setting.
     * 
     * @default null
     */

    private $_defaultStorage = null;

    /**
     * @var boolean Whether the `defaultStorage` setting has already been normalized.
     */

    protected $isDefaultStorageNormalized;

    /**
     * @var string|\craft\models\Volume
     * 
     * The default volume used to store output files when the `storage` parameter
     * was omitted and the `input` parameter was not a craft asset.
     * 
     * @default 'coconut'
     */

    private $_defaultUploadVolume = 'coconut';

    /**
     * @var boolean Whether the `defaultUploadVolume` setting has alreay been normalized.
     */

    protected $isDefaultUploadVolumeNormalized;

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
     *          'storage' => 'coconut', // assumin there is a volume called 'coconut'
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

    public $configs = [];

    /**
     * @var array Sets default config parameters for craft assets in given volumes.
     * 
     * Each key should match the handle of a craft volume, and the its value should
     * be either a key from the `configs` setting, or an array of parameters (in the
     * same format as the `configs` setting).
     * 
     * @var array
     */

    public $volumeConfigs = [];

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

        parent::init();
    }

    // =Attributes
    // -------------------------------------------------------------------------

    /**
     * 
     */

    public function setOutputVolume( $value )
    {
        $this->_outputVolume = $value;
        $this->_isOutputVolumeNormalized = false;
    }

    /**
     * 
     */

    public function getOutputVolume()
    {
        if (!$this->_isOutputVolumeNormalized)
        {
            $volume = $this->_outputVolume;

            if (empty($volume)) {
                $volume = 'coconut';
            }

            if (is_numeric($volume)) {
                $volume = Craft::$app->getVolumes()->getVolumeById($volume);
            }

            else if (is_string($volume)) {
                $volume = $this->getOrCreateOutputVolume($volume);
            }

            if (!$volume instanceof VolumeInterface) {
                throw new InvalidConfigException('Could not determine output volume.');
            }

            $this->_outputVolume = $volume;
        }

        return $this->_outputVolume;
    }

    // =Validation
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function rules()
    {
        $rules = parent::rules();

        $rules['attrsRequired'] = [ ['apiKey', 'outputVolume'], 'required' ];
        $rules['attrsString'] = [ ['apiKey', 'outputPathFormat'], 'string' ];
        $rules['attrsHandle'] = [ ['outputVolume'], HandleValidator::class ];
        $rules['attrsConfigMap'] = [ ['configs', 'volumeConfigs'], 'validateConfigMap', 'baseAttribute' => 'configs' ];
        $rules['wathVolumesEach'] = [ 'watchVolumes', 'each', 'rule' => HandleValidator::class ];

        return $rules;
    }

    /**
     * 
     */

    public function validateConfigMap( $attribute, array $params = [], InlineValidator $validator )
    {
        $configs = $this->$attribute;
        $baseAttribute = $params['baseAttribute'] ?? null;

        if (!is_array($configs) || !ArrayHelper::isAssociative($configs)) {
            $validator->addError($this, $attribute,
                '{attribute} must be an array mapping volume handles with config settings');
        }

        foreach ($configs as $handle => $config)
        {
            if (is_string($config) && $baseAttribute) {
                $config = $this->$baseAttribute[config] ?? null;
            }

            if (!is_array($config))
            {
                $validator->addError($this, $attribute,
                    'Each value in {attribute} must be a config name, or an array of config params.');
            }
        }
    }

    // =Operations
    // -------------------------------------------------------------------------

    /**
     * @return array | false
     */

    public function getConfig( string $name )
    {
        $config = $this->configs[$name] ?? false;


        if (is_array($config))
        {
            $config['class'] = Config::class;
            $config = Craft::createObject($config);
        }

        else if (!($config instanceof Config)) {
            $this->configs[$name] = false;
        }

        return $config;
    }

    /**
     * @return array | false
     */

    public function getVolumeConfig( string $handle )
    {
        $config = $this->volumeConfigs[$handle] ?? false;

        if (is_string($config)) {
            $config = $this->getConfig($config);
        }

        else if (is_array($config)) {
            $config['class'] = Config::class;
            $config = Craft::createObject($config);
        }

        return $config;
    }

    // =Protected Methods
    // =========================================================================

    /**
     * @return \craft\base\VolumeInterface
     */

    protected function getOrCreateOutputVolume( string $handle )
    {
        $volumes = Craft::$app->getVolumes();
        $volume = $volumes->getVolumeByHandle($handle);

        if ($volume) {
            return $volume;
        }

        // create local volume based on handle
        $name = $this->humanizeHandle($handle);
        $slug = StringHelper::toKebabCase($handle);
        $props = [
            'type' => LocalVolume::class,
            'settings' => [
                'name' => $name,
                'handle' => $handle,
                'hasUrls' => true,
                'url' => '@web/'.$slug,
                'path' => '@webroot/'.$slug,
            ],
        ];

        $volume = $volumes->createVolume($props);
        if ($volumes->saveVolume($volume)) {
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