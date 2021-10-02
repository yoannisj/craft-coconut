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
use yii\base\InvalidArgumentException;
use yii\base\InvalidValueException;

use Craft;
use craft\base\VolumeInterface;
use craft\base\Plugin;
use craft\base\Element;
use craft\models\Volume;
use craft\elements\Asset;
use craft\services\Elements;
use craft\services\Assets;
use craft\web\twig\variables\CraftVariable;
use craft\events\RegisterElementActionsEvent;
use craft\events\ElementEvent;
use craft\events\AssetEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\UrlHelper;
use craft\helpers\ElementHelper;

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

        // register action types
        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_ACTIONS,
            function( RegisterElementActionsEvent $e ) {
                $e->actions[] = TranscodeVideo::class;
                $e->actions[] = ClearVideoOutputs::class;
            }
        );

        // add event listeners for automatic video conversions
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function( ElementEvent $e ) {
                $this->onAfterSaveElement($e);
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_RESTORE_ELEMENT,
            function ( ElementEvent $e ) {
                $this->onAfterRestoreElement($e);
            }
        );

        Event::on(
            Assets::class,
            Assets::EVENT_AFTER_REPLACE_ASSET,
            function ( AssetEvent $e ) {
                $this->onAfterReplaceAsset($e);
            }
        );

        // add event listeners for automatic output cleanup
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function( ElementEvent $e ) {
                $this->onAfterDeleteElement($e);
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
     * Creates a new coconut client to connect to the coconut API
     *
     * @return \Coconut\Client
     */

    public function createClient()
    {
        $apiKey = $this->getSettings()->apiKey;
        return new \Coconut\Client($apiKey);
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
            $job = $this->getSettings()->getNamedJob($job);
        }

        else if (!$job && $source instanceof Asset)
        {
            $volume = $source->getVolume();
            $job = $this->getSettings()->getVolumeJob($volume->handle);
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
     * @param \craft\events\ElementEvent
     */

    protected function onAfterSaveElement( ElementEvent $e )
    {
        // if ($e->isNew && $e->element instanceof Asset
        //     && !ElementHelper::isDraftOrRevision($e->element)
        // ) {
        //     $this->checkWatchAsset($e->element);
        // }
    }

    /**
     * @param \craft\events\ElementEvent
     */

    protected function onAfterDeleteElement( ElementEvent $e )
    {
        // if ($e->element instanceof Asset
        //     && !ElementHelper::isDraftOrRevision($e->element)
        // ) {
        //     $this->getOutputs()->clearSourceOutputs($e->element);
        // }
    }

    /**
     * @param \craft\events\ElementEvent
     */

    protected function onAfterRestoreElement( ElementEvent $e )
    {
        // if ($e->isNew && $e->element instanceof Asset
        //     && !ElementHelper::isDraftOrRevision($e->element)
        // ) {
        //     $this->checkWatchAsset($e->element);
        // }
    }

    /**
     * @param \craft\events\AssetEvent
     */

    protected function onAfterReplaceAsset( AssetEvent $e )
    {
        // $this->checkWatchAsset($e->asset);
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
        // filename, let the "replaceAsset" event handler decide whether asset
        // should be automatically transcoded or not.
        if (!$asset->kind == 'video'
            || !empty($asset->conflictingFilename))
        {
            return false;
        }

        $settings = $this->getSettings();
        $volume = $asset->getVolume();

        if (in_array($volume->handle, $settings->watchVolumes))
        {
            $this->transcodeSource($asset, null);
            return true;
        }

        return false;
    }
}
