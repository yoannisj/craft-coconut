<?php

namespace yoannisj\coconuttests\unit;

use Codeception\Specify;
use Codeception\AssertThrows;
use Codeception\Test\Unit as UnitTest;
use UnitTester;

use yii\base\InvalidConfigException;

use Craft;
use craft\elements\Asset;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Input;

/**
 *
 */

class InputTest extends UnitTest
{
    // =Traits
    // ========================================================================

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
     * @inheritdoc
     * Method ran before each test to setup test context
     */

    public function _before()
    {
    }

    /**
     * @inheritdoc
     * Method ran after each test to clean up side effects
     */

    public function _after()
    {
    }

    /**
     * @inheritdoc
     * Method defining fixtures used by tests in this file
     */

    public function _fixtures()
    {
        return [
            'assets' => [
                'class' => \yoannisj\coconuttests\fixtures\AssetsFixture::class,
            ],
        ];
    }

    // =Tests
    // ------------------------------------------------------------------------

    /**
     * =asset
     */

    public function testAssetProperty()
    {
        $this->specify("Returns the craft Asset element defined by input's `assetId`",
            function()
        {
            $asset = $this->tester->grabFixture('assets')
                ->getElement('localMp4VideoAsset');

            $input = new Input();
            $input->assetId = $asset->id;

            $assetById = Asset::find()
                ->id($asset->id)
                ->one();

            $this->assertEquals( $assetById, $input->asset );
        });

        $this->specify("Updates the input's `assetId`",
            function()
        {
            $asset = $this->tester->grabFixture('assets')
                ->getElement('localMp4VideoAsset');

            $input = new Input();
            $input->asset = $asset;

            $this->assertSame( $asset->id, $input->assetId );
        });

        $this->specify("Throws an error if it was set to a craft Asset element of another kind than `video`",
            function()
        {
            $imageAsset = $this->tester->grabFixture('assets')
                ->getElement('localJpgImageAsset');

            $input = new Input();
            $input->asset = $imageAsset;

            $this->assertThrows(
                InvalidConfigException::class,
                function() use ($input) {
                    $inputAsset = $input->asset;
                }
            );
        });
    }

    /**
     * =url
     */

    public function testUrlProperty()
    {
        $this->specify("Returns the input's asset URL when not set",
            function()
        {
            $asset = $this->tester->grabFixture('assets')
                ->getElement('localMp4VideoAsset');

            $input = new Input();
            $input->asset = $asset;

            $this->assertSame($asset->url, $input->url);
        });

        $this->specify("Returns an empty value if not set and input's asset is also not set",
            function()
        {
            $input = new Input();
            $this->assertEmpty( null, $input->url );
        });
    }

    /**
     * =urlHash
     */

    public function testUrlHashProperty()
    {
        $this->specify("Always returns the same hash string for a given input URL",
            function()
        {
            $inputUrl = 'https://www.example.com/media/input-video.mp4';

            $inputA = new Input();
            $inputA->url = $inputUrl;

            $inputB = new Input();
            $inputB->url = $inputUrl;

            $this->assertSame( $inputA->urlHash, $inputB->urlHash );
        });

        $this->specify("Changes when input's `url` is updated", function()
        {
            $input = new Input();

            $input->url = 'https://www.example.com/media/input-video-a.mp4';
            $urlHashA = $input->urlHash;

            $input->url = 'https://www.example.com/media/input-video-b.mp4';
            $urlHashB = $input->urlHash;

            $this->assertNotEquals( $urlHashA, $urlHashB );
        });
    }

    /**
     * =metadata
     */

    public function testMetadataProperty()
    {
        $this->specify("Decodes JSON string value as an associative array",
            function()
        {
            $jsonString = '{"type":"video","mimeType":"video\/mp4","duration":"10.23s"}';

            $input = new Input();
            $input->metadata = $jsonString;

            $this->assertEquals( json_decode($jsonString, true), $input->metadata );
        });

        $this->specify("Throw an error when set to a value that is not `null`, nor an array",
            function( $value )
        {
            $this->assertThrows(InvalidConfigException::class, function() use ($value)
            {
                $input = new Input();
                $input->metadata = $value;
            });
        }, [
            'examples' => [
                [ 'hello world' ],
                [ false ],
                [ 15 ],
            ],
        ]);
    }
}
