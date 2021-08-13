<?php

namespace yoannisj\coconuttests\unit;

use Codeception\Specify;
use Codeception\Test\Unit as UnitTest;
use UnitTester;

use Craft;

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

    // =Fixtures
    // ------------------------------------------------------------------------

    /**
     * @inheritdoc
     * Method defining fixtures used by tests in this file
     */

    public function _fixtures()
    {
        return [
            'assets' => [
                'class' => \yoannisj\coconuttests\fixtures\AssetFixture::class,
            ],
        ];
    }

    // =Tests
    // ------------------------------------------------------------------------

    /**
     * =asset
     * @test
     */

    public function asset()
    {
        $this->specify("Returns the craft Asset element defined by input's `assetId`",
            function()
        {
            // $assets = $this->tester->grapFixture('assets');
            // var_dump($assets); die();

            // $input = new Input();
            // $input->asset = $asset;

            // $this->assertSame( $asset->id, $input->assetId );
        });

        // $this->specify("Updates the input's `assetId`",
        //     function()
        // {

        // });

        // $this->specify("Throws an error if set to a craft Asset element of another kind than `video`",
        //     function()
        // {

        // });
    }

    /**
     * =url
     * @test
     */

    public function url()
    {
        // $this->specify("Returns public URL of input's `asset`",
        //     function()
        // {
        //     $input = new Input();
        // });
    }
}
