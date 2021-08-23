<?php

namespace yoannisj\coconuttests\unit;

use Codeception\Specify;
use Codeception\AssertThrows;
use Codeception\Test\Unit as UnitTest;
use UnitTester;

use yii\base\InvalidConfigException;

use Craft;
use craft\volumes\Local as LocalVolume;
use craft\helpers\App as AppHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Settings;
use yoannisj\coconut\models\Storage;

/**
 *
 */

class SettingsTest extends UnitTest
{
    use Specify;
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
    }

    /**
     * Method ran after each test to clean up side effects
     */

    public function _after()
    {
    }

    // =Tests
    // ------------------------------------------------------------------------

    /**
     * =apiKey
     */

    public function testApiKey()
    {
        // get original environment variable
        $envApiKey = AppHelper::env('COCONUT_API_KEY');

        $this->specify('Defaults to the `COCONUT_API_KEY` environment variable',
            function() use ($envApiKey)
        {
            // make sure there is an actual COCONUT_API_KEY environment variable
            if (empty($envApiKey)) {
                putenv("COCONUT_API_KEY=coconut----------------------api-key");
            }

            $settings = new Settings();

            $this->assertSame(AppHelper::env('COCONUT_API_KEY'), $settings->apiKey);
        });

        $this->specify('Throws an error when accessed and empty',
            function()
        {
            $this->assertThrows(InvalidConfigException::class, function()
            {
                $settings = new Settings();
                $settings->apiKey = '';

                $apiKey = $settings->apiKey;
            });
        });

        // restore original environment variable
        putenv("COCONUT_API_KEY=$envApiKey");
    }

    /**
     * =storages
     */

    public function testStorages()
    {
        $this->specify('Resolves map of storage properties into Storage models',
            function()
        {
            $storages = [
                'httpUploadStorage' => [
                    'url' => 'https://www.myapp.com/action/coconut/jobs/upload',
                ],
                'coconutStorage' => [
                    'service' => 'coconut',
                ],
                's3Storage' => [
                    'service' => 's3',
                    'credentials' => [
                        'access_key_id' => 's3----------------------------key-id',
                        'secret_access_key' => 's3------------------------secret-key',
                    ],
                ],
            ];

            $settings = new Settings();
            $settings->storages = $storages;

            $this->assertCount(count($storages), $settings->storages);
            $this->assertContainsOnlyInstancesOf(Storage::class, $settings->storages);
        });

        $this->specify('Throws an error if one of the given storages could not be resolved into a Storage model instance',
            function()
        {
            $this->assertThrows(InvalidConfigException::class, function()
            {
                $settings = new Settings();
                $settings->storages = [
                    'httpUploadStorage' => new Storage([
                        'url' => 'https://www.myapp.com/action/coconut/jobs/upload',
                    ]),
                    'baseModelStorage' => (object)[
                        'service' => 'coconut',
                    ],
                ];
            });
        });

        $this->specify('Throws an error if one of the storages is defined by an index key',
            function()
        {
            $this->assertThrows(InvalidConfigException::class, function()
            {
                $settings = new Settings();
                $settings->storages = [
                    'httpUploadStorage' => [
                        'url' => 'https://www.myapp.com/action/coconut/jobs/upload',
                    ],
                    [ 'service' => 'coconut' ],
                ];
            });
        });
    }

    /**
     * =defaultStorage
     */

    /**
     * =defaultUploadVolume
     */

    /**
     * =outputPathFormat
     */

    /**
     * =configs
     */

    /**
     * =volumeConfigs
     */

    // =Protected Methods
    // ========================================================================

}
