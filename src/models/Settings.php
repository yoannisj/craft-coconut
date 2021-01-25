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

use yoannisj\coconut\models\Config;

/**
 * 
 */

class Settings extends Model
{
    // =Properties
    // =========================================================================

    /**
     * @var string The API key of the coconut.co account used to convert videos.
     */

    public $apiKey;

    /**
     * @var bool Whether transcoding videos should default to using the queue,
     * or run synchonously. It is highly recommended to use the queue whenever
     * possible, but if your craft environment is not running queued jobs in the
     * background, you may want to default to running jobs synchronously if.
     *
     * More info on how to run queued jobs in the background:
     *  https://nystudio107.com/blog/robust-queue-job-handling-in-craft-cms
     */

    public $preferQueue = true;

    /**
     * @var int
     *
     * Depending on your Coconut plan and the config you are using to transcode
     * your video, Transcoding jobs can take a long time. To avoid jobs to fail
     * with a timeout error, this plugin sets a high `Time to Reserve` on jobs
     * pushed to Yii's queue.
     *
     * More info:
     *  https://craftcms.stackexchange.com/questions/25437/queue-exec-time/25452
     */

    public $transcodeJobTtr = 600;

    /**
     * @var int | string | \craft\base\VolumeInterface The default volume
     * where coconut output files are uploaded, when the transcoding config does
     * not specify its own outputVolume. If this is set to a string which does
     * not correspond to an existing volume, a new local volume will be created
     * in the webroot directory.
     */

    private $_outputVolume;

    /**
     * @var bool Whether the output volume was initialized
     */

    private $_isOutputVolumeNormalized;

    /**
     * @var string Relative path to folder where coconut output files are
     * uploaded in volumes. This may contain the following placeholder strings:
     * - '{path}' the source folder path (relative to asset volume or url host)
     * - '{filename}' the source filename (without extension)
     * - '{hash}' a unique md5 hash based on the source url
     */

    public $outputPathFormat = '/_coconut/{hash}-{format}.{ext}';

    /**
     * @var array Named coconut job config settings. Only the 'outputs' and
     * optional 'vars'* keys are supported. The plugin will set the 'source' and
     * 'webhook' settings programatically.
     */

    public $configs = [];

    /**
     * @var array Sets default coconut config for source asset volumes.
     *  Keys should be the handle of a craft volume, and values should be a
     *  key from the "configs" setting, or an array defining job settings.
     */

    public $volumeConfigs = [];

    /**
     * @var array List of source volumes handles, for which the plugin should
     *  automatically create a Coconut conversion job (when a video asset is
     *  added or updated). 
     */

    public $watchVolumes = [];

    // =Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */

    public function init()
    {
        if (!isset($this->apiKey)) {
            $this->apiKey = getenv('COCONUT_API_KEY');
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