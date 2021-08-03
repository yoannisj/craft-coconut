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

use yoannisj\coconut\services\Jobs;
use yoannisj\coconut\services\Outputs;
use yoannisj\coconut\models\Settings;
use yoannisj\coconut\models\Config;
use yoannisj\coconut\models\Storage;
use yoannisj\coconut\elements\actions\TranscodeVideo;
use yoannisj\coconut\elements\actions\ClearVideoOutputs;
use yoannisj\coconut\queue\jobs\TranscodeSourceJob;
use yoannisj\coconut\variables\CoconutVariable;
use yoannisj\coconut\events\VolumeStorageEvent;

/**
 * Coconut plugin class for Craft
 */

class Coconut extends Plugin
{
    // =Static
    // =========================================================================

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

    // =Events
    // -------------------------------------------------------------------------

    const BEFORE_RESOLVE_VOLUME_STORAGE = 'beforeResolveVolumeStorage';
    const AFTER_RESOLVE_VOLUME_STORAGE = 'afterResolveVolumeStorage';

    // =Properties
    // =========================================================================

    /**
     * reference to the plugin's instance
     * @var Plugin
     */

    public static $plugin;

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
     * @param string | \craft\elements\Asset $source
     * @param string | array | \yoannisj\coconut\models\Config $config
     * @param bool | null $useQueue
     * @param int $checkInterval
     *
     * @throws JobException if job errored
     * @return array
     */

    public function transcodeSource( $source, $config = null, bool $useQueue = null, int $checkInterval = 0 )
    {
        // default to global useQueue value
        if ($useQueue === null) $useQueue = $this->getSettings()->preferQueue;
        // normalize and fill in config attributes based on source
        $config = $this->normalizeSourceConfig($source, $config);

        if ($useQueue)
        {
            // add job to the queue
            $queueJob = new TranscodeSourceJob([ 'config' => $config ]);
            Craft::$app->getQueue()->push($queueJob);

            // return initialized outputs for job config
            $outputs = $this->getOutputs()->initConfigOutputs($config);
        }

        else {
            // synchronous use of the coconut job api
            $outputs = $this->getJobs()->runJob($config);
        }

        return ArrayHelper::index($outputs, 'format');
    }

    /**
     * @param string | int | \craft\elements\Asset $source
     * @param string | array | yoannisj\coconut\models\Config | null $config
     * @param bool $strict Whether no config is allowed or not
     *
     * @return yoannisj\coconut\models\Config
     */

    public function normalizeSourceConfig( $source, $config = null, bool $strict = true )
    {
        if (is_array($config)) {
            $config = new Config($config);
        }

        else if (is_string($config)) {
            $config = $this->getSettings()->getConfig($config);
        }

        else if (!$config && $source instanceof Asset)
        {
            $volume = $source->getVolume();
            $config = $this->getSettings()->getVolumeConfig($volume->handle);
        }

        if ($strict && !($config instanceof Config)) {
            throw new InvalidArgumentException('Could not determine transcode config.');
        }

        $config->setSource($source);
        return $config;
    }

    /**
     * @param Volume $volume
     *
     * @return Storage|null
     *
     * @throws InvalidValueException If another module/plugin resolves to storage settings
     *  that are not an instance of \yoannisj\coconut\models\Storage
     */

    public function resolveVolumeStorage( Volume $volume )
    {
        if (!array_key_exists($volume->id, $this->_volumeStorages))
        {
            $storage = null;

            // allow modules/plugins to define storage settings
            if ($this->hasEventHandlers(self::BEFORE_RESOLVE_VOLUME_STORAGE))
            {
                $event = new VolumeStorageEvent([
                    'volume' => $volume,
                    'storage' => $storage,
                ]);

                $this->trigger(self::BEFORE_RESOLVE_VOLUME_STORAGE, $event);
                $storage = $event->storage;
            }

            // no need to resolve volume storage if module/plugin already did
            if (!$storage)
            {
                // @todo: resolve storage settings for service Volumes supported by Coconut
                $uploadUrl = UrlHelper::actionUrl('coconut/jobs/upload', [
                    'volumeId' => $volume->id,
                ]);

                $storage = new Storage([
                    'service' => self::SERVICE_COCONUT,
                    'url' => $uploadUrl,
                ]);
            }

            // allow modules/plugins to further customise storage settings
            if ($this->hasEventHandlers(self::AFTER_RESOLVE_VOLUME_STORAGE))
            {
                // allow modules/plugins to modify storage settings
                $event = new VolumeStorageEvent([
                    'volume' => $volume,
                    'storage' => $storage,
                ]);

                $this->trigger(self::AFTER_RESOLVE_VOLUME_STORAGE, $event);
                $storage = $event->storage;
            }

            // validate storage before continuing
            if (!$storage instanceof Storage)
            {
                throw new InvalidValueException(
                    'Resolved volume storage must be an instance of '.Storage::class);
            }

            $this->_volumeStorages[$volume->id] = $storage;
        }

        return $this->_volumeStorages[$volume->id];
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
        if ($e->isNew && $e->element instanceof Asset
            && !ElementHelper::isDraftOrRevision($e->element)
        ) {
            $this->checkWatchAsset($e->element);
        }
    }

    /**
     * @param \craft\events\ElementEvent
     */

    protected function onAfterDeleteElement( ElementEvent $e )
    {
        if ($e->element instanceof Asset
            && !ElementHelper::isDraftOrRevision($e->element)
        ) {
            $this->getOutputs()->clearSourceOutputs($e->element);
        }
    }

    /**
     * @param \craft\events\ElementEvent
     */

    protected function onAfterRestoreElement( ElementEvent $e )
    {
        if ($e->isNew && $e->element instanceof Asset
            && !ElementHelper::isDraftOrRevision($e->element)
        ) {
            $this->checkWatchAsset($e->element);
        }
    }

    /**
     * @param \craft\events\AssetEvent
     */

    protected function onAfterReplaceAsset( AssetEvent $e )
    {
        $this->checkWatchAsset($e->asset);
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
