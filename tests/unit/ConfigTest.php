<?php

namespace yoannisj\coconuttests\unit;

use Codeception\Specify;
use Codeception\Test\Unit as UnitTest;
use UnitTester;

use Craft;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Config;
use yoannisj\coconut\models\Input;
use yoannisj\coconut\models\Output;
use yoannisj\coconut\models\Storage;
use yoannisj\coconut\helpers\ConfigHelper;

/**
 * Unit tests for Config model
 */

class ConfigTest extends UnitTest
{
    use Specify;

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

    /**
     *
     */

    public function _fixtures()
    {
        return [
            'volumes' => [
                'class' => \yoannisj\coconuttests\fixtures\VolumesFixture::class,
            ],
            'assets' => [
                'class' => \yoannisj\coconuttests\fixtures\AssetsFixture::class,
            ],
        ];
    }

    // =Tests
    // ------------------------------------------------------------------------

    /**
     * @test
     * @testdox Input property resolves configuration arrays into an input model
     */

    public function inputPropertyResolvesInputConfigArray()
    {
        // given
        $asset = $this->tester->grabFixture('assets')
            ->getElement('localMp4VideoAsset');

        // when
        $config = new Config();
        $config->input = [ 'assetId' => $asset->id ];

        // then
        $this->assertInstanceOf(Input::class, $config->input);
        $this->assertSame($asset->id, $config->input->assetId);


        // given
        $inputUrl = 'https://www.example.com/videos/video.mp4';

        // when
        $config = new Config();
        $config->input = [ 'url' => $inputUrl ];

        $this->assertInstanceOf(Input::class, $config->input);
        $this->assertSame($inputUrl, $config->input->url);
    }

    /**
     * @test
     * @testdox Input property resolves asset elements into an input model
     */

    public function inputPropertyResolvesAssetElement()
    {
        // given
        $asset = $this->tester->grabFixture('assets')
            ->getElement('localMp4VideoAsset');

        // when
        $config = new Config();
        $config->input = $asset;

        // then
        $this->assertInstanceOf(Input::class, $config->input);
        $this->assertEquals($asset, $config->input->asset);
    }

    /**
     * @test
     * @testdox Input property resolves external file URLs into an input model
     */

    public function inputPropertyResolvesExternalFileUrl()
    {
        // given
        $inputUrl = 'https://www.example.com/videos/video.mp4';

        // when
        $config = new Config();
        $config->input = $inputUrl;

        // then
        $this->assertInstanceOf(Input::class, $config->input);
        $this->assertSame($inputUrl, $config->input->url);
    }

    /**
     * =outputs
     *
     * @test
     * @testdox Outputs property maps indexed list of outputs by their output key
     */

    public function outputsPropertyMapsIndexedListOfOutputsByTheirOutputKey()
    {
        $config = new Config();
        $config->outputs = [
            new Output([
                'key' => 'mp4_hd',
                'format' => [ 'container' => 'mp4', 'resolution' => '1080p' ],
            ]),
            new Output([
                'format' => [ 'container' => 'webm', 'video_codec' => 'vp9', 'quality' => '4' ],
            ]),
            new Output([
                'key' => 'thumbs',
                'format' => [ 'container' => 'jpg', 'resolution' => '240p' ],
            ])
        ];

        foreach ($config->outputs as $key => $output) {
            $this->assertSame($key, $output->key);
        }
    }

    /**
     * @test
     * @testdox Outputs property preserves array keys
     */

    public function outputsPropertyPreservesArrayKeys()
    {
        $outputParams = [
            'mp4_hq' => new Output([
                'key' => 'mp4_720p',
                'format' => [ 'container' => 'mp4', 'resolution' => '720p' ],
            ]),
            'mp4_sq' => new Output([
                'key' => 'mp4_480p',
                'format' => [ 'container' => 'mp4', 'resolution' => '480p' ],
            ]),
            'poster' => new Output([
                'format' => [ 'container' => 'jpeg' ],
                'number' => 1,
            ]),
        ];

        $config = new Config();
        $config->outputs = $outputParams;

        foreach ($outputParams as $key => $params) {
            $this->assertArrayHasKey($key, $config->outputs);
        }
    }

    /**
     * @test
     * @testdox Outputs property resolves list of outputs params into Output models
     */

    public function outputsPropertyResolvesListOfOutputParams()
    {
        $outputs = [
            [ 'key' => 'video', 'format' => 'mp4:1080p::quality=5' ],
            [ 'key' => 'sound', 'format' => 'mp3:320k' ],
            [ 'format' => 'jpg:240p', 'number' => 3 ],
        ];

        $config = new Config();
        $config->outputs = $outputs;

        foreach ($config->outputs as $key => $output)
        {
            $this->assertInstanceOf(Output::class, $output);
            $this->assertSame($key, $output->key);
        }
    }

    /**
     * @test
     * @testdox Outputs property resolves list of format strings into Output models with parsed format specs
     */

    public function outputsPropertyResolvesListOfFormatStrings()
    {
        $outputFormats = [
            'mp4:hevc_720p:320k:quality=4',
            'mp3:256k',
            'jpg:240p'
        ];

        $config = new Config();
        $config->outputs = $outputFormats;

        $index = 0;
        foreach ($config->outputs as $key => $output)
        {
            $parsedFormat = ConfigHelper::parseFormat($outputFormats[$index++]);

            $this->assertInstanceOf(Output::class, $output);
            $this->assertSame($key, $output->key);
            $this->assertSame($parsedFormat, $output->format);
        }
    }

    /**
     * @test
     * @testdox Outputs property supports list with mixed output definitions
     */

    public function outputsPropertySupportsListWithMixedOutputDefinitions()
    {

    }

    /**
     * @test
     * @testdox Outputs property merges format specs from the output's index and format params
     */

    public function outputsPropertyMergesFormatSpecs()
    {
        $config = new Config();
        $config->outputs = [
            'mp4' => [
                'format' => [
                    'video_codec' => 'hevc',
                ]
            ]
        ];

        $outputFormatSpecs = $config->outputs['mp4']->format;

        $this->assertArrayHasKey('container', $outputFormatSpecs);
        $this->assertSame('mp4', $outputFormatSpecs['container']);
        $this->assertArrayHasKey('video_codec', $outputFormatSpecs);
        $this->assertSame('hevc', $outputFormatSpecs['video_codec']);
    }

    /**
     * @test
     * @testdox Outputs property shadows format container from output params with container from output index
     */

    public function outputsPropertyPrefersFormatContainerFromIndex()
    {
        $config = new Config();
        $config->outputs = [
            'mp4:hevc' => [
                'format' => [
                    'container' => 'webm',
                ],
            ],
        ];

        $outputContainer = $config->outputs['mp4:hevc']['format']['container'];
        $this->assertSame('mp4', $outputContainer);
    }

    /**
     * =storage
     *
     * @test
     * @testdox Storage property defaults to default storage setting
     */

    public function storagePropertyDefaultsToDefaultStorageSetting()
    {
        // init
        $settings = Coconut::$plugin->getSettings();
        $originalDefaultStorage = $settings->defaultStorage;

        // given
        $defaultStorage = new Storage([ 'service' => 'coconut' ]);
        $settings->defaultStorage = $defaultStorage;

        // when
        $config = new Config();

        // then
        $this->assertSame($defaultStorage, $config->storage);

        // cleanup
        $settings->defaultStorage = $originalDefaultStorage;
    }

    /**
     * @test
     * @testdox Storage property falls back to input asset volume storage if the default storage setting is not set
     */

    public function storagePropertyFallsBackToInputAssetVolumeStorage()
    {
        // init
        $settings = Coconut::$plugin->getSettings();
        $originalDefaultStorage = $settings->defaultStorage;

        // given
        $settings->defaultStorage = null;
        $inputAsset = $this->tester->grabFixture('assets')->getElement('localMp4VideoAsset');
        $inputVolumeStorage = Coconut::$plugin->getStorages()
            ->getVolumeStorage($inputAsset->getVolume());

        $config = new Config();
        $config->input = $inputAsset;

        $this->assertEquals($inputVolumeStorage, $config->storage);

        // cleanup
        $settings->defaultStorage = $originalDefaultStorage;
    }

    /**
     * @test
     * @testdox Storage property falls back to default uploads volume storage if default storage setting is not set, and input is not an asset
     */

    public function storagePropertyFallsBackToDefaultUploadVolume()
    {
        // init
        $settings = Coconut::$plugin->getSettings();
        $originalDefaultStorage = $settings->defaultStorage;
        $originaldefaultUploadVolume = $settings->defaultUploadVolume;

        // given
        $settings->defaultStorage = null;
        $settings->defaultUploadVolume = Craft::$app->getVolumes()
            ->getVolumeByHandle('localUploads');

        $volumeStorage = Coconut::$plugin->getStorages()
            ->getVolumeStorage($settings->defaultUploadVolume);

        // when
        $config = new Config();
        $config->input = 'https://www.example.com/videos/video.mp4';

        // then
        $this->assertEquals($volumeStorage, $config->storage);

        // cleanup
        $settings->defaultUploadVolume = $originaldefaultUploadVolume;
        $settings->defaultStorage = $originalDefaultStorage;
    }

    /**
     * @test
     * @testdox Storage property accepts storage model
     */

    public function storagePropertyAcceptsStorageModel()
    {
        $storage = new Storage([ 'service' => 'coconut' ]);

        $config = new Config();
        $config->storage = $storage;

        $this->assertSame($storage, $config->storage);
    }

    /**
     * @test
     * @testdox storage property resolve storage configuration array into storage model
     */

    public function storagePropertyResolvesStorageConfigArray()
    {
        $config = new Config();
        $config->storage = [ 'service' => 'coconut' ];

        $this->assertInstanceOf(Storage::class, $config->storage);
        $this->assertSame('coconut', $config->storage->service);
    }

    /**
     * @test
     * @testdox storage property resolves named storage handle into storage model
     */

    public function storagePropertyResolvesNamedStorageHandle()
    {
        $config = new Config();
        $config->storage = 'coconutStorage';

        $this->assertInstanceOf(Storage::class, $config->storage);
    }

    /**
     * @test
     * @testdox storage property resolves volume model into storage model
     */

    public function storagePropertyResolvesVolumeModel()
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle('localUploads');

        $config = new Config();
        $config->storage = $volume;

        $this->assertInstanceOf(Storage::class, $config->storage);
    }

    /**
     * @test
     * @testdox storage property resolves volume handle into storage model
     */

    public function storagePropertyResolvesVolumeHandle()
    {
        $config = new Config();
        $config->storage = 'localUploads';

        $this->assertInstanceOf(Storage::class, $config->storage);
    }
}
