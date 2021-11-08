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

use yii\base\ErrorException;
use yii\web\NotFoundHttpException;
use yii\web\BadRequestHttpException;
use yii\web\ServerErrorHttpException;

use Craft;
use craft\web\Controller;
use craft\elements\Asset;
use craft\web\Request;
use craft\web\UploadedFile;
use craft\errors\UploadFailedException;
use craft\helpers\ArrayHelper;
use craft\helpers\FileHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Job;
use yoannisj\coconut\models\Notification;

/**
 *
 */

class JobsController extends Controller
{
    // =Static
    // =========================================================================

    // =Properties
    // =========================================================================

    /**
     * @inheritdoc
     */

    public $allowAnonymous = true;

    /**
     * @inheritdoc
     */

    public $enableCsrfValidation = false;

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
        $hasMetadata = $params['metadata'] ?? false;
        $data = $params['data'] ?? [];

        Craft::error("NOTIFY [#$jobId] $event");

        $coconutJobs = Coconut::$plugin->getJobs();
        $job = $coconutJobs->getJobByCoconutId($jobId);
        $success = false;

        // received notification from unknown job?
        if (!$job) {
            throw new NotFoundHttpException("Could not find job with ID '$jobId'");
        }

        // safely ignore any events coming in after job has been completed or failed
        else if ($job->status == Job::STATUS_COMPLETED
            || $job->status == Job::STATUS_FAILED
        ) {
            $success= true;
        }

        else
        {
            switch ($event)
            {
                case Notification::EVENT_INPUT_TRANSFERRED:
                    $success = $coconutJobs->updateJobInput($job, $data);
                    break;
                case Notification::EVENT_OUTPUT_COMPLETED:
                case Notification::EVENT_OUTPUT_FAILED:
                    $success = $coconutJobs->updateJobOutput($job, $data);
                    break;
                case Notification::EVENT_JOB_COMPLETED:
                case Notification::EVENT_JOB_FAILED:
                    $success = $coconutJobs->updateJob($job, $data);
                    break;
            }
        }

        if (!$success)
        {
            Craft::error('JOB ERRORS');
            Craft::error($job->getErrorSummary(true));

            Craft::error('OUTPUT ERRORS');
            foreach ($job->getOutputs() as $output)
            {
                Craft::error('--> '.$output->key);
                Craft::error($output->getErrorSummary(true));
            }

            throw new ServerErrorHttpException("Could not handle job notification");
        }

        $this->response->setStatusCode(200);
        return $this->response;
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
