<?php

namespace yoannisj\coconuttests\unit\models;

use Codeception\Specify;
use Codeception\Test\Unit as UnitTest;
use UnitTester;

use Craft;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Settings;
use yoannisj\coconut\models\Storage;

/**
 *
 */

class StorageTest extends UnitTest
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

    // =Tests
    // ------------------------------------------------------------------------

    // =Protected Methods
    // ========================================================================

}
