<?php

namespace yoannisj\coconut\tests;

use Codeception\Test\Unit;

use UnitTester;
use Craft;

class ExampleTest extends Unit
{
    /**
     * @var UnitTester
     */
    protected $tester;

    public function testExample()
    {
        Craft::$app->setEdition(Craft::Pro);

        $this->assertSame( Craft::$app->getEdition(), Craft::Pro );
    }
}
