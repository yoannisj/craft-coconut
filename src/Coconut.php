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

use Craft;
use craft\base\VolumeInterface;
use craft\base\Plugin;
use craft\base\Element;
use craft\elements\Asset;
use craft\services\Elements;
use craft\services\Assets;
use craft\web\twig\variables\CraftVariable;
use craft\events\RegisterElementActionsEvent;
use craft\events\ElementEvent;
use craft\events\AssetEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\ElementHelper;

use yoannisj\coconut\services\Jobs;
use yoannisj\coconut\services\Outputs;
use yoannisj\coconut\base\VolumeAdapterInterface;
use yoannisj\coconut\base\VolumeAdapter;
use yoannisj\coconut\models\Settings;
use yoannisj\coconut\models\Config;
use yoannisj\coconut\elements\actions\TranscodeVideo;
use yoannisj\coconut\elements\actions\ClearVideoOutputs;
use yoannisj\coconut\queue\jobs\TranscodeSourceJob;
use yoannisj\coconut\variables\CoconutVariable;
use yoannisj\coconut\events\VolumeAdaptersEvent;


/**
 * Coconut plugin class for Craft
 */

class Coconut extends Plugin
{
    // =Static
    // =========================================================================

    /**
     * Name of database table used to store references to coconut inputs
     */

    const TABLE_INPUTS = '{{%coconut_inputs}}';

    /**
     * Name of database table used to store coconut outputs
     */

    const TABLE_OUTPUTS = '{{%coconut_outputs}}';

    /**
     * @var array [ 'class' => \yoannisj\coconut\base\VolumeAdapterInterface ]
     */

    const DEFAULT_VOLUME_ADAPTERS = [];

    /**
     * @var string
     */

    const EVENT_REGISTER_VOLUME_ADAPTERS = 'registerVolumeAdapters';

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
     * @var array
     */

    private $_defaultVolumeAdapters;

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

    /**

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
     * @return array
     */

    public function getDefaultVolumeAdapters(): array
    {
        if (!isset($this->_defaultVolumeAdapters))
        {
            $adapters = $this->resolveDefaultVolumeAdapters();
            $this->_defaultVolumeAdapters = $adapters;
        }

        return $this->_defaultVolumeAdapters;
    }

    /**
     * @return \yoannisj\coconut\base\VolumeAdapterInterface[]
     */

    public function getAllVolumeAdapters(): array
    {
        $defaultAdapters = $this->getDefaultVolumeAdapters();
        $event = new VolumeAdaptersEvent([
            'adapters' => $defaultAdapters
        ]);

        $this->trigger(self::EVENT_REGISTER_VOLUME_ADAPTERS, $event);

        return $event->adapters;
    }

    /**
     * @param \craft\base\VolumeInterface $volume
     *
     * @return \yoannisj\coconut\base\VolumeAdapterInterface
     */

    public function getVolumeAdapter( VolumeInterface $volume ): VolumeAdapterInterface
    {
        $volumeType = get_class($volume);
        $adapters = $this->getAllVolumeAdapters();
        $adapter = $adapters[$volumeType] ?? VolumeAdapter::class;

        return Craft::createObject($adapter);
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
     *
     */

    protected function resolveDefaultVolumeAdapters(): array
    {
        $plugins = Craft::$app->getPlugins();

        // add base volume adapter for completeness
        $adapters = [
            \craft\volumes\Local::class => VolumeAdapter::class,
        ];

        if ($plugins->isPluginInstalled('aws-s3')) {
            $adapters[\craft\awss3\Volume::class] = \yoannisj\coconut\base\AwsS3VolumeAdapter::class;
        }

        return $adapters;
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
