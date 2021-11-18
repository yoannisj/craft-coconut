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
        Craft::error('UPLOAD!');

        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        Craft::error('-> UPLOAD QUERY PARAMS');
        Craft::error($request->getQueryParams());
        Craft::error('-> UPLOAD BODY PARAMS');
        Craft::error($request->getBodyParams());
        Craft::error('-----');
        Craft::error('VOLUME:: '.$request->getParam('volume'));
        Craft::error('VOLUME ID:: '.$request->getParam('volumeId'));
        Craft::error('=====');

        $volumeHandle = $request->getParam('volume');
        $volumeId = $request->getParam('volumeId');

        if ($volumeHandle) {
            $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);
        } else if ($volumeId) {
            $volume = Craft::$app->getVolumes()->getVolumeById($volumeId);
        } else {
            throw new BadRequestHttpException(
                "Missing one of required parameters `volume` or `volumeId`.");
        }

        if (!$volume)
        {
            throw new NotFoundHttpException(
                "Could not determine upload storage volume.");
        }

        $uploadedFile = UploadedFile::getInstanceByName('encoded_video');
        // $outputPath = $request->getRequiredParam('outputPath');

        if (!$uploadedFile) {
            throw new BadRequestHttpException('No file was uplaoded');
        }

        // Get path to temporarily saved file
        $tempPath = $this->getUploadedFileTempPath($uploadedFile);

        Craft::error('UPLOAD TEMP PATH:: '.$tempPath);

        // try to open a file stream
        if (($stream = fopen($tempPath, 'rb')) === false)
        {
            FileHelper::unlink($tempPath); // delete temporarily saved file

            throw new FileException(Craft::t('app',
                'Could not open file for streaming at {path}', [
                    'path' => $tempPath
                ])
            );
        }

        // // upload file to the volume
        // if ($volume->fileExists($outputPath)) {
        //     // replace output file
        //     $volume->updateFileByStream($outputPath, $stream, [
        //         'mimetype' => FileHelper::getMimeType($tempPath),
        //     ]);
        // } else {
        //     // create output file
        //     $volume->createFileByStream($outputPath, $stream, [
        //         'mimetype' => FileHelper::getMimeType($tempPath),
        //     ]);
        // }

        // // Rackspace will disconnect the stream automatically
        // if (is_resource($stream)) {
        //     fclose($stream);
        // }

        // Tell coconut we could upload the file successfully
        $this->response->setStatusCode(200);
        return $this->response;
    }

    // =Protected Methods
    // =========================================================================

    /**
     * Saves given uploaded file temporarily on the file-system, and returns
     * the file's temporary path.
     *
     * @param UploadedFile $uploadedFile
     *
     * @return string Path to temporarily saved uploaded file
     *
     * @throws UploadFailedException if $uploadedFile could not be saved
     */

    protected function getUploadedFileTempPath( UploadedFile $uploadedFile )
    {
        if ($uploadedFile->getHasError()) {
            throw new UploadFailedException($uploadedFile->error);
        }

        try {
            $tempPath = $uploadedFile->saveAsTempFile();
        } catch (ErrorException $e) {
            throw new UploadFailedException(0, null, $e);
        }

        if ($tempPath === false) {
            throw new UploadFailedException(UPLOAD_ERR_CANT_WRITE);
        }

        return $tempPath;
    }










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
