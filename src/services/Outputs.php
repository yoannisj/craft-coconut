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

use yii\base\InvalidArgumentException;

use Craft;
use craft\base\Component;
use craft\volumes\Local as LocalVolume;
use craft\elements\Asset;
use craft\helpers\ArrayHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Job;
use yoannisj\coconut\models\Output;
use yoannisj\coconut\records\OutputRecord;
use yoannisj\coconut\events\OutputEvent;

/**
 * Singleton class to work with Coconut outputs
 */

class Outputs extends Component
{
    // =Static
    // =========================================================================

    const EVENT_BEFORE_SAVE_OUTPUT = 'beforeSaveOutput';
    const EVENT_AFTER_SAVE_OUTPUT = 'afterSaveOutput';

    // =Properties
    // =========================================================================

    /**
     * @var array Resolved list of outputs per source url
     */

    protected $sourceOutputs = [];

    // =Public Methods
    // =========================================================================

    /**
     * Updates a job output with given data
     *
     * @param Output $output Coconut job output to update
     * @param array|object $data Output data to update output with
     * @param bool $runValidation Whether to validate the updated output
     *
     * @return bool
     */

    public function updateOutput( Output $output, array $data, bool $runValidation = true )
    {
        if (!isset($output->id)) {
            throw new InvalidArgumentException("Can not update new output");
        }

        $dataType = ArrayHelper::remove($data, 'type');
        if (!$dataType != 'video' && $dataType != 'image' && $dataType != 'httpstream')
        {
            throw new InvalidArgumentException(
                "Can not update job output with input or job data");
        }

        $key = ArrayHelper::getValue($data, 'key');
        if ($key && $key != $output->key)
        {
            throw new InvalidArgumentException(
                "Output key does not correspond with given data");
        }

        JobHelper::populateJobOutput($output, $data);

        if (!$this->saveOutput($output, $runValidation)) {
            return false;
        }

        return true;
    }

    /**
     * @param Output $output
     * @param bool $runValidation
     *
     * @return bool
     */

    public function saveOutput( Output $output, bool $runValidation = true ): bool
    {
        $isNewOutput = !isset($output->id);
        $previousStatus = $output->status;

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_OUTPUT))
        {
            $this->trigger(self::EVENT_BEFORE_SAVE_OUTPUT, new OutputEvent([
                'output' => $output,
                'isNew' => $isNewOutput,
                'previousStatus' => $previousStatus,
            ]));
        }

        if ($runValidation && !$output->validate()) {
            return false;
        }

        // get existing record for this output
        $record = ($isNewOutput ? new OutputRecord() :
            OutputRecord::findOne($output->id));

        // update the record attributes and try saving
        $record->setAttributes($output->getAttributes(), false);
        // $record->type = $output->getType(); // read-only, but searchable attribute
        if (!$record->save()) return false;

        // update output model's attributes based on what's now saved in the database
        $output->id = $record->id;
        $output->dateCreated = $record->dateCreated;
        $output->dateUpdated = $record->dateUpdated;
        $output->uid = $record->uid;

        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_OUTPUT))
        {
            $this->trigger(self::EVENT_AFTER_SAVE_OUTPUT, new OutputEvent([
                'output' => $output,
                'isNew' => $isNewOutput,
                'previousStatus' => $previousStatus,
            ]));
        }

        return true;
    }

    /**
     * @param Output $output
     *
     * @return bool
     */

    public function deleteOutput( Output $output ): bool
    {
        if (!$output->id) return false;

        $record = OutputRecord::findOne($output->id);
        if ($record) return $record->delete();

        return false;
    }

    /**
     * @param array $criteria
     *
     * @return bool
     */

    public function clearOutputs( array $criteria = [] )
    {
        $records = OutputRecord::find()
            ->where($criteria)
            ->all();

        $success = true;

        foreach ($records as $record)
        {
            if ($record->delete() === false) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Initializes outputs for given job
     */

    public function initJobOutputs( Job $job )
    {
        // delete existing job outputs (deletes output files)
        $this->clearJobOutputs($job);

        // initialize common attributes for new outputs
        $outputVolume = $job->getOutputVolumeModel();
        $newAttrs = [
            'sourceAssetId' => $job->getSourceAssetId(),
            'source' => $job->getSource(),
            'volumeId' => (int)$outputVolume->id,
            'inProgress' => !!($job->coconutId),
            'coconutJobId' => $job->coconutId,
        ];

        // create new output for all output urls defined by job
        $outputUrls = $job->getOutputUrls();
        $newOutputs = [];

        foreach ($outputUrls as $format => $url)
        {
            // normalize for formats which create multiple outputs
            if (!is_array($url)) $url = [ $url ];

            foreach ($url as $outputUrl)
            {
                $attrs = array_merge($newAttrs, [
                    'format' => $format,
                    'url' => $outputUrl,
                ]);

                $output = new Output();
                $output->setAttributes($attrs);

                $this->saveOutput($output); // gives the output an id
                $newOutputs[] = $output; // collect newly created outputs
            }
        }

        return $newOutputs;
    }

    /**
     * Returns all saved outputs for given job id
     *
     * @param int $jobId
     *
     * @return Output[]
     */

    public function getOutputsByJobId( int $jobId ): array
    {
        $records = OutputRecord::findAll([ 'jobId' => $jobId ]);
        $outputs = [];

        foreach ($records as $record)
        {
            $output = new Output();
            $output->attributes = $record->attributes;

            $outputs[] = $output;
        }

        return $outputs;
    }

    /**
     * Returns existing outputs for given coconut job
     *
     * @param Job $job
     *
     * @return array
     */

    public function getJobOutputs( Job $job, array $criteria = [] ): array
    {
        $criteria = array_merge($job->getOutputCriteria(), $criteria);

        // in case job does not define any criteria
        if (empty($criteria)) return [];

        $sourceAssetId = ArrayHelper::remove($criteria['sourceAssetId']);
        $source = ArrayHelper::remove($criteria['source']);
        $sourceKey = $sourceAssetId ?? $source;

        return $this->getSourceOutputs($sourceKey, $criteria);
    }

    /**
     * @param Job
     * @param array $criteria
     *
     * @return bool
     */

    public function clearJobOutputs( Job $job, array $criteria = [] )
    {
        $jobCriteria = $job->getOutputCriteria();
        $criteria = array_merge($criteria, $jobCriteria);

        return $this->clearOutputs($criteria);
    }

    /**
     * Returns output model for given source video and output key
     *
     * @param \craft\elements\Asset | string $source
     * @param array $criteria
     *
     * @return yoannisj\coconut\models\Output | null
     */

    public function getSourceOutputs( $source, array $criteria = [] )
    {
        $outputs = $this->getAllSourceOutputs($source);

        if (!empty($criteria)) {
            $outputs = ArrayHelper::whereMultiple($outputs, $criteria);
        }

        return $outputs;
    }

    /**
     * Returns all output models for given source video
     *
     * @param string | int | \craft\elements\Asset $source
     *
     * @return array
     */

    public function getAllSourceOutputs( $source ): array
    {
        $key = $source->id ?? (is_numeric($source) ? (int)$source : $source);

        if (!array_key_exists($key, $this->sourceOutputs))
        {
            $outputs = [];

            $criteria = $this->getSourceCriteria($source);
            $records = OutputRecord::find()
                ->where($criteria)
                ->all();

            foreach ($records as $record)
            {
                $output = new Output();
                $output->setAttributes($record->getAttributes(), false);

                $outputs[] = $output;
            }

            $this->sourceOutputs[$key] = $outputs;
        }

        return $this->sourceOutputs[$key];
    }

    /**
     * @param string | int | \craft\elements\Asset $source
     * @param array $criteria
     *
     * @return bool
     */

    public function clearSourceOutputs( $source, array $criteria = [] )
    {
        $sourceCriteria = $this->getSourceCriteria($source);
        $criteria = array_merge($criteria, $sourceCriteria);

        return $this->clearOutputs($criteria);
    }

    /**
     * Returns outputs for given source and formats
     *
     * @param string | \craft\elements\Asset $source
     * @param array $formats
     * @param bool $transcodeMissing
     */

    public function getFormatOutputs( $source, array $formats = null, bool $transcodeMissing = false, bool $useQueue = null )
    {
        // get source job and outputs
        $sourceJob = Coconut::$plugin->normalizeSourceJob($source, null, false);
        $sourceOutputs = $this->getSourceOutputs($source);

        // if no formats were specified, use configured formats
        if (empty($formats) && $sourceJob) {
            $formats = array_keys($sourceJob->getOutputs());
        }

        // select and index source outputs for formats
        $formatOutputs = ArrayHelper::whereMultiple($sourceOutputs, [ 'format' => $formats ]);
        $outputs = [];

        foreach ($formatOutputs as $output)
        {
            if (!array_key_exists($output->format, $outputs)) {
                $outputs[$output->format] = [];
            }

            $outputs[$output->format][] = $output;
        }

        if ($transcodeMissing)
        {
            // look for missing output formats
            $missingFormats = array_diff($formats, array_keys($outputs));

            if (!empty($missingFormats))
            {
                // limit job to missing formats only
                $job = $sourceJob->forFormats($missingFormats);

                // transcode missing output formats, and merge in resulting outputs
                $newOutputs = Coconut::$plugin->transcodeSource($source, $job, $useQueue);
                $newOutputs = ArrayHelper::index($newOutputs, null, 'format');

                $outputs = array_merge_recursive($outputs, $newOutputs);
            }
        }

        return $outputs;
    }

    // =Protected Methods
    // =========================================================================

    /**
     * Returns criteria to query for outputs in the database
     *
     * @param \craft\elements\Asset | string $source
     * @param string $key
     *
     * @return array4
     * @throws \yii\base\InvalidArgumentException
     */

    protected function getSourceCriteria( $source, $key = null ): array
    {
        $criteria = [];

        if (is_numeric($source)) {
            $criteria['sourceAssetId'] = $source;
        } else if (is_string($source)) {
            $criteria['source'] = $source;
        } else if ($source instanceof Asset) {
            $criteria['sourceAssetId'] = $source->id;
        } else {
            throw new InvalidArgumentException('Argument `source` must be an Asset element, an asset id, or a video url.');
        }

        if ($key) {
            $criteria['key'] = $key;
        }

        return $criteria;
    }
}
