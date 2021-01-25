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

namespace yoannisj\coconut\queue\jobs;

use Coconut\Job as CoconutJob;

use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\queue\RetryableJobInterface;

use Craft;
use craft\queue\BaseJob;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\records\OutputRecord;

/**
 * 
 */

class TranscodeSourceJob extends BaseJob implements RetryableJobInterface
{
    // =Properties
    // =========================================================================

    /**
     * @var \yoannisj\coconut\models\Config
     */

    public $config;

    /**
     * @var int
     */

    public $checkJobInterval = 1000;

    // =Public Methods
    // =========================================================================

    // =Magic
    // -------------------------------------------------------------------------

    // /**
    //  * @inheritdoc
    //  */

    // public function __serialize(): array
    // {
    //     $data = [
    //         'description' => $this->getDescription(),
    //         'config' => $this->config->toArray(),
    //     ];

    //     return $data;
    // }

    // =Properties
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function getDescription()
    {
        $sourceTitle = null;
        $sourceAsset = $this->config->getSourceAsset();
        $source = $this->config->getSource();

        if ($sourceAsset) {
            $sourceTitle = $sourceAsset->title;
        } else {
            $sourceTitle = StringHelper::basename(parse_url($source, PHP_URL_PATH));
        }

        return Craft::t('coconut', 'Transcoding "{source}" with Coconut.co', [
            'source' => $sourceTitle,
        ]);
    }

    /**
     * @inheritdoc
     */

    public function getTtr()
    {
        // set high Time to Reserve for this job to support longer Coconut jobs
        // @link https://craftcms.stackexchange.com/questions/25437/queue-exec-time/25452#25452

        return Coconut::$plugin->getSettings()->transcodeJobTtr;
    }

    /**
     * @inheritdoc
     */

    public function canRetry( $attempt, $error )
    {
        return false;
    }

    // =Queue
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function execute( $queue )
    {
        $service = Coconut::$plugin->getJobs();

        $service->runJob(
            $this->config,
            $this->checkJobInterval,
            function($jobInfo) use ($queue) {
                $progress = $jobInfo->status == 'completed' ? 1 : ((int)$jobInfo->progress / 100);
                $this->setProgress($queue, $progress);
            }
        );
    }

    // =Protected Methods
    // =========================================================================

    // =Private Methods
    // =========================================================================

}