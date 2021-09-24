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

use Coconut\Client as CoconutClient;

use yii\base\Exception;
use yii\base\InvalidArgumentException;

use Craft;
use craft\base\Component;
use craft\helpers\ArrayHelper;
use craft\helpers\Json as JsonHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Input;
use yoannisj\coconut\models\Job;
use yoannisj\coconut\records\JobRecord;
use yoannisj\coconut\exceptions\CoconutApiExeption;
use yoannisj\coconut\events\JobEvent;
use yoannisj\coconut\events\CancellableJobEvent;
use yoannisj\coconut\helpers\JobHelper;

/**
 * Singleton service class to work with Coconut Jobs
 */

class Jobs extends Component
{
    // =Static
    // =========================================================================

    const EVENT_BEFORE_SAVE_JOB = 'beforeSaveJob';
    const EVENT_AFTER_SAVE_JOB = 'afterSaveJob';

    // =Properties
    // =========================================================================

    /**
     * @var Job[] List of memoized Coconut jobs indexed by their ID
     */

    private $_jobsPerId = [];

    /**
     * @var Job[] List of memoized Coconut jobs indexed by their coconut ID
     */

    private $_jobsPerCoconutId = [];

    /**
     * @var Job[] List of memoized Coconut jobs indexed by their input asset ID
     */

    private $_jobsPerInputAssetId = [];

    /**
     * @var Job[] List of memoized Coconut jobs indexed by their input asset ID
     */

    private $_jobsPerInputAssetUrlHash = [];

    /**
     * @var array[]
     */

    private $_jobInfoByCoconutId = [];

    /**
     * @var array[]
     */

    private $_jobMetadataByCoconutId = [];

    // =Public Methods
    // =========================================================================

    /**
     * Runs given job via Coconut's API
     *
     * @param Job $job Th job model to run
     * @param boolean $runValidation Whether to validate given job before submitting it to Coconut
     *
     * @return boolean
     *
     * @throws \Coconut\Error if Coconut API could not be reached or returned an error
     */

    public function runJob( Job $job, bool $runValidation = true )
    {
        if (isset($job->coconutId))
        {
            throw new InvalidArgumentException(
                'Can not re-run a job that has been ran before');
        }

        if ($runValidation && !$job->validate()) {
            return false;
        }

        // use Coconut API to run the job (by creating a new one)
        // (throws an error if API is not reachable or returned an error code)
        $client = Coconut::$plugin->createClient();
        $data = $client->job->create($job->toParams());
        $data = ArrayHelper::toArray($data); // client return a StdObject instance

        $job = JobHelper::populateJobFromData($job, $data);

        if (!$this->saveJob($job)) {
            return false;
        }

        return $job;
    }

    /**
     * Saves given job model to the database
     *
     * @param Job $job
     * @param boolean $runValidation
     *
     * @return boolean
     */

    public function saveJob( Job $job, bool $runValidation = true ): bool
    {
        $isNewJob = !isset($job->id);
        $previousStatus = $job->status;
        $previousInputStatus = $job->getInput()->status;

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_JOB))
        {
            $this->trigger(self::EVENT_BEFORE_SAVE_JOB, new JobEvent([
                'job' => $job,
                'isNew' => $isNewJob,
                'previousStatus' => $previousStatus,
                'previousInputStatus' => $previousInputStatus,
            ]));
        }

        if ($runValidation && !$job->validate()) {
            return false;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try
        {
            // save job outputs
            $coconutOutputs = Coconut::$plugin->getOutputs();
            foreach ($job->getOutputs() as $output)
            {
                if (!$coconutOutputs->saveOutput($output, $runValidation))
                {
                    $transaction->rollBack();
                    return false;
                }
            }

            $record = JobHelper::populateRecordFromJob(new JobRecord(), $job);

            if (isset($record->id)) {
                $record->update();
            } else {
                $record->insert();
            }

            // update job model's attributes based on what's now saved in the database
            $job->id = $record->id;
            $job->dateCreated = $record->dateCreated;
            $job->dateUpdated = $record->dateUpdated;
            $job->uid = $record->uid;

            $transaction->commit();
        }

        catch (\Throwable $e)
        {
            $transaction->rollBack();
            throw $e;
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_JOB))
        {
            $this->trigger(self::EVENT_AFTER_SAVE_JOB, new JobEvent([
                'job' => $job,
                'isNew' => $isNewJob,
                'previousStatus' => $previousStatus,
                'previousInputStatus' => $previousInputStatus,
            ]));
        }

        return true;
    }

    /**
     * Pulls info for given job from Coconut API
     *
     * @param Job $job
     *
     * @return Job
     */

    public function pullJobInfo( Job $job )
    {
        if (!isset($job->coconutId))
        {
            throw new InvalidArgumentExceptino(
                'Can not pull info for new job');
        }

        if (!array_key_exists($job->coconutId, $this->_jobInfoByCoconutId))
        {
            $client = Coconut::$plugin->createClient();
            $data = $client->job->retrieve($job->coconutId);
            $data = ArrayHelper::toArray($data); // client return a StdObject instance

            $this->_jobInfoByCoconutId[$job->coconutId] = $data;
            $job = JobHelper::populateJobFromData($job, $data);
        }

        return $job;
    }

    /**
     * Pulls metadata for given job from Coconut API
     *
     * @param Job $job
     *
     * @return Job
     */

    public function pullJobMetadata( Job $job )
    {
        if (!isset($job->coconutId))
        {
            throw new InvalidArgumentExceptino(
                'Can not pull metadata for new job');
        }

        if (!array_key_exists($job->coconutId, $this->_jobMetadataByCoconutId))
        {
            $client = Coconut::$plugin->createClient();
            $data = $client->metadata->retrieve($job->coconutId);
            $data = ArrayHelper::toArray($data); // client return a StdObject instance

            $this->_jobMetadataByCoconutId[$job->coconutId] = $data;
            $job = JobHelper::populateJobFromData($job, $data);
        }

        return $job;
    }

    /**
     * Updates a coconut job with given data
     *
     * @param Job $job Coconut job to update
     * @param array $data Data to update the job with (may include outputs data)
     * @param bool $runValidation Whether to validate the updated job
     *
     * @return bool
     */

    public function updateJob( Job $job, array $data, bool $runValidation = true )
    {
        if (!isset($job->id)) {
            throw new InvalidArgumentException('Can not update a new job');
        }

        if (ArrayHelper::remove($data, 'type') != 'job')
        {
            throw new InvalidArgumentException(
                'Can not update job directly with input or output data');
        }

        // normalize data so it can be used to populate the job and output models
        $data = JobHelper::prepareJobData($data);
        $outputsData = ArrayHelper::remove($data, 'outputs');

        if ($outputsData)
        {
            foreach ($outputsData as $outputData)
            {
                if (!$this->updateJobOutput($job, $outputData, $runValidation)) {
                    return false;
                }
            }
        }

        // populate job with given data
        $job = JobHelper::populateJobFromData($job, $data);

        // save updated job in database
        return $this->saveJob($job, $runValidation);
    }

    /**
     * Updates job input metadata based on given data
     *
     * @param Jon $job Coconut job the input belongs to
     * @param array $inputData Data to update the job input with
     * @param bool $runValidation Whether to validate the updated job
     *
     * @return bool
     */

    public function updateJobInput( Job $job, array $inputData, bool $runValidation = true )
    {
        $job = JobHelper::populateJobFromData($job, [
            'input' => $inputData,
        ]);

        return $this->saveJob($job, $runValidation);
    }

    /**
     *
     */

    public function updateJobOutput( $job, $outputData, bool $runValidation = true )
    {
        $outputKey = ArrayHelper::getValue($outputData, 'key');
        if (!$outputKey) {
            throw new InvalidArgmentExceptino('Output data is missing an output key');
        }

        $coconutOutputs = Coconut::$plugin->getOutputs();
        $output = $job->getOutputByKey($outputKey);

        if (!$output)
        {
            $output = JobHelper::populateJobOutput(new Output([
                'job' => $job,
                'key' => $outputKey
            ]), $outputData);

            if (!$coconutOutputs->saveOutput($output, $runValidation)) {
                return false;
            }

            $job->addOutput($output);
        }

        else if (!$coconutOutputs->updateOutput($output, $runValidation)) {
            return false;
        }

        return true;
    }

    /**
     * @param integer $id
     *
     * @return Job|null
     */

    public function getJobById( int $id )
    {
        if (!array_key_exists($id, $this->_jobsPerId))
        {
            $record = JobRecord::findOne($id);

            if ($record) {
                $this->memoizeJobRecord($record);
            } else {
                $this->_jobsPerId[$id] = null;
            }
        }

        return $this->_jobsPerId[$id];
    }

    /**
     * Retrieves job with given coconut ID
     *
     * @return Job|null
     */

    public function getJobByCoconutId( string $coconutId )
    {
        if (!array_key_exists($coconutId, $this->_jobsPerCoconutId))
        {
            $record = JobRecord::find([
                'coconutId' => $coconutId,
            ])->limit(1)->one();

            if ($record) {
                $this->memoizeJobRecord($record);
            } else {
                $this->_jobsPerCoconutId[$coconutId] = null;
            }
        }

        return $this->_jobsPerCoconutId[$coconutId];
    }

    /**
     * Retrieves list of all jobs for given Asset
     *
     * @param Asset $asset
     *
     * @return Job[]
     */

    public function getJobsForInputAsset( Asset $asset )
    {
        return $this->getJobsForInputAssetId($asset->id);
    }

    /**
     * Retrieves list of all jobs for given asset URL
     *
     * @param int $assetId
     *
     * @return Job[]
     */

    public function getJobsForInputAssetId( int $assetId ): array
    {
        if (!array_key_exists($this->assetId, $this->_jobsPerInputAssetId))
        {
            $records = JobRecord::findAll([
                'inputAssetId' => $assetId,
            ]);

            foreach ($records as $record) {
                $this->memoizeJobRecord($record);
            }
        }

        return $this->_jobsPerInputAssetId[$assetId];
    }

    /**
     * Retrieves list of all jobs for given input url
     *
     * @param string $url
     *
     * @return Job[]
     */

    public function getJobsForInputUrl( string $url )
    {
        $urlHash = md5($url);

        if (!array_key_exists($urlHash, $this->_jobsPerInputUrlHash))
        {
            $record = JobRecord::findByCondition([
                'urlHash' => $urlHash,
            ])->limit(1)->one();

            if ($record) {
                $this->memoizeJobRecord($record);
            }
        }

        return $this->_jobsPerInputUrlHash[$urlHash];
    }










    /**
     * Creates a new Coconut job, runs it synchronously by waiting on its
     * completion, and returns the resulting outputs
     *
     * @param Job $job Coconut job model
     * @param int $checkInterval Amount of time to wait between each job update (in miliseconds)
     *
     * @throws Job error eception if job's status is "error"
     * @return nul|array List of resulting outputs
     */

    // public function runJob( Job $job, int $checkInterval = 0, callable $updateCallback = null )
    // {
    //     $jobInfo = $this->createJob($job, true);

    //     while ($jobInfo && $jobInfo->status == 'processing')
    //     {
    //         // wait a certain amount of time before continuing
    //         if ($checkInterval) usleep($checkInterval * 1000);

    //         // get updated job info
    //         $jobInfo = $this->checkJob($jobInfo->id, false);

    //         if ($updateCallback) { // optionally run update callback
    //             call_user_func($updateCallback, $jobInfo);
    //         }
    //     }

    //     // job is completed: update job and return resulting outputs
    //     return $this->updateJob($jobInfo, true);
    // }

    /**
     * Creates a new Coconut job, and optionally updates outputs in the database.
     *
     * @param Job $job
     * @param bool $updateOutputs Whether outputs in the db should be updated
     *
     * @throws Job error exception if job's status is "error"
     * @return object | null Information about the newly created job or null if job creation was cancelled
     */

    // public function createJob( Config $job, $updateOutputs = true )
    // {
    //     $params = $job->getJobParams();

    //     // trigger EVENT_BEFORE_CREATE_JOB
    //     $beforeCreateEvent = new CancellableJobEvent([
    //         'config' => $config,
    //         'updateOutputs' => $updateOutputs,
    //         'jobInfo' => null,
    //     ]);

    //     $this->trigger(self::EVENT_BEFORE_CREATE_JOB, $beforeCreateEvent);

    //     // allow event listeners to cancel job creation
    //     if ($beforeCreateEvent->isValid === false) {
    //         return null;
    //     }

    //     // create coconut job using Coconut API
    //     $jobInfo = CoconutJob::create($params);
    //     $this->updateJob($jobInfo, false); // update jobInfo

    //     // trigger EVENT_AFTER_CREATE_JOB
    //     $afterCreateEvent = new JobEvent([
    //         'config' => $config,
    //         'updateOutputs' => $updateOutputs,
    //         'jobInfo' => $jobInfo,
    //     ]);

    //     $this->trigger(self::EVENT_AFTER_CREATE_JOB, $afterCreateEvent);

    //     if ($updateOutputs) {
    //         $newOutputs = Coconut::$plugin->getOutputs()->initJobOutputs($config, $jobInfo->id);
    //     }

    //     return $jobInfo;
    // }

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

    // public function updateJob( $jobInfo, bool $updateOutputs = true )
    // {
    //     $result = null;

    //     // accept a job id
    //     if (is_numeric($jobInfo)) {
    //         $jobInfo = CoconutJob::get($jobId);
    //     }

    //     if ($jobInfo->status == 'error') {
    //         $this->onJobError($jobInfo, $updateOutputs);
    //     }

    //     if ($jobInfo->status == 'completed') {
    //         $result = $this->onJobComplete($jobInfo, $updateOutputs);
    //     }

    //     return $result;
    // }

    // =Protected Methods
    // =========================================================================

    /**
     *
     */

    protected function memoizeJobRecord( JobRecord $record = null )
    {
        $job = JobHelper::populateJobFromRecord(new Job(), $record);

        $this->_jobsPerId[$record->id] = $job;
        $this->_jobsPerCoconutId[$record->coconutId] = $job;

        if (!empty($record->inputAssetId)) {
            $this->_jobsPerInputAssetId[$record->inputAssetId] = $job;
        }

        if (!empty($record->inputAssetUrlHash)) {
            $this->_jobsPerInputAssetUrlHash[$record->inputAssetUrlHash] = $job;
        }
    }

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
