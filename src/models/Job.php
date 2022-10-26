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

use yii\base\Exception;
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

    const COMPLETED_STATUSES = [
        'job.completed', 'job.failed',
    ];

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
     * @var string The format used to generate missing output paths.
     *  Defaults to the plugin's `defaultOutputPath` setting.
     */

    private $_outputPathFormat = null;

    /**
     * @var array List of models representing the Output files to transcode
     */

    private $_outputs;

    /**
     * @var array List of output models saved for this job in the database
     */

    private $_savedOutputs;

    /**
     * @var array IDs for saved outputs that are not relevant anymore
     */

    private $_legacyOutputIds;

    /**
     * @var Storage The storage settings for output files
     */

    private $_storage;

    /**
     * @var boolean
     */

    protected $isNormalizedStorage;

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

    private $_progress = '0%';

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
     * @param mixed $input
     */

    public function setInput( $input )
    {
        $this->_input = JobHelper::resolveInput($input);

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
        $resolvedOutputs = [];

        if ($this->coconutId)
        {
            // if this is an existing coconut job
            $savedOutputs = $this->getSavedOutputs();
            $savedOutputsByKey = [];

            foreach ($savedOutputs as $index => $savedOutput) {
                $savedOutputsByKey[$savedOutput->key] = $savedOutput;
            }

            $savedOutputs = $savedOutputsByKey;

            foreach ($outputs as $index => $output)
            {
                if (!is_numeric($index)
                    || (!$output instanceof Output && !ArrayHelper::isAssociative($output))
                ) {
                    throw new InvalidConfigException(
                        'Existing job outputs must be an indexed list of output models or parameters');
                }

                $outputKey = ArrayHelper::getValue($output, 'key');

                if (is_array($output)) {
                    $output = new Output($output);
                }

                // if output with same key was saved before
                $savedOutput = $savedOutputs[$outputKey] ?? null;
                if ($savedOutput)
                {
                    // replace old output by new one
                    $output->id = $savedOutput->id;
                    $output->dateCreated = $savedOutput->dateCreated;
                    $output->uid = $savedOutput->uid;

                    unset($savedOutputs[$outputKey]);
                }

                $resolvedOutputs[] = $output;
            }

            // saved outputs that were not replaced are now legacy
            $this->_legacyOutputIds = ArrayHelper::getColumn($savedOutputs, 'id');
        }

        else {
            $resolvedOutputs = JobHelper::resolveOutputs($outputs);
        }

        foreach ($resolvedOutputs as $output) {
            $output->setJob($this);
        }

        $this->_outputs = $resolvedOutputs;
    }

    /**
     * Getter method for normalized `outputs` property
     *
     * @return Output[]
     */

     public function getOutputs(): array
    {
        // default to saved outputs
        if (!isset($this->_outputs)) {
            return $this->getSavedOutputs();
        }

        return $this->_outputs ?? [];
    }

    /**
     * Getter method for read-only `savedOutputs` property
     *
     * @return Output[] List of Output models saved in the database for this job
     *
     * @todo Memoize queried outputs in the Outputs service
     */

    public function getSavedOutputs(): array
    {
        if (!isset($this->_savedOutputs) && $this->id)
        {
            $this->_savedOutputs = Coconut::$plugin->getOutputs()
                ->getOutputsByJobId($this->id);
        }

        return $this->_savedOutputs ?? [];
    }

    /**
     * Getter method for computd `legacyOutputs` property
     *
     * @return Output[]
     */

    public function getLegacyOutputs(): array
    {
        if (empty($this->_legacyOutputIds)) {
            return [];
        }

        $savedOutputs = $this->getSavedOutputs();
        $legacyOutputs = [];

        foreach ($savedOutputs as $output)
        {
            if (in_array($output->id, $this->_legacyOutputIds)) {
                $legacyOutputs[] = $output;
            }
        }

        return $legacyOutputs;
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

        $this->_storage = $storage;
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

            // try to resolve previously set storage
            if ($storage) {
                $storage = JobHelper::resolveStorage($storage);
            }

            // if storage was not set or could not be resolved
            if (!$storage) {
                $storage = $this->getFallbackStorage();
                $this->isFallbackStorage = true;
            }

            else {
                $this->isFallbackStorage = false;
            }

            // make sure we resolved to a valid storage
            if ($storage && !$storage instanceof Storage)
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
        if (!$this->_fallbackStorage)
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
     * @param mixed $notification
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
            $notification = $this->_notification ?? $settings->defaultJobNotification;

            if (is_string($notification)) {
                $notification = JsonHelper::decodeIfJson($notification);
            }

            // still as string? than it must be a URL
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
     * @param string|array|null $metadata
     */

    public function setMetadata( $metadata )
    {
        if (is_string($metadata)) {
            $metadata = JsonHelper::decode($metadata);
        }

        if ($metadata !== null && !ArrayHelper::isAssociative($metadata))
        {
            throw new InvalidConfigException(
                "Property `metadata` must be a JSON string, an associative array or null");
        }

        if ($metadata)
        {
            // transfer input metadata to the input model
            $inputMetadata = ArrayHelper::remove($metadata, 'input');
            if ($inputMetadata)
            {
                $input = $this->getInput();
                if ($input) $input->setMetadata($inputMetadata);
            }

            // transfer outputs metadata to the output models
            $outputsMetadata = ArrayHelper::remove($metadata, 'outputs');
            if ($outputsMetadata)
            {
                foreach ($metadata['outputs'] as $key => $outputMetadata)
                {
                    $output = $this->getOutputByKey($key);

                    if (!$output) {
                        $output = new Output([ 'key' => $key ]);
                        $this->addOutput($output);
                    }

                    $output->setMetadata($outputMetadata);
                }
            }
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
        // job metadata comes from Coconut, so it needs to exist there
        if (!$this->coconutId) {
            return null;
        }

        $metadata = $this->_metadata ?? [];

        // collect metadata from input and output models
        $input = $this->getInput();
        $outputs = $this->getOutputs();

        $metadata['input'] = $input ? $input->getMetadata() : null;
        $metadata['outputs'] = [];

        foreach ($outputs as $output) {
            $metadata['outputs'][] = $output->getMetadata();
        }

        return $metadata;
    }

    /**
     * Setter method for defaulted `progress` property
     *
     * @param string|null $progress
     */

    public function setProgress( string $progress = null )
    {
        $this->_progress = $progress;
    }

    /**
     * Getter method for defaulted `progress` property
     *
     * @return string
     */

    public function getProgress()
    {
        if (!isset($this->_progress))
        {
            if ($this->getIsCompleted()) {
                $this->_progress = '100%';
            }
        }

        return $this->_progress;
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

    /**
     * Getter method for computed 'isCompleted' property
     *
     * @return bool
     */

    public function getIsCompleted(): bool
    {
        return in_array($this->status, static::COMPLETED_STATUSES);
    }

    // =Attributes
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function attributes()
    {
        $attributes = parent::attributes();

        $attributes[] = 'outputPathFormat';
        $attributes[] = 'notification';
        $attributes[] = 'progress';
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

        $rules['attrRequired'] = [ [
            'input',
            'outputs',
            'storage',
        ], 'required' ];

        $rules['attrInteger']  = [ [
            'id',
        ], 'integer' ];

        $rules['attrString'] = [ [
            'coconutId',
            'status',
            'progress',
            'outputPathFormat',
            'message',
            'uid',
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

        $rules['outputsValid'] = [ 'outputs', 'validateModels' ];

        return $rules;
    }


    /**
     *
     */

    public function validateModels( string $attribute, array $params = null, InlineValidator $validator )
    {
        $models = $this->$attribute;

        foreach ($models as $model)
        {
            if (!$model->validate())
            {
                $validator->addError($this, $attribute,
                    'All `{attribute}` models must be valid');
                break; // no need to validate other models
            }
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
     * Returns output model for given output key
     *
     * @param string $key
     *
     * @return Output|null
     */

    public function getOutputByKey( string $key )
    {
        $outputs = $this->getOutputs();

        foreach ($outputs as $output)
        {
            // when configuring the job, multiple outputs per format are supported
            if (is_array($output))
            {
                foreach ($output as $index => $nthOutput)
                {
                    if ($nthOutput->key == $key) {
                        return $nthOutput;
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
     * Adds given list of output models to the job
     *
     * The `$outputs` argument can be anything supported by the
     *  `JobHelper::resolveOutputs()` function.
     *  or
     *
     * @param array $outputs List of outputs to add
     *
     * @throws InvalidArgumentException If job has already been ran by Coconut.co
     */

    public function addOutputs( array $outputs )
    {
        $outputs = JobHelper::resolveOutputs($outputs);

        foreach ($outputs as $output) {
            $this->addOutput($output);
        }
    }

    /**
     * Adds given output model to the job
     *
     * @param Output $output Output model (or config array) to add
     *
     * @throws InvalidArgumentException If job has already been ran by Coconut.co
     */

    public function addOutput( Output $output )
    {
        if ($this->coconutId)
        {
            throw new InvalidArgumentException(
                'Can not add output to a Job that has already been ran.');
        }

        $outputKey = $output->key;

        if ($this->id && $output->jobId && $this->id !== $output->jobId) {
            throw new InvalidArgumentException('Output already belongs to a different job');
        }

        if ($outputKey && $this->getOutputByKey($outputKey)) {
            throw new InvalidArgumentException("Job already has an output with key '$outputKey'");
        }

        if ($this->coconutId) {
            $this->_outputs[] = $output;
        }

        else
        {
            $outputs = $this->getOutputs();

            // when configuring a job, multiple outputs can be defined for one format
            $formatString = $output->getFormatString();
            $formatOutputs = $outputs[$formatString] ?? null;

            if ($formatOutputs)
            {
                if (!is_array($formatOutputs)) {
                    $formatOutputs = [ $formatOutputs ];
                }

                $formatOutputs[] = $output;

                $this->_outputs[$formatString] = $formatOutputs;
            }

            else {
                $this->_outputs[$formatString] = $output;
            }
        }
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

    protected function resolveOutputParams( $params, string $formatKey = null, int $formatIndex = null )
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
