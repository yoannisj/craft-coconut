<?php
/**
 * Coconut plugin for Craft
 *
 * @author Yoannis Jamar
 * @copyright Copyright (c) 2020 Yoannis Jamar
 * @link https://github.com/yoannisj/
 * @package craft-coconut
 */

namespace yoannisj\coconut\helpers;

use yii\base\InvalidArgumentException;

use Craft;
use craft\models\Volume;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use craft\helpers\ArrayHelper;
use craft\helpers\Json as JsonHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Input;
use yoannisj\coconut\models\Output;
use yoannisj\coconut\models\Storage;
use yoannisj\coconut\models\Job;
use yoannisj\coconut\records\OutputRecord;
use yoannisj\coconut\records\JobRecord;

/**
 * Static helper class to work with coconut configs
 *
 * @todo Enable ultra-fast mode for 4K or 1080p:60s+ videos by default
 */
class JobHelper
{
    // =Constants
    // =========================================================================

    /**
     * List of format specs that are relevant for video encoding
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var string[]
     */
    const VIDEO_SPECS = [
        'video_codec', 'resolution', 'video_bitrate', 'fps',
    ];

    /**
     * Default video spec values
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var array[]
     */
    const DEFAULT_VIDEO_SPECS = [
        'resolution' => '0x0', 'video_bitrate' => '1000k', 'fps' => '0fps',
    ];

    /**
     * Format specs equivalent of unsetting all video specs
     * (i.e. no video track in output).
     *
     * @var array
     */
    const DISABLED_VIDEO_SPECS = [
        'video_codec' => false, 'resolution' => false, 'video_bitrate' => false, 'fps' => false,
    ];

    /**
     * List of video codec spec values supported by the Coconut service
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var string[]
     */
    const VIDEO_CODECS = [
        'mpeg4', 'xvid', 'flv', 'h263', 'mjpeg', 'mpeg1video', 'mpeg2video',
        'qtrle', 'svq3', 'wmv1', 'wmv2', 'huffyuv', 'rv20', 'h264', 'hevc',
        'vp8', 'vp9', 'theora', 'dnxhd', 'prores',
    ];

    /**
     * List of format specs that are relevant for audio encoding
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var string[]
     */
    const AUDIO_SPECS = [
        'audio_codec',
        'audio_bitrate',
        'sample_rate',
        'audio_channel',
    ];

    /**
     * Default audio spec values
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var array
     */
    const DEFAULT_AUDIO_SPECS = [
        'audio_bitrate' => '128k',
        'sample_rate' => '44100hz',
        'audio_channel' => 'stereo',
    ];

    /**
     * Equivalent of unsetting all audio specs (i.e. no audio)
     *
     * @var array
     */
    const DISABLED_AUDIO_SPECS = [
        'audio_codec' => false,
        'audio_bitrate' => false,
        'sample_rate' => false,
        'audio_channel' => false,
    ];

    /**
     * List of audio codec spec values supported by the Coconut service
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var string[]
     */
    const AUDIO_CODECS = [
        'mp3', 'mp2', 'aac', 'amr_nb', 'ac3', 'vorbis', 'flac',
        'pcm_u8', 'pcm_s16le', 'pcm_alaw', 'wmav2',
    ];

    /**
     * List of format specs that are relevant for image creation
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var string[]
     */
    const IMAGE_SPECS = [
        'resolution', 'pix_fmt', // '2pass',
    ];

    /**
     * Default image spec values
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var array
     */
    const DEFAULT_IMAGE_SPECS = [
        'resolution' => '0x0',
    ];

    /**
     * List of format options
     *
     * @var string[]
     */
    const FORMAT_OPTIONS = [
        'pix_fmt', '2pass', 'quality', 'vprofile', 'level', 'frag',
    ];

    /**
     * Regex pattern used to identify spec values
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var string
     */
    const RESOLUTION_PATTERN = '/^(\d+)x(\d+)$/';

    /**
     * Maps formatting video resolution definitions to video specs
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var array[]
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

    /**
     * Regex pattern used to identify video bitrate spec values
     * @see https://docs.coconut.co/references/formats#video-specs
     *
     * @var string
     */
    const VIDEO_BITRATE_PATTERN = '/^(\d{2,6})k$/';

    /**
     * Regex pattern used to identify video FPS spec values
     * @see https://docs.coconut.co/references/formats#video-specs
     *
     * @var string
     */
    const FPS_PATTERN = '/^(0|15|23\.98|25|29\.97|30)fps$/';

    /**
     * Regex pattern used to identify segments of audio format specs
     * @see https://docs.coconut.co/references/formats#audio-specs
     *
     * @var string
     */
    const AUDIO_SEGMENTS_SPLIT_PATTERN = '/(?<!amr|pcm)_/';

    /**
     * Regex pattern used to identify audio bitrate spec values
     * @see https://docs.coconut.co/references/formats#audio-specs
     *
     * @var string
     */
    const AUDIO_BITRATE_PATTERN = '/^(32|64|96|128|160|192|224|256|288|320|352|384|416|448|480|512)k$/';

    /**
     * Regex pattern used to identify sample rate spec values
     * @see https://docs.coconut.co/references/formats#audio-specs
     *
     * @var string
     */
    const SAMPLE_RATE_PATTERN = '/^(8000|11025|16000|22000|22050|24000|32000|44000|44100|48000)hz$/';

    /**
     * Regex pattern used to identify audio channel spec values
     * @see https://docs.coconut.co/references/formats#audio-specs
     *
     * @var string
     */
    const AUDIO_CHANNEL_PATTERN = '/^(mono|stereo)$/';

    /**
     * Regex pattern used to identify the pixel format option in format specs
     * @see https://docs.coconut.co/references/formats#audio-specs
     *
     * @var string
     */
    const PIX_FMT_OPTION_PATTERN = '/^(yuv420p|yuv422p|yuva444p10le)$/';

    /**
     * Regex pattern used to identify the quality option in format specs
     * @see https://docs.coconut.co/references/formats#audio-specs
     *
     * @var string
     */
    const QUALITY_OPTION_PATTERN = '/^[1-5]{1}$/';

    /**
     * Regex pattern used to identify the vprofile option in format specs
     * @see https://docs.coconut.co/references/formats#audio-specs
     *
     * @var string
     */
    const VPROFILE_OPTION_PATTERN = '/^(baseline|main|high|high10|high422|high444|444)$/';

    /**
     * Regex pattern used to identify the vprofile's prores option in format specs
     * @see https://docs.coconut.co/references/formats#audio-specs
     *
     * @var string
     */
    const VPROFILE_OPTION_PATTERN_PRORES = '/^[0-3]{1}$/';

    /**
     * Regex pattern used to identify the level option in format specs
     * @see https://docs.coconut.co/references/formats#audio-specs
     *
     * @var string
     */
    const LEVEL_OPTION_PATTERN = '/^(10|11|12|13|20|21|22|30|31|32|40|41|42|50|51)$/';

    /**
     * Map of patterns to validate supported options for each container.
     *
     * Each option can be set to a pattern to identify values supported by the
     * container, or to the boolean `true` for on/off options.
     *
     * @var array[]
     */
    const CONTAINER_OPTION_PATTERNS = [
        'mp4' => [
            'frag' => true, // boolean value
        ],
    ];

    /**
     * Map of patterns to validate supported options for each codec.
     *
     * Each option can be set to a pattern to identify values supported by the
     * container, or to the boolean `true` for on/off options.
     *
     * @var array[]
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
     * Map of container aliases and their target container.
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var array
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
     * List of input file containers (i.e. extensions) supported by
     * the Coconut service.
     *
     * @var string[]
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
     * List of video output file containers (i.e. extensions) supported by
     * the Coconut service.
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var string[]
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
     * List of audio output file containers (i.e. extensions) supported by
     * the Coconut service.
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var string[]
     */
    const AUDIO_OUTPUT_CONTAINERS = [
        'mp3', 'ogg',
    ];

    /**
     * List of image output file containers (i.e. extensions) supported by
     * the Coconut service.
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var string[]
     */
    const IMAGE_OUTPUT_CONTAINERS = [
        'jpg', 'jpeg', 'png', 'gif',
    ];

    /**
     * Default format spec values for each video file container
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var array[]
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

    /**
     * Default format spec values for each audio file container
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var array[]
     */
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

    /**
     * Default format spec values for each image file container
     * @see https://docs.coconut.co/references/formats#basics
     *
     * @var array[]
     */
    const CONTAINER_IMAGE_SPECS = [];

    /**
     * Regex patterns to parse file paths and urls
     *
     * @var string
     */
    const FILE_EXTENSION_PATTERN = '/\.([a-zA-Z0-9]{2,5}$)/';

    /**
     * Regex patterns to parse sequence options
     *
     * @var string
     */
    const SEQUENTIAL_PLACEHOLDER_PATTERN = "/%(\d+\$)?(-|\+| {1}|0|')?(\d+)?(\.\d+)?(%|[b-h]|[F-H]|o|s|u|x|X){1}/";

    /**
     * Pattern used to recognize and resolve path formats expressions
     *
     * @var string
     */
    const PATH_EXPRESSION_PATTERN = '/(?<!\{)\{([a-zA-Z0-9_]+)\}(?!\})/';

    /**
     * Pattern used to make paths private
     *
     * @var string
     */
    const PATH_PRIVATISATION_PATTERN = '/^(\.{0,2}\/)?([^_])/';

    /**
     * Pattern used to find extra separators in a path
     *
     * @var string
     */
    const PATH_EXTRA_SEPARATORS_PATTERN = '/\/+/';

    // =Public Methods
    // =========================================================================

    /**
     * Resolved given input params into an Input model.
     *
     * This method can resolve a URL string, an Asset ID, an Asset model,
     * an Input model or an array of Input model properties. Any other value
     * will return `null`
     *
     * @param mixed $input Input params to resolve
     *
     * @return Input The Input model resolved from $input
     * @return null If $input could not be resolved to an Input model
     */
    public static function resolveInput( mixed $input ): ?Input
    {
        if ($input instanceof Input) {
            return $input;
        }

        if ($input instanceof Asset) {
            return new Input([ 'asset' => $input ]);
        }

        if (empty($input)) {
            return null;
        }

        if (is_numeric($input)) {
            return new Input([ 'assetId' => (int)$input ]);
        }

        if (is_string($input)) {
            return new Input([ 'url' => $input ]);
        }

        if (is_array($input)) {
            return new Input($input);
        }

        return null;
    }

    /**
     * Resolves list of output params to Output models.
     *
     * This method can resolve a list containing format strings, arrays of
     * format specs, Output properties or Output models.
     * It can also resolve an associative array with format string keys mapped
     * to additional Output properties.
     *
     * @param array $outputs Output params to resolve
     *
     * @return Output[] Output models resolved from $outputs
     */

    public static function resolveOutputs(
        array $outputs
    ): array
    {
        if (empty($outputs)) return [];

        $resolved = [];

        // this job is still being configured
        foreach ($outputs as $formatKey => $params)
        {
            $output = null;

            // support defining output as a format string (no extra params)
            // or to define format fully in output's 'format' param (instead of in index)
            if (is_numeric($formatKey))
            {
                $output = static::resolveOutputParams($params, null);
                $resolved[$output->formatString] = $output; // use output key as index
            }

            // support multiple outputs for 1 same format
            // @see https://docs.coconut.co/jobs/api#same-output-format-with-different-settings
            else if (is_array($params) && !empty($params)
                && ArrayHelper::isIndexed($params)
            ) {
                $formatIndex = 1;

                foreach ($params as $prm)
                {
                    $output = static::resolveOutputParams($prm, $formatKey, $formatIndex++);
                    $resolved[$formatKey][] = $output;
                }
            }

            else {
                $output = static::resolveOutputParams($params, $formatKey);
                $resolved[$formatKey] = $output;
            }
        }

        return $resolved;
    }

    /**
     * Resolves given storage param into a Storage model.
     *
     * This method can resolve a named storage handle, an Asset Volume
     * an Asset Volume handle, an Asset Volume ID, or an array of Storage
     * model properties.
     *
     * @param mixed $storage Storage params to resolve
     *
     * @return Storage Storage model resolved from $storage
     * @return null If $storage could not be resolved to a Storage model
     */
    public static function resolveStorage( mixed $storage ): ?Storage
    {
        if ($storage instanceof Storage) {
            return $storage;
        }

        else if (is_array($storage)) {
            return new Storage($storage);
        }

        else if (is_numeric($storage))
        {
            $storage = Craft::$app->getVolumes()
                ->getVolumeById($storage);
        }

        else if (is_string($storage))
        {
            $handle = $storage;

            // check if this is a named storage handle
            $storage = Coconut::$plugin->getStorages()
                ->getNamedStorage($handle);

            if ($storage) return $storage;

            // or, assume this is a volume handle
            $storage = Craft::$app->getVolumes()
                ->getVolumeByHandle($handle);
        }

        if ($storage instanceof Volume)
        {
            return Coconut::$plugin->getStorages()
                ->getVolumeStorage($storage);
        }

        return null;
    }

    /**
     * Resolves given Output params into an Output model
     *
     * @param $params Output params to resolve
     * @param string $formatKey The Output's format key
     *
     * @return Output The Output model resolved from $params and $formatKey
     * @return null If the Output model could not be resolved
     *
     * @throws InvalidArgumentException If given multiple outputs and one of them is not a format string, array of params or output model
     */
    public static function resolveOutputParams(
        mixed $params,
        string $formatKey = null,
        int $formatIndex = null
    ): Output
    {
        if (!$formatKey)
        {
            if (is_string($params))
            {
                $params =[
                    'format' => static::decodeFormat($params)
                ];
            }
        }

        $isArray = is_array($params);
        $isModel = ($isArray == false && ($params instanceof Output));

        if (!$isArray && !$isModel)
        {
            throw new InvalidArgumentException(
                "Each output must be a format string, an array of output params or an Output model");
        }

        $output = null;

        // merge format specs from output index with output params
        $keySpecs = $formatKey ? static::decodeFormat($formatKey) : [];
        $container = $keySpecs['container'] ?? null; // index should always define a container
        $paramSpecs = ArrayHelper::getValue($params, 'format');

        if (is_array($paramSpecs))
        {
            if ($container) $paramSpecs['container'] = $container;
            $paramSpecs = static::parseFormat($paramSpecs);
        }

        else if (is_string($paramSpecs)) { // support defining 'format' param as a JSON or format string
            $paramSpecs = static::decodeFormat($paramSpecs);
            if ($container) $paramSpecs['container'] = $container;
        }


        // @todo: should index specs override param specs?
        $formatSpecs = array_merge($keySpecs, $paramSpecs ?? []);

        if ($isArray)
        {
            $params['format'] = $formatSpecs;
            $output = new Output($params);
        }

        else {
            $output = $params;
            $output->format = $formatSpecs;
        }

        if ($output && $formatIndex) {
            $output->formatIndex = $formatIndex;
        }

        return $output;
    }

    /**
     * Rewrites and output's routing URL to be based on given Volume.
     *
     * @param Output $output
     * @param Volume $volume
     *
     * @return bool Whether the output URL(s) was/were changed
     */
    public static function rewriteOutputUrls( Output &$output, Volume $volume ): bool
    {
        $url = $output->url;
        $output->url = static::rewriteOutputUrl($url, $volume);
        $changed = ($output->url != $url);

        $urls = $output->getUrls();
        if (!empty($urls))
        {
            $newUrls = [];

            foreach ($urls as $url)
            {
                $newUrl = static::rewriteOutputUrl($url, $volume);
                if (!$changed) $changed = ($newUrl != $url);

                $newUrls[] = $newUrl;
            }

            $output->setUrls($newUrls);
        }

        return $changed;
    }

    /**
     * Rewrites an output URL to be based on given Volume.
     *
     * Note that the URL will only be changed if it is based on the plugin's
     * Output routing URL.
     *
     * @param ?string $url
     * @param Volume $volume
     *
     * @return string|null The rewritten URL, or null if it is empty
     */
    public static function rewriteOutputUrl( ?string $url, Volume $volume ): ?string
    {
        if (!$url) return null;

        $volumeFs = $volume->getFs();
        if (!$volumeFs->hasUrls) return null;

        $rootUrl = rtrim($volumeFs->getRootUrl(), '/');
        if (strncmp($rootUrl, $url, mb_strlen($rootUrl)) === 0) {
            return $url; // URL is already based on volume's file-system
        }

        $trigger = '/coconut/outputs/'.$volume->handle;

        // if the URL is not based on the output route
        if (strpos($url, $trigger) === false) {
            return $url; // leave it unchanged
        }

        // remove trigger base from output path
        $urlParts = explode($trigger, $url);
        array_shift($urlParts);
        $path = implode($trigger, $urlParts);

        if (!$path) {
            return null; // maybe there is nothing left?
        }

        // preserve URL params
        $params = [];
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        // @todo Add timestamp param to the URL in an `Output::getUrl()` method

        // encode URL path
        $pathParts = explode('/', ltrim($path, '/'));
        $path = implode('/', array_map('rawurlencode', $pathParts));

        return UrlHelper::urlWithParams($rootUrl.'/'.$path, $params);
    }

    /**
     * Parses given format string or array of format specs, by analyzing and
     * validating spec values against the format container, and discarding
     * invalid and irrelevant values.
     *
     * ::: warning
     * If `$format` is given as an array of specs, make sure you include the
     * 'container' key.
     * :::
     *
     * @param string|array $format Format specs to parse
     *
     * @return array Parsed specs for given $format
     *
     * @throws InvalidArgumentException If given array of $format specs is missing the 'container' key
     */
    public static function parseFormat( string|array $format ): array
    {
        if (is_string($format)) {
            $format = static::decodeFormat($format);
        }

        else if (!array_key_exists('container', $format))
        {
            throw new InvalidArgumentException(
                "Array of format specs must include the 'container' key");
        }

        // else if (!is_array($format))
        // {
        //     throw new InvalidArgumentException(
        //         'Argument #1 `$format` must be a format string or an array of format specs');

        // }

        return static::_normalizeFormatSpecs($format);
    }

    /**
     * Parses given format string, and returns the corresponding map of
     * (explicit and implicit) spec values
     *
     * @param string $format Format string to parse
     *
     * @return array Parsed specs for given $format
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
     * Returns format string for given format specs.
     *
     * ::: tip
     * If the format $specs don't specify the container, you must pass it in
     * separately as 2nd argument.
     * :::
     *
     * @param array $specs Formatting specs to encode
     * @param string|null $container Format container
     *
     * @return string Format string for given $specs
     *
     * @throws InvalidArgumentException If the format container could not be determined
     */
    public static function encodeFormat(
        array $specs,
        string $container = null
    ): string
    {
        if (!$container) {
            $container = $specs['container'] ?? null;
        }

        if (empty($container)) {
            throw new InvalidArgumentException("Could not resolve container from given arguments");
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
            return rtrim($container.':'.$videoSegment.':'.$audioSegment.':'.$optionsSegment, ':');
        }

        if ($type == 'audio') {
            return rtrim($container.':'.$audioSegment.':'.$optionsSegment, ':');
        }

        return $container;
    }

    /**
     * Returns file extension for given format specs.
     *
     * @param string|array $format Format specs for which to get the extension
     *
     * @return string The file extension for $format
     * @return null If the format's file extension is unknown
     *
     * @throws InvalidArgumentException If the format container could not be determined
     */
    public static function formatExtension( string|array $format )
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
     * Returns file extension for given container.
     *
     * @param string $container Container for which to get the file extension
     *
     * @return string The file extension for $container
     * @return null If container's file extension is unknown
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
     * @param string $container Container for which to get the media type
     *
     * @return string Either 'video', 'audio' or 'image'
     * @return null If the container is of another or unknown file type
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
     * @return array Array of default format specs for $container
     */
    public static function containerFormatDefaults( string $container ): array
    {
        $base = self::CONTAINER_ALIASES[$container] ?? $container;
        $type = static::containerType($container);

        return static::_containerFormatDefaults($base, $type);
    }

    /**
     * Returns the key-friendly version of given format string
     *
     * @param string $format The format string
     *
     * @return string A key-friendly version of given format
     */
    public static function formatAsKey( string $format )
    {
        return str_replace(
            [':', '=', ','],
            ['-', '_', '__'],
        $format);
    }

    /**
     * Returns the key for given format path
     *
     * @param string $path The format path (as returned by `JobHelper::keyPath()`)
     *
     * @return string The corresponding format key
     */
    // public static function keyFromPath( string $path )
    // {
    //     return str_replace(
    //         ['-', '_'],
    //         [':', '='],
    //     str_replace('__', ',', $path)); // don't replace '__' with '=='
    // }

    /**
     * Turns given file path private, so Craft-CMS does not index it the file
     * an Asset if it is found on a Volume.
     *
     * @param string $path File path to privatise
     *
     * @return string Private version of $path
     */
    public static function privatisePath( string $path ): string
    {
        return preg_replace(self::PATH_PRIVATISATION_PATTERN, '$1_$2', $path);
    }

    /**
     * Normalizes given file path.
     *
     * @param string $path File path to normalize
     *
     * @return string Normalized version of $path
     */
    public static function normalizePath( string $path ): string
    {
        return trim(preg_replace(static::PATH_EXTRA_SEPARATORS_PATTERN, '/', $path), '/');
    }

    /**
     * Adds a sequential placeholder to given path, so Coconut knows where to
     * insert the output file's format index.
     *
     * @param string $path File path to transform
     *
     * @return string Version of $path with sequential placeholder suffix
     */
    public static function sequencePath( string $path ): string
    {
        if (!preg_match(static::SEQUENTIAL_PLACEHOLDER_PATTERN, $path))
        {
            $path = (
                pathinfo($path, PATHINFO_DIRNAME).'/'.
                pathinfo($path, PATHINFO_FILENAME).
                '-%02d.'.pathinfo($path, PATHINFO_EXTENSION)
            );
        }

        return $path;
    }

    /**
     * Creates a Craft Action URL that can be visited by external services
     *
     * @param string $action Action ID segments
     * @param array $params Action parameters to include in the URL
     * @param string|null $scheme Whether to include the URL scheme (i.e. 'http' or 'https')
     *
     * @return string
     */
    public static function publicActionUrl(
        string $action,
        array $params = null,
        string $scheme = null
    ): string
    {
        // always include script name to avoid 404s trying to render a template
        return static::publicUrl(
            UrlHelper::actionUrl($action, $params, $scheme, true));
    }

    /**
     * Turns given URL public by replacing it's base wtih the
     * 'publicBaseUrl' setting
     *
     * @param string $url
     *
     * @return string
     */
    public static function publicUrl( string $url ): string
    {
        $publicBaseUrl = Coconut::$plugin->getSettings()->publicBaseUrl;

        if (!empty($publicBaseUrl))
        {
            if (UrlHelper::isRootRelativeUrl($url)) {
                return rtrim($publicBaseUrl, '/').'/'.ltrim($url, '/');
            }

            // is this a Craft URL?
            $baseSiteUrl = UrlHelper::baseSiteUrl();
            $baseCpUrl = UrlHelper::baseCpUrl();

            // (compare hosts to work around scheme inconsistencies)
            $protoCpBase = rtrim(preg_replace('/(http|https):\/\//', '://', $baseCpUrl), '/');
            $protoSiteBase = rtrim(preg_replace('/(http|https):\/\//', '://', $baseSiteUrl), '/');

            // (get a replacement pattern based on craft url type)
            $replacePattern = null;
            if (strpos($url, $protoCpBase) !== false) {
                $replacePattern = str_replace('/', '\/', preg_quote($protoCpBase));
            } else if (strpos($url, $protoSiteBase) !== false) {
                $replacePattern = str_replace('/', '\/', preg_quote($protoSiteBase));
            }

            if ($replacePattern) { // replace with public version of base URL
                $replacePattern = '/(http|https)?'.$replacePattern.'/';
                $url = preg_replace($replacePattern, rtrim($publicBaseUrl, '/'), $url);
            }
        }

        else if (UrlHelper::isRootRelativeUrl($url)) {
            // prepend base URL so it can be visited from external services
            $url = rtrim(UrlHelper::baseUrl(), '/').'/'.ltrim($url, '/');
        }

        return $url;
    }

    /**
     * Populates job model with given data (e.g. returned by API)
     *
     * @param Job $job
     * @param array $data
     *
     * @return Job
     */
    public static function populateJobFromData( Job $job, array $data ): Job
    {
        $dataType = ArrayHelper::remove($data, 'type');

        if ($dataType && $dataType != 'job')
        {
            throw new InvalidArgumentException(
                "Can not update job with data of type '$dataType'");
        }

        // move 'id' key to 'coconutId'
        $id = ArrayHelper::remove($data, 'id');
        $job_id = ArrayHelper::remove($data, 'job_id');
        $coconutId = $job_id ?: $id;
        if ($coconutId) $data['coconutId'] = $coconutId;

        // rename date keys
        $createdAt = ArrayHelper::remove($data, 'created_at');
        if ($createdAt) $data['createdAt'] = $createdAt;

        $completedAt = ArrayHelper::remove($data, 'completed_at');
        if ($completedAt) $data['completedAt'] = $completedAt;

        // get job progress to fill-in missing output progress
        // (some output notifications arrive after the job completion
        //  notification and will be ignored..)
        $progress = $data['progress'] ?? null;

        // remove 'null' input keys
        // (those would otherwise override valid input on job model)
        $inputData = ArrayHelper::remove($data, 'input');
        if ($inputData) $data['input'] = array_filter($inputData);

        // update job outputs with data received from Coconut
        $outputsData = ArrayHelper::remove($data, 'outputs');
        if ($outputsData)
        {
            foreach ($outputsData as $outputData)
            {
                $outputKey = ArrayHelper::getValue($outputData, 'key');
                if (!$outputKey)
                {
                    throw new InvalidArgumentException(
                        'Given data is missing the output key');
                }

                $output = $job->getOutputByKey($outputKey);
                if (!$output)
                {
                    // @todo throw error for invalid key?
                    // add new output to job based on given data
                    // @todo get output format from job params and key?
                    $output = new Output([
                        'job' => $job,
                        'key' => $outputKey,
                    ]);

                    $job->addOutput($output);
                }

                // if job has completed it means all outputs have completed too
                if ($progress == '100%') $outputData['progress'] = '100%';

                static::populateJobOutput($output, $outputData);
            }
        }

        // update job with rest of the data received from Coconut
        $job = static::populateObject($job, $data, true);

        return $job;
    }

    /**
     * Populates Output model with given output data
     *
     * @param Output $output Output to populate
     * @param array $data Date to populate output with
     *
     * @return InvalidArgumentException If given $data's 'type' is not a valid Output type
     */
    public static function populateJobOutput( Output $output, array $data ): Output
    {
        $dataType = ArrayHelper::remove($data, 'type');

        if ($dataType && $dataType != Output::TYPE_VIDEO
            && $dataType != Output::TYPE_AUDIO
            && $dataType != Output::TYPE_IMAGE
            && $dataType != Output::TYPE_HTTPSTREAM)
        {
            throw new InvalidArgumentException(
                "Can not populate job output with data of type '$dataType'");
        }

        return static::populateObject($output, $data);
    }

    /**
     * Transfers data from given Job Record to Job Model.
     *
     * @param Job $job Job model to populate
     * @param JobRecord $record Job record from which to get populated data
     *
     * @return Job
     */
    public static function populateJobFromRecord( Job $job, JobRecord $record ): Job
    {
        $attrs = $record->getAttributes();
        $inputAssetId = ArrayHelper::remove($attrs, 'inputAssetId');
        $inputUrl = ArrayHelper::remove($attrs, 'inputUrl');

        if ($inputAssetId || $inputUrl)
        {
            $input = new Input([
                'assetId' => $inputAssetId,
                'url' => $inputUrl,
                // 'urlHash' => ArrayHelper::remove($attrs, 'inputUrlHash'),
                'status' => ArrayHelper::remove($attrs, 'inputStatus'),
                'metadata' => ArrayHelper::remove($attrs, 'inputMetadata'),
                'error' => ArrayHelper::remove($attrs, 'inputError'),
                'expires' => ArrayHelper::remove($attrs, 'inputExpires'),
            ]);

            $job->setInput($input);
        }

        $storageParams = ArrayHelper::remove($attrs,'storageParams');
        $storageHandle = ArrayHelper::remove($attrs, 'storageHandle');
        $storageVolumeId = ArrayHelper::remove($attrs,'storageVolumeId');

        if ($storageParams)
        {
            if (is_string($storageParams)) {
                $storageParams = JsonHelper::decode($storageParams);
            }

            $storage = new Storage(array_merge($storageParams, [
                'handle' => $storageHandle,
                'volumeId' => $storageVolumeId,
            ]));

            $job->setStorage($storage);
        }

        else {
            // will be resolved into a Storage model when needed
            $job->setStorage($storageHandle ?: $storageVolumeId);
        }

        // $notification = ArrayHelper::remove($attrs, 'notification');
        // if ($notification) $job->setNotification($notification);

        $job->setAttributes($attrs, false);

        return $job;
    }

    /**
     * Transfers data from given Job Model to Job Record.
     *
     * @param JobRecord $record Job record to populate
     * @param Job $job Job model from which to get populated data
     *
     * @return JobRecord
     */
    public static function populateRecordFromJob( JobRecord $record, Job $job): JobRecord
    {
        $attrs = $job->getAttributes();
        $input = $job->getInput();
        $storage = $job->getStorage();

        if ($input)
        {
            $record->inputAssetId = $input->assetId;
            $record->inputUrl = $input->url;
            $record->inputUrlHash = $input->urlHash;
            $record->inputStatus = $input->status;
            $record->inputMetadata = $input->metadata;
            $record->inputExpires = $input->expires;
            $record->inputError = $input->error;
        }

        if ($storage)
        {
            $record->storageHandle = $storage->handle;
            $record->storageVolumeId = $storage->volumeId;
            $record->storageParams = $storage->toParams();
        }

        $record->setAttributes($attrs, false);

        return $record;
    }

    /**
     * Creates a transcoding Job config from given Job Model.
     * The resutl can be used to run a new transcoding Job.
     *
     * @param Job $job Job from which to get config.
     *
     * @return Job
     */
    public static function jobAsConfig( Job $job ): Job
    {
        $attrs = $job->getAttributes([
            'input',
            'outputPathFormat',
            'storage',
            'notification',
        ]);

        $config = new Job();
        $config->setAttributes($attrs, false);

        $configOutputs = [];

        foreach ($job->getOutputs() as $output) {
            $configOutputs[] = static::outputAsConfig($output);
        }

        $config->outputs = $configOutputs;

        return $config;
    }

    /**
     * Populates Output Model with data from Output Record.
     *
     * @param Output $output Output Model to populate
     * @param OutputRecord $record Output Record from which to get populated data
     *
     * @return Output
     */
    public static function populateOutputFromRecord(
        Output $output,
        OutputRecord $record
    ): Output
    {
        $attrs = $record->getAttributes();

        // remove readonly attrs that are searchable (therefore saved in the DB)
        unset($attrs['type']);

        $output->setAttributes($attrs, false);

        return $output;
    }

    /**
     * Populates Output Record with data from given Output Model.
     *
     * @param OutputRecord $record Output Record to populate
     * @param Output $output Output Model from which to get populated data
     *
     * @return OutputRecord
     */
    public static function populateRecordFromOutput(
        OutputRecord $record,
        Output $output
    ): OutputRecord
    {
        $attrs = $output->getAttributes();
        $record->setAttributes($attrs, false);

        return $record;
    }

    /**
     * Returns an Output config based on given Output.
     *
     * The result can be used to transcode a new Output (via a new Job Model).
     *
     * @param Output $output Output to get config from
     *
     * @return Output
     */
    public static function outputAsConfig( Output $output ): Output
    {
        $attrs = $output->getAttributes([
            'key',
            'format',
            'formatIndex',
            'path',
            'if',
            'deinterlace',
            'square',
            'blur',
            'fit',
            'transpose',
            'vflip',
            'hflip',
            'offset',
            'duration',
            'number',
            'interval',
            'offsets',
            'sprite',
            'vtt',
            'scene',
            'watermark',
        ]);

        $config = new Output();
        $config->setAttributes($attrs, false);

        return $config;
    }

    /**
     * Populates object with given data.
     *
     * @param array|object $object Object to populate
     * @param array $data Data to populate $object with
     * @param bool $recursive Whether values should be merged recursively
     *
     * @return array|object
     */
    public static function populateObject(
        array|object $object,
        array $data,
        bool $recursive = true
    ): array|object
    {
        // if (!is_object($object) && !is_array($object)) {
        //     throw new InvalidArgumentException(
        //         "Argument #2 `object` must be an object or array to populate");
        // }

        // if (!is_object($data) && !is_array($data))
        // {
        //     throw new InvalidArgumentException(
        //         'Argument #2 `data` must be an array or object to traverse');
        // }

        foreach ($data as $prop => $value)
        {
            if ($recursive
                && (is_object($value) || ArrayHelper::isAssociative($value))
                && ($currValue = ArrayHelper::getValue($object, $prop))
                && (is_object($currValue) || ArrayHelper::isAssociative($currValue))
            ) {
                ArrayHelper::setValue($object, $prop,
                    static::populateObject($currValue, $value, $recursive));
            }

            else {
                ArrayHelper::setValue($object, $prop, $value);
            }
        }

        return $object;
    }

    /**
     * Removes empty values from given params. Similar to php's `array_filter`
     * function but recursive.
     *
     * @param array $params Params to clean
     *
     * @return array $params without keys that had empty values.
     */
    public static function cleanParams( array $params ): array
    {
        $res = [];

        foreach ($params as $key => $value)
        {
            if (ArrayHelper::isAssociative($value)) {
                $value = static::cleanParams($value);
            }

            if (!empty($value)) $res[$key] = $value;
        }

        return $res;
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
    private static function _containerFormatDefaults(
        string $container,
        string $type
    ): array
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
     * Parses video spec segment from format string
     *
     * @param string $container The format's output file container
     * @param string $segment The video spec segment to parse
     * @param string|null $options The format options segment to parse
     *
     * @return array All corresponding video spec values (implicit and explicit)
     */
    private static function _decodeFormatVideoSpecs(
        string $container,
        string $segment,
        string $options = null
    ): array
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
     * Parses audio spec segment from format string.
     *
     * @param string $container The format's output file container
     * @param string $segment The audio spec segment to parse
     *
     * @return array All corresponding audio spec values (implicit and explicit)
     */
    private static function _decodeFormatAudioSpecs(
        string $container,
        string $segment = null
    ): array
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
     * Parses image spec segment from format string
     *
     * @param string $container The format's output file container
     * @param string $segment The image spec segment to parse
     *
     * @return array All corresponding image spec values (implicit and explicit)
     */
    private static function _decodeFormatImageSpecs(
        string $container,
        string $segment
    ): array
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

        // @todo Check if image containers support the `pix_fmt` or '2pass' options?

        return $specs;
    }

    /**
     * Parses options segment from format string
     *
     * @param string $container
     * @param string $codec
     * @param string $segment
     *
     * @return array
     */
    private static function _decodeFormatOptions(
        string $container,
        string $codec,
        string $segment
    ): array
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
    private static function _normalizeFormatSpecs(
        array $specs,
        string $container = null,
        string $type = null
    ): array
    {
        // make sure we know which output container we are working with
        if (!$container) {
            $container = $specs['container'] ?? null;
            $type = null; // force re-evaluation of container type
        }

        if (empty($container))
        {
            throw new InvalidArgumentException(
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
    private static function _resolveDefinitionInFormatSpecs(
        array $specs,
        string $type = null
    ): array
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
