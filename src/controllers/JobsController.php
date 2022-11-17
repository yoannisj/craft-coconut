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
use craft\base\Fs;
use craft\web\Controller;
use craft\web\Response;
use craft\web\UploadedFile;
use craft\errors\UploadFailedException;
use craft\errors\FileException;
use craft\helpers\FileHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Job;
use yoannisj\coconut\models\Notification;

/**
 * Web Controller to work with Coconut.co transcoding Jobs
 */
class JobsController extends Controller
{
    // =Static
    // =========================================================================

    /**
     * Regex pattern to validate output file paths
     *
     * @var string
     */
    const FORBIDDEN_PATH_PATTERN = '/^\.{2}\/[\s\S]+/';

    // =Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public array|int|bool $allowAnonymous = true;

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    // =Public Methods
    // =========================================================================

    /**
     * Webhook to update coconut job information in local DB
     *
     * @return Response
     */
    public function actionNotify(): Response
    {
        // @todo Inject valid token in notification URL
        // @todo Secure notification webhook:

        // $this->requireToken();
        $this->requirePostRequest();

        $params = $this->request->getBodyParams();

        $jobId = $params['job_id'] ?? null;
        $event = $params['event'] ?? null;
        $hasMetadata = $params['metadata'] ?? false;
        $data = $params['data'] ?? [];

        Craft::debug([
            'method' => __METHOD__,
            'jobId' => $jobId,
            'event' => $event,
            'hasMetadata' => $hasMetadata,
            'data' => $data,
        ], 'coconut-debug');

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

        if ($success === false)
        {
            Craft::debug([
                'message' => 'Job not updated',
                'method' => __METHOD__,
                'jobId' => $jobId,
                'event' => $event,
                'hasMetadata' => $hasMetadata,
                'data' => $data,
                'jobErrors' => $job->getErrorSummary(true),
                'inputErrors' => $job->getInput()->getErrorSummary(true),
                'outputErrors' => array_map(function($output) {
                    return [
                        'key' => $output->key,
                        'errors' => $output->getErrorSummary(true),
                    ];
                }, $job->getOutputs()),
            ], 'coconut-debug');

            throw new ServerErrorHttpException("Could not update job via notification");
        }

        // Tell Coconut we get the update :)
        $this->response->setStatusCode(200);
        return $this->response;
    }

    /**
     * Saves Coconut http(s) output files
     * @todo: use a setting to configure the `encoded_video` parameter name?
     *
     * @return Response
     */
    public function actionUpload(
        string $volumeHandle,
        string $outputPath
    ): Response
    {
        Craft::debug([
            'message' => 'Upload output...',
            'method' => __METHOD__,
            'volumeHandle' => $volumeHandle,
            'outputPath' => $outputPath,
        ], 'coconut-debug');

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

            $error = 'Could not open file for streaming at {path}';
            throw new FileException(Craft::t('app', $error, [
                'path' => $tempPath
            ]));
        }

        $volumeFs = $volume->getFs();

        // write uploaded file contents to target file on the volume
        $volumeFs->writeFileFromStream($outputPath, $stream, [
            Fs::CONFIG_MIMETYPE => FileHelper::getMimeType($tempPath),
        ]);

        // Rackspace will disconnect the stream automatically
        if (is_resource($stream)) {
            fclose($stream);
        }

        // Tell coconut we managed to upload the file successfully
        $this->response->setStatusCode(200);
        return $this->response;
    }

    /**
     * Serves given output file if it exists
     *
     * @return Response
     */
    public function actionOutput(
        string $volumeHandle,
        string $outputPath
    ): Response
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);

        if (!$volume)
        {
            throw new NotFoundHttpException(
                "Could not find storage volume '$volumeHandle'.");
        }

        $volumeFs = $volume->getFs();

        // check filename for allowed chars
        // (do not allow ../ to avoid security issue: downloading arbitrary files)
        if (preg_match(static::FORBIDDEN_PATH_PATTERN, $outputPath)
            || !$volumeFs->fileExists($outputPath)
        ) {
            throw new NotFoundHttpException('The output file does not exists.');
        }

        $fileName = basename($outputPath);
        $mimeType = FileHelper::getMimeTypeByExtension($outputPath);
        $stream = $volumeFs->getFileStream($outputPath);

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
     * @throws UploadFailedException if $uploadedFile has errors
     * @throws UploadFailedException if $uploadedFile could not be saved
     */
    protected function getUploadedFileTempPath( UploadedFile $uploadedFile ): string
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

    // =Private Methods
    // =========================================================================

}
