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

class JobController extends Controller
{
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

    public function actionComplete()
    {
        $request = Craft::$app->getRequest();


        $jobInfo = $this->getJobInfoFromWebhook($request);

        Craft::error('WEBHOOK');
        Craft::error($jobInfo);

        Coconut::$plugin->getJobs()->updateJob($jobInfo, true);
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

    protected function getJobInfoFromWebhook( Request $request )
    {
        $params = $request->getBodyParams();
        $status = $params['event'] == 'job.completed' ? 'completed' : 'error';

        $jobInfo = (object)[
            'id' => $params['id'],
            'status' => $status,
            'output_urls' => [],
            'errors' => [],
            'metadata' => [],
        ];

        $jobInfo->outputUrls = $params['output_urls'];
        $jobInfo->errors = $params['errors'];
        $jobInfo->metadata = $params['metadata'];

        return (object)$jobInfo;
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