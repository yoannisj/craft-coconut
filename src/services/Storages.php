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

namespace yoannisj\coconut\services;

use yii\base\InvalidValueException;

use Craft;
use craft\base\Component;
use craft\models\Volume;
use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Storage;
use yoannisj\coconut\events\VolumeStorageEvent;
use yoannisj\coconut\helpers\JobHelper;

/**
 * Service component to work with Coconut Storages
 */
class Storages extends Component
{
    // =Static
    // =========================================================================

    /**
     * Name of event triggered before a volume storage is resoled.
     *
     * This allows modules and plugins to override the storage model for
     * specific volumes (before the Coconut plugin tries to resolve it).
     *
     * @var string
     */
    const EVENT_BEFORE_RESOLVE_VOLUME_STORAGE = 'beforeResolveVolumeStorage';

    /**
     * Name of event triggered before saving an output in the database
     *
     * This allows modules and plugins to override or customise the storage model
     * that was resolved by the Coconut plugin for a specific volumes.
     *
     * @var string
     */
    const EVENT_AFTER_RESOLVE_VOLUME_STORAGE = 'afterResolveVolumeStorage';

    // =Properties
    // =========================================================================

    /**
     * Map of resolved and memoized volume storages indexed by volume ID
     *
     * @var Storage[]
     */
    private array $_volumeStoragesById = [];

    // =Public Methods
    // =========================================================================

    /**
     * Returns storage model for given storage handle.
     *
     * @see \yoannisj\models\Settings::storages To learn about named jobs
     *
     * @param string $handle Handle of named storage
     *
     * @return Storage|null The storage model named after given $handle
     */
    public function getNamedStorage( string $handle ): ?Storage
    {
        $storages = Coconut::$plugin->getSettings()->getStorages();
        return $storages[$handle] ?? null;
    }

    /**
     * Resolves storage model for given Craft-CMS Volume.
     *
     * If no storage settings where registered for given Volume, this method
     * returns a default HTTP upload storage model.
     *
     * @param Volume $volume Volume for which to get a storage model
     *
     * @return Storage The storage model for given $volume
     *
     * @throws InvalidValueException If registered storage is not an instance of [[Storage:class]]
     * @throws InvalidValueException If registered storage is not valid
     */
    public function getVolumeStorage( Volume $volume ): Storage
    {
        if (!array_key_exists($volume->id, $this->_volumeStoragesById))
        {
            $storage = null;

            // allow modules/plugins to define storage settings
            if ($this->hasEventHandlers(self::EVENT_BEFORE_RESOLVE_VOLUME_STORAGE))
            {
                $event = new VolumeStorageEvent([
                    'volume' => $volume,
                    'storage' => $storage,
                ]);

                $this->trigger(self::EVENT_BEFORE_RESOLVE_VOLUME_STORAGE, $event);
                $storage = $event->storage;
            }

            // no need to resolve volume storage if module/plugin already did
            if (!$storage)
            {
                // @todo Resolve storage settings for commonly used Filesystems which correspond to a service supported by Coconut (e.g. AWS S3)

                // See note about HTTP uploads in `Settings::storages` comment
                $uploadUrl = JobHelper::publicUrl('/coconut/outputs/'.$volume->handle.'/');
                $storage = new Storage([ 'url' => $uploadUrl ]);
            }

            // allow modules/plugins to further customise storage settings
            if ($this->hasEventHandlers(self::EVENT_AFTER_RESOLVE_VOLUME_STORAGE))
            {
                // allow modules/plugins to modify storage settings
                $event = new VolumeStorageEvent([
                    'volume' => $volume,
                    'storage' => $storage,
                ]);

                $this->trigger(self::EVENT_AFTER_RESOLVE_VOLUME_STORAGE, $event);
                $storage = $event->storage;
            }

            // validate storage before continuing
            if (!($storage instanceof Storage))
            {
                $message = 'Registered volume storage must be an instance of '.Storage::class;
                throw new InvalidValueException(Craft::t('coconut', $message));
            }

            else if (!$storage->validate())
            {
                $message = "Resolved volume storage is not valid";

                Craft::info($message.':'.print_r($storage->errors, true), 'coconut');
                throw new InvalidValueException(Craft::t('coconut', $message));
            }

            // set volume-related storage attributes
            $storage->handle = $volume->handle;
            $storage->volumeId = $volume->id;

            $this->_volumeStoragesById[$volume->id] = $storage;
        }

        return $this->_volumeStoragesById[$volume->id];
    }

    // =Protected Methods
    // =========================================================================

}
