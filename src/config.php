<?php

use craft\helpers\App;

return [

    /**
     * The API key of the Coconut.co account used to convert videos.
     *
     * If this is not set, the plugin will check for an environment variable
     * named `COCONUT_API_KEY` (using `\craft\helper\App::env()`).
     *
     * @default null
     *
     * @var string
     */
    'apiKey' => App::env('COCONUT_API_KEY'),

    /**
     * The endpoint to use for Coconut API calls.
     * @see https://docs.coconut.co/
     *
     * @tip This will override the `region` setting.
     * @tip Coconut API Keys are bound to the Endpoint/Region, so if you change
     *  this you probably want to change the `apiKey` setting as well
     *
     * @var string|null
     */
    'endpoint' => App::env('COCONUT_ENDPOINT'),

    /**
     * The region of the Coconut.co cloud infrastructure to use
     *  for Coconut API calls.
     *
     * @note: This will have no effect if the `endpoint` setting is also set.
     * @note: Coconut API Keys are bound to the Endpoint/Region, so if you change
     *  this you probably want to change the `apiKey` setting as well
     *
     * @var string|null
     */
    'region' => App::env('COCONUT_REGION') ?: null,

    /**
     * Public URL to use as *base* for all URLs sent to the Coconut API
     * (i.e. local asset URLs and notification webhooks)
     *
     * @tip For local development, you want to set this to a URL tunnelling to
     * your local machine (e.g. provided by ngrok via `ddev share`).
     *
     * @var string|null
     */
    'publicBaseUrl' => null,

    /**
     * Depending on your Coconut plan and the parameters you are using to transcode
     * your video, jobs can take a long time. To avoid jobs to fail with a timeout
     * error, this plugin sets a high `Time to Reserve` on the jobs it pushes to
     * Craft's queue.
     *
     * More info:
     *  https://craftcms.stackexchange.com/questions/25437/queue-exec-time/25452
     *
     * @var int
     *
     * @default 900
     */
    'transcodeJobTtr' => 900,

    /**
     * Named storage settings to use in Coconut transcoding jobs.
     *
     * Each key defines a named storage, and its value should be an array of
     * storage settings as defined here: https://docs.coconut.co/jobs/storage
     *
     * @note For HTTP uploads, Coconut will give the outputs a URL based on the
     * upload URL by appending the output file path. This means that the same
     * URL needs to function for both uploading the asset file (POST) and serving
     * the outptut file (GET).
     * To achieve this , the Coconut plugin for Craft registers a custom route
     * `/coconut/outputs/<volume-handle>/<output-path>` which maps to:
     * - the 'coconut/jobs/upload' action for POST requests (saves file in volume)
     * - the 'coconut/jobs/output' action for GET requests (serves file from volume)
     *
     * @var array
     *
     * @example [
     *      'myS3Bucket' => [
     *          'service' => 's3',
     *          'region' => 'us-east-1',
     *          'bucket' => 'mybucket',
     *          'path' = '/coconut/outputs',
     *          'credentials' => [
     *              'access_key_id' => '...',
     *              'secret_access_key' = '...',
     *          ]
     *      ],
     *      'httpUpload' => [
     *          'url' => 'https://remote.server.com/coconut/upload',
     *      ],
     * ]
     *
     * @default []
     */
    'storages' => [],

    /**
     * The storage name or settings used to store Coconut output files when none
     * is given in transcoding job parameters.
     *
     * This can be set to a string which must be either a key from the `storages`
     * setting, or a volume handle.
     *
     * If this is set to `null`, the plugin will try to generate storage settings
     * based on the input asset's volume, or fallback to use the HTTP upload method
     * to store files in the volume defined by the 'defaultUploadVolume' setting.
     *
     * @var string|array|yoannisj\coconut\models\Storage
     *
     * @default null
     */
    'defaultStorage' => null, // auto based on asset, or httpUpload

    /**
     * The default volume used to store output files when the `storage` parameter
     * was omitted and the input asset's volume could be determined (.e.g. if the
     * `input` parameter was a URL and not a Craft asset).
     *
     * @var string|craft\models\Volume
     *
     * @default 'coconut'
     */
    'defaultUploadVolume' => 'coconut',

    /**
     * Format used to generate default path for output files saved in storages.
     *
     * Supports the following placeholder strings:
     * - '{path}' the input folder path, relative to the volume base path (asset input),
     *      or the URL path (external URL input)
     * - '{filename}' the input filename (without extension)
     * - '{hash}' a unique md5 hash based on the input URL
     * - '{shortHash}' a shortened version of the unique md5 hash
     * - '{key}' the output `key` parameter (a path-friendly version of it)
     * - '{ext}' the output file extension
     *
     * @tip To prevent outputs which are saved in asset volumes to end up in
     * Craft's asset indexes, the path will be prefixed with an '_' character
     * (if it is not already).
     *
     * @var string
     *
     * @default '_coconut/{path}/{filename}-{key}.{ext}'
     */
    'defaultOutputPathFormat' => '_coconut/{path}/{filename}-{key}.{ext}',

    /**
     * Named coconut job settings.
     *
     * Each key defines a named job, and its value should be an array setting
     * the 'storage' and 'outputs' parameters.
     *
     * The 'storage' parameter can be a string, which will be matched against
     * one of the named storages defined in the `storages` setting, or a
     * volume handle.
     *
     * If the 'storage' parameter is omitted, the plugin will try to generate
     * storage settings for the input asset's volume, or fallback to use the
     * HTTP upload method to store files in the volume defined by the
     * `defaultUploadVolume` setting.
     *
     * The 'outputs' parameter can have indexed string items, in which case
     * the string will be used as the output’s `format` parameter, and the
     * output’s `path` parameter will be generated based on the
     * `defaultOutputPathFormat` setting.
     *
     * @tip To prevent outputs which are saved in asset volumes to end up in
     * Craft's asset indexes, their path parameter will be prefixed with an '_'
     * character (if it is not already).
     *
     * The 'input' and 'notification' parameters are not supported, as the plugin will
     * set those programatically.
     *
     * @example [
     *      'videoSources' => [
     *          'storage' => 'coconut', // assuming there is a volume with handle 'coconut'
     *          'outputs' => [
     *              'webm', // will generate the output's `path` parameter based on `defaultOutputPathFormat`
     *              'mp4:360p',
     *              'mp4:720p',
     *              'mp4:1080p::quality=4' => [
     *                  'key' => 'mp4:1080p',
     *                  'if' => "{{ input.width }} >= 1920
     *              ]
     *          ],
     *      ],
     * ]
     *
     * @var array[]
     *
     * @default []
     *
     * @tip Include the word 'poster' in the `key` of image outputs that
     *  can be used as video posters.
     */
    'jobs' => [],

    /**
     * Sets default job parameters for craft assets in given volumes.
     *
     * Each key should match the handle of a craft volume, and its value should
     * be either a key from the `jobs` setting, or an array of parameters (in the
     * same format as the `jobs` setting).
     *
     * @var string[]|array[]
     *
     * @default []
     */
    'volumeJobs' => [],

    /**
     * List of input volumes handles, for which the plugin should
     * automatically create a Coconut conversion job every time a video asset
     * is added or updated.
     *
     * @var string[]
     *
     * @default []
     */
    'watchVolumes' => [],

];
