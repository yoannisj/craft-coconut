<?php

namespace yoannisj\coconuttests\unit\services;

use Codeception\AssertThrows;
use Codeception\Test\Unit as UnitTest;
use UnitTester;

use yii\base\Event;
use yii\base\InvalidArgumentException;

use Craft;
use craft\test\EventItem;
use craft\helpers\Db as DbHelper;
use craft\helpers\StringHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Job;
use yoannisj\coconut\models\Output;
use yoannisj\coconut\records\JobRecord;
use yoannisj\coconut\records\OutputRecord;
use yoannisj\coconut\services\Outputs;
use yoannisj\coconut\events\OutputEvent;

/**
 * Unit tests for the plugin's Jobs service
 */

class OutputsTest extends UnitTest
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
    }

    /**
     * Method ran after each test to clean up side effects
     */

    public function _after()
    {
    }

    /**
     * @inheritdoc
     */

    public function _fixtures()
    {
        return [
            'jobs' => [
                'class' => \yoannisj\coconuttests\fixtures\JobsFixture::class,
            ],
        ];
    }

    // =Tests
    // ------------------------------------------------------------------------

    /**
     * @test
     * @testdox saveOutput() saves output model in database
     */

    public function saveOutputSavesOutputInDb()
    {
        // given
        $output = new Output([
            'jobId' => 100,
            'format' => 'webm:480p',
            'key' => 'webm-lq',
        ]);

        // when
        $success = Coconut::$plugin->getOutputs()->saveOutput($output);

        // then
        $this->assertNotNull($output->id);
        $this->assertSame(true, $success);

        $this->tester->seeRecord(OutputRecord::class, [
            'id' => $output->id
        ]);
    }

    /**
     * @test
     * @testdox saveOutput() does not save invalid output model in database by default
     */

    public function saveOutputDoesntSaveInvalidOutputByDefault()
    {
        // given
        $output = new Output([
            'jobId' => 100,
            'format' => 'webm:480p',
            'key' => 'webm-lq',
        ]);

        $output->type = 'document'; // invalid output type

        // when
        $success = Coconut::$plugin->getOutputs()->saveOutput($output);

        // then
        $this->assertSame(false, $success);
        $this->assertNull($output->id);

        $this->tester->dontSeeRecord(OutputRecord::class, [
            'type' => 'document',
        ]);
    }

    /**
     * @test
     * @testdox saveOutput() optionally skips validation and saves invalid output model
     */

    public function saveOutputOptionallySkipsValidation()
    {
        // given
        $output = new Output([
            'jobId' => 100,
            'format' => 'webm:480p',
            'key' => 'webm-lq',
        ]);

        $output->type = 'document'; // invalid output type

        // when
        $success = Coconut::$plugin->getOutputs()->saveOutput($output, false);

        // then
        $this->assertSame(true, $success);
        $this->assertNotNull($output->id);

        $this->tester->seeRecord(OutputRecord::class, [
            'type' => 'document',
        ]);
    }

    /**
     * @test
     * @testdox saveOutput() triggers event *before* saving output in database
     */

    public function saveOutputTriggersEventBeforeSaving()
    {
        // create new copy of job that was created in Coconut
        $output = new Output([
            'jobId' => 100,
            'format' => 'webm:480p',
            'key' => 'webm-lq',
        ]);

        $this->tester->expectEvent(
            Outputs::class,
            Outputs::EVENT_BEFORE_SAVE_OUTPUT,
            function() use ($output) {
                Coconut::$plugin->getOutputs()->saveOutput($output);
            },
            OutputEvent::class,
            $this->tester->createEventItems([
                [
                    'eventPropName' => 'output',
                    'type' => EventItem::TYPE_OTHERVALUE,
                    'desiredValue' => $output,
                ],
                [
                    'eventPropName' => 'isNew',
                    'type' => EventItem::TYPE_OTHERVALUE,
                    'desiredValue' => true,
                ],
            ])
        );
    }

    /**
     * @test
     * @testdox saveOutput() triggers event *after* saving output in database
     */

    public function saveOutputTriggersEventAfterSaving()
    {
        // create new copy of job that was created in Coconut
        $output = new Output([
            'jobId' => 100,
            'format' => 'webm:480p',
            'key' => 'webm-lq',
        ]);

        $this->tester->expectEvent(
            Outputs::class,
            Outputs::EVENT_AFTER_SAVE_OUTPUT,
            function() use ($output) {
                Coconut::$plugin->getOutputs()->saveOutput($output);
            },
            OutputEvent::class,
            $this->tester->createEventItems([
                [
                    'eventPropName' => 'output',
                    'type' => EventItem::TYPE_OTHERVALUE,
                    'desiredValue' => $output,
                ],
                [
                    'eventPropName' => 'isNew',
                    'type' => EventItem::TYPE_OTHERVALUE,
                    'desiredValue' => true,
                ],
            ])
        );
    }
}
