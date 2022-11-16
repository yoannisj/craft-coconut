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

namespace yoannisj\coconut\variables;

use Craft;
use craft\base\Component;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\services\Storages;
use yoannisj\coconut\services\Jobs;
use yoannisj\coconut\services\Outputs;

/**
 * Twig variable component for Coconut plugin, usable in Twig templates
 * via `craft.coconut`.
 */
class CoconutVariable extends Component
{
    // =Properties
    // =========================================================================

    // =Public Methods
    // =========================================================================

    /**
     * Transcodes an input video into given outputs.
     *
     * Outputs which were successfully transcoded before will be retreived from
     * the database. A new transcoding job will be submitted to the Coconut.co
     * web-service for transcoding.
     *
     * @see yoannisj\coconut\services\Outputs::getOutputs()
     *
     * @param string|array|Output[] $outputs Outputs to retreive or transcode
     * @param string|array|Output[] $outputs Outputs to retreive or transcode
     *
     * @return Output[] Resulting outputs
     */
    public function transcodeVideo( $video, $outputs = null ): array
    {
        return Coconut::$plugin->transcodeVideo($video, $outputs);
    }

    /**
     * Returns the Coconut plugin 'storages' service component
     *
     * @return Jobs
     */
    public function getStorages(): Storages
    {
        return Coconut::$plugin->get('storages');
    }

    /**
     * Returns the Coconut plugin 'jobs' service component
     *
     * @return Jobs
     */
    public function getJobs(): Jobs
    {
        return Coconut::$plugin->get('jobs');
    }

    /**
     * Returns the Coconut plugin 'outputs' service component
     *
     * @return Jobs
     */
    public function getOutputs(): Outputs
    {
        return Coconut::$plugin->get('outputs');
    }

    // =Protected Methods
    // =========================================================================

    // =Private Methods
    // =========================================================================

}
