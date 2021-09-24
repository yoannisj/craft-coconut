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

namespace yoannisj\coconut\controllers;

use yii\web\NotFoundHttpException;
use yii\web\BadRequestHttpException;

use Craft;
use craft\base\Controller;
use craft\elements\Asset;
use craft\web\Request;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\queue\jobs\TranscodeJob;

/**
 *
 */

class JobsController extends Controller
{
    // =Static
    // =========================================================================

    const NOTIFICATION_EVENT_JOB_COMPLETED = 'job.completed';
    const NOTIFICATION_EVENT_JOB_FAILED = 'job.failed';
    const NOTIFICATION_EVENT_INPUT_TRANSFERED = 'input.transferred';
    const NOTIFICATION_EVENT_OUTPUT_COMPLETED = 'output.completed';
    const NOTIFICATION_EVENT_OUTPUT_FAILED = 'output.failed';

    // =Properties
    // =========================================================================

    /**
     * @inheritdocs
     */

    public $allowAnonymous = true;

    // =Public Methods
    // =========================================================================

    /**
     * Pushes a new coconut job to the queue
     */

    public function actionNotify()
    {
        // $this->requireToken(); // @todo: inject valid token in notification URL
        $this->requirePostRequest();

        $params = $this->request->getBodyParams();
        $jobId = $params['job_id'] ?? null;
        $event = $params['event'] ?? null;
        $isMetadata = $params['metadata'] ?? false;
        $data = $params['data'] ?? [];
        $dataType = ArrayHelper::remove($data, 'type');

        Craft::error('NOTIFICATION ('.$event.')', __METHOD__);
        Craft::error($data, __METHOD__);

        $coconutJobs = Coconut::$plugin->getJobs();
        $job = $coconutJobs->getJobByCoconutId($jobId);

        // received notification from unknown job?
        if (!$job) {
            throw new NotFoundHttpException("Could not find job with given coconut ID");
        }

        // safely ignore any events coming in after job has been completed
        else if ($job->status == Job::STATUS_COMPLETED) {
            $success= true;
        }

        else
        {
            switch ($event)
            {
                case self::NOTIFICATION_EVENT_JOB_COMPLETED:
                case self::NOTIFICATION_EVENT_JOB_FAILED:
                    $success = $coconutJobs->updateJob($job, $data);
                    break;
                case self::NOTIFICATION_EVENT_INPUT_TRANSFERED:
                    $success = $coconutJobs->updateJobInput($job, $data);
                    break;
                case self::NOTIFICATION_OUTPUT_COMPLETED:
                case self::NOTIFICATION_OUTPUT_FAILED:
                    $success = $coconutJobs->updateJobOutput($job, $data);
                    break;
            }
        }

        if (!$success) {
            throw new BadRequestHttpException("Could not handle job notification");
        }
    }

    /**
     * Saves Coconut http(s) output files
     * @todo: use a setting to configure the `encoded_video` parameter name?
     */

    public function actionUpload()
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $volumeId = $request->getRequiredParam('volumeId');
        $volume = Craft::$app->getVolumes()->getVolumeById($volumeId);

        if (!$volume) {
            throw new BadRequestHttpException('Could not find upload volume.');
        }

        $outputPath = $request->getRequiredParam('outputPath');
        $outputFile = $request->getRequiredParam('encoded_video');

        Craft::error('OUTPUT IS RESOURCE:: ' . is_resource($outputFile) ? 'YES' : 'NO');

        // replace / create file on volume
        if ($volume->fileExists($outputPath)) {
            $volume->updateFileByStream($outputPath, $outputFile);
        } else {
            $volume->createFileByStream($outputPath, $outputFile);
        }
    }

    // =Protected Methods
    // =========================================================================

    /**
     *
     */

    protected function getJobInfoFromPayload( Request $request )
    {
        $params = $request->getBodyParams();
        return $params['data'] ?? [];
    }

    /**
     *
     */

    protected function handleComplete( array $data )
    {

    }

    /**
     *
     */


    protected function handleProgress( array $data )
    {

    }

    /**
     *
     */

    protected function handleOutputs()
    {

    }

    /**
     *
     */

    protected function handleErrors()
    {

    }

    // =Private Methods
    // =========================================================================

}
