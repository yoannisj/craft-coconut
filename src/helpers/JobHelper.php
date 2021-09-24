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

namespace yoannisj\coconut\helpers;

use yii\base\InvalidArgumentException;
use yii\base\InvalidCallException;

use Craft;
use craft\base\VolumeInterface;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\FileHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Input;
use yoannisj\coconut\models\Output;
use yoannisj\coconut\models\Storage;
use yoannisj\coconut\models\Job;
use yoannisj\coconut\records\JobRecord;

/**
 * Static helper class to work with coconut configs
 */

class JobHelper
{
    // =Constants
    // =========================================================================

    /**
     * List of format specs that are relevant for video encoding
     * @see https://docs.coconut.co/references/formats#basics
     */

    const VIDEO_SPECS = [
        'video_codec', 'resolution', 'video_bitrate', 'fps',
    ];

    /**
     * Default video specification values
     * @see https://docs.coconut.co/references/formats#basics
     */

    const DEFAULT_VIDEO_SPECS = [
        'resolution' => '0x0', 'video_bitrate' => '1000k', 'fps' => '0fps',
    ];

    /**
     * Equivalent of unsetting all video specifications (i.e. no video)
     */

    const DISABLED_VIDEO_SPECS = [
        'video_codec' => false, 'resolution' => false, 'video_bitrate' => false, 'fps' => false,
    ];

    /**
     * List of video codec spec values supported by the Coconut service
     * @see https://docs.coconut.co/references/formats#basics
     */

    const VIDEO_CODECS = [
        'mpeg4', 'xvid', 'flv', 'h263', 'mjpeg', 'mpeg1video', 'mpeg2video',
        'qtrle', 'svq3', 'wmv1', 'wmv2', 'huffyuv', 'rv20', 'h264', 'hevc',
        'vp8', 'vp9', 'theora', 'dnxhd', 'prores',
    ];

    /**
     * List of format specs that are relevant for audio encoding
     * @see https://docs.coconut.co/references/formats#basics
     */

    const AUDIO_SPECS = [
        'audio_codec', 'audio_bitrate', 'sample_rate', 'audio_channel',
    ];

    /**
     * Default audio specification values
     * @see https://docs.coconut.co/references/formats#basics
     */

    const DEFAULT_AUDIO_SPECS = [
        'audio_bitrate' => '128k', 'sample_rate' => '44100hz', 'audio_channel' => 'stereo',
    ];

    /**
     * Equivalent of unsetting all audio specifications (i.e. no audio)
     */

    const DISABLED_AUDIO_SPECS = [
        'audio_codec' => false, 'audio_bitrate' => false, 'sample_rate' => false, 'audio_channel' => false,
    ];

    /**
     * List of audio codec spec values supported by the Coconut service
     * @see https://docs.coconut.co/references/formats#basics
     */

    const AUDIO_CODECS = [
        'mp3', 'mp2', 'aac', 'amr_nb', 'ac3', 'vorbis', 'flac',
        'pcm_u8', 'pcm_s16le', 'pcm_alaw', 'wmav2',
    ];

    /**
     * List of format specs that are relevant for image creation
     * @see https://docs.coconut.co/references/formats#basics
     *
     */

    const IMAGE_SPECS = [
        'resolution', 'pix_fmt', // '2pass',
    ];

    /**
     * Default image specification values
     * @see https://docs.coconut.co/references/formats#basics
     */

    const DEFAULT_IMAGE_SPECS = [
        'resolution' => '0x0',
    ];

    /**
     * List of format options
     */

    const FORMAT_OPTIONS = [
        'pix_fmt', '2pass', 'quality', 'vprofile', 'level', 'frag',
    ];

    /**
     * Regex patterns used to identify specification values
     * @see https://docs.coconut.co/references/formats#basics
     */

    // =common
    const RESOLUTION_PATTERN = '/^(\d+)x(\d+)$/';

    /**
     * Maps formatting video resolution definitions to video specs
     * @see https://docs.coconut.co/references/formats#basics
     */

    const RESOLUTION_DEFINITION_SPECS = [
        '240p' => [ 'resolution' => '0x240', 'video_bitrate' => '500k' ],
        '360p' => [ 'resolution' => '0x360', 'video_bitrate' => '800k' ],
        '480p' => [ 'resolution' => '0x480', 'video_bitrate' => '1000k' ],
        '540p' => [ 'resolution' => '0x540', 'video_bitrate' => '1000k' ],
        '576p' => [ 'resolution' => '0x576', 'video_bitrate' => '1000k' ],
        '720p' => [ 'resolution' => '1280x720', 'video_bitrate' => '2000k' ],
        '1080p' => [ 'resolution' => '1920x1080', 'video_bitrate' => '4000k' ],
        '2160p' => [ 'resolution' => '3840x2160', 'video_bitrate' => '8000k' ],
    ];

    // =video
    const VIDEO_BITRATE_PATTERN = '/^(\d{2,6})k$/';
    const FPS_PATTERN = '/^(0|15|23\.98|25|29\.97|30)fps$/';

    // =audio
    const AUDIO_SEGMENTS_SPLIT_PATTERN = '/(?<!amr|pcm)_/';
    const AUDIO_BITRATE_PATTERN = '/^(32|64|96|128|160|192|224|256|288|320|352|384|416|448|480|512)k$/';
    const SAMPLE_RATE_PATTERN = '/^(8000|11025|16000|22000|22050|24000|32000|44000|44100|48000)hz$/';
    const AUDIO_CHANNEL_PATTERN = '/^(mono|stereo)$/';

    // =options
    const PIX_FMT_OPTION_PATTERN = '/^(yuv420p|yuv422p|yuva444p10le)$/';
    const QUALITY_OPTION_PATTERN = '/^[1-5]{1}$/';
    const VPROFILE_OPTION_PATTERN = '/^(baseline|main|high|high10|high422|high444|444)$/';
    const VPROFILE_OPTION_PATTERN_PRORES = '/^[0-3]{1}$/';
    const LEVEL_OPTION_PATTERN = '/^(10|11|12|13|20|21|22|30|31|32|40|41|42|50|51)$/';

    /**
     * Map of patterns to validate supported container options
     */

    const CONTAINER_OPTION_PATTERNS = [
        'mp4' => [
            'frag' => true, // boolean value
        ],
    ];

    /**
     * MAp of patterns to validate supported codec options
     */

    const CODEC_OPTION_PATTERNS = [
        'prores' => [
            'vprofile' => self::VPROFILE_OPTION_PATTERN_PRORES,
        ],
        'h264' => [
            'vprofile' => self::VPROFILE_OPTION_PATTERN,
            'level' => self::LEVEL_OPTION_PATTERN,
            'quality' => self::QUALITY_OPTION_PATTERN,
            'maxrate' => self::VIDEO_BITRATE_PATTERN,
        ],
        'hevc' => [
            'vprofile' => self::VPROFILE_OPTION_PATTERN,
            'level' => self::LEVEL_OPTION_PATTERN,
            'quality' => self::QUALITY_OPTION_PATTERN,
            'maxrate' => self::VIDEO_BITRATE_PATTERN,
        ],
        'vp8' => [
            'quality' => self::QUALITY_OPTION_PATTERN,
            'maxrate' => self::VIDEO_BITRATE_PATTERN,
        ],
        'vp9' => [
            'quality' => self::QUALITY_OPTION_PATTERN,
            'maxrate' => self::VIDEO_BITRATE_PATTERN,
        ],
    ];

    /**
     * Map of container aliases and their target container
     * @see https://docs.coconut.co/references/formats#basics
     */

    const CONTAINER_ALIASES = [
        'divx' => 'avi',
        'xvid' => 'avi',
        'wmv' => 'asf',
        'flash' => 'flv',
        'theora' => 'ogv',
        'jpeg' => 'jpg',
    ];

    /**
     * List of input file containers (i.e. extensions) supported by the Coconut service
     */

    const INPUT_CONTAINERS = [
        'mp4', 'm4p', 'm4v',
        'webm',
        'avi', 'avchd',
        'asf', 'wmv',
        'mov', 'qt',
        'mpg', 'mp2', 'mpeg', 'mpe', 'mpv', 'mpegts',
        'mkv', '3gp',
        'ogv', 'ogg',
        'flv', 'swf',
    ];

    /**
     * List of video output file containers supported by the Coconut service
     * @see https://docs.coconut.co/references/formats#basics
     */

    const VIDEO_OUTPUT_CONTAINERS = [
        'mp4',
        'webm',
        'avi', 'divx', 'xvid',
        'asf', 'wmv',
        'mpegts', 'mov',
        'mkv', '3gp',
        'ogv', 'theora',
        'flv', 'flash',
    ];

    /**
     * List of audio output file containers supported by the Coconut service
     * @see https://docs.coconut.co/references/formats#basics
     */

    const AUDIO_OUTPUT_CONTAINERS = [
        'mp3', 'ogg',
    ];

    /**
     * List of image output file containers supported by the Coconut service
     * @see https://docs.coconut.co/references/formats#basics
     */

    const IMAGE_OUTPUT_CONTAINERS = [
        'jpg', 'jpeg', 'png', 'gif',
    ];

    /**
     * Default format specification values for given container
     * @see https://docs.coconut.co/references/formats#basics
     */

    const CONTAINER_VIDEO_SPECS = [
        // =video containers
        'mp4' => [ 'video_codec' => 'h264', ],
        'webm' => [ 'video_codec' => 'vp8', ],
        'avi' => [ 'video_codec' => 'mpeg4', ],
        'asf' => [ 'video_codec' => 'wmv2', ],
        'mpegts' => [ 'video_codec' => 'h264', ],
        'mov' => [ 'video_codec' => 'h264', ],
        'flv' => [ 'video_codec' => 'flv', ],
        'mkv' => [ 'video_codec' => 'h264', ],
        '3gp' => [ 'video_codec' => 'h263', ],
        'ogv' => [ 'video_codec' => 'theora', ],
    ];

    const CONTAINER_AUDIO_SPECS = [
        // =video containers
        'mp4' => [ 'audio_codec' => 'aac' ],
        'webm' => [ 'audio_codec' => 'vorbis' ],
        'avi' => [ 'audio_codec' => 'mp3' ],
        'asf' => [ 'audio_codec' => 'wmav2' ],
        'mpegts' => [ 'audio_codec' => 'aac' ],
        'mov' => [ 'audio_codec' => 'aac' ],
        'flv' => [ 'audio_codec' => 'mp3' ],
        'mkv' => [ 'audio_codec' => 'aac' ],
        '3gp' => [ 'audio_codec' => 'aac', 'audio_bitrate' => '32k' ],
        'ogv' => [ 'audio_codec' => 'vorbis' ],

        // =audio containers
        'ogg' => [ 'audio_codec' => 'vorbis' ],
        'mp3' => [ 'audio_codec' => 'mp3' ],
    ];

    const CONTAINER_IMAGE_SPECS = [];

    /**
     * Regex patterns to parse file paths and urls
     */

    const FILE_EXTENSION_PATTERN = '/\.([a-z0-9]{2,5}$)/';

    /**
     * Regex patterns to parse sequence options
     */

    const SEQUENTIAL_PLACEHOLDER_PATTERN = "/%(\d+\$)?(-|\+| {1}|0|')?(\d+)?(\.\d+)?(%|[b-h]|[F-H]|o|s|u|x|X){1}/";

    /**
     * Pattern used to recognize and resolve path formats expressions
     */

    const PATH_EXPRESSION_PATTERN = '/(?<!\{)\{([a-zA-Z0-9_]+)\}(?!\})/';

    /**
     * Pattern used to make paths private
     */

    const PATH_PRIVATISATION_PATTERN = '/^(\.{0,2}\/)?([^_])/';

    // =Public Methods
    // =========================================================================

    /**
     * Parses given format string or array of format specs, by
     * analyzing and validating spec values against the format container,
     * and discarding invalid and irrelevant values.
     *
     * @note: If format is given as an array of specs, make sure you
     *  include the 'container' key.
     *
     * @param string|array $format Format to parse
     *
     * @return array Parsed specs for given $format
     */

    public static function parseFormat( $format ): array
    {
        if (is_string($format)) {
            $format = static::decodeFormat($format);
        }

        else if (!is_array($format))
        {
            throw new InvalidArgumentException(
                'Argument #1 `$format` must be a format string or an array of format specs');

        }

        return static::_normalizeFormatSpecs($format);
    }

    /**
     * Parses given format string, and returns the corresponding map of
     * (explicit and implicit) spec values
     *
     * @param string $format
     *
     * @return array
     */

    public static function decodeFormat( string $format ): array
    {
        // don't throw errors if this is an empty format string
        if (empty($format)) return [];

        $segments = explode(':', $format);
        $container = $segments[0];
        $type = static::containerType($container);

        $specs = [
            'container' => $container,
        ];

        switch ($type)
        {
            case 'video':
                $options = $segments[3] ?? '';
                $specs = array_merge( $specs,
                    static::_decodeFormatVideoSpecs($container, $segments[1] ?? '', $options),
                    static::_decodeFormatAudioSpecs($container, $segments[2] ?? '', $options)
                );
                break;
            case 'audio':
                $audio_segment = ($segments[1] ?? '') ?: ($segments[2] ?? '');
                $specs = array_merge( $specs,
                    static::_decodeFormatAudioSpecs($container, $audio_segment));
                break;
            case 'image':
                $specs = array_merge( $specs,
                    static::_decodeFormatImageSpecs($container, $segments[1] ?? ''));
                break;
        }

        return $specs;
    }

    /**
     * Returns formatted string for given format specs
     *
     * @param array $specs Formatting specs to encode
     * @param string|null $container Format container
     *
     * @return string
     */

    public static function encodeFormat( array $specs, string $container = null ): string
    {
        if (!$container) {
            $container = $specs['container'] ?? null;
        }

        if (empty($container)) {
            throw new InvalidCallException("Could not resolve container from given arguments");
        }

        // get output type based on container
        $type = static::containerType($container);

        // normalize format specs
        $specs = static::_normalizeFormatSpecs($specs, $container, $type);

        // image containers only support the `resolution` spec
        if ($type == 'image')
        {
            $resolution = $specs['resolution'] ?? null;
            return rtrim($container.':'.$resolution, ':');
        }

        // build segments of format string
        $videoSegment = '';
        $audioSegment = '';
        $optionsSegment = '';

        $videoDisabled = $specs['video_disabled'] ?? false;
        $audioDisabled = $specs['audio_disabled'] ?? false;

        // build segment with audio specs
        if (!$audioDisabled)
        {
            $audioSpecs = [];

            foreach (self::AUDIO_SPECS as $spec)
            {
                $specValue = $specs[$spec] ?? null;
                if ($specValue) $audioSpecs[] = $specValue;
            }

            $audioSegment = implode('_', $audioSpecs);
        } else {
            $audioSegment = 'x';
        }

        // build segment with video specs
        if ($type == 'video')
        {
            if (!$videoDisabled)
            {
                $videoSpecs = [];

                foreach (self::VIDEO_SPECS as $spec)
                {
                    $specValue = $specs[$spec] ?? null;
                    if ($specValue) $videoSpecs[] = $specValue;
                }

                $videoSegment = implode('_', $videoSpecs);
            } else {
                $videoSegment = 'x';
            }
        }

        // build segment with format options
        if (!$videoDisabled || !$audioDisabled)
        {
            $formatOptions = [];

            foreach (self::FORMAT_OPTIONS as $option)
            {
                $optionValue = $specs[$option] ?? null;

                if ($optionValue === true) {
                    $formatOptions[] = $option;
                } else if ($optionValue) {
                    $formatOptions[] = $option.'='.$optionValue;
                }
            }

            $optionsSegment = implode('_', $formatOptions);
        } else {
            $optionsSegment = '';
        }

        if ($type == 'video') {
            return rtrim($container.':'.$audioSegment.':'.$optionsSegment, ':');
        }

        if ($type == 'audio') {
            return rtrim($container.':'.$audioSegment.':'.$optionsSegment, ':');
        }

        return $container;
    }

    /**
     * @param string|array $format
     *
     * @return string|null
     */

    public static function formatExtension( $format )
    {
        $container = null;

        if (is_string($format)) {
            $container = explode(':', $format)[0] ;
        }

        else if (is_array($format)) {
            $container = $format['container'] ?? null;
        }

        if (empty($container)) {
            throw new InvalidArgumentException(
                "Could not determine file container for given `format` argument");
        }

        return static::containerExtension($container);
    }

    /**
     * @param string $container
     *
     * @return string|null
     */

    public static function containerExtension( string $container )
    {
        $extension = null;

        switch ($container)
        {
            case 'divx':
            case 'xvid':
                $extension = 'avi';
                break;
            case 'wmv':
                $extension = 'asf';
                break;
            case 'flash':
                $extension = 'flv';
                break;
            case 'theora':
                $extension = 'ogv';
                break;
        }

        return $extension ?? $container;
    }

    /**
     * Returns the media type of given output file container
     *
     * @param string $container
     *
     * @return string|null
     */

    public static function containerType( string $container )
    {
        if (in_array($container, self::VIDEO_OUTPUT_CONTAINERS)) {
            return 'video';
        } else if (in_array($container, self::AUDIO_OUTPUT_CONTAINERS)) {
            return 'audio';
        } else if (in_array($container, self::IMAGE_OUTPUT_CONTAINERS)) {
            return 'image';
        }

        return null;
    }

    /**
     * Returns default format specs for given output container
     *
     * @param string $container Output container
     *
     * @return array
     */

    public static function containerFormatDefaults( string $container ): array
    {
        $base = self::CONTAINER_ALIASES[$container] ?? $container;
        $type = static::containerType($container);

        return static::_containerFormatDefaults($base, $type);
    }

    /**
     * Returns the path-friendly version for given format key
     *
     * @param string $key The format key
     *
     * @return string A path-friendly version of given key
     */

    public static function keyAsPath( string $key )
    {
        return str_replace(
            [':', '=', ','],
            ['-', '_', '__'],
        $key);
    }

    /**
     * Returns the key for given format path
     *
     * @param string $path The format path (as returned by `JobHelper::keyPath()`)
     *
     * @return string The corresponding format key
     */

    public static function keyFromPath( string $path )
    {
        return str_replace(
            ['-', '_'],
            [':', '='],
        str_replace('__', ',', $path)); // don't replace '__' with '=='
    }

    /**
     * Turns given volume path private, so Craft-CMS does not index it as an asset
     *
     * @param string $path
     *
     * @return string
     */

    public static function privatisePath( string $path ): string
    {
        return preg_replace(self::PATH_PRIVATISATION_PATTERN, '$1_$2', $path);
    }

    /**
     * Adds a sequential placeholder to given path
     *
     * @param string $path
     *
     * @return string
     */

    public static function sequencePath( string $path ): string
    {

    }

    /**
     * Turns given URL public by replacing it's base wtih the
     * 'publicBaseUrl' setting
     *
     * @param string $url
     */

    public static function publicUrl( string $url )
    {
        $publicBaseUrl = Coconut::$plugin->getSettings()->publicBaseUrl;

        if (!empty($publicBaseUrl))
        {
            if (UrlHelper::isRootRelativeUrl($url)) {
                return rtrim($publicBaseUrl, '/').'/'.$url;
            }

            $baseUrl = UrlHelper::baseUrl();
            $protoRelativeBase = rtrim(preg_replace('/(http|https):\/\//', '://', $baseUrl), '/');

            // is this a Craft URL ?
            // (compare hosts to work around scheme inconsistencies)
            if (strpos($url, $protoRelativeBase) !== false)
            {
                // replace base URL (with whatever scheme it uses) by public base URL
                $baseUrlPattern = str_replace('/', '\/', preg_quote($protoRelativeBase));
                $baseUrlPattern = '/(http|https)?'.$baseUrlPattern.'/';

                // use public version of base URL
                $url = preg_replace($baseUrlPattern, rtrim($publicBaseUrl, '/'), $url);
            }
        }

        return $url;
    }

    /**
     * Prepares and normalized structure of job data returned by the coconut API
     * in order to populate the job, input and output models with it.
     *
     * @param array|object $data
     *
     * @return array
     */

    public static function prepareJobData( $data ): array
    {
        // move 'id' key to 'coconutId'
        $data['coconutId'] = ArrayHelper::remove($data, 'id');

        // rename date keys
        $createdAt = ArrayHelper::remove($data, 'created_at');
        if ($createdAt) $data['createdAt'] = $createdAt;

        $completedAt = ArrayHelper::remove($data, 'completed_at');
        if ($completedAt) $data['completedAt'] = $completedAt;

        // remove 'null' input keys
        $inputData = $data['input'] ?? null;
        if ($inputData) $data['input'] = array_filter($data['input']);

        // normalize structure of metadata
        $metadata = ArrayHelper::remove($data, 'metadata');

        if ($metadata && is_array($metadata))
        {
            $inputMetadata = $metadata['input'] ?? null;
            $outputsMetadata = $metadata['outputs'] ?? null;

            if ($inputMetadata)
            {
                $data['input'] = $data['input'] ?? [];
                $data['input']['metadata'] = $inputMetadata;
            }

            if ($outputsMetadata)
            {
                $data['outputs'] = $data['outputs'] ?? [];

                foreach ($outputsMetadata as $key => $outputMetadata)
                {
                    $foundOutputData = false;

                    foreach ($data['outputs'] as $index => $outputData)
                    {
                        if ($outputData['key'] == $key)
                        {
                            $foundOutputData = true;
                            $data['outputs'][$index]['metadata'] = $outputMetadata;
                        }
                    }

                    if (!$foundOutputData)
                    {
                        // add output entry to data
                        $data['outputs'][] = [
                            'key' => $key,
                            'metadata' => $metadata,
                        ];
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Poplates job model with given data (e.g. returned by API)
     *
     * @param Job $job
     * @param array $data
     *
     * @return Job
     */

    public static function populateJobFromData( Job $job, array $data ): Job
    {
        $data = static::prepareJobData($data);

        // $outputsData = ArrayHelper::remove($data, 'outputs');
        // if ($outputsData)
        // {
        //     foreach ($outputsData as $outputData)
        //     {
        //         $outputKey = ArrayHelper::getValue($outputData, 'key');
        //         $output = $job->getOutputByKey($outputKey);

        //         if (!$output)
        //         {
        //             // add missing output, based on given data
        //             $output = static::populateJobOutput(new Output([
        //                 'job' => $job,
        //                 'key' => $outputKey
        //             ]), $outputData);

        //             $job->addOutput($output);
        //         }

        //         else {
        //             $output = static::populateJobOutput($output, $outputData);
        //         }
        //     }
        // }

        // update job with data received from Coconut
        return static::populateObject($job, $data, true);
    }

    /**
     *
     */

    public static function populateJobOutput( Output $output, array $data ): Output
    {
        return static::populateObject($output, $data);
    }

    /**
     * Transfers data from given job record to job model
     *
     * @param Job $job
     * @param JobRecord $record
     *
     * @return Job
     */

    public static function populateJobFromRecord( Job $job, JobRecord $record ): Job
    {
        $attrs = $record->getAttributes();

        $job->setInput(new Input([
            'assetId' => ArrayHelper::remove($attrs, 'inputAssetId'),
            'url' => ArrayHelper::remove($attrs, 'inputUrl'),
            // 'urlHash' => ArrayHelper::remove($attrs, 'inputUrlHash'),
            'status' => ArrayHelper::remove($attrs, 'inputStatus'),
            'metadata' => ArrayHelper::remove($attrs, 'inputMetadata'),
            'expires' => ArrayHelper::remove($attrs, 'inputExpires'),
        ]));

        $job->setStorage(ArrayHelper::remove($attrs, 'storageHandle')
            ?? ArrayHelper::remove($attrs,'storageVolumeId')
            ?? ArrayHelper::remove($attrs,'storageParams'));

        $job->setAttributes($attrs, false);

        return $job;
    }

    /**
     * Transfers data from given job model to job record
     *
     * @param JobRecord $record
     * @param Job $job
     *
     * @return JobRecord
     */

    public static function populateRecordFromJob( JobRecord $record, Job $job): JobRecord
    {
        $attrs = $job->getAttributes();

        $input = $job->getInput();
        $record->inputAssetId = $input->assetId;
        $record->inputUrl = $input->url;
        // $record->inputUrlHash = $input->urlHash;
        $record->inputStatus = $input->status;
        $record->inputMetadata = $input->metadata;
        $record->inputExpires = $input->expires;

        // prioritize storage handle over storage volume id over plain storage params
        $storageHandle = ArrayHelper::remove($attrs, 'storageHandle');
        $storageVolumeId = ArrayHelper::remove($attrs, 'storageVolumeId');

        if ($storageHandle) {
            $record->storageHandle = $storageHandle;
        } else if ($storageVolumeId) {
            $record->storageVolumeId = $storageVolumeId;
        } else if (($storage = $job->getStorage())) {
            $record->storageParams = $storage->toParams();
        }

        $record->setAttributes($attrs, false);

        return $record;
    }

    /**
     * Populates object with given data
     *
     * @param object $object
     * @param array|object $data Data to
     * @param bool $recursive Whether values should be merged recursively
     *
     * @return object
     */

    public static function populateObject( object $object, $data, bool $recursive = true ): object
    {
        if (!is_object($data) && !is_array($data))
        {
            throw new InvalidArgumentException(
                'Argument #2 `data` must be an array or object to traverse');
        }

        foreach ($data as $prop => $value)
        {
            if ((is_object($value) || ArrayHelper::isAssociative($value))
                && ($currVal = $object->$prop)
                && (is_object($currVal) || ArrayHelper::isAssociative($currVal))
            ) {
                $object->$prop = static::populateObject($currVal, $value, $recursive);
            }

            else {
                $object->$prop = $value;
            }
        }

        return $object;
    }

    // =Private Methods
    // =========================================================================

    /**
     * Returns default format specs for given output container
     *
     * @param string $container Output container
     * @param string $type Output container type
     *
     * @return array
     */

    private static function _containerFormatDefaults( string $container, string $type ): array
    {
        $defaults = [];

        switch ($type)
        {
            case 'video':
                $defaults = array_merge($defaults,
                    self::DEFAULT_VIDEO_SPECS,
                    (self::CONTAINER_VIDEO_SPECS[$container] ?? []),
                    self::DEFAULT_AUDIO_SPECS,
                    (self::CONTAINER_AUDIO_SPECS[$container] ?? [])
                );
                break;
            case 'audio':
                $defaults = array_merge($defaults,
                    self::DEFAULT_AUDIO_SPECS,
                    (self::CONTAINER_AUDIO_SPECS[$container] ?? [])
                );
                break;
            case 'image':
                $defaults = array_merge($defaults,
                    self::DEFAULT_IMAGE_SPECS);
                break;
        }

        return $defaults;
    }

    /**
     * Parses video specification segment from format string
     *
     * @param string $container The format's output file container
     * @param string $segment The video specification segment to parse
     * @param string|null $options The format options segment to parse
     *
     * @return array All corresponding video specification values (implicit and explicit)
     */

    private static function _decodeFormatVideoSpecs( string $container, string $segment, string $options = null )
    {
        // support disabling video by using 'x'
        if ($segment == 'x') return [ 'video_disabled' => true ];

        // include default video specs
        $specs = array_merge([], self::DEFAULT_VIDEO_SPECS);

        // include container's default specs
        $base = self::CONTAINER_ALIASES[$container] ?? $container;
        if (array_key_exists($base, self::CONTAINER_VIDEO_SPECS)) {
            $specs = array_merge($specs, self::CONTAINER_VIDEO_SPECS[$base]);
        }

        if (!empty($segment))
        {
            $segment_specs = explode('_', $segment);

            // resolution definition override other (implied) spec defaults,
            // so we need to process it first
            for ($i = 0; $i < count($segment_specs); $i++)
            {
                $spec = $segment_specs[$i];

                if (array_key_exists($spec, self::RESOLUTION_DEFINITION_SPECS))
                {
                    $specs = array_merge($specs, self::RESOLUTION_DEFINITION_SPECS[$spec]);
                    array_splice($segment_specs, $i, 1); // no need to process this spec again
                }
            }

            // @todo: optionally throw errors when unknown or invalid spec is given
            foreach ($segment_specs as $spec)
            {
                $matches = [];

                if (preg_match(self::RESOLUTION_PATTERN, $spec, $matches)) {
                    $specs['resolution'] = $spec;
                }

                else if (in_array($spec, self::VIDEO_CODECS)) {
                    $specs['video_codec'] = $spec;
                }

                else if (preg_match(self::VIDEO_BITRATE_PATTERN, $spec, $matches))
                {
                    if ((int)$matches[1] < 200000) {
                        $specs['video_bitrate'] = $spec;
                    } else {
                        $matches = [];
                    }
                }

                else if (preg_match(self::FPS_PATTERN, $spec, $matches)) {
                    $specs['fps'] = $spec;
                }
            }
        }

        if (!empty($options))
        {
            $codec = $specs['video_codec'];
            $optionSpecs = static::_decodeFormatOptions($container, $codec, $options);
            $specs = array_merge($specs, $optionSpecs);
        }

        // `video_bitrate` is ignored when `quality` option is set
        if (array_key_exists('quality', $specs)) {
            $specs['video_bitrate'] = null;
        }

        return $specs;
    }

    /**
     * Parses audio specification segment from format string
     *
     * @param string $container The format's output file container
     * @param string $segment The audio specification segment to parse
     *
     * @return array All corresponding audio specification values (implicit and explicit)
     */

    private static function _decodeFormatAudioSpecs( string $container, string $segment = null )
    {
        // support disabling audio by using 'x'
        if ($segment == 'x') return [ 'audio_disabled' => true ];

        // include default specs
        $specs = array_merge([], self::DEFAULT_AUDIO_SPECS);

        // include container's default specs
        $base = self::CONTAINER_ALIASES[$container] ?? $container;
        if (array_key_exists($base, self::CONTAINER_AUDIO_SPECS)) {
            $specs = array_merge($specs, self::CONTAINER_AUDIO_SPECS[$base]);
        }

        if (!empty($segment))
        {
            // @todo: optionally throw errors when unknown or invalid spec is given
            foreach (preg_split(self::AUDIO_SEGMENTS_SPLIT_PATTERN, $segment) as $spec)
            {
                if (in_array($spec, self::AUDIO_CODECS)) {
                    $specs['audio_codec'] = $spec;
                }

                else if (preg_match(self::AUDIO_BITRATE_PATTERN, $spec)) {
                    $specs['audio_bitrate'] = $spec;
                }

                else if (preg_match(self::SAMPLE_RATE_PATTERN, $spec)) {
                    $specs['sample_rate'] = $spec;
                }

                else if (preg_match(self::AUDIO_CHANNEL_PATTERN, $spec)) {
                    $specs['audio_channel'] = $spec;
                }
            }
        }

        if (!empty($options))
        {
            $codec = $specs['audio_codec'];
            $optionSpecs = static::_decodeFormatOptions($container, $codec, $options);
            $specs = array_merge($specs, $optionSpecs);
        }

        return $specs;
    }

    /**
     * Parses image specification segment from format string
     *
     * @param string $container The format's output file container
     * @param string $segment The image specification segment to parse
     *
     * @return array All corresponding image specification values (implicit and explicit)
     */

    private static function _decodeFormatImageSpecs( string $container, string $segment )
    {
        // include default image specs
        $specs = array_merge([], self::DEFAULT_IMAGE_SPECS);

        // include container's default specs
        // $base = self::CONTAINER_ALIASES[$container] ?? $container;
        // if (array_key_exists($base, self::CONTAINER_SPECS)) {
        //     $specs = array_merge($specs, self::CONTAINER_SPECS[$base]);
        // }

        // @todo: optionally throw an error if segment is 'x'

        if ($segment != 'x' && $segment != '')
        {
            // normally this should just be a single resolution spec, but hey...
            foreach (explode('_', $segment) as $spec)
            {
                if (array_key_exists($spec, self::RESOLUTION_DEFINITION_SPECS)) {
                    // image formats only support the 'resolution' spec
                    $specs['resolution'] = self::RESOLUTION_DEFINITION_SPECS[$spec]['resolution'];
                }

                else if (preg_match(self::RESOLUTION_PATTERN, $spec)) {
                    $specs['resolution'] = $spec;
                }
            }
        }

        // @todo: do image containers support the `pix_fmt` or '2pass' options?

        return $specs;
    }

    /**
     * Parses options segment from format string
     *
     * @param string $container
     */

    private static function _decodeFormatOptions( string $container, string $codec, string $segment )
    {
        $options = [];

        // recognise container aliases
        $base = self::CONTAINER_ALIASES[$container] ?? $container;

        // transform options string into a map
        $map = [];
        foreach (explode(',', $segment) as $option)
        {
            $option = explode('=', $option);
            $name = $option[0];
            $value = $option[1] ?? true;

            // =common format options
            // @todo: check if common options are supported for all output types
            if ($name == '2pass') {
                $options['2pass'] = true;
            }

            if ($name == 'pix_fmt' && preg_match(self::PIX_FMT_OPTION_PATTERN, $value)) {
                $options['pix_fmt'] = $value;
            }

            // =specific container/codec format options
            $pattern = (self::CONTAINER_OPTION_PATTERNS[$base][$name] ??
                self::CODEC_OPTION_PATTERNS[$codec][$name] ?? null);

            if ($pattern)
            {
                $matches = [];

                // support boolean options
                if ($pattern === true) {
                    $options[$name] = true;
                }

                else if (preg_match($pattern, $value, $matches))
                {
                    if ($pattern != self::VIDEO_BITRATE_PATTERN || (int)$matches[1] < 200000) {
                        $options[$name] = $value;
                    } else {
                        $matches = []; // reset for next parsed options
                    }
                }
            }
        }

        // `maxrate` option is only supported if `quality` option is present
        if (array_key_exists('maxrate', $options)
            && !array_key_exists('quality', $options)
        ) {
            unset($options['maxrate']);
        }

        return $options;
    }

    /**
     * Normalizes given format specs by resolving aliases such as '720p', and removing
     * specs set to the container's default spec value
     *
     * @param array $specs Format specs to normalize
     * @param string|null $container Optional if $specs contains a 'container' key
     *
     * @return array
     */

    private static function _normalizeFormatSpecs( array $specs, string $container = null, string $type = null )
    {
        // make sure we know which output container we are working with
        if (!$container) {
            $container = $specs['container'] ?? null;
            $type = null; // force re-evaluation of container type
        }

        if (empty($container))
        {
            throw new InvalidCallException(
                "Could not determine the container from given arguments");
        }

        // get output type based on container
        if (!$type) {
            $type = static::containerType($container);
        }

        // @todo: throw error if type could not be determined

        // start collecting normalized specs
        $normalized = [
            'container' => $container,
        ];

        // resolve resolution definition
        $specs = static::_resolveDefinitionInFormatSpecs($specs, $type);

        // recognize container aliases
        $base = self::CONTAINER_ALIASES[$container] ?? $container;

        // get defaults for the container and its type (unknown type? no defaults!)
        $defaults = $type ? self::_containerFormatDefaults($base, $type) : [];

        // image containers only support the 'resolution' spec
        if ($type == 'image')
        {
            $resolution = $specs['resolution'] ?? null;

            if ($resolution && $resolution != $defaults['resolution']) {
                $normalized['resolution'] = $resolution;
            }

            return $normalized;
        }

        // collect patterns for format options supported by container
        $optionPatterns = array_merge([],
            self::CONTAINER_OPTION_PATTERNS[$base] ?? []);

        // we assume video/audio are disabled, and we check spec values
        // below to confirm this or to enable video/audio again
        $videoDisabled = true;
        $audioDisabled = true;

        // normalize video specs
        if ($type == 'video')
        {
            $videoSpecs = [];

            foreach (self::VIDEO_SPECS as $spec)
            {
                $specValue = $specs[$spec] ?? null;
                $defaultValue = $defaults[$spec] ?? null;

                // enable video as soon as one valid video spec is found
                if ($videoDisabled)
                {
                    $disabledValue = self::DISABLED_VIDEO_SPECS[$spec] ?? false;
                    if ($specValue !== $disabledValue) $videoDisabled = false;
                }

                // don't include default spec values
                if ($specValue && $specValue != $defaultValue) {
                    $videoSpecs[$spec] = $specValue;
                }
            }

            if ($videoDisabled) { // effectively disable video in normalized specs
                $normalized['video_disabled'] = true;
            }

            else
            {
                // or include non-default video specs
                $normalized = array_merge($normalized, $videoSpecs);

                // and collect patterns for format options supported by video codec
                $videoCodec = $normalized['video_codec'] ?? $defaults['video_codec'] ?? null;
                if ($videoCodec)
                {
                    $optionPatterns = array_merge($optionPatterns,
                        (self::CODEC_OPTION_PATTERNS[$videoCodec] ?? []));
                }
            }
        }

        // normalize audio specs
        if ($type == 'video' || $type == 'audio')
        {
            $audioSpecs = [];

            foreach (self::AUDIO_SPECS as $spec)
            {
                $specValue = $specs[$spec] ?? null;
                $defaultValue = $defaults[$spec] ?? null;

                // enable audio as soon as one valid video spec is found
                if ($audioDisabled)
                {
                    $disabledValue = self::DISABLED_AUDIO_SPECS[$spec] ?? false;
                    if ($specValue !== $disabledValue) $audioDisabled = false;
                }

                // don't include default spec values
                if ($specValue && $specValue != $defaultValue) {
                    $audioSpecs[$spec] = $specValue;
                }
            }

            if ($audioDisabled) { // effectively disable audio in normalized specs
                $normalized['audio_disabled'] = true;
            }

            else
            {
                // or include non-default audio specs
                $normalized = array_merge($normalized, $audioSpecs);

                // and collect patterns for format options supported by audio codec
                $audioCodec = $normalized['audio_codec'] ?? $defaults['audio_codec'] ?? null;
                if ($audioCodec)
                {
                    $optionPatterns = array_merge($optionPatterns,
                        (self::CODEC_OPTION_PATTERNS[$audioCodec] ?? []));
                }
            }
        }

        // normalize format options
        $matches = [];

        foreach (self::FORMAT_OPTIONS as $option)
        {
            // only consider given format options
            if (!array_key_exists($option, $specs)) continue;

            $optionValue = $specs[$option];

            // =common format options
            // @todo: check if common options are supported for all output types
            if ($option == '2pass' && $optionValue !== false) {
                $normalized[$option] = true;
            }

            elseif ($option == 'pix_fmt'
                && preg_match(self::PIX_FMT_OPTION_PATTERN, $optionValue)
            ) {
                $normalized[$option] = $optionValue;
            }

            // =specific container/codec format options
            else
            {
                $pattern = $optionPatterns[$option] ?? null;

                if ($pattern === true && $specs[$option]) {
                    $normalized[$option] = true;
                }

                else if ($pattern)
                {
                    $optionValue = $specs[$option];

                    if (preg_match($pattern, $optionValue, $matches))
                    {
                        if ($pattern != self::VIDEO_BITRATE_PATTERN || (int)$matches[1] < 200000) {
                            $normalized[$option] = $optionValue;
                        } else {
                            $matches = []; // reset for next parsed options
                        }
                    }
                }
            }
        }

        // `maxrate` option is only supported if `quality` option is present
        if (array_key_exists('maxrate', $normalized)
            && !array_key_exists('quality', $normalized)
        ) {
            unset($normalized['maxrate']);
        }

        return $normalized;
    }

    /**
     * Resolves resolution definition expression in given format specs
     *
     * @param array $specs Format specs to resolve
     * @param string|null $type Output container type
     *
     * @return array
     */

    private static function _resolveDefinitionInFormatSpecs( array $specs, string $type = null ): array
    {
        if (array_key_exists('resolution', $specs))
        {
            $resolution = $specs['resolution'];
            $definitionSpecs = self::RESOLUTION_DEFINITION_SPECS[$resolution] ?? null;

            if ($definitionSpecs)
            {
                if ($type == 'image') { // image's only support resolution spec
                    $specs['resolution'] = $definitionSpecs['resolution'];
                }

                else
                {
                    // don't override given specs (only new defaults)
                    foreach ($definitionSpecs as $spec => $value)
                    {
                        if ($spec == 'resolution'
                            || !array_key_exists($spec, $specs) || $specs[$spec] === null
                        ) {
                            $specs[$spec] = $value;
                        }
                    }
                }
            }
        }

        return $specs;
    }
}
