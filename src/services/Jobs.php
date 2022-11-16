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
use Coconut\Error as CoconutError;

use yii\base\Exception;
use yii\base\InvalidArgumentException;

use Craft;
use craft\base\Component;
use craft\base\VolumeInterface;
use craft\elements\Asset;
use craft\helpers\ArrayHelper;
use craft\helpers\Json as JsonHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Input;
use yoannisj\coconut\models\Output;
use yoannisj\coconut\models\Job;
use yoannisj\coconut\records\JobRecord;
use yoannisj\coconut\exceptions\CoconutApiExeption;
use yoannisj\coconut\events\JobEvent;
use yoannisj\coconut\events\CancellableJobEvent;
use yoannisj\coconut\helpers\JobHelper;

/**
 * Service component class to work with Coconut Jobs
 */
class Jobs extends Component
{
    // =Static
    // =========================================================================

    /**
     * Name of event triggered before a Coconut job is saved
     *
     * @var string
     */
    const EVENT_BEFORE_SAVE_JOB = 'beforeSaveJob';

    /**
     * Name of event triggered after a Coconut job is saved
     *
     * @var string
     */
    const EVENT_AFTER_SAVE_JOB = 'afterSaveJob';

    // =Properties
    // =========================================================================

    /**
     * Map of memoized Coconut jobs indexed by their ID
     *
     * @var Job[]
     */
    private array $_jobsPerId = [];

    /**
     * Map of memoized Coconut jobs indexed by their coconut ID
     *
     * @var Job[]
     */
    private array $_jobsPerCoconutId = [];

    /**
     * Map of memoized Coconut jobs indexed by their input asset ID
     *
     * @var Job[]
     */
    private array $_jobsPerInputAssetId = [];

    /**
     * Map of memoized Coconut jobs indexed by their input asset ID
     *
     * @var Job[]
     */
    private array $_jobsPerInputUrlHash = [];

    /**
     * Map of memoized job info per Coconut job ID.
     *
     * @var array[]
     */
    private $_jobInfoByCoconutId = [];

    /**
     * Map of memoized job metadata per Coconut job ID.
     *
     * @var array[]
     */
    private array $_jobMetadataByCoconutId = [];

    // =Public Methods
    // =========================================================================

    /**
     * Returns the Job model for given job name. Jobs are named by their key
     * in the `jobs` config setting.
     *
     * @see yoannisj\coconut\models\Settings::jobs To learn about named jobs
     *
     * @param string $handle Name of the job to get
     *
     * @return Job|null
     */
    public function getNamedJob( string $handle ): ?Job
    {
        $namedJobs = Coconut::$plugin->getSettings()->getJobs();
        return $namedJobs[$handle] ?? null;
    }

    /**
     * Returns the default Job model configured for given Asset Volume.
     *
     * @see yoannisj\coconut\models\Settings::volumeJobs To learn about
     * configuring default transcoding jobs for asset volumes.
     *
     * @param string|VolumeInterface $volume
     *
     * @return Job|null
     *
     * @throws InvalidArgumentException If given $volume argument is not a volume instance of handle
     */
    public function getVolumeJob( string|VolumeInterface $volume ): ?Job
    {
        $handle = null;

        if ($volume instanceof VolumeInterface) {
            $handle = $volume->handle;
        } else if (is_string($volume)) {
            $handle = $volume;
        }

        else {
            throw new InvalidArgumentException(
                "Argument 'volume' must be a Volume instance or a volume handle");
        }

        $volumeJobs = Coconut::$plugin->getSettings()->getvolumeJobs();

        return $volumeJobs[$handle] ?? null;
    }

    /**
     * Runs given transcoding job via the Coconut API.
     *
     * @param Job $job The transcoding Job to run
     * @param bool $runValidation Whether to validate given job before submitting it to Coconut
     *
     * @return bool Whether job was successfully ran or not
     *
     * @throws CoconutError if Coconut API could not be reached or returned an error
     */
    public function runJob( Job $job, bool $runValidation = true ): bool
    {
        if (isset($job->coconutId))
        {
            throw new InvalidArgumentException(
                'Can not re-run a job that has been ran by Coconut.co before');
        }

        if ($runValidation && !$job->validate())
        {
            $message = 'Could not run Job due to validation error(s)';
            Craft::info($message.':'.print_r($job->errors, true), 'coconut');

            return false;
        }

        // use Coconut API to run the job
        // (throws an error if API is not reachable or returned an error code)
        $client = Coconut::$plugin->createClient();
        $data = $client->job->create($job->toParams()); // client will throw potential API errors
        $data = ArrayHelper::toArray($data); // client returns a StdObject instance

        $job = JobHelper::populateJobFromData($job, $data);

        return true;
    }

    /**
     * Saves given transcoding Job model in the database.
     *
     * @param Job $job The transcoding job to save
     * @param bool $runValidation Whether to validate the Job model before saving it
     *
     * @return bool Whether the Job model was saved successfully
     */
    public function saveJob( Job $job, bool $runValidation = true ): bool
    {
        $isNewJob = !isset($job->id);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_JOB))
        {
            $this->trigger(self::EVENT_BEFORE_SAVE_JOB, new JobEvent([
                'job' => $job,
                'isNew' => $isNewJob,
            ]));
        }

        if ($runValidation && !$job->validate()) {
            return false;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try
        {
            // save job record
            $record = $isNewJob ? new JobRecord() : JobRecord::findOne($job->id);
            $record = JobHelper::populateRecordFromJob($record, $job);

            $success = true;

            if (isset($record->id)) {
                $success = $record->update();
            } else {
                $success = $record->insert();
            }

            if (!$success) {
                $transaction->rollBack();
                return false;
            }

            // update job model's attributes based on what's now saved in the database
            $job->id = $record->id;
            $job->dateCreated = $record->dateCreated;
            $job->dateUpdated = $record->dateUpdated;
            $job->uid = $record->uid;

            // save job outputs
            $coconutOutputs = Coconut::$plugin->getOutputs();
            foreach ($job->getOutputs() as $output)
            {
                // make sure output records are linked to the job record
                $output->jobId = $job->id;

                if (!$coconutOutputs->saveOutput($output, $runValidation)) {
                    $transaction->rollBack();
                    return false;
                }
            }

            // delete legacy outputs
            foreach ($job->getLegacyOutputs() as $output)
            {
                if (!$coconutOutputs->deleteOutput($output)) {
                    $transaction->rollBack();
                    return false;
                }
            }

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
            ]));
        }

        return true;
    }

    /**
     * Updates given transcoding Job model with the information returned by
     * the Coconut API.
     *
     * @param Job $job The transcoding job to pull information for
     *
     * @return bool Whether job info could be retrieved successfully
     */
    public function pullJobInfo( Job $job ): bool
    {
        if (!isset($job->coconutId)) {
            throw new InvalidArgumentException("Can not pull info for new job");
        }

        $data = $this->_jobInfoByCoconutId[$job->coconutId] ?? null;

        if (!$data)
        {
            $client = Coconut::$plugin->createClient();

            try {
                $data = $client->job->retrieve($job->coconutId);
                $data = ArrayHelper::toArray($data); // client returns a StdObject instance
                $this->_jobInfoByCoconutId[$job->coconutId] = $data;
            }

            // catch and log Coconut API errors
            catch (CoconutError $e) {
                Craft::error($e->getMessage(), 'coconut');
                return false;
            }
        }

        $job = JobHelper::populateJobFromData($job, $data);

        return true;
    }

    /**
     * Updates given transcoding Job model with the metadata returned by
     * the Coconut API.
     *
     * @param Job $job Job to pull the metadata for
     *
     * @return bool Whether the metadata could be retrieved successfully
     */
    public function pullJobMetadata( Job $job ): bool
    {
        if (!isset($job->coconutId)) {
            throw new InvalidArgumentException('Can not pull metadata for new job');
        }

        $data = $this->_jobMetadataByCoconutId[$job->coconutId] ?? null;

        if (!$data)
        {
            $client = Coconut::$plugin->createClient();

            try {
                $data = $client->metadata->retrieve($job->coconutId);
                $data = ArrayHelper::toArray($data); // client returns a StdObject instance
                $this->_jobMetadataByCoconutId[$job->coconutId] = $data;
            }

            // catch and log Coconut API errors
            catch (CoconutError $e)
            {
                Craft::error($e->getMessage(), 'coconut');
                return false;
            }
        }

        $job = JobHelper::populateJobFromData($job, $data);

        return true;
    }

    /**
     * Updates transcoding Job model with given data, and saves it in
     * the database.
     *
     * @param Job $job Transcoding job to update
     * @param array $data Data to update the job with (may include outputs data)
     * @param bool $runValidation Whether to validate the updated job
     *
     * @return bool Whether the job was be updated and saved successfully
     */
    public function updateJob(
        Job $job,
        array $data,
        bool $runValidation = true
    ): bool
    {
        if (!isset($job->id)) {
            throw new InvalidArgumentException('Can not update a new job');
        }

        // populate job (and its outputs) with given data
        $job = JobHelper::populateJobFromData($job, $data);

        // save updated job in database
        return $this->saveJob($job, $runValidation);
    }

    /**
     * Updates transcoding Job with given input data, and saves it back
     * in the database.
     *
     * @param Job $job Transcoding job the input belongs to
     * @param array $inputData Input data to update the job input with
     * @param bool $runValidation Whether to validate the updated job when saving
     *
     * @return bool Whether the job was succesfully updated and saved successfully
     */
    public function updateJobInput(
        Job $job,
        array $inputData,
        bool $runValidation = true
    ): bool
    {
        if (!$job->id) {
            throw new InvalidArgumentException("Can not update new Job model");
        }

        // we are updating the input on the job
        $data = [ 'input' => $inputData, ];
        $job = JobHelper::populateJobFromData($job, $data);

        return $this->saveJob($job, $runValidation);
    }

    /**
     * Updates transcofing Job with given output data, and saves it back
     * in the database.
     *
     * @param Job $job Transcofing job the output data belongs to
     * @param array $outputData Output data to update the job with
     * @param bool $runValidation Whether to validate the updated job when saving
     *
     * @return bool Whether the job output was succesfully updated and saved successfully
     */
    public function updateJobOutput(
        Job $job,
        array $outputData,
        bool $runValidation = true
    ): bool
    {
        if (!$job->id) {
            throw new InvalidArgumentException("Can not update new Job model");
        }

        // translate into job data so we can populate relevant job attributes
        $jobData = [];

        // 'progress' refers to the job's progress (not the output's progress)
        $jobProgress = ArrayHelper::remove($outputData, 'progress');
        if ($jobProgress) $jobData['progress'] = $jobProgress;

        // set output progress based on it's status
        if (($outputStatus = $outputData['status'] ?? null)
            && in_array($outputStatus, Output::COMPLETED_STATUSES))
        {
            $outputData['progress'] = '100%'; // we're done working with this output
        }

        // include output data into job data and populate job with the result
        $jobData['outputs'] = [ $outputData ];
        $job = JobHelper::populateJobFromData($job, $jobData);

        // we need to save the whole job because of 'progress'
        // will also save the updated output
        if (!$this->saveJob($job, $runValidation)) {
            return false;
        }

        return true;
    }

    /**
     * Fetches transcoding Job from the database by its ID.
     *
     * @param int $id ID of job to retreive.
     *
     * @return Job|null
     */
    public function getJobById( int $id ): ?Job
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
     * Retrieves transcoding Job from the database by its coconut ID.
     *
     * @param string $coconutId Cococnut ID of the Job
     *
     * @return Job|null
     */
    public function getJobByCoconutId( string $coconutId ): ?Job
    {
        if (!array_key_exists($coconutId, $this->_jobsPerCoconutId))
        {
            $record = JobRecord::findOne([
                'coconutId' => $coconutId,
            ]);

            if ($record) {
                $this->memoizeJobRecord($record);
            } else {
                $this->_jobsPerCoconutId[$coconutId] = null;
            }
        }

        return $this->_jobsPerCoconutId[$coconutId];
    }

    /**
     * Retrieves list of all transcoding jobs for given Asset
     *
     * @param Asset $asset
     *
     * @return Job[]
     */
    public function getJobsForInputAsset( Asset $asset ): array
    {
        return $this->getJobsForInputAssetId($asset->id);
    }

    /**
     * Retrieves list of all transcoding jobs for given Asset ID.
     *
     * @param int $assetId
     *
     * @return Job[]
     */
    public function getJobsForInputAssetId( int $assetId ): array
    {
        if (!array_key_exists($assetId, $this->_jobsPerInputAssetId))
        {
            $records = JobRecord::findAll([ 'inputAssetId' => $assetId ]);

            if (!empty($records))
            {
                foreach ($records as $record) {
                    $this->memoizeJobRecord($record);
                }
            } else {
                $this->_jobsPerInputAssetId[$assetId] = [];
            }
        }

        return $this->_jobsPerInputAssetId[$assetId];
    }

    /**
     * Retrieves list of all transcoding jobs for given input url.
     *
     * @param string $url URL of input to get jobs for
     *
     * @return Job[]
     */
    public function getJobsForInputUrl( string $url ): array
    {
        $urlHash = md5($url);

        if (!array_key_exists($urlHash, $this->_jobsPerInputUrlHash))
        {
            $records = JobRecord::findAll([
                'inputUrlHash' => $urlHash
            ]);

            if (!empty($records))
            {
                foreach ($records as $record) {
                    $this->memoizeJobRecord($record);
                }
            } else {
                $this->_jobsPerInputUrlHash[$urlHash] = [];
            }
        }

        return $this->_jobsPerInputUrlHash[$urlHash];
    }

    /**
     * Retrieves list of all transcoding jobs for given input video.
     *
     * @param string|int|Asset|Input $input Input for which to get all jobs
     *
     * @return Job[]
     */
    public function getJobsForInput( string|int|Asset|Input $input ): array
    {
        $input = JobHelper::resolveInput($input);

        if ($input->assetId) {
            return $this->getJobsForInputAssetId($input->assetId);
        }

        else if ($input->url) {
            return $this->getJobsForInputUrl($input->url);
        }

        return [];
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

    // public function checkJob( int $jobId, $updateOutputs = true )
    // {
    //     $jobInfo = CoconutJob::get($jobId);
    //     $this->updateJob($jobInfo, $updateOutputs);

    //     return $jobInfo;
    // }

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
     * Stores reference to given job record in internal memoization properties,
     * in order to optimize the multiple methods for fetching jobs from the
     * database.
     *
     * @param JobRecord $record Job record to store/memoize
     *
     * @return void
     */
    protected function memoizeJobRecord( JobRecord $record = null ): void
    {
        $job = JobHelper::populateJobFromRecord(new Job(), $record);
        $input = $job->getInput();

        $this->_jobsPerId[$job->id] = $job;

        if (!empty($job->coconutId)) {
            $this->_jobsPerCoconutId[$job->coconutId] = $job;
        }

        if (($assetId = $input->assetId))
        {
            if (!isset($this->_jobsPerInputAssetId[$assetId])) {
                $this->_jobsPerInputAssetId[$assetId] = [];
            }

            $this->_jobsPerInputAssetId[$input->assetId][] = $job;
        }

        if (($urlHash = $input->urlHash))
        {
            if (!isset($this->_jobsPerInputUrlHash[$urlHash])) {
                $this->_jobsPerInputUrlHash[$urlHash] = [];
            }

            $this->_jobsPerInputUrlHash[$input->urlHash][] = $job;
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
    // protected function onJobError( $jobInfo, bool $updateOutputs = true )
    // {
    //     $jobId = $jobInfo->id ?? null;
    //     $jobOutputs = null;

    //     if ($updateOutputs && $jobId)
    //     {
    //         $service = Coconut::$plugin->getOutputs();
    //         $jobOutputs = $service->getJobOutputs($jobId);

    //         foreach ($jobOutputs as $output) {
    //             $service->deleteOutput($output);
    //         }
    //     }

    //     // trigger EVENT_JOB_ERROR
    //     $errorEvent = new JobEvent([
    //         'updateOutputs' => $updateOutputs,
    //         'jobInfo' => $jobInfo,
    //         'jobOutputs' => $jobOutputs,
    //     ]);

    //     $this->trigger(self::EVENT_JOB_ERROR, $errorEvent);

    //     throw new Exception($jobInfo->message);
    // }

    /**
     * Handles completion for given Coconut job.
     *
     * @param object $jobInfo Info object or id of job to update
     * @param bool $updateOutputs Whether outputs in the db should be updated
     *
     * @return null|array Updated list of outputs if `$updateOutputs` argument is `true`
     */
    // protected function onJobComplete( $jobInfo, bool $updateOutputs = true )
    // {
    //     if (!$updateOutputs) {
    //         return null;
    //     }

    //     $service = Coconut::$plugin->getOutputs();

    //     $jobOutputs = $service->getJobOutputs($jobInfo->id);
    //     $outputUrls = (array)$jobInfo->output_urls;

    //     $metadata = [];
    //     $result = CoconutJob::getAllMetadata($jobInfo->id);
    //     if ($result && property_exists($result, 'metadata')) {
    //         $metadata = JsonHelper::decode(JsonHelper::encode($result->metadata));
    //     }

    //     // @todo: compare at db output urls with jobInfo->outputUrls to discover
    //     // errors in anticipative urls saved in the DB
    //     // -> problem: if we don't add the ?host arg in output urls to the coconut job,
    //     //  resulting jobInfo->outputUrls use "http://"
    //     // -> problem: when passing "https://s3.amazonaws.com/<bucket-name>" in the ?host arg,
    //     //  resulting jobInfo->outputUrls still use "https://<bucket-name>.s3.amazonaws.com/"
    //     // ->problem: when passing "https://<bucket-name>.s3.amazonaws.com/" in the ?host arg,
    //     //  the coconut job does not throw an error, but transcoding still fails for every file

    //     foreach ($jobOutputs as $output)
    //     {
    //         $output->inProgress = false;
    //         $output->metadata = $metadata[$output->format] ?? null;

    //         $service->saveOutput($output);
    //     }

    //     // $completedOutputs = [];
    //     // $invalidOutputs = [];

    //     // foreach ($jobOutputs as $output)
    //     // {
    //     //     $formatUrls = $outputUrls[$output->format] ?? [];
    //     //     if (!is_array($formatUrls)) $formatUrls = [ $formatUrls ];

    //     //     if (in_array($output->url, $formatUrls)) {
    //     //         $completedOutputs[] = $output;
    //     //     }

    //     //     else {
    //     //         $invalidOutputs[] = $output;
    //     //     }
    //     // }

    //     // foreach ($completedOutputs as $output)
    //     // {
    //     //     $output->inProgress = false;
    //     //     $service->saveOutput($output);
    //     // }

    //     // foreach ($invalidOutputs as $output) {
    //     //     $service->deleteOutput($output); // removes output file
    //     // }

    //     // return $completedOutputs;

    //     // trigger EVENT_JOB_COMPLETE
    //     $completeEvent = new JobEvent([
    //         'updateOutputs' => $updateOutputs,
    //         'jobInfo' => $jobInfo,
    //         'jobOutputs' => $jobOutputs,
    //     ]);

    //     $this->trigger(self::EVENT_JOB_COMPLETE, $completeEvent);

    //     return $jobOutputs;
    // }

}
