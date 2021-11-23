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
use craft\base\VolumeInterface;
use craft\helpers\UrlHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Storage;
use yoannisj\coconut\events\VolumeStorageEvent;

/**
 * Singleton class to work with Coconut storages
 */

class Storages extends Component
{
    // =Static
    // =========================================================================

    // =Events
    // -------------------------------------------------------------------------

    const EVENT_BEFORE_RESOLVE_VOLUME_STORAGE = 'beforeResolveVolumeStorage';
    const EVENT_AFTER_RESOLVE_VOLUME_STORAGE = 'afterResolveVolumeStorage';

    // =Properties
    // =========================================================================

    /**
     * @var array Registry of resolved volume storages by volume ID
     */

    private $_volumeStoragesById = [];

    // =Public Methods
    // =========================================================================

    /**
     * Returns storage model for given storage handle
     *
     * @param string $handle
     *
     * @return Storage|null
     */

    public function getNamedStorage( string $handle )
    {
        $storages = Coconut::$plugin->getSettings()->getStorages();

        return $storages[$handle] ?? null;
    }

    /**
     * Resolves storage model for given Craft-CMS Volume
     *
     * @param VolumeInterface $volume
     *
     * @return Storage
     *
     * @throws InvalidValueException If another module/plugin resolves to storage settings
     *  that are not an instance of \yoannisj\coconut\models\Storage
     */

    public function getVolumeStorage( VolumeInterface $volume ): Storage
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
                $url = UrlHelper::actionUrl('coconut/jobs/upload', [
                    'volume' => $volume->handle,
                ]);

                // @todo: resolve storage settings for services supported by Coconut
                $storage = new Storage([ 'url' => $url.'&outputPath=' ]);
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
            if (!$storage instanceof Storage)
            {
                throw new InvalidValueException(
                    'Resolved volume storage must be an instance of '.Storage::class);
            }

            $this->_volumeStoragesById[$volume->id] = $storage;
        }

        return $this->_volumeStoragesById[$volume->id];
    }

    /**
     * @param string|array|VolumeInterface $storage
     *
     * @return Storage|null
     */

    public function parseStorage( $storage )
    {
        if ($storage instanceof Storage) {
            return $storage;
        }

        else if (is_array($storage)) {
            return new Storage($storage);
        }

        else if (is_string($storage))
        {
            // check if this is a named storage handle
            $handle = $storage;
            $storage = $this->getNamedStorage($handle);

            if ($storage) {
                return $storage;
            }

            // or, assume this is a volume handle
            $storage = Craft::$app->getVolumes()
                ->getVolumeByHandle($handle);
        }

        if ($storage instanceof VolumeInterface) {
            return $this->getVolumeStorage($storage);
        }

        return null;
    }

    // =Protected Methods
    // =========================================================================

}
