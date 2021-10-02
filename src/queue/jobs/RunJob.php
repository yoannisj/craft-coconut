<?php

namespace yoannisj\coconut\queue\jobs;

use yii\base\Exception;
use yii\base\InvalidConfigException;

use Craft;
use craft\queue\BaseJob;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Job;

/**
 *
 */

class RunJob extends BaseJob
{
    // =Properties
    // =========================================================================

    /**
     * @var int Internal ID of job to run
     */

    public $jobId;

    /**
     * @var Job Model for Coconut job to run
     */

    private $_job;

    /**
     * @var int Time in miliseconds to wait before checking job's status
     */

    public $checkJobInterval = 1000;

    // =Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        if (!$this->jobId)
        {
            throw new InvalidConfigException(
                'Missing required `jobId` property');
        }

        parent::init();

    }

    /**
     * Getter method for readonly `job` property
     *
     * @return Job|null
     */

    public function getJob()
    {
        if (!$this->_job) {
            $this->fetchJob(); // sets the '_job' property internally
        }

        return $this->_job;
    }

    /**
     * @inheritdoc
     */

    public function getDescription()
    {
        $input = $this->job->input;

        return Craft::t('coconut',
            "Transcoding '{inputName}' with Coconut.co", [
                'inputName' => $this->job->input->getName(),
            ]
        );
    }

    /**
     * @inheritdoc
     *
     * Set high Time to Reserve for this job to support longer Coconut jobs
     * @link https://craftcms.stackexchange.com/questions/25437/queue-exec-time/25452#25452
     */

    public function getTtr()
    {
        return Coconut::$plugin->getSettings()->transcodeJobTtr;
    }

    /**
     * @inheritdoc
     */

    public function execute( $queue )
    {
        $job = $this->getJob();
        $coconutJobs = Coconut::$plugin->getJobs();
        $success = $coconutJobs->runJob($job);

        if (!$success)
        {
            if ($job->hasErrors())
            {
                throw new InvalidConfigException($job->getFirstError());
            }

            throw new Exception('Could not run given Coconut Job.');
        }

        // update job's progress in the queue over time
        while ($job->status != Job::STATUS_COMPLETED
            && $job->status != Job::STATUS_FAILED)
        {
            sleep($this->checkJobInterval);

            $job = $this->fetchJob();
            $queueProgress = (int)(str_replace('%', '', $job->progress)) / 100;

            $this->setProgress($queue, $queueProgress);
        }
    }

    /**
     * Fetches job from database
     *
     * @return Job
     *
     * @throws InvalidConfigException If job could not be found
     */

    protected function fetchJob(): Job
    {
        $job = Coconut::$plugin->getJobs()->getJobById($this->jobId);

        if (!$job) {
            throw new InvalidConfigException(
                'Could not find job with id `'.$this->jobId.'`');
        }

        $this->_job = $job;
        return $this->_job;
    }

}
