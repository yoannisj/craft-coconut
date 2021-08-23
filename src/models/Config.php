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
use craft\helpers\ArrayHelper;
use craft\helpers\Json as JsonHelper;
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

    /**
     * @var boolean Whether outputs property was normalized or not
     */

    protected $isNormalizedOutputs = false;

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
            $input = null;

            if ($this->_input instanceof Input) {
                $input = $this->_input;
            }

            else if (is_array($this->_input)) {
                $input = new Input($this->_input);
            }

            else if ($this->_input)
            {
                $input = new Input();

                if ($this->_input instanceof Asset) {
                    $input->asset = $this->_input;
                } else if (is_numeric($this->_input)) {
                    $input->assetId = (int)$this->_input;
                } else if (is_string($this->_input)) {
                    $input->url = $this->_input;
                }
            }

            $this->_input = $input;
            $this->isNormalizedInput = true;
        }

        return $this->_input;
    }

    /**
     * Setter method for defaulted `outputPathFormat` property
     *
     * @param string $pathFormat
     */

    public function setOutputPathFormat( string $pathFormat = null )
    {
        $this->_outputPathFormat = $pathFormat;

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
     * @param Output[]|string[]|array[] $outputs
     */

    public function setOutputs( array $outputs )
    {
        $this->_outputs = $outputs;
        $this->isNormalizedOutputs = false;
    }

    /**
     * @return Output[]
     */

     public function getOutputs()
    {
        if (!$this->isNormalizedOutputs && isset($this->_outputs))
        {
            $outputs = [];

            foreach ($this->_outputs as $formatKey => $params)
            {
                $output = null;

                // support defining output as a format string (no extra params)
                // or to define format fully in output's 'format' param (instead of in index)
                if (is_numeric($formatKey))
                {
                    $output = $this->resolveOutputParams($params, null);
                    $outputs[$output->key] = $output; // use output key as index
                }

                // support multiple outputs for 1 same format
                // @see https://docs.coconut.co/jobs/api#same-output-format-with-different-settings
                else if (is_array($params) && !empty($params)
                    && ArrayHelper::isIndexed($params)
                ) {
                    $formatIndex = 1;

                    foreach ($params as $prm)
                    {
                        $output = $this->resolveOutputParams($prm, $formatKey, $formatIndex++);
                        $outputs[$formatKey][] = $output;
                    }
                }

                else {
                    $output = $this->resolveOutputParams($params, $formatKey);
                    $outputs[$formatKey] = $output;
                }
            }

            $this->_outputs = $outputs;
            $this->isNormalizedOutputs = true;
        }

        return $this->_outputs;
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
        if (!$storage)
        {
            $this->_storage = null;
            $this->_storageHandle = null;
            $this->_storageVolumeId = null;
            $this->_storageVolume = null;

            $this->isNormalizedStorage = false;
        }

        else if ($storage instanceof Storage)
        {
            $this->_storage = $storage;
            $this->_storageHandle = null;
            $this->_storageVolume = null;
            $this->_storageVolumeId = null;

            $this->isNormalizedStorage = true;
        }

        else if ($storage instanceof VolumeInterface)
        {
            // $this->_storage = null;
            $this->_storageVolume = $storage;
            $this->_storageHandle = $storage->handle;
            $this->_storageVolumeId = $storage->id;
            // $this->setStorageVolume($storage);

            // force re-calculation next time storage is accessed
            $this->isNormalizedStorage = false;
        }

        else if (is_string($storage) && $storage != $this->_storageHandle
            && (!$this->_storageVolume || $storage != $this->_storageVolume->handle)
        ) {
            // $this->_storage = null;
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
                $storage = Coconut::$plugin->getStorages()
                    ->getNamedStorage($this->_storageHandle);

                // or check volume by handle
                if (!$storage && ($volume = $this->getStorageVolume()))
                {
                    $storage = Coconut::$plugin->getStorages()
                        ->getVolumeStorage($volume);
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
        $attributes[] = 'outputs';
        $attributes[] = 'storage';
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
     * Resolves parameters for single output in Cococnut job config settings,
     * and returns the corresponding Output model
     *
     * @param array|Output $params The output params from the config's list of `outputs`
     * @param string|null $formatKey The key of the output in the config's list of `outputs`
     * @param string|null $formatIndex The output params index when more than one was given for this string index
     *
     * @return Output
     */

    /**
     * @return Output|null
     */

    protected function resolveOutputParams( $params, $formatKey, int $formatIndex = null )
    {
        if (!$formatKey)
        {
            if (is_string($params))
            {
                $params =[
                    'format' => ConfigHelper::decodeFormat($params)
                ];
            }
        }

        $isArray = is_array($params);
        $isModel = !$isArray && ($params instanceof Output);

        if (!$isArray && !$isModel)
        {
            // var_dump($formatKey); var_dump($params);
            // die();

            throw new InvalidConfigException(
                "Each output must be a format string, an array of output params or an Output model");
        }

        $output = null;

        // merge format specs from output index with output params
        $keySpecs = $formatKey ? ConfigHelper::decodeFormat($formatKey) : [];
        $container = $keySpecs['container'] ?? null; // index should always define a container
        $paramSpecs = ArrayHelper::getValue($params, 'format');

        if (is_array($paramSpecs))
        {
            if ($container) $paramSpecs['container'] = $container;
            $paramSpecs = ConfigHelper::parseFormat($paramSpecs);
        }

        else if (is_string($paramSpecs)) { // support defining 'format' param as a JSON or format string
            $paramSpecs = ConfigHelper::decodeFormat($paramSpecs);
            if ($container) $paramSpecs['container'] = $container;
        }

        // @todo: should index specs override param specs?
        $formatSpecs = array_merge($keySpecs, $paramSpecs);

        if ($isArray)
        {
            $params['format'] = $formatSpecs;
            $output = new Output($params);
        }

        else {
            $output = $params;
            $output->format = $formatSpecs;
        }

        return $output;
    }
}
