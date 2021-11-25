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
use yoannisj\coconut\models\Input;
use yoannisj\coconut\models\Output;
use yoannisj\coconut\models\Job;
use yoannisj\coconut\records\OutputRecord;
use yoannisj\coconut\events\OutputEvent;
use yoannisj\coconut\helpers\JobHelper;

/**
 * Singleton class to work with Coconut outputs
 */

class Outputs extends Component
{
    // =Static
    // =========================================================================

    const EVENT_BEFORE_SAVE_OUTPUT = 'beforeSaveOutput';
    const EVENT_AFTER_SAVE_OUTPUT = 'afterSaveOutput';
    const EVENT_COMPLETE_OUTPUT = 'completeOutput';
    const EVENT_BEFORE_DELETE_OUTPUT = 'deleteOutput';
    const EVENT_AFTER_DELETE_OUTPUT = 'deleteOutput';

    // =Properties
    // =========================================================================

    // =Public Methods
    // =========================================================================

    /**
     * Returns output for given input and format
     *
     * @param int|string|array|Asset|Input $input
     * @param string|array $format
     * @param bool $transcode Whether to transcode missing output with Coconut.co
     *
     * @return Output[]
     */

    public function getOutputs( $input, $outputs, bool $transcode = false ): array
    {
        $input = JobHelper::resolveInput($input);
        $outputs = JobHelper::resolveOutputs($outputs);

        $savedOutputs = $this->getOutputsForInput($input);
    }

    /**
     *
     */

    public function getOutputsForInput( $input ): array
    {
        $input = JobHelper::resolveInput($input);
        $jobs = Coconut::$plugin->getJobs()->getJobsForInput($input);

        $outputs = [];

        foreach ($jobs as $job)
        {
            // don't inlcude outputs Coconut does not know about ;)
            if ($job->coconutId) {
                $outputs += $job->getOutputs();
            }
        }

        return $outputs;
    }

    /**
     * Updates a job output with given data
     *
     * @param Output $output Coconut job output to update
     * @param array $data Output data to update output with
     * @param bool $runValidation Whether to validate the updated output
     *
     * @return bool
     */

    public function updateOutput( Output $output, array $data, bool $runValidation = true )
    {
        if (!isset($output->id)) {
            throw new InvalidArgumentException("Can not update new output");
        }

        $dataType = ArrayHelper::get($data, 'type');
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

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_OUTPUT))
        {
            $this->trigger(self::EVENT_BEFORE_SAVE_OUTPUT, new OutputEvent([
                'output' => $output,
                'isNew' => $isNewOutput,
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
            ]));
        }

        // trigger output completion event for convenience
        if ($output->getIsFinished()
            && $this->hasEventHandlers(self::EVENT_COMPLETE_OUTPUT))
        {
            $this->trigger(self::EVENT_COMPLETE_OUTPUT, new OutputEvent([
                'output' => $output,
                'isNew' => $isNewOutput,
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
        // we can not delete an output which has not been saved yet
        if (!$output->id) return false;

        $record = OutputRecord::findOne($output->id);
        if (!$record) return false;

        if ($this->hasEventHandlers(self::EVENT_BEFORE_DELETE_OUTPUT))
        {
            if (!$output) $output = new Output($record->getAttributes());

            $this->trigger(self::EVENT_BEFORE_DELETE_OUTPUT, new OutputEvent([
                'output' => $output,
                'isNew' => false,
            ]));
        }

        // delete output files from volume storages
        $job = $output->getJob();
        $storageVolume = $job->getStorage()->getVolume();

        if ($storageVolume) {
            $storageVolume->deleteFile($output->path);
        }

        if ($record->delete())
        {
            // @todo: delete job if it was left without any outputs?

            if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_OUTPUT))
            {
                if (!$output) $output = new Output($record->getAttributes());

                $this->trigger(self::EVENT_AFTER_DELETE_OUTPUT, new OutputEvent([
                    'output' => $output,
                    'isNew' => false,
                ]));
            }

            return true;
        }

        return false;
    }

    /**
     * Clears all outputs for given input
     *
     * @param mixed $input
     *
     * @return integer|false
     */

    public function clearOutputsForInput( $input )
    {
        $outputs = $this->getOutputsForInput($input);
        $success = true;

        $success = true;
        $count = 0;

        foreach ($outputs as $output)
        {
            // only delete finished outputs
            if (!$output->getIsFinished()) continue;

            // @todo: check if job output has id before deleting it?
            // -> might change whether this is considered successfull or not
            if ($this->deleteOutput($output))  {
                $count++;
            } else {
                $success = false;
            }
        }

        return $success ? $count : false;
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
            $output->setAttributes($record->getAttributes(), false);

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
     * Returns output model for given source video, and optionally for
     * matching criteria.
     *
     * @param Asset | string $source Source for which to get outputs
     * @param array $criteria Criteria against which returned outputs should match
     *
     * @return Output | null
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

    // =Private Methods
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
