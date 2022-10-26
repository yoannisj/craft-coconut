<?php

namespace yoannisj\coconuttests\unit\services;

use Codeception\AssertThrows;
use Codeception\Test\Unit as UnitTest;
use UnitTester;

use yii\base\Event;
use yii\base\InvalidArgumentException;

use Craft;
use craft\test\EventItem;
use craft\helpers\Json as JsonHelper;

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
            'input' => 'https://s3.amazonaws.com/coconut.co/samples/1min.mp4',
            'outputPathFormat' => '{filename}/{key}.{ext}',
            'outputs' => [
                'avi:240p' => [
                    [
                        'key' => 'intro:avi:240p',
                        'duration' => 3,
                    ],
                    [
                        'key' => 'avi:240p',
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
        $success = Coconut::$plugin->getJobs()->runJob($job);

        // then
        $this->assertEquals(true, $success);
        $this->assertNotNull($job->coconutId);
        $this->assertNotNull($job->status);

        return $job; // return value for depending tests
    }

    /**
     * @test
     * @depends runJobCreatesNewJobInCoconut
     */

    public function saveJobAddsRecordToDb( $job )
    {
        // when
        $success = Coconut::$plugin->getJobs()->savejob($job);

        // then
        $this->assertEquals(true, $success);
        $this->assertNotNull($job->id);
        $this->tester->seeRecord(JobRecord::class, [
            'coconutId' => $job->coconutId
        ]);

        return $job;
    }

    /**
     * @test
     * @depends saveJobAddsRecordToDb
     */

    public function saveJobUpdatesRecordWithSameJobCoconutId( $job )
    {
        $idBefore = $job->id;

        // when
        Coconut::$plugin->getJobs()->savejob($job);

        // then
        $this->assertEquals($idBefore, $job->id);
    }

    /**
     * @test
     * @depends runJobCreatesNewJobInCoconut
     */

    public function pullJobInfoPopulatesJobModel( $job )
    {
        // given
        $job->status = null;

        // when
        $success = Coconut::$plugin->getJobs()->pullJobInfo($job);

        // then
        $this->assertEquals(true, $success);
        $this->assertNotNull($job->status);
    }

    /**
     * @test
     * @depends runJobCreatesNewJobInCoconut
     */

    public function pullJobInfoFailsOnApiError( $job )
    {
        // when
        $job->coconutId = 'foobar123';
        $job->status = null;

        // given
        $success = Coconut::$plugin->getJobs()->pullJobInfo($job);

        // then
        $this->assertEquals(false, $success);
        $this->assertNull($job->status);
    }

    /**
     * @test
     */

    public function pullJobInfoThrowsErrorIfJobIsNew()
    {
        $this->assertThrows(InvalidArgumentException::class, function()
        {
            $job = new Job();
            Coconut::$plugin->getJobs()->pullJobInfo($job);
        });
    }

    /**
     * @test
     * @depends runJobCreatesNewJobInCoconut
     * @large
     */

    // public function pullJobMetadataPopulatesJobModel( $job )
    // {
    //     // given
    //     $job->metadata = null;

    //     // when
    //     $success = Coconut::$plugin->getJobs()->pullJobMetadata($job);

    //     // then
    //     $this->assertEquals(true, $success);
    //     $this->assertNotNull($job->metadata);
    // }

    /**
     * @test
     * @depends runJobCreatesNewJobInCoconut
     */

    // public function pullJobMetadataFailsOnApiError( $job )
    // {
    //     // when
    //     $job->coconutId = 'foobar123';
    //     $job->metadata = null;

    //     // given
    //     $success = Coconut::$plugin->getJobs()->pullJobMetadata($job);

    //     // then
    //     $this->assertEquals(false, $success);
    //     $this->assertNull($job->metadata);
    // }

    /**
     * @test
     */

    // public function pullJobMetadataThrowsErrorIfJobIsNew()
    // {
    //     $this->assertThrows(InvalidArgumentException::class, function()
    //     {
    //         $job = new Job();
    //         Coconut::$plugin->getJobs()->pullJobMetadata($job);
    //     });
    // }

    /**
     * @test
     */

    public function updateJobSavesJobData()
    {
        // given
        $jsonFile = dirname(__DIR__, 2) . '/_data/job-info-completed.json';
        $jobData = JsonHelper::decode(file_get_contents($jsonFile));
        $coconutId = $jobData['id'];

        $job = new Job([
            'coconutId' => $coconutId,
            'status' => 'job.starting',
            'progress' => '0%',
        ]);

        // when
        $success = Coconut::$plugin->getJobs()->updateJob($job, $jobData);
        $job = Coconut::$plugin->getJobs()->getJobByCoconutId($coconutId);

        // then
        $this->assertEquals(true, $success);
        $this->assertEquals($jobData['status'], $job->status);
        $this->assertEquals($jobData['progress'], $job->progress);
    }

    /**
     * @test
     */

    public function updateJobInputSavesJobInputData()
    {
        // given
        $jsonFile = dirname(__DIR__, 2) . '/_data/notification-input-transferred.json';
        $notificationData = JsonHelper::decode(file_get_contents($jsonFile));
        $coconutId = $notificationData['job_id'];
        $jobInputData = $notificationData['data'];

        // given
        $id = 1011;
        $coconutId = '2QcdNFigRRztnh';

        $this->tester->haveRecord(JobRecord::class, [
            'id' => $id,
            'coconutId' => $coconutId,
            'inputUrl' => 'https://s3.amazonaws.com/coconut.co/samples/1min.mp4',
            'inputAssetId' => null,
            'status' => null,
            'progress' => '0%',
        ]);

        $job = new Job([
            'id' => $id,
            'coconutId' => $coconutId,
            'status' => null,
            'progress' => '0%',
            'input' => null,
            'outputs' => [
                [
                    'key' => 'intro:avi:240p',
                    'type' => 'video',
                    'format' => 'avi:240p',
                    'duration' => 3,
                ],
                [
                    'key' => 'avi:240p',
                    'type' => 'video',
                    'format' => 'avi:240p',
                    'duration' => null,
                ],
            ]
        ]);

        // when
        $success = Coconut::$plugin->getJobs()->updateJobInput($job, $jobInputData);
        $job = Coconut::$plugin->getJobs()->getJobByCoconutId($coconutId);

        // then
        $this->assertEquals(true, $success);
        $this->assertEquals($jobInputData['status'], $job->status); // set job status
        $this->assertEquals($jobInputData['metadata'], $job->metadata['input']); // set job input metadata

        // don't alter job progress
        $this->assertEquals('0%', $job->progress);
        $this->assertEquals('100%', $job->input->progress);
    }

    /**
     * @test
     */

    public function updateJobOutput()
    {

    }
}
