<?php

namespace yoannisj\coconuttests\unit\services;

use Codeception\AssertThrows;
use Codeception\Test\Unit as UnitTest;
use UnitTester;

use yii\base\Event;
use yii\base\InvalidArgumentException;

use Craft;
use craft\test\EventItem;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Job;
use yoannisj\coconut\records\JobRecord;
use yoannisj\coconut\services\Jobs;
use yoannisj\coconut\events\JobEvent;

/**
 * Unit tests for the plugin's Jobs service
 */

class JobsTest extends UnitTest
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
            'assets' => [
                'class' => \yoannisj\coconuttests\fixtures\AssetsFixture::class,
            ],
        ];
    }

    // =Tests
    // ------------------------------------------------------------------------

    /**
     * =runJob
     *
     * @test
     * @testdox runJob() creates new Job on Coconut and gives it a new status and coconut ID
     */

    public function runJobCreatesNewJobInCoconut()
    {
        $job = new Job([
            'input' => 'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerEscapes.mp4',
            'outputPathFormat' => '{filename}/{key}.{ext}',
            'outputs' => [
                'webm:480p' => [
                    [
                        'key' => 'intro:webm:480p',
                        'duration' => 6,
                    ],
                    [
                        'key' => 'webm:480p',
                    ],
                ],
            ],
            'storage' => [
                'service' => 'coconut',
            ],
            'notification' => [
                'type' => 'http',
                'url' => 'https://app.coconut.co/notifications/http/e8557c9f'
            ],
        ]);

        // when
        Coconut::$plugin->getJobs()->runJob($job);

        // then
        $this->assertNotNull($job->coconutId);
        $this->assertNotNull($job->status);

        return $job; // return value for depending tests
    }



    /**
     * @test
     * @depends runJobCreatesNewJobInCoconut
     */

    public function pullJobMetadataPopulatesJobModel( $job )
    {
        $client = Coconut::$plugin->createClient();
        $data = $client->metadata->retrieve('gFvuAVKheVSqdp');
        $data = \craft\helpers\ArrayHelper::toArray($data); // client return a StdObject instance

        var_dump($data); die();

        // $job = Coconut::$plugin->getJobs()->pullJobMetadata($job);
    }
}
