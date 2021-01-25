<?php

/**
 * Coconut plugin for Craft
 *
 * @author Yoannis Jamar
 * @copyright Copyright (c) 2020 Yoannis Jamar
 * @link https://github.com/yoannisj/
 * @package craft-coconut
 *
 */

namespace yoannisj\coconut\services;

use Coconut\Job as CoconutJob;

use yii\base\Exception;

use Craft;
use craft\base\Component;
use craft\helpers\ArrayHelper;
use craft\helpers\Json as JsonHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Config;
use yoannisj\coconut\services\Outputs;
use yoannisj\coconut\events\JobEvent;
use yoannisj\coconut\events\CancellableJobEvent;

/**
 *
 */

class Jobs extends Component
{
    // =Static
    // =========================================================================

    const EVENT_BEFORE_CREATE_JOB = 'beforeCreateJob';
    const EVENT_AFTER_CREATE_JOB = 'afterCreateJob';
    const EVENT_JOB_ERROR = 'jobError';
    const EVENT_JOB_COMPLETE = 'jobComplete';

    // =Public Methods
    // =========================================================================

    /**
     * Creates a new Coconut job, runs it synchronously by waiting on its
     * completion, and returns the resulting outputs
     *
     * @param \yoannisj\coconut\models\Config $config Coconut job config model
     * @param int $checkInterval Amount of time to wait between each job update (in miliseconds)
     *
     * @throws Job error eception if job's status is "error"
     * @return nul|array List of resulting outputs
     */

    public function runJob( Config $config, int $checkInterval = 0, callable $updateCallback = null )
    {
        $jobInfo = $this->createJob($config, true);
        
        while ($jobInfo && $jobInfo->status == 'processing')
        {
            // wait a certain amount of time before continuing
            if ($checkInterval) usleep($checkInterval * 1000);

            // get updated job info
            $jobInfo = $this->checkJob($jobInfo->id, false);

            if ($updateCallback) { // optionally run update callback
                call_user_func($updateCallback, $jobInfo);
            }
        }

        // job is completed: update job and return resulting outputs
        return $this->updateJob($jobInfo, true);
    }

    /**
     * Creates a new Coconut job, and optionally updates outputs in the database.
     *
     * @param \yoannisj\coconut\models\Config $config
     * @param bool $updateOutputs Whether outputs in the db should be updated
     *
     * @throws Job error exception if job's status is "error"
     * @return object | null Information about the newly created job or null if job creation was cancelled
     */

    public function createJob( Config $config, $updateOutputs = true )
    {
        $params = $config->getJobParams();

        // trigger EVENT_BEFORE_CREATE_JOB
        $beforeCreateEvent = new CancellableJobEvent([
            'config' => $config,
            'updateOutputs' => $updateOutputs,
            'jobInfo' => null,
        ]);

        $this->trigger(self::EVENT_BEFORE_CREATE_JOB, $beforeCreateEvent);

        // allow event listeners to cancel job creation
        if ($beforeCreateEvent->isValid === false) {
            return null;
        }

        // create coconut job using Coconut API
        $jobInfo = CoconutJob::create($params);        
        $this->updateJob($jobInfo, false); // update jobInfo

        // trigger EVENT_AFTER_CREATE_JOB
        $afterCreateEvent = new JobEvent([
            'config' => $config,
            'updateOutputs' => $updateOutputs,
            'jobInfo' => $jobInfo,
        ]);

        $this->trigger(self::EVENT_AFTER_CREATE_JOB, $afterCreateEvent);

        if ($updateOutputs) {
            $newOutputs = Coconut::$plugin->getOutputs()->initConfigOutputs($config, $jobInfo->id);
        }

        return $jobInfo;
    }

    /**
     * Retrieves job information for given Coconut job id, handles job status
     * update, and optionally updates outputs in the database.
     *
     * @param int $jobId Id of job to retrieve
     * @param bool $updateOutputs Whether outputs in the db should be updated
     *
     * @throws Job error exception if job's status is "error"
     * @return object Information retrieved about the job
     */

    public function checkJob( int $jobId, $updateOutputs = true )
    {
        $jobInfo = CoconutJob::get($jobId);
        $this->updateJob($jobInfo, $updateOutputs);

        return $jobInfo;
    }

    /**
     * Helper method to handle status update status for given Coconut job.
     *
     * @param object|int $jobInfo Info object or id of job to update
     * @param bool $updateOutputs Whether outputs in the db should be updated
     *
     * @throws Job error exception if job's status is "error"
     * @return null|array Updated list of outputs if `$updateOutputs` argument is `true`
     */

    public function updateJob( $jobInfo, bool $updateOutputs = true )
    {
        $result = null;

        // accept a job id
        if (is_numeric($jobInfo)) {
            $jobInfo = CoconutJob::get($jobId);
        }

        if ($jobInfo->status == 'error') {
            $this->onJobError($jobInfo, $updateOutputs);
        }

        if ($jobInfo->status == 'completed') {
            $result = $this->onJobComplete($jobInfo, $updateOutputs);
        }

        return $result;
    }

    // =Protected Methods
    // =========================================================================

    /**
     * Handles errors for given Coconut job.
     *
     * @param object $jobInfo Info object or id of job to update
     * @param bool $updateOutputs Whether outputs in the db should be updated
     *
     * @throws Job error exception
     */

    protected function onJobError( $jobInfo, bool $updateOutputs = true )
    {
        $jobId = $jobInfo->id ?? null;
        $jobOutputs = null;

        if ($updateOutputs && $jobId)
        {
            $service = Coconut::$plugin->getOutputs();
            $jobOutputs = $service->getJobOutputs($jobId);

            foreach ($jobOutputs as $output) {
                $service->deleteOutput($output);
            }
        }

        // trigger EVENT_JOB_ERROR
        $errorEvent = new JobEvent([
            'updateOutputs' => $updateOutputs,
            'jobInfo' => $jobInfo,
            'jobOutputs' => $jobOutputs,
        ]);

        $this->trigger(self::EVENT_JOB_ERROR, $errorEvent);

        throw new Exception($jobInfo->message);
    }

    /**
     * Handles completion for given Coconut job.
     *
     * @param object $jobInfo Info object or id of job to update
     * @param bool $updateOutputs Whether outputs in the db should be updated
     *
     * @return null|array Updated list of outputs if `$updateOutputs` argument is `true`
     */

    protected function onJobComplete( $jobInfo, bool $updateOutputs = true )
    {
        if (!$updateOutputs) {
            return null;
        }

        $service = Coconut::$plugin->getOutputs();

        $jobOutputs = $service->getJobOutputs($jobInfo->id);
        $outputUrls = (array)$jobInfo->output_urls;

        $metadata = [];
        $result = CoconutJob::getAllMetadata($jobInfo->id);
        if ($result && property_exists($result, 'metadata')) {
            $metadata = JsonHelper::decode(JsonHelper::encode($result->metadata));
        }

        // @todo: compare at db output urls with jobInfo->outputUrls to discover
        // errors in anticipative urls saved in the DB
        // -> problem: if we don't add the ?host arg in output urls to the coconut job,
        //  resulting jobInfo->outputUrls use "http://"
        // -> problem: when passing "https://s3.amazonaws.com/<bucket-name>" in the ?host arg,
        //  resulting jobInfo->outputUrls still use "https://<bucket-name>.s3.amazonaws.com/"
        // ->problem: when passing "https://<bucket-name>.s3.amazonaws.com/" in the ?host arg,
        //  the coconut job does not throw an error, but transcoding still fails for every file

        foreach ($jobOutputs as $output)
        {
            $output->inProgress = false;
            $output->metadata = $metadata[$output->format] ?? null;

            $service->saveOutput($output);
        }

        // $completedOutputs = [];
        // $invalidOutputs = [];

        // foreach ($jobOutputs as $output)
        // {
        //     $formatUrls = $outputUrls[$output->format] ?? [];
        //     if (!is_array($formatUrls)) $formatUrls = [ $formatUrls ];

        //     if (in_array($output->url, $formatUrls)) {
        //         $completedOutputs[] = $output;
        //     }

        //     else {
        //         $invalidOutputs[] = $output;
        //     }
        // }

        // foreach ($completedOutputs as $output)
        // {
        //     $output->inProgress = false;
        //     $service->saveOutput($output);
        // }

        // foreach ($invalidOutputs as $output) {
        //     $service->deleteOutput($output); // removes output file
        // }

        // return $completedOutputs;

        // trigger EVENT_JOB_COMPLETE
        $completeEvent = new JobEvent([
            'updateOutputs' => $updateOutputs,
            'jobInfo' => $jobInfo,
            'jobOutputs' => $jobOutputs,
        ]);

        $this->trigger(self::EVENT_JOB_COMPLETE, $completeEvent);

        return $jobOutputs;
    }

}