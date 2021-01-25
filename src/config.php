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

return [

    /**
     * Api key to use when communicating with Coconut's API. If this is not set,
     * it will default to the `COCONUT_API_KEY` environment variable.
     */

    'apiKey' => null,

    /**
     * Whether transcoding videos should default to using the queue, or run
     * synchonously. It is highly recommended to use the queue whenever possible,
     * but if your craft environment is not running queued jobs in a background
     * process, you may want to default to running jobs synchronously.
     *
     * More info on how to configure your server to run queued jobs as
     * background processes:
     *  https://nystudio107.com/blog/robust-queue-job-handling-in-craft-cms
     */

    'preferQueue' => true,

    /**
     * Depending on your Coconut plan and the config you are using to transcode
     * your video, Transcoding jobs can take a long time. To avoid jobs to fail
     * with a timeout error, this plugin sets a high `Time to Reserve` on jobs
     * pushed to Yii's queue.
     *
     * More info:
     *  https://craftcms.stackexchange.com/questions/25437/queue-exec-time/25452
     */

    'transcodeJobTtr' => 600,

    /**
     * Handle of volume where output files will be stored by default. If not set,
     * this will default to "coconut" and create a local volume called "Coconut"
     * (unless it already exists).
     */

    'outputVolume' => null,

    /**
     * The file path where output files will be stored by default. This is a
     * template string, which may contain the following tokens:
     *
     * - {volume} Name of the source's volume (or host when converting source urls directly)
     * - {folderPath} Path to folder where source is stored (relative to source volume)
     * - {hash} A unique hash for the source url
     * - {format} A string representing the output format
     * - {ext} Output file extension
     *
     * **Note**: to avoid craft from indexing output files as assets, the path
     * should start with an underscore.
     */

    'outputPathFormat' => null,

    /**
     * Defines named coconut configurations. Keys are configuration names and
     * values should be arrays with the "outputs" and optional "vars" keys.
     *
     * **Note** Output urls are determined by the plugin's `outputPathFormat`
     * setting, so items in the "outputs" setting should be a format string,
     * or a format string mapping to format options. For example:
     *  "outputs" => [
     *      "mp4:720p",
     *      "webm:1080p",
     *      "jpg" => "number=3",
     *  ],
     *
     * **Note**: the plugin will set the "source" and "webhook" settings
     * programatically.
     */

    'configs' => [],

    /**
     * Sets default coconut config for source asset volumes. Keys should be
     * the handle of the source volume, and values should be a config file name
     * or an array defining coconut job settings (same format as "configs" setting).
     */

    'volumeConfigs' => [],

    /**
     * List of source volumes handles, for which the plugin should automatically
     * create a Coconut conversion job (when a video asset is added or updated).
     */

    'watchVolumes' => [],

];