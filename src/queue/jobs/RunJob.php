<?php

namespace yoannisj\coconut\queue\jobs;

use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\queue\RetryableJobInterface;

use Craft;
use craft\queue\BaseJob;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Job;

/**
 * Job to run a Coconut transcoding Job via the Craft Queue
 */
class RunJob extends BaseJob implements RetryableJobInterface
{
    // =Properties
    // =========================================================================

    /**
     * @var int Internal ID of job to run
     */
    public int $jobId;

    /**
     * @var Job Model for Coconut job to run
     */
    private Job $_job;

    /**
     * @var int Time in miliseconds to wait before checking job's status
     */
    public int $checkJobInterval = 1000;

    // =Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        if (!$this->jobId)
        {
            throw new InvalidConfigException(
                'Missing required `jobId` property');
        }

        parent::init();
    }

    /**
     * @inheritdoc
     */
    public function getDescription(): ?string
    {
        $input = $this->job->getInput();

        return Craft::t('coconut',
            "Transcoding '{inputName}' with Coconut.co", [
                'inputName' => $input->getName(),
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
     *
     * @param mixed $attempt
     * @param mixed $error
     *
     * @return bool
     */
    public function canRetry( $attempt, $error )
    {
        // @todo Determine retry conditions for RunJob queue jobs
        return false;
    }

    /**
     * @inheritdoc
     */
    public function execute( $queue ): void
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
     * Getter method for readonly `job` property
     *
     * @return Job|null
     */
    public function getJob(): ?Job
    {
        if (!$this->_job) {
            $this->fetchJob(); // sets the '_job' property internally
        }

        return $this->_job;
    }

    // =Protected Methods
    // =========================================================================

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
