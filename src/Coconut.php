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

namespace yoannisj\coconut;

use yii\base\Event;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;


use Craft;
use craft\base\VolumeInterface;
use craft\base\Plugin;
use craft\base\Element;
use craft\models\Volume;
use craft\elements\Asset;
use craft\web\UrlManager;
use craft\services\Elements;
use craft\services\Assets;
use craft\web\twig\variables\CraftVariable;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\ElementEvent;
use craft\events\AssetEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\UrlHelper;
use craft\helpers\ElementHelper;

use Coconut\Client as CoconutClient;

use yoannisj\coconut\services\Storages;
use yoannisj\coconut\services\Jobs;
use yoannisj\coconut\services\Outputs;
use yoannisj\coconut\models\Settings;
use yoannisj\coconut\models\Job;
use yoannisj\coconut\models\Storage;
use yoannisj\coconut\elements\actions\TranscodeVideo;
use yoannisj\coconut\elements\actions\ClearVideoOutputs;
use yoannisj\coconut\queue\jobs\RunJob;
use yoannisj\coconut\variables\CoconutVariable;
use yoannisj\coconut\events\VolumeStorageEvent;
use yoannisj\coconut\helpers\JobHelper;

/**
 * Coconut plugin class for Craft
 */

class Coconut extends Plugin
{
    // =Static
    // =========================================================================

    /**
     * reference to the plugin's instance
     * @var Plugin
     */

    public static $plugin;


    // =Tables (DB)
    // -------------------------------------------------------------------------

    /**
     * Name of database table used to store references to coconut inputs
     */

    const TABLE_JOBS = '{{%coconut_jobs}}';

    /**
     * Name of database table used to store coconut outputs
     */

    const TABLE_OUTPUTS = '{{%coconut_outputs}}';

    // =Services
    // -------------------------------------------------------------------------

    const SERVICE_COCONUT = 'coconut';
    const SERVICE_S3 = 's3';
    const SERVICE_GCS = 'gcs';
    const SERVICE_DOSPACES = 'dospaces';
    const SERVICE_LINODE = 'linode';
    const SERVICE_WASABI = 'wasabi';
    const SERVICE_S3OTHER = 's3other';
    const SERVICE_BACKBLAZE = 'backblaze';
    const SERVICE_RACKSPACE = 'rackspace';
    const SERVICE_AZURE = 'azure';

    const SUPPORTED_SERVICES = [
        self::SERVICE_COCONUT,
        self::SERVICE_S3,
        self::SERVICE_GCS,
        self::SERVICE_DOSPACES,
        self::SERVICE_LINODE,
        self::SERVICE_WASABI,
        self::SERVICE_S3OTHER,
        self::SERVICE_BACKBLAZE,
        self::SERVICE_RACKSPACE,
        self::SERVICE_AZURE,
    ];

    const S3_COMPATIBLE_SERVICES = [
        self::SERVICE_S3,
        self::SERVICE_GCS,
        self::SERVICE_DOSPACES,
        self::SERVICE_LINODE,
        self::SERVICE_WASABI,
        self::SERVICE_S3OTHER,
    ];

    // =Properties
    // =========================================================================

    /**
     * @inheritdoc
     */

    public $schemaVersion = '1.1.0';

    /**
     * @var array List of resolved volume storages
     */

    private $_volumeStorages = [];

    // =Public Methods
    // =========================================================================

    /**
     * Method running when the plugin gets initialized
     * This is where all of the plugin's functionality gets loaded into the system
     */

    public function init()
    {
        parent::init();

        // store reference to plugin instance
        self::$plugin = $this;

        // register plugin services as components
        $this->setComponents([
            'storages' => Storages::class,
            'jobs' => Jobs::class,
            'outputs' => Outputs::class,
        ]);

        // register plugin (template) variables
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function ( Event $e ) {
                /** @var CraftVariable $variable */
                $variable = $e->sender;
                $variable->set('coconut', CoconutVariable::class);
            }
        );

        $request = Craft::$app->getRequest();

        // register URL rules
        if ($request->getIsCpRequest())
        {
            Event::on(
                UrlManager::class,
                UrlManager::EVENT_REGISTER_CP_URL_RULES,
                function( RegisterUrlRulesEvent $event ) {
                    $this->onRegisterUrlRules($event);
                }
            );
        }

        else
        {
            Event::on(
                UrlManager::class,
                UrlManager::EVENT_REGISTER_SITE_URL_RULES,
                function( RegisterUrlRulesEvent $event ) {
                    $this->onRegisterUrlRules($event);
                }
            );
        }

        // register action types
        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_ACTIONS,
            function( RegisterElementActionsEvent $event ) {
                $event->actions[] = TranscodeVideo::class;
                $event->actions[] = ClearVideoOutputs::class;
            }
        );

        // add event listeners for automatic video conversions
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function( ElementEvent $event ) {
                $this->onAfterSaveElement($event);
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_RESTORE_ELEMENT,
            function ( ElementEvent $event ) {
                $this->onAfterRestoreElement($event);
            }
        );

        Event::on(
            Assets::class,
            Assets::EVENT_AFTER_REPLACE_ASSET,
            function ( AssetEvent $event ) {
                $this->onAfterReplaceAsset($event);
            }
        );

        // add event listeners for automatic output cleanup
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function( ElementEvent $event ) {
                $this->onAfterDeleteElement($event);
            }
        );
    }

    // =Services
    // -------------------------------------------------------------------------

    /**
     * @return \yoannisj\coconut\services\Storages
     */

    public function getStorages()
    {
        return $this->get('storages');
    }

    /**
     * @return \yoannisj\coconut\services\Jobs
     */

    public function getJobs()
    {
        return $this->get('jobs');
    }

    /**
     * @return \yoannisj\coconut\services\Outputs
     */

    public function getOutputs()
    {
        return $this->get('outputs');
    }

    // =Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns Coconut outputs for given video, or transcodes them if they
     * have not been transcoded before
     *
     * The first `$input` argument would typically be a video Asset or an
     * external video URL.
     *
     * The second `$outputs` argument can be a list of output formats, output
     * models (or arrays configuring output models)
     *
     * Alternatively the `$input` argument can be a Job model (or an array
     * configuring one) with an input. In that case the `$outputs` argument
     * should be omitted, or it can be a list of outputs to add to the job.
     *
     * @param mixed $input Video to transcode
     * @param mixed $outputs Outputs to transcode video into
     *
     * @return Output[]
     *
     * @throws InvalidArgumentException If no input could be resolved from given arguments
     */

    public function transcodeVideo( $input, $outputs = null ): array
    {
        $job = null;
        $storage = null;

        // check if any of given arguments is a job
        if ($input instanceof Job) {
            $job = $input;
            if ($outputs) $job->addOutputs($outputs); // add given outputs
        } else if (is_array($input) && array_key_exists('input', $input)) {
            $job = new Job($input);
            if ($outputs) $job->addOutputs($outputs); // add given outputs
        }

        else if ($outputs instanceof Job) {
            $job = $outputs;
            $outputs = null;
        } else if (is_array($outputs) && array_key_exists('outputs', $outputs)) {
            $job = new Job($outputs);
            $outputs = null;
        } else if (is_string($outputs)) { // could be a named job...
            $job = $this->getJobs()->getNamedJob($outputs);
            $outputs = null;
        }

        // or resolve arguments as input + outputs
        if (!$job)
        {
            $input = JobHelper::resolveInput($input);
            if ($outputs) $outputs = JobHelper::resolveOutputs($outputs);
        }

        // We need at least an input video to transcode
        if (!$input)
        {
            throw new InvalidArgumentException(
                "Could not resolve `\$input` video argument to transcode");
        }

        // if input is an asset, then we want to use it's volume job as base
        if (($inputAsset = $input->getAsset()))
        {
            $job = $this->getJobs()->getVolumeJob($inputAsset->getVolume());
            $job->setInput($input);

            // in this case, we want to override the volume jobs' outputs
            if ($job && $outputs) $job->setOutputs($outputs);
        }

        if ($job)
        {
            $input = $job->getInput();
            $outputs = $job->getOutputs();
            $storage = $job->getStorage();
        }

        // re-use outputs saved for input, and identify missing outputs
        $savedOutputs = $this->getOutputs()->getOutputsForInput($input);
        $missingOutputs = [];
        $transcodedOutputs = [];

        foreach ($outputs as $key => $output)
        {
            // @todo: check output key vs. output params?
            // -> checking key might result in multiple identical outputs (from ≠ jobs)
            // @todo: do we care about storage here? we might return outputs
            //  from a different storage since storage is not part of the output params..
            // $savedOutput = ArrayHelper::firstWhere($savedOutputs,
            //     $output->toParams());
            $savedOutput = ArrayHelper::firstWhere($savedOutputs,
                'key', $output->key);

            if ($savedOutput && !$savedOutput->getIsFruitless()) {
                $transcodedOutputs[$key] = $savedOutput;
            } else {
                $missingOutputs[$key] = JobHelper::outputAsConfig($output);
            }
        }

        if (!empty($missingOutputs))
        {
            // Create new job to run and transcode missing outputs
            // Note: if no outputs/storage were given in arguments and input
            //  is an asset, then the job will resolve internally to use the
            //  default outputs/storage configured for the input asset's volume
            $job = new Job([
                'input' => $input,
                'outputs' => $missingOutputs,
                'storage' => $storage,
            ]);

            $coconutJobs = $this->getJobs();

            Craft::error('RUN JOB...');
            Craft::error($job->toParams());

            // return [];

            // Run job via Coconut.co API (updates the job properties)
            // @todo: implement UI for job's feedback progress (based on notifications)
            if (!$coconutJobs->runJob($job))
            {
                if ($job->hasErrors())
                {
                    throw new InvalidConfigException(
                        "Could not run job due to validation error(s)");
                }

                throw new Exception('Could not run job');
            }

            // save new job and its configured outputs to the database
            if (!$coconutJobs->saveJob($job))
            {
                if ($job->hasErrors())
                {
                    $errorsText = implode("\n- ", $job->getErrorSummary(true));

                    if ($job->hasErrors('outputs'))
                    {
                        $errorsText .= "\n\tOutput errors:\n";

                        foreach ($job->getOutputs() as $output)
                        {
                            $glue = "\n\t- [".$output->key.'] ';
                            $errorsText .= implode($glue, $output->getErrorSummary(true));
                        }
                    }

                    throw new InvalidConfigException(
                        "Could not save job due to validation error(s)"
                        ."\n - ". $errorsText);
                }

                throw new Exception('Could not save job');
            }

            // return newly saved outputs as well
            $transcodedOutputs += $job->getOutputs();
        }

        return $transcodedOutputs;
    }

    /**
     * Creates a new coconut client to connect to the coconut API
     *
     * @return \Coconut\Client
     */

    public function createClient()
    {
        $apiKey = $this->getSettings()->apiKey;
        $endpoint = $this->getSettings()->endpoint;
        $region = $this->getSettings()->region;

        return new CoconutClient($apiKey, [
            'endpoint' => $endpoint,
            'region' => $region,
        ]);
    }

    /**
     * @param string|Asset $source
     * @param string|array|Job $job
     * @param bool|null $useQueue
     * @param int $checkInterval
     *
     * @throws JobException if job errored
     * @return array
     */

    public function transcodeSource( $source, $job = null, bool $useQueue = null, int $checkInterval = 0 )
    {
        // default to global useQueue value
        if ($useQueue === null) $useQueue = $this->getSettings()->preferQueue;
        // normalize and fill in config attributes based on source
        $job = $this->normalizeSourceConfig($source, $job);

        if ($useQueue)
        {
            // add job to the queue
            $queueJob = new transcodeSourceJob([ 'config' => $job ]);
            Craft::$app->getQueue()->push($queueJob);

            // return initialized outputs for job config
            $outputs = $this->getOutputs()->initJobOutputs($job);
        }

        else {
            // synchronous use of the coconut job api
            $outputs = $this->getJobs()->runJob($job);
        }

        return ArrayHelper::index($outputs, 'format');
    }

    /**
     * @param string|int|Asset $source
     * @param string|array|Job|null $job
     * @param bool $strict Whether no job parameters is allowed or not
     *
     * @return Job
     */

    public function normalizeSourceConfig( $source, $job = null, bool $strict = true )
    {
        if (is_array($job)) {
            $job = new Job($job);
        }

        else if (is_string($job)) {
            $job = $this->getJobs()->getNamedJob($job);
        }

        else if (!$job && $source instanceof Asset)
        {
            $volume = $source->getVolume();
            $job = $this->getJobs()->getVolumeJob($volume->handle);
        }

        if ($strict && !($job instanceof Job))
        {
            throw new InvalidArgumentException(
                'Could not resolve given job into a `'.Job::class.'` instance');
        }

        $job->setSource($source);

        return $job;
    }

    // =Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */

    protected function createSettingsModel()
    {
        return new Settings();
    }

    /**
     * @param RegisterUrlRulesEvent $event
     */

    protected function onRegisterUrlRules( RegisterUrlRulesEvent $event )
    {
        $uploadUrlPattern = '/coconut/uploads/<volumeHandle:{handle}>/<outputPath:(?:\S+)>';

        $event->rules["POST $uploadUrlPattern"] = '/coconut/jobs/upload';
        $event->rules["GET $uploadUrlPattern"] = '/coconut/jobs/output';
    }


    /**
     * @param \craft\events\ElementEvent
     */

    protected function onAfterSaveElement( ElementEvent $event )
    {
        if ($event->isNew && $event->element instanceof Asset
            && !ElementHelper::isDraftOrRevision($event->element)
        ) {
            $this->checkWatchAsset($event->element);
        }
    }

    /**
     * @param \craft\events\ElementEvent
     */

    protected function onAfterDeleteElement( ElementEvent $event )
    {
        if ($event->element instanceof Asset
            && $element->kind == 'video' // only videos can be inputs
            && !ElementHelper::isDraftOrRevision($event->element)
        ) {
            Craft::error('CLEAR OUTPUTS FOR DELETED ASSET');
            $this->getOutputs()->clearOutputsForInput($event->element);
        }
    }

    /**
     * @param \craft\events\ElementEvent
     */

    protected function onAfterRestoreElement( ElementEvent $event )
    {
        if ($event->isNew && $event->element instanceof Asset
            && !ElementHelper::isDraftOrRevision($event->element)
        ) {
            $this->checkWatchAsset($event->element);
        }
    }

    /**
     * @param \craft\events\AssetEvent
     */

    protected function onAfterReplaceAsset( AssetEvent $event )
    {
        $this->checkWatchAsset($event->asset);
    }

    /**
     * Checks if given asset element should be converted, and creates a
     * coconut conversion job if it does.
     *
     * @param \craft\elements\Asset
     *
     * @return bool
     */

    protected function checkWatchAsset( Asset $asset ): bool
    {
        // to avoid creating 2x coconut jobs in case there is a conflicting
        // filename on upload, let the "replaceAsset" event handler decide
        // whether asset should be automatically transcoded or not
        if (!$asset->kind == 'video'
            || !empty($asset->conflictingFilename))
        {
            return false;
        }

        Craft::error('CHECK WATCH ASSET:: '.$asset->volume->handle.' > '.$asset->filename);

        $settings = $this->getSettings();
        $volume = $asset->getVolume();

        if (in_array($volume->handle, $settings->watchVolumes))
        {
            $this->transcodeVideo($asset, null);
            return true;
        }

        return false;
    }
}
