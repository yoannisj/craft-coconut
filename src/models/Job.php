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

use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\InvalidArgumentException;
use yii\validators\InlineValidator;

use Craft;
use craft\base\Model;
use craft\validators\HandleValidator;
use craft\validators\DateTimeValidator;
use craft\models\Volume;
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
 * Model containing and validation Coconut transcoding Jobs
 */
class Job extends Model
{
    // =Static
    // =========================================================================

    /**
     * @var string
     */
    const STATUS_STARTING = 'job.starting';

    /**
     * @var string
     */
    const STATUS_COMPLETED = 'job.completed';

    /**
     * @var string
     */
    const STATUS_FAILED = 'job.failed';

    /**
     * @var string[]
     */
    const COMPLETED_STATUSES = [
        'job.completed', 'job.failed',
    ];

    // =Properties
    // =========================================================================

    /**
     * Job's ID in Craft's database
     *
     * @var int|null
     */
    public ?int $id = null;

    /**
     * Job's ID for reference in Coconut service and API
     *
     * @var string|null
     */

    public ?string $coconutId = null;

    /**
     * Job's handle (a.k.a its name)
     *
     * @var string|null
     */
    public ?string $handle = null;

    /**
     * Model representing the job's input video to transcode
     *
     * @var Input|null
     */

    private ?Input $_input = null;

    /**
     * The format used to generate missing output paths.
     * Defaults to the plugin's `defaultOutputPath` setting.
     *
     * @var string|null
     */
    private ?string $_outputPathFormat = null;

    /**
     * List of models representing the Output files to transcode
     *
     * @var array|null
     */
    private ?array $_outputs = null;

    /**
     * List of output models saved for this job in the database
     *
     * @var array|null
     */
    private ?array $_savedOutputs = null;

    /**
     * IDs for saved outputs that are not relevant anymore
     *
     * @var array|null
     */
    private ?array $_legacyOutputIds = null;

    /**
     * The storage settings for output files
     *
     * @var Storage|null
     */
    private ?Storage $_storage = null;

    /**
     * @var bool
     */
    protected bool $isNormalizedStorage = false;

    /**
     * @var Storage|null
     */
    private ?Storage $_fallbackStorage = null;

    /**
     * @var bool|null
     */
    protected ?bool $isFallbackStorage = null;

    /**
     * @var string|Notification|null
     */
    private string|Notification|null $_notification = null;

    /**
     * @var bool
     */
    protected bool $isNormalizedNotification = false;

    /**
     * Latest Job status, as communicated by the Coconut API and Notifications.
     *
     * @var string|null
     */
    public ?string $status = null;

    /**
     * Current progress of job's transcoding (in percentage), as communicated by
     * the Coconut API and Notifications.
     *
     * @var string|null
     */
    private ?string $_progress = '0%';

    /**
     * @var string|null
     */
    public ?string $message = null;

    /**
     * @var array|null
     */
    private ?array $_metadata = null;

    /**
     * Date at which the job was created by Coconut service
     *
     * @var Datetime|null
     */
    private ?DateTime $_createdAt = null;

    /**
     * Date at which the job was completed by Coconut service
     *
     * @var Datetime|null
     */
    private ?DateTime $_completedAt = null;

    /**
     * Date at which the job was created in Craft's database
     *
     * @var DateTime|null
     */
    public ?DateTime $dateCreated = null;

    /**
     * Date at which the job was last updated in Craft's database
     *
     * @var DateTime|null
     */
    public ?DateTime $dateUpdated = null;

    /**
     * @var string|null
     */
    public ?string $uid = null;

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
     * Setter method for normalized `input` property.
     *
     * Given `$input` parameter can be an Input model, an array of input properties,
     * an Asset element, an Asset element ID or a URL to an external input file
     *
     * @param mixed $input
     *
     * @return static Back-reference for method chaining
     */
    public function setInput( $input ): static
    {
        $this->_input = JobHelper::resolveInput($input);

        // fallback storage depends on input
        $this->_fallbackStorage = null;
        if ($this->isFallbackStorage) {
            $this->isNormalizedStorage = false;
        }

        return $this;
    }

    /**
     * Getter method for normalized `input` property.
     *
     * @return Input|null
     */
    public function getInput()
    {
        return $this->_input;
    }

    /**
     * Setter method for defaulted `outputPathFormat` property.
     *
     * @param string $pathFormat
     *
     * @return static Back-reference for method chaining
     */
    public function setOutputPathFormat( string $pathFormat = null ): static
    {
        $this->_outputPathFormat = $pathFormat;

        return $this;
    }

    /**
     * Getter method for defaulted `outputPathFormat` property.
     *
     * @return string
     */
    public function getOutputPathFormat(): string
    {
        return ($this->_outputPathFormat ?:
            Coconut::$plugin->getSettings()->defaultOutputPathFormat);
    }

    /**
     * Setter method for normalized `outputs` property.
     *
     * @param Output[]|string[]|array[] $outputs
     *
     * @return static Back-reference for method chaining
     */
    public function setOutputs( array $outputs ): static
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

        return $this;
    }

    /**
     * Getter method for normalized `outputs` property.
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
     * Getter method for read-only `savedOutputs` property.
     *
     * @return Output[] List of Output models saved in the database for this job
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
     * Getter method for computd `legacyOutputs` property.
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
     * @param string|array|Storage|Volume|null $storage
     *
     * @return static Back-reference for method chaining
     */
    public function setStorage( mixed $storage ): static
    {
        if (is_string($storage)) {
            $storage = JsonHelper::decodeIfJson($storage);
        }

        $this->_storage = $storage;
        $this->isNormalizedStorage = false;

        return $this;
    }

    /**
     * Getter method for resolved `storage` property
     *
     * @return Storage|null
     */
    public function getStorage(): ?Storage
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
    public function getFallbackStorage(): ?Storage
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
     *
     * @return static Back-reference for method chaining
     */
    public function setNotification( $notification ): static
    {
        $this->_notification = $notification;
        $this->isNormalizedNotification = false;

        return $this;
    }

    /**
     * Getter method for normalized `notification` property
     *
     * @return Notification|null
     */
    public function getNotification(): ?Notification
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
     *
     * @return static Back-reference for method chaining
     */
    public function setMetadata( string|array|null $metadata ): static
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

        return $this;
    }

    /**
     * Getter method for normalized `metadata` property
     *
     * @return array|null
     */
    public function getMetadata(): ?array
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
     *
     * @return static Back-reference for method chaining
     */
    public function setProgress( string|null $progress ): static
    {
        $this->_progress = $progress;
        return $this;
    }

    /**
     * Getter method for defaulted `progress` property
     *
     * @return string|null
     */
    public function getProgress(): ?string
    {
        if (!isset($this->_progress))
        {
            if ($this->getIsCompleted()) {
                $this->_progress = '100%';
            } else if (!$this->coconutId) {
                $this->_progress = '0%';
            }
        }

        return $this->_progress;
    }

    /**
     * Setter method for normalized `createdAt` property
     *
     * @param string|int|Datetime|null $createdAt
     *
     * @return static Back-reference for method chaining
     */
    public function setCreatedAt(
        string|int|DateTime|null $createdAt
    ): static
    {
        if ($createdAt) {
            $createdAt = DateTimeHelper::toDateTime($createdAt);
        }

        $this->_createdAt = $createdAt;

        return $this;
    }

    /**
     * Getter method for normalized `createdAt` property
     *
     * @return DateTime|null
     */
    public function getCreatedAt(): ?DateTime
    {
        return $this->_createdAt;
    }

    /**
     * Setter method for normalized `completedAt` property
     *
     * @param string|int|Datetime|null $completedAt
     *
     * @return static Back-reference for method chaining
     */
    public function setCompletedAt( $completedAt ): static
    {
        if ($completedAt) {
            $completedAt = DateTimeHelper::toDateTime($completedAt);
        }

        $this->_completedAt = $completedAt;

        return $this;
    }

    /**
     * Getter method for normalized `completedAt` property
     *
     * @return DateTime|null
     */
    public function getCompletedAt(): ?DateTime
    {
        return $this->_completedAt;
        return $this;
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

    // =Validation
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules['attrRequired'] = [ [
            'input',
            'outputs',
            'storage',
        ], 'required' ];

        $rules['attrint']  = [ [
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
     * Validation method for attributes storing related models.
     *
     * @param string $attribute Attribute to validate
     * @param array $params Validation params
     * @param InlindeValidator $validator Yii validator class
     *
     * @return void
     */
    public function validateModels(
        string $attribute,
        array $params = null,
        InlineValidator $validator
    ): void
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
    public function fields(): array
    {
        $fields = parent::fields();

        // some attributes should be 'extraFields'
        ArrayHelper::removeValue($fields, 'storageParams');

        return $fields;
    }

    /**
     * @inheritdoc
     */
    public function extraFields(): array
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
    public function getOutputByKey( string $key ): ?Output
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
     * Adds given list of output models to the job.
     *
     * Each value in the `$outputs` argument can be anything understood by
     * [[JobHelper::resolveOutput()]]
     *
     * @param array $outputs List of outputs to add
     *
     * @return static Back-reference for method chaining
     *
     * @throws InvalidArgumentException If job has already been ran by Coconut.co
     */
    public function addOutputs( array $outputs ): static
    {
        $outputs = JobHelper::resolveOutputs($outputs);

        foreach ($outputs as $output) {
            $this->addOutput($output);
        }

        return $this;
    }

    /**
     * Adds given output model to the job.
     *
     * * The `$output` argument can be anything understood by
     * [[JobHelper::resolveOutput()]]
     *
     * @param Output $output Output model (or config array) to add
     *
     * @return static Back-reference for method chaining
     *
     * @throws InvalidArgumentException If job has already been ran by Coconut.co
     */
    public function addOutput( Output $output ): static
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

        return $this;
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
     * @return Output|null
     */
    // protected function resolveOutputParams( $params, string $formatKey = null, int $formatIndex = null )
    // {
    //     if (!$formatKey)
    //     {
    //         if (is_string($params))
    //         {
    //             $params =[
    //                 'format' => JobHelper::decodeFormat($params)
    //             ];
    //         }
    //     }

    //     $isArray = is_array($params);
    //     $isModel = ($isArray == false && ($params instanceof Output));

    //     if (!$isArray && !$isModel)
    //     {
    //         throw new InvalidConfigException(
    //             "Each output must be a format string, an array of output params or an Output model");
    //     }

    //     $output = null;

    //     // merge format specs from output index with output params
    //     $keySpecs = $formatKey ? JobHelper::decodeFormat($formatKey) : [];
    //     $container = $keySpecs['container'] ?? null; // index should always define a container
    //     $paramSpecs = ArrayHelper::getValue($params, 'format');

    //     if (is_array($paramSpecs))
    //     {
    //         if ($container) $paramSpecs['container'] = $container;
    //         $paramSpecs = JobHelper::parseFormat($paramSpecs);
    //     }

    //     else if (is_string($paramSpecs)) { // support defining 'format' param as a JSON or format string
    //         $paramSpecs = JobHelper::decodeFormat($paramSpecs);
    //         if ($container) $paramSpecs['container'] = $container;
    //     }


    //     // @todo: should index specs override param specs?
    //     $formatSpecs = array_merge($keySpecs, $paramSpecs ?? []);

    //     if ($isArray)
    //     {
    //         $params['format'] = $formatSpecs;
    //         $output = new Output($params);
    //     }

    //     else {
    //         $output = $params;
    //         $output->format = $formatSpecs;
    //     }

    //     return $output;
    // }
}
