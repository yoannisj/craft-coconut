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
use yii\base\InvalidArgumentException;
use yii\validators\InlineValidator;

use Craft;
use craft\base\VolumeInterface;
use craft\base\Model;
use craft\validators\HandleValidator;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use craft\helpers\FileHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Input;
use yoannisj\coconut\models\Notification;
use yoannisj\coconut\helpers\ConfigHelper;

/**
 *
 */

class Config extends Model
{
    // =Properties
    // =========================================================================

    /**
     * @var string Public url of input video to transcode
     */

    private $_input;

    /**
     * @var boolean Whether the input property value has been normalized
     */

    protected $isNormalizedInput;

    /**
     * @var string
     */

    private $_storageHandle;

    /**
     * @var integer
     */

    private $_storageVolumeId;

    /**
     * @var VolumeInterface|null
     */

    private $_storageVolume;

    /**
     * @var Storage|null
     */

    private $_fallbackStorage;

    /**
     * @var boolean
     */

    protected $isFallbackStorage;

    /**
     * @var Storage The storage settings for output files
     */

    private $_storage;

    /**
     * @var boolean
     */

    protected $isNormalizedStorage;

    /**
     * @var string The format used to generate missing output paths.
     *  Defaults to the plugin's `defaultOutputPath` setting.
     */

    private $_outputPathFormat = null;

    /**
     * @var array Raw parameters for Coconut job outputs
     */

    private $_rawOutputs;

    /**
     * @var array List of normalized output parameters
     */

    private $_outputs;

    // =Public Methods
    // =========================================================================

    /**
     *
     */

    public function __sleep()
    {
        $fields = $this->fields();
        $props = [];

        foreach ($fields as $field)
        {
            if (property_exists($this, $field)) {
                $props[] = $field;
            } else if (property_exists($this, '_' . $field)) {
                $props[] = '_'.$field;
            }
        }

        return $props;
    }

    // =Properties
    // -------------------------------------------------------------------------

    /**
     * Setter method for normalized `input` property
     *
     * Given `$input` parameter can be an Input model, an array of input properties,
     * an Asset element, an Asset element ID or a URL to an external input file
     *
     * @param string|array|Input|Asset|null $input
     */

    public function setInput( $input )
    {
        $this->_input = $input;

        if ($input === null || $input instanceof Input) {
            $this->isNormalizedInput = true;
        } else {
            $this->isNormalizedInput = false;
        }

        // fallback storage depends on input
        $this->_fallbackStorage = null;
        if ($this->isFallbackStorage) {
            $this->isNormalizedStorage = false;
        }
    }

    /**
     * Getter method for normalized `input` property
     *
     * @return Input|null
     */

    public function getInput()
    {
        if (!$this->isNormalizedInput)
        {
            $input = $this->_input;

            if (!is_array($input))
            {
                if (!array_key_exists('class', $input)) {
                    $input['class'] = Input::class;
                }

                $input = Craft::createObject($input);
            }

            else
            {
                $model = new Input();

                if ($input instanceof Asset) {
                    $model->asset = $input;
                } else if (is_numeric($input)) {
                    $model->assetId = (int)$input;
                } else if (is_string($input)) {
                    $model->url = $input;
                }

                $input = $model;
            }

            $this->_input = $input;
            $this->isNormalizedInput = true;
        }

        return $this->_input;
    }

    /**
     * Setter method for normalized `storage` property
     *
     * If given $storage is a string, it will first be checked against named storage
     * settings, or it will be considered a volume handle.
     *
     * @param string|array|Storage|VolumeInterface|null $storage
     */

    public function setStorage( $storage )
    {
        if (empty($storage))
        {
            $this->_storage = null;
            $this->_storageHandle = null;
            $this->_storageVolumeId = null;

            $this->isNormalizedStorage = false;
        }

        else if ($storage instanceof Storage)
        {
            $this->_storage = $storage;
            $this->_storageHandle = null;
            $this->_storageVolumeId = null;

            $this->isNormalizedStorage = true;
        }

        else if ($storage instanceof VolumeInterface) {
            $this->setStorageVolume($storage);
        }

        else if (is_string($storage) && $storage != $this->_storageHandle
            && (!$this->_storageVolume || $storage != $this->_storageVolume->handle)
        ) {
            $this->_storageHandle = $storage;
            $this->_storageVolume = null;
            $this->_storageVolumeId = null;

            // force re-calculation next time storage is accessed
            $this->isNormalizedStorage = false;
        }

        else {
            $this->_storage = $storage;
            $this->_storageHandle = null;
            $this->_storageVolumeId = null;
            $this->_storageVolume = null;

            // force re-calculation next time storage is accessed
            $this->isNormalizedStorage = false;
        }
    }

    /**
     * Getter method for resolved `storage` property
     *
     * @return Storage|null
     */

    public function getStorage()
    {
        if (!$this->isNormalizedStorage)
        {
            $storage = $this->_storage;

            // give priority to storage handle
            if (!empty($this->_storageHandle))
            {
                // check named storage settings
                $storage = Craft::$app->getSettings()
                    ->getNamedStorage($this->_storageHandle);

                // or check volume by handle
                if (!$storage && ($volume = $this->getStorageVolume())) {
                    $storage = Coconut::resolveVolumeStorage($volume);
                }
            }

            else if (is_array($storage))
            {
                if (!array_key_exists('class', $storage)) {
                    $storage['class'] = Storage::class;
                }

                $storage = Craft::createObject($storage);
            }

            if (!$storage) {
                $storage = $this->getFallbackStorage();
                $this->isFallbackStorage = true;
            } else {
                $this->isFallbackStorage = false;
            }

            if (!$storage instanceof Storage)
            {
                $class = Storage::class;
                throw new InvalidConfigException(
                    "Attribute `storage` must be a valid storage name, volume handle"
                    ." or instance of $class");
            }

            $this->_storage = $storage;
            $this->isNormalizedStorage = true;
        }

        return $this->_storage;
    }

        /**
     * Getter method for reactive `storageVolumeId` property
     *
     * @return integer|null
     */

    public function getStorageVolumeId()
    {
        if (!isset($this->_storageVolumeId)
            && ($volume = $this->getStorageVolume())
        )
        {
            $this->_storageVolumeId = $volume->id;
        }

        return $this->_storageVolumeId;
    }

    /**
     * Getter method for read-only `storageVolume` property
     *
     * @return VolumeInterface|null
     */

    public function getStorageVolume()
    {
        if (!$this->isNormalizedStorage
            && !isset($this->_storageVolume))
        {
            $volume = null;

            if ($this->_storageHandle)
            {
                $volume = Craft::$app->getVolumes()
                    ->getVolumeByHandle($this->_storageHandle);
            }

            else if ($this->_storageVolumeId)
            {
                $volume = Craft::$app->getVolumes()
                    ->getVolumeById($this->_storageVolumeId);
            }

            if ($volume)
            {
                $this->_storageVolume = $volume;
                $this->_storageVolumeId = $volume->id;
                $this->_storageHandle = $volume->handle;
            }
        }

        return $this->_storageVolume;
    }

    /**
     * Getter method for read-only `fallbackStorage` property
     *
     * Returns fallback storage, used if `storage` property was set to `null`
     * or could not be resolved
     *
     * @return Storage|null
     */

    public function getFallbackStorage()
    {
        if (!isset($this->_fallbackStorage))
        {
            $coconutSettings = Coconut::$plugin->getSettings();
            $storage = $coconutSettings->getDefaultStorage();

            if (!$storage)
            {
                $input = $this->getInput();
                $inputAsset = $input ? $input->getAsset() : null;
                $uploadVolume = ($inputAsset ? $inputAsset->getVolume()
                    : $coconutSettings->getDefaultUploadVolume());

                if ($uploadVolume)
                {
                    $storage = Coconut::$plugin->getStorages()
                        ->getVolumeStorage($uploadVolume);
                }
            }

            $this->_fallbackStorage  = $storage;
        }

        return $this->_fallbackStorage;
    }

    /**
     * Setter method for defaulted `outputPathFormat` property
     *
     * @param string $pathFormat
     */

    public function setOutputPathFormat( string $pathFormat = null )
    {
        $this->_outputPathFormat;

        // update normalized output models
        foreach ($this->getOutputs() as $key => $output)
        {
            // only set path format on outputs that don't have an explicit path
            $explicitPath = $output->getExplicitPath();
            if (empty($explicitPath)) $output->setPath($pathFormat);

            $this->_outputs[$key] = $output;
        }
    }

    /**
     * Getter method for defaulte `outputPathFormat` property
     *
     * @return string
     */

    public function getOutputPathFormat(): string
    {
        return ($this->_outputPathFormat ??
            Coconut::$plugin->getSettings()->defaultPathFormat);
    }

    /**
     * @param array $outputs
     */

    public function setOutputs( array $outputs )
    {
        $this->_outputs = $outputs;
        $this->isNormalizedOutputs = false;
    }

    /**
     * @return array | null
     */

    public function getOutputs()
    {
        if (!$this->isNormalizedOutputs || !isset($this->_outputs))
        {
            $outputParams = [];

            foreach ($this->_outputs as $key => $params)
            {
                // support defining output as format string (no extra params)
                if (is_numeric($key)) {
                    $key = $params;
                    $params = [];
                }

                // support multiple outputs for 1 format
                if (array_key_exists($key, $outputParams))
                {
                    $keyParams = $outputParams[$key];

                    // @todo: remove duplicate output parameters

                    if (ArrayHelper::isIndexed($keyParams)) {
                        $keyParams[] = $params;
                    } else {
                        $keyParams = [ $keyParams, $params ];
                    }

                    $outputParams[$key] = $keyParams;
                }

                // support defining outputs as Output models
                else if ($params instanceof Output) {
                    // transforming to an array simplifies normalization happening below
                    $outputParams[$key] = $params->toArray();
                }

                else {
                    $outputParams[$key] = $params;
                }
            }

            // resolve Output models from `outputs` config params
            foreach ($outputParams as $key => $params)
            {
                // flatten list of multiple output settings for 1 same format
                // so we can fill-in missing output keys
                // @see https://docs.coconut.co/jobs/api#same-output-format-with-different-settings
                if (ArrayHelper::isIndexed($params))
                {
                    $formatIndex = 1; // use index to generate missing `key` param
                    foreach ($params as $prm)
                    {
                        $output = $this->resolveOutput($key, $prm, $formatIndex++);

                        $outputKey = $output->getKey(); // includes index if `key` param was missing
                        $outputs[$outputKey] = $output;
                    }
                }

                else
                {
                    $output = $this->resolveOutput($key, $params);
                    $outputKey = $output->getKey(); // returns format string if `key` param was missing

                    $outputs[$outputKey] = $output;
                }
            }

            $this->_outputs = $outputs;
            $this->isNormalizedOutputs = true;
        }

        return $this->_outputs;
    }

    /**
     * Setter method for the resolved `nofitication` property
     *
     * @param string|Notification|null $notification
     */

    public function setNotification( $notification )
    {
        if ($notification === null) {
            $this->_notification = null;
        }

        else
        {
            $model = new Notification([
                'metadata' => true,
                'events' => true,
            ]);

            if (is_array($notification)) {
                $model = Craft::configure($model, $notification);
            }

            else if (is_string($notification))
            {
                $model->type = 'http';
                $model->url = $notification;
            }

            $this->_notification = $model;
        }
    }

    /**
     * @return Notification|null
     */

    public function getNotification()
    {
        if (!Coconut::$plugin->getSettings()->enableNotifications) {
            return null;
        }

        if (!isset($this->_notification))
        {
            $this->_notification = new Notification([
                'type' => 'http',
                'url' => UrlHelper::actionUrl('coconut/jobs/notification'),
                'metadata' => true,
                'events' => true,
            ]);
        }

        return $this->_notification;
    }

    // =Attributes
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function attributes()
    {
        $attributes = parent::attributes();

        $attributes[] = 'input';
        $attributes[] = 'storage';
        $attributes[] = 'outputs';
        $attributes[] = 'notification';

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

        $rules['attrsRequired'] = [ ['input', 'storage', 'outputs'], 'required' ];
        $rules['attrsString'] = [ ['outputPathFormat'], 'string' ];

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

        $fields[] = 'input';
        $fields[] = 'storage';
        $fields[] = 'outputs';
        $fields[] = 'notification';

        return $fields;
    }

    /**
     * @inheritdoc
     */

    public function extraFields()
    {
        $fields = parent::extraFields();

        $fields[] = 'outputPathFormat';

        return $fields;
    }

    // =Operations
    // -------------------------------------------------------------------------

    /**
     * Returns a new config model, which only includes given list of output
     * formats.
     *
     * @param array $formats
     *
     * @return \yoannisj\coconut\models\Config
     */

    function forFormats( array $formats )
    {
        $config = clone $this;
        $outputs = $this->getOutputs();

        foreach ($outputs as $output)
        {

        }

        $rawOutputs = $this->_outputs;
        $formatOutputs = [];

        foreach ($formats as $format => $options)
        {
            if (is_numeric($format)) {
                $format = $options;
                $options = null;
            }

            if (in_array($format, $rawOutputs)) {
                $formatOutputs[] = $format;
            }

            else if (array_key_exists($format, $rawOutputs)) {
                $formatOutputs[$format] = $rawOutputs[$format];
            }
        }

        $newConfig = clone $this;
        $newConfig->outputs = $formatOutputs;

        return $newConfig;
    }

    // =Protected Method
    // =========================================================================

    /**
     * Resolves output parameters in Cococnut job config settings, and returns
     * the corresponding Output model
     *
     * @param string $key The key of the output params in the config's `outputs` list
     * @param array|Output $output The output params from the config's `outputs` list
     * @param string|null $formatIndex The output index when one format key was used to define multiple outputs
     *
     * @return Output
     */

    protected function resolveOutput( string $key, $output, string $formatIndex = null ): Output
    {
        if (is_string($output))
        {
            $output = JsonHelper::decodeIfJson($output);

            if (is_string($output)) {
                $output = ConfigHelper::decodeOutput($output);
            }
        }

        else if ($output instanceof Output)
        {
            $output->scenario = Output::SCENARIO_CONFIG;
            $output = $output->toArray();
        }

        else if (!is_array($output))
        {
            throw new InvalidArgumentException(
                "Output must be an array of parameters, a format string, or an ".Output::class." instance");
        }

        // get parsed format specs from output params key
        $formatSpecs = ConfigHelper::decodeFormat($key);

        // merge-in specs from 'format' parameter
        if (array_key_exists('format', $output)) {
            $formatSpecs = array_merge($formatSpecs, $output['format']);
        }

        // default to output path format to resolve output paths
        $path = ArrayHelper::getValue($output, 'path') ?? $this->_outputPathFormat;

        // create output model, and merge in normalized/default params
        return new Output(array_merge([
            'scenario' => Output::SCENARIO_CONFIG,
            'format' => $formatSpecs,
            'formatIndex' => $formatIndex,
            'path' => $path,
        ], $params));
    }
}
