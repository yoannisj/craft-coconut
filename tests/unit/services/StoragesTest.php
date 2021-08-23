<?php

namespace yoannisj\coconuttests\unit\services;

use Codeception\AssertThrows;
use Codeception\Test\Unit as UnitTest;
use UnitTester;

use yii\base\Event;
use yii\base\InvalidValueException;

use Craft;
use craft\volumes\Local as LocalVolume;
use craft\test\EventItem;
use craft\helpers\UrlHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Storage;
use yoannisj\coconut\services\Storages;
use yoannisj\coconut\events\VolumeStorageEvent;

/**
 * Unit tests for the plugin's Storages service
 */

class StoragesTest extends UnitTest
{
    use AssertThrows;

    // =Properties
    // ========================================================================

    /**
     * @var UnitTester
     */

    protected $tester;

    // =Public Methods
    // ========================================================================

    // =Setup/Cleanup
    // ------------------------------------------------------------------------

    /**
     * Method ran before each test to setup test context
     */

    public function _before()
    {
        // remove event handlers for tested events
        Event::off(
            Storages::class,
            Storages::EVENT_BEFORE_RESOLVE_VOLUME_STORAGE,
        );

        Event::off(
            Storages::class,
            Storages::EVENT_AFTER_RESOLVE_VOLUME_STORAGE,
        );

        // Clear memoized properties on the storages service singleton
        Coconut::$plugin->set('storages', new Storages());
    }

    /**
     * Method ran after each test to clean up side effects
     */

    public function _after()
    {
        // remove event handlers for tested events
        Event::off(
            Storages::class,
            Storages::EVENT_BEFORE_RESOLVE_VOLUME_STORAGE,
        );

        Event::off(
            Storages::class,
            Storages::EVENT_AFTER_RESOLVE_VOLUME_STORAGE,
        );

        // Clear memoized properties on the storages service singleton
        Coconut::$plugin->set('storages', new Storages());
    }

    /**
     * Method ran after each test to clean up side effects
     */

    public function _fixtures()
    {
        return [
            'volumes' => [
                'class' => \yoannisj\coconuttests\fixtures\VolumesFixture::class,
            ],
        ];
    }

    // =Tests
    // ------------------------------------------------------------------------

    /**
     * =getNamedStorage
     *
     * @test
     * @testdox Recognizes given handle from keys  in the `storages` setting
     */

    public function getNamedStorageRecognizesKeysFromStoragesSetting()
    {
        $storages = Coconut::$plugin->getSettings()->getStorages();
        $coconutStorage = $storages['coconutStorage'];

        $storage = Coconut::$plugin->getStorages()->getNamedStorage('coconutStorage');

        $this->assertInstanceOf(Storage::class, $storage);
        $this->assertEquals($coconutStorage, $storage);
    }

    /**
     * @test
     * @testdox getNamedStorage() returns `null` if given handle could not be found in `storages` setting
     */

    public function getNamedStorageReturnsNullIfHandleNotFound()
    {
        $storage = Coconut::$plugin->getStorages()->getNamedStorage('foo');

        $this->assertNull($storage);
    }

    /**
     * =getVolumeStorage
     *
     * @test
     * @testdox getVolumeStorage() returns storage model with upload action URL for given volume
     */

    public function getVolumeStorageReturnsStorageModelWithUploadActionUrl()
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle('localUploads');
        $httpUploadUrl = UrlHelper::actionUrl('coconut/jobs/upload', [
            'volume' => $volume->handle,
        ]);

        $storage = Coconut::$plugin->getStorages()
            ->getVolumeStorage($volume);

        $this->assertEquals($httpUploadUrl, $storage->url);
    }

    /**
     * @test
     * @testdox getVolumeStorage() triggers event before resolving storage
     */

    public function getVolumeStorageTriggersEventBeforeResolvingStorage()
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle('localUploads');

        $this->tester->expectEvent(
            Storages::class, // class triggering the event
            Storages::EVENT_BEFORE_RESOLVE_VOLUME_STORAGE, // name of the event
            function() use ($volume) // code under test (should trigger the event)
            {
                Coconut::$plugin->getStorages()->getVolumeStorage($volume);
            },
            VolumeStorageEvent::class, // class the event object is an instance of
            $this->tester->createEventItems([ // properties to verify on the event object
                [
                    'eventPropName' => 'volume',
                    'type' => EventItem::TYPE_OTHERVALUE,
                    'desiredValue' => $volume
                ],
            ])
        );
    }

    /**
     * @test
     * @testdox getVolumeStorage() triggers event afer resolving storage
     */

    public function getVolumeStorageTriggersEventAfterResolvingStorage()
    {
        $volume = Craft::$app->getVolumes()
            ->getVolumeByHandle('localUploads');

        $this->tester->expectEvent(
            Storages::class, // class triggering the event
            Storages::EVENT_AFTER_RESOLVE_VOLUME_STORAGE, // name of the event
            function() use ($volume) // code under test (should trigger the event)
            {
                Coconut::$plugin->getStorages()->getVolumeStorage($volume);
            },
            VolumeStorageEvent::class, // class the event object is an instance of
            $this->tester->createEventItems([ // properties to verify on the event object
                [
                    'eventPropName' => 'volume',
                    'type' => EventItem::TYPE_CLASS,
                    'desiredClass' => LocalVolume::class,
                    'desiredValue' => [
                        'id' => $volume->id,
                        'handle' => $volume->handle,
                    ]
                ],
                [
                    'eventPropName' => 'storage',
                    'type' => EventItem::TYPE_CLASS,
                    'desiredClass' => Storage::class
                ],
            ])
        );
    }

    /**
     * @test
     * @testdox getVolumeStorage() returns storage model from *before* event
     */

    public function getVolumeStorageReturnsStorageModelFromBeforeEvent()
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle('localUploads');
        $customStorage = new Storage([ 'service' => 'coconut' ]);

        Event::on(
            Storages::class,
            Storages::EVENT_BEFORE_RESOLVE_VOLUME_STORAGE,
            function($event) use ($customStorage)
            {
                $event->storage = $customStorage;
            }
        );

        $storage = Coconut::$plugin->getStorages()->getVolumeStorage($volume);

        $this->assertEquals($storage, $customStorage);
    }

    /**
     * @test
     * @testdox getVolumeStorage() returns storage model from *after* event
     */

    public function getVolumeStorageReturnsStorageModelFromAfterEvent()
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle('localUploads');
        $customStorage = new Storage([ 'service' => 'coconut' ]);

        Event::on(
            Storages::class,
            Storages::EVENT_AFTER_RESOLVE_VOLUME_STORAGE,
            function($event) use ($customStorage)
            {
                $event->storage = $customStorage;
            }
        );

        $storage = Coconut::$plugin->getStorages()->getVolumeStorage($volume);

        $this->assertEquals($storage, $customStorage);
    }

    /**
     * @test
     * @testdox getVolumeStorage() populates storage from *before* event on *after* event
     */

    public function getVolumeStoragePopulatesStorageFromBeforeEventOnAfterEvent()
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle('localUploads');
        $customStorage = new Storage([ 'service' => 'coconut' ]);

        Event::on(
            Storages::class,
            Storages::EVENT_BEFORE_RESOLVE_VOLUME_STORAGE,
            function($event) use ($customStorage)
            {
                $event->storage = $customStorage;
            }
        );

        $this->tester->expectEvent(
            Storages::class, // class triggering the event
            Storages::EVENT_AFTER_RESOLVE_VOLUME_STORAGE, // name of the event
            function() // code under test (should trigger the event)
            {
                $volume = Craft::$app->getVolumes()->getVolumeByHandle('localUploads');
                Coconut::$plugin->getStorages()->getVolumeStorage($volume);
            },
            VolumeStorageEvent::class, // class the event object is an instance of
            $this->tester->createEventItems([ // properties to verify on the event object
                [
                    'eventPropName' => 'storage',
                    'type' => EventItem::TYPE_OTHERVALUE,
                    'desiredValue' => $customStorage,
                ],
            ])
        );
    }

    /**
     * @test
     * @testdox getVolumeStorage() throws error if before event's storage is not a storage model
     */

    public function getVolumeStorageThrowsErrorIfBeforeStorageIsNotAStorageModel()
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle('localUploads');

        $this->assertThrows(InvalidValueException::class, function() use ($volume)
        {
            Event::on(
                Storages::class,
                Storages::EVENT_BEFORE_RESOLVE_VOLUME_STORAGE,
                function($event)
                {
                    $event->storage = (object)[ 'service' => 'coconut' ];
                }
            );

            $storage = Coconut::$plugin->getStorages()->getVolumeStorage($volume);
        });
    }

    /**
     * @test
     * @testdox getVolumeStorage() throws error if after event's storage is not a storage model
     */

    public function getVolumeStorageThrowsErrorIfAfterStorageIsNotAStorageModel()
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle('localUploads');

        $this->assertThrows(InvalidValueException::class, function() use ($volume)
        {
            Event::on(
                Storages::class,
                Storages::EVENT_AFTER_RESOLVE_VOLUME_STORAGE,
                function($event)
                {
                    $event->storage = (object)[ 'service' => 'coconut' ];
                }
            );

            $storage = Coconut::$plugin->getStorages()->getVolumeStorage($volume);
        });
    }

    /**
     * =parseStorage
     *
     * @test
     * @testdox parseStorage() recognizes named storage handle
     */

    public function parseStorageRecognizesNamedStorageHandle()
    {
        $storageHandle = 'coconutStorage';
        $namedStorage = Coconut::$plugin->getStorages()
            ->getNamedStorage($storageHandle);

        $storage = Coconut::$plugin->getStorages()
            ->parseStorage($storageHandle);

        $this->assertSame($namedStorage, $storage);
    }

    /**
     * @test
     * @testdox parseStorage() recognizes volume handle
     */

    public function parseStorageRecognizesVolumeHandle()
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle('localUploads');
        $volumeStorage = Coconut::$plugin->getStorages()
            ->getVolumeStorage($volume);

        $storage = Coconut::$plugin->getStorages()
            ->parseStorage($volume->handle);

        $this->assertEquals($volumeStorage, $storage);
    }

    /**
     * @test
     * @testdox parseStorage() accepts storage model configuration array
     */

    public function parseStorageAcceptsStorageConfigArray()
    {
        $storageConfig = [ 'service' => 'coconut' ];

        $storage = Coconut::$plugin->getStorages()
            ->parseStorage($storageConfig);

        $this->assertInstanceOf(Storage::class, $storage);
        $this->assertEquals('coconut', $storage->service);
    }
}
