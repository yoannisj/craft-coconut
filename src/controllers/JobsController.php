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

    const FORBIDDEN_PATH_PATTERN = '/^\.{2}\/[\s\S]+/';

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
     * Webhook to update coconut job information in local DB
     */

    public function actionNotify()
    {
        // @todo: inject valid token in notification URL
        // @todo: secure notification webhook:

        // $this->requireToken();
        $this->requirePostRequest();

        $params = $this->request->getBodyParams();

        $jobId = $params['job_id'] ?? null;
        $event = $params['event'] ?? null;
        $hasMetadata = $params['metadata'] ?? false;
        $data = $params['data'] ?? [];

        Craft::error("NOTIFY [#$jobId] $event", 'coconut-debug');

        $coconutJobs = Coconut::$plugin->getJobs();
        $job = $coconutJobs->getJobByCoconutId($jobId);
        $success = false;

        // received notification from unknown job?
        // @todo: create job and its outputs? what about the input?
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
            Craft::error('JOB ERRORS', 'coconut-debug');
            Craft::error($job->getErrorSummary(true));

            Craft::error('OUTPUT ERRORS', 'coconut-debug');
            foreach ($job->getOutputs() as $output)
            {
                Craft::error('> '.$output->key, 'coconut-debug');
                Craft::error($output->getErrorSummary(true), 'coconut-debug');
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

    public function actionUpload( string $volumeHandle, string $outputPath )
    {
        Craft::error('UPLOAD:: '. $volumeHandle . ' > '.$outputPath, 'coconut-debug');
        $this->requirePostRequest();

        // get volume model based on given handle
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);

        if (!$volume)
        {
            throw new NotFoundHttpException(
                "Could not find storage volume '$volumeHandle'.");
        }

        $uploadedFile = (UploadedFile::getInstanceByName('encoded_video') ?:
            UploadedFile::getInstanceByName('thumbnail'));

        if (!$uploadedFile) {
            throw new BadRequestHttpException('No file was uploaded');
        }

        // Get path to temporarily saved file
        $tempPath = $this->getUploadedFileTempPath($uploadedFile);

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
        if ($volume->fileExists($outputPath)) {
            // replace output file
            $volume->updateFileByStream($outputPath, $stream, [
                'mimetype' => FileHelper::getMimeType($tempPath),
            ]);
        } else {
            // create output file
            $volume->createFileByStream($outputPath, $stream, [
                'mimetype' => FileHelper::getMimeType($tempPath),
            ]);
        }

        // Rackspace will disconnect the stream automatically
        if (is_resource($stream)) {
            fclose($stream);
        }

        // Tell coconut we managed to upload the file successfully
        $this->response->setStatusCode(200);
        return $this->response;
    }

    /**
     *
     */

    public function actionOutput( string $volumeHandle, string $outputPath )
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);

        if (!$volume) {
            throw new NotFoundHttpException(
                "Could not find storage volume '$volumeHandle'.");
        }

        // check filename for allowed chars (do not allow ../ to avoid security issue: downloading arbitrary files)
        if (preg_match(static::FORBIDDEN_PATH_PATTERN, $outputPath)
            || !$volume->fileExists($outputPath)
        ) {
            throw new NotFoundHttpException('The output file does not exists.');
        }

        $fileName = basename($outputPath);
        $mimeType = FileHelper::getMimeTypeByExtension($outputPath);
        $stream = $volume->getFileStream($outputPath);

        return $this->response->setCacheHeaders()
            ->sendStreamAsFile($stream, $fileName, [
                'mimeType' => $mimeType,
                'inline' => true,
            ]);
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
