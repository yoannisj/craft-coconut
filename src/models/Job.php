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
use craft\validators\DateTimeValidator;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use craft\helpers\ArrayHelper;
use craft\helpers\Json as JsonHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\FileHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\behaviors\PropertyAliasBehavior;
use yoannisj\coconut\models\Input;
use yoannisj\coconut\models\Notification;
use yoannisj\coconut\validators\AssociativeArrayValidator;
use yoannisj\coconut\helpers\JobHelper;

/**
 *
 */

class Job extends Model
{
    // =Static
    // =========================================================================

    const STATUS_STARTING = 'job.starting';
    const STATUS_COMPLETED = 'job.completed';
    const STATUS_FAILED = 'job.failed';

    // =Properties
    // =========================================================================

    /**
     * @var int|null Job's ID in Craft's database
     */

    public $id;

    /**
     * @var string|null Job's ID for reference in Coconut service and API
     */

    public $coconutId;

    /**
     * @var Input|null Model representing the job's input video to transcode
     */

    private $_input;

    /**
     * @var boolean Whether the input property value has been normalized
     */

    protected $isNormalizedInput;

    /**
     * @var string The format used to generate missing output paths.
     *  Defaults to the plugin's `defaultOutputPath` setting.
     */

    private $_outputPathFormat = null;

    /**
     * @var array List of models representing the Output files to transcode
     */

    private $_outputs;

    /**
     * @var boolean Whether the outputs property has been normalized
     */

    protected $isNormalizedOutputs = false;

    /**
     * @var string
     */

    private $_storageHandle;

    /**
     * @var Storage The storage settings for output files
     */

    private $_storage;

    /**
     * @var boolean
     */

    protected $isNormalizedStorage;

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
     * @var Notification
     */

    private $_notification;

    /**
     * @var bool
     */

    protected $isNormalizedNotification;

    /**
     * @var string|null Latest job status
     */

    public $status;

    /**
     * @var string|null Current progress of job's transcoding (in percentage)
     */

    public $progress;

    /**
     * @var string
     */

    public $errorCode;

    /**
     * @var string
     */

    public $message;

    /**
     * @var array
     */

    private $_metadata;

    /**
     * @var Datetime|null Date at which the job was created by Coconut service
     */

    private $_createdAt;

    /**
     * @var Datetime|null Date at which the job was completed by Coconut service
     */

    private $_completedAt;

    /**
     * @var Datetime|null Date at which the job was created in Craft's database
     */

    public $dateCreated;

    /**
     * @var Datetime|null Date at which the job was last updated in Craft's database
     */

    public $dateUpdated;

    /**
     * @var string
     */

    public $uid;

    // =Public Methods
    // =========================================================================

    // /**
    //  *
    //  */

    // public function __sleep()
    // {
    //     $fields = $this->fields();
    //     $props = [];

    //     foreach ($fields as $field)
    //     {
    //         if (property_exists($this, $field)) {
    //             $props[] = $field;
    //         } else if (property_exists($this, '_' . $field)) {
    //             $props[] = '_'.$field;
    //         }
    //     }

    //     return $props;
    // }

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

        // optimize for normal input values
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

            if (is_array($input)) {
                $input = new Input($input);
            }

            else if ($input instanceof Asset) {
                $input = new Input([ 'asset' => $input ]);
            }

            else if (is_numeric($input)) {
                $input = new Input([ 'assetId' => (int)$input ]);
            }

            else if (is_string($input)) {
                $input = new Input([ 'url' => $input ]);
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
    }

    /**
     * Getter method for defaulted `outputPathFormat` property
     *
     * @return string
     */

    public function getOutputPathFormat(): string
    {
        return ($this->_outputPathFormat ?:
            Coconut::$plugin->getSettings()->defaultOutputPathFormat);
    }

    /**
     * @param Output[]|string[]|array[] $outputs
     */

    public function setOutputs( array $outputs )
    {
        if ($this->coconutId)
        {
            $currOutputs = $this->getOutputs();
            $newOutputs = [];

            foreach ($outputs as $output)
            {
                if (is_array($output)) {
                    $output = new Output($output);
                } else if (is_string($output)) {
                    $output = new Output([ 'format' => $output ]);
                } else if (!$output instanceof Output) {
                    throw new InvalidConfigException('Could not resolve output');
                }

                $currOutput = $this->getOutputByKey($output->key);
                if ($currOutput) {
                    Craft::configure($currOutput, $output->getAttributes());
                    $output = $currOutput;
                }

                $newOutputs[] = $output;
            }

            $this->_outputs = $newOutputs;
            $this->isNormalizedOutputs = true;
        }

        else
        {
            $this->_outputs = $outputs;
            $this->isNormalizedOutputs = false;
        }
    }

    /**
     * @return Output[]
     */

     public function getOutputs()
    {
        if (!$this->isNormalizedOutputs)
        {
            $outputs = [];

            if (isset($this->_outputs))
            {
                // normalize set value for outputs property
                foreach ($this->_outputs as $formatKey => $params)
                {
                    $output = null;

                    // if job has an Id
                    if ($this->id && $this->coconutId)
                    {
                        if (!($params instanceof Output)
                            && ArrayHelper::isAssociative($params)
                        ) {
                            throw new InvalidConfigException(
                                'Existing job outputs must be an indexed list of output models or params');
                        }

                        $outputs[] = $this->resolveOutputParams($params, null);
                    }

                    // support defining output as a format string (no extra params)
                    // or to define format fully in output's 'format' param (instead of in index)
                    else if (is_numeric($formatKey))
                    {
                        $output = $this->resolveOutputParams($params, null);
                        $outputs[$output->formatString] = $output; // use output key as index
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
            }

            else if (isset($this->id)) {
                // get outputs from db records
                $outputs = Coconut::$plugin->getOUtputs()
                    ->getOutputsByJobId($this->id);
            }

            $this->_outputs = $outputs;
            $this->isNormalizedOutputs = true;
        }

        return $this->_outputs;
    }

    /**
     * Setter method for reactive `storageHandle` property
     *
     * @param string|null $handle
     */

    public function setStorageHandle( string $handle = null )
    {
        if ($handle != $this->_storageHandle
            && (!($volume = $this->getStorageVolume()) || $volume->handle != $handle)
        ) {
            $this->_storageHandle = $handle;
            $this->_storageVolumeId = null;
            $this->_storageVolume = null;
            $this->_storage = null;

            // force re-calculation next time storage is accessed
            $this->isNormalizedStorage = false;
        }
    }

    /**
     * @return string|null
     */

    public function getStorageHandle()
    {
        return $this->_storageHandle;
    }

    /**
     * Setter method for reactive 'storageVolumeId' property
     *
     * @param integer|null $volumeId
     */

    public function setStorageVolumeId( int $volumeId = null )
    {
        if (!$this->_storageVolumeId == $volumeId
            && (!($volume = $this->getStorageVolume()) || $volume->id != $volumeId)
        ) {
            $this->_storageVolumeId = $volumeId;
            $this->_storageVolume = null;
            $this->_storageHandle = null;
            $this->_storage = null;

            // force re-calculation next time storage is accessed
            $this->isNormalizedStorage = false;
        }
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
        ) {
            $this->_storageVolumeId = $volume->id;
        }

        return $this->_storageVolumeId;
    }

    /**
     * Setter method for reactive `storageVolume` property
     *
     * @param VolumeInterface|null $volume
     */

    public function setStorageVolume( VolumeInterface $volume = null )
    {
        if (!$volume || (!$currVolume = $this->getStorageVolume())
            || $currVolume->id != $volume->id
        ) {
            $this->_storageVolume = $volume;
            $this->_storageVolumeId = $volume ? $volume->id : null;
            $this->_storageHandle = null;
            $this->_storage = null;

            // force re-calculation next time storage is accessed
            $this->isNormalizedStorage = false;
        }
    }

    /**
     * Getter method for reactive `storageVolume` property
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
     * Setter method for normalized `storage` property
     *
     * If given $storage is a string, it will first be checked against named storage
     * settings, or it will be considered a volume handle.
     *
     * @param string|array|Storage|VolumeInterface|null $storage
     */

    public function setStorage( $storage )
    {
        if (is_string($storage)) {
            $storage = JsonHelper::decodeIfJson($storage);
        }

        if (is_numeric($storage)) {
            $this->setStorageVolumeId((int)$storage);
        }

        else if (is_string($storage)) {
            $this->setStorageHandle($storage);
        }

        else if ($storage instanceof VolumeInterface) {
            $this->setStorageVolume($storage);
        }

        else
        {
            $this->_storage = $storage ?: null;
            $this->_storageHandle = null;
            $this->_storageVolumeId = null;
            $this->_storageVolume = null;
        }

        // force re-calculation next time storage is accessed
        $this->isNormalizedStorage = false;
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
     * Setter method for the normalized `notification` property
     *
     * @param bool|string|array|Notification|null $notification
     */

    public function setNotification( $notification )
    {
        $this->_notification = $notification;
        $this->isNormalizedNotification = false;
    }

    /**
     * Getter method for normalized `notification` property
     *
     * @return Notification|null
     */

    public function getNotification()
    {
        if (!$this->isNormalizedNotification)
        {
            $settings = Coconut::$plugin->getSettings();
            $notification = $this->_notification  ?? $settings->defaultJobNotification;

            if (is_string($notification))
            {
                $notification = new Notification([
                    'type' => 'http',
                    'url' => $notification,
                    'events' => true,
                    'metadata' => true,
                ]);
            }

            else if (is_array($notification))
            {
                $notification = new Notification(array_merge([
                    'events' => true,
                    'metadata' => true,
                ], $notification));
            }

            $this->_notification = $notification;
            $this->isNormalizedNotification = true;
        }

        return $this->_notification;
    }

    /**
     * Setter method for normalized metadata property
     *
     * @param string|array $metadata
     */

    public function setMetadata( $metadata )
    {
        if (is_string($metadata)) {
            $metadata = JsonHelper::decode($metadata);
        }

        $this->_metadata = $metadata;
    }

    /**
     * Getter method for normalized metadata property
     *
     * @return array|null
     */

    public function getMetadata()
    {
        return $this->_metadata;
    }

    /**
     * Setter method for normalized `createdAt` property
     *
     * @param string|integer|Datetime|null $createdAt
     */

    public function setCreatedAt( $createdAt )
    {
        if ($createdAt) {
            $createdAt = DateTimeHelper::toDateTime($createdAt);
        }

        $this->_createdAt = $createdAt;
    }

    /**
     * Getter method for normalized `createdAt` property
     *
     * @return DateTime|null
     */

    public function getCreatedAt()
    {
        return $this->_createdAt;
    }

    /**
     * Setter method for normalized `completedAt` property
     *
     * @param string|integer|Datetime|null $completedAt
     */

    public function setCompletedAt( $completedAt )
    {
        if ($completedAt) {
            $completedAt = DateTimeHelper::toDateTime($completedAt);
        }

        $this->_completedAt = $completedAt;
    }

    /**
     * Getter method for normalized `completedAt` property
     *
     * @return DateTime|null
     */

    public function getCompletedAt()
    {
        return $this->_completedAt;
    }

    // =Attributes
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function attributes()
    {
        $attributes = parent::attributes();

        $attributes[] = 'storageHandle';
        $attributes[] = 'storageVolumeId';
        $attributes[] = 'notification';
        $attributes[] = 'metadata';
        $attributes[] = 'createdAt';
        $attributes[] = 'completedAt';

        return $attributes;
    }

    /**
     * @inheritdoc
     */

    public function datetimeAttributes(): array
    {
        $attributes = parent::datetimeAttributes();

        $attributes[] = 'outputPathFormat';
        $attributes[] = 'createdAt';
        $attributes[] = 'completedAt';

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

        $rules['attrsString'] = [ [
            'outputPathFormat',
            'progress',
            'errorCode',
            'message'
        ], 'string' ];

        $rules['attrAssociativeArray'] = [ ['metadata'], AssociativeArrayValidator::class ];

        $rules['attrDateTime'] = [ [
            'createdAt',
            'completedAt',
            'dateCreated',
            'dateUpdated',
        ], DateTimeValidator::class ];

        $rules['statusInRange'] = [ 'status', 'in', 'range' => [
            self::STATUS_STARTING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ]];

        $rules['errorCodeValid'] = [ 'errorCode', 'validateErrorCode'];

        return $rules;
    }

    /**
     *
     */

    public function validateErrorCode( $attribute, $params, $validator )
    {
        if (!empty($this->$attribute))
        {
            $message = $model->message ?? Craft::t('coconut', 'Job API returned error');
            $validator->addError($attribute, $message);
        }
    }

    // =Fields
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function fields()
    {
        $fields = parent::fields();

        // some attributes should be 'extraFields'
        ArrayHelper::removeValue($fields, 'storageParams');

        return $fields;
    }

    /**
     * @inheritdoc
     */

    public function extraFields()
    {
        $fields = parent::extraFields();

        $fields[] = 'input';
        $fields[] = 'outputs';
        $fields[] = 'storage';
        $fields[] = 'storageVolume';

        return $fields;
    }

    // =Operations
    // -------------------------------------------------------------------------

    /**
     * Returns coconut API parameters to create and start the job
     *
     * @return array
     */

    public function toParams(): array
    {
        if (!$this->input) {
            return [];
        }

        $params = [
            'input' => $this->input->toParams(),
            'storage' => $this->storage->toParams(),
            'notification' => $this->notification->toParams(),
            'outputs' => [],
        ];

        foreach ($this->getOutputs() as $index => $output)
        {
            if (is_array($output))
            {
                foreach ($output as $formatIndex => $formatOutput) {
                    $outputParams = $formatOutput->toParams();
                    $params['outputs'][$index][$formatIndex] = $outputParams;
                }
            }

            else {
                $params['outputs'][$index] = $output->toParams();
            }
        }

        return $params;
    }

    /**
     * Returns output mode for given output key
     *
     * @param string $key
     *
     * @return Output|null
     */

    public function getOutputByKey( string $key )
    {
        $outputs = $this->getOutputs();

        foreach ($outputs as $format => $output)
        {
            if (is_array($output))
            {
                foreach ($output as $index => $indexOutput)
                {
                    if ($indexOutput->key == $key) {
                        return $indexOutput;
                    }
                }
            }

            else if ($output->key == $key) {
                return $output;
            }
        }

        return null;
    }

    /**
     *
     */

    public function addOutput( Output $output )
    {
    }

    /**
     * Returns a new config model, which only includes given list of output
     * formats.
     *
     * @param array $formats
     *
     * @return \yoannisj\coconut\models\Job
     */

    // function forFormats( array $formats )
    // {
    //     $config = clone $this;
    //     $outputs = $this->getOutputs();

    //     $rawOutputs = $this->_outputs;
    //     $formatOutputs = [];

    //     foreach ($formats as $format => $options)
    //     {
    //         if (is_numeric($format)) {
    //             $format = $options;
    //             $options = null;
    //         }

    //         if (in_array($format, $rawOutputs)) {
    //             $formatOutputs[] = $format;
    //         }

    //         else if (array_key_exists($format, $rawOutputs)) {
    //             $formatOutputs[$format] = $rawOutputs[$format];
    //         }
    //     }

    //     $newConfig = clone $this;
    //     $newConfig->outputs = $formatOutputs;

    //     return $newConfig;
    // }

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
                    'format' => JobHelper::decodeFormat($params)
                ];
            }
        }

        $isArray = is_array($params);
        $isModel = ($isArray == false && ($params instanceof Output));

        if (!$isArray && !$isModel)
        {
            throw new InvalidConfigException(
                "Each output must be a format string, an array of output params or an Output model");
        }

        $output = null;

        // merge format specs from output index with output params
        $keySpecs = $formatKey ? JobHelper::decodeFormat($formatKey) : [];
        $container = $keySpecs['container'] ?? null; // index should always define a container
        $paramSpecs = ArrayHelper::getValue($params, 'format');

        if (is_array($paramSpecs))
        {
            if ($container) $paramSpecs['container'] = $container;
            $paramSpecs = JobHelper::parseFormat($paramSpecs);
        }

        else if (is_string($paramSpecs)) { // support defining 'format' param as a JSON or format string
            $paramSpecs = JobHelper::decodeFormat($paramSpecs);
            if ($container) $paramSpecs['container'] = $container;
        }


        // @todo: should index specs override param specs?
        $formatSpecs = array_merge($keySpecs, $paramSpecs ?? []);

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
