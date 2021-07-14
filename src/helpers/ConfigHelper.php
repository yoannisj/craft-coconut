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
use craft\helpers\FileHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Storage;

/**
 * Static helper class to work with coconut configs
 */

class ConfigHelper
{
    // =Constants
    // -------------------------------------------------------------------------

    /**
     * List of format specs that are relevant for video encoding
     * @see https://docs.coconut.co/references/formats#basics
     */

    const VIDEO_SPECS = [
        'video_codec', 'resolution', 'video_bitrate', 'fps',
        'pix_fmt', '2pass', 'frag', 'vprofile', 'level', 'quality', 'maxrate',
    ];

    /**
     * List of format specs that are relevant for audio encoding
     * @see https://docs.coconut.co/references/formats#basics
     */

    const AUDIO_SPECS = [
        'audio_codec', 'audio_bitrate', 'sample_rate', 'audio_channel',
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
     * List of video codec spec values supported by the Coconut service
     * @see https://docs.coconut.co/references/formats#basics
     */

    const VIDEO_CODECS = [
        'mpeg4', 'xvid', 'flv', 'h263', 'mjpeg', 'mpeg1video', 'mpeg2video',
        'qtrle', 'svq3', 'wmv1', 'wmv2', 'huffyuv', 'rv20', 'h264', 'hevc',
        'vp8', 'vp9', 'theora', 'dnxhd', 'prores',
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
     * List of format options
     */

    const FORMAT_OPTIONS = [
        'pix_fmt', '2pass', 'frag', 'quality', 'vprofile', 'level',
    ];

    /**
     * Regex patterns used to identify specification values
     * @see https://docs.coconut.co/references/formats#basics
     */

    // =common
    const RESOLUTION_PATTERN = '/^\d+x\d+$/';
    const RESOLUTION_DEFINITION_PATTERN = '/^(240|360p|480p|720p|1080p|2160p)p$/';

    // =video
    const VIDEO_BITRATE_PATTERN = '/^\d{2,6}k$/';
    const FPS_PATTERN = '/^(0fps|15fps|23.98fps|25fps|29.97fps|30fps)$/';

    // =audio
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
        'v8' => [
            'quality' => self::QUALITY_OPTION_PATTERN,
            'maxrate' => self::VIDEO_BITRATE_PATTERN,
        ],
        'v9' => [
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
     * Default specification values for video encoding
     * @see https://docs.coconut.co/references/formats#basics
     */

    const DEFAULT_VIDEO_SPECS = [
        'resolution' => '0x0', 'video_bitrate' => '1000k', 'fps' => '0fps',
    ];

    /**
     * Default specification values for audio encoding
     * @see https://docs.coconut.co/references/formats#basics
     */

    const DEFAULT_AUDIO_SPECS = [
        'audio_bitrate' => '44100hz', 'sample_rate' => '128k', 'audio_channel' => 'stereo',
    ];

    /**
     * Default specification values for image creation
     * @see https://docs.coconut.co/references/formats#basics
     */

    const DEFAULT_IMAGE_SPECS = [
        'resolution' => '0x0',
    ];

    /**
     * Default format specification values for given container
     * @see https://docs.coconut.co/references/formats#basics
     */

    const CONTAINER_SPECS = [
        // =video
        'mp4' => [ 'video_codec' => 'h264', 'audio_codec' => 'aac' ],
        'webm' => [ 'video_codec' => 'vp8', 'audio_codec' => 'vorbis' ],
        'avi' => [ 'video_codec' => 'mpeg4', 'audio_codec' => 'mp3' ],
        'asf' => [ 'video_codec' => 'wmv2', 'audio_codec' => 'wmav2' ],
        'mpegts' => [ 'video_codec' => 'h264', 'audio_codec' => 'aac' ],
        'mov' => [ 'video_codec' => 'h364', 'audio_codec' => 'aac' ],
        'flv' => [ 'video_codec' => 'flv', 'audio_codec' => 'mp3' ],
        'mkv' => [ 'video_codec' => 'h264', 'audio_codec' => 'aac' ],
        '3gp' => [ 'video_codec' => 'h263', 'audio_codec' => 'aac', 'sample_rate' => '32k' ],
        'ogv' => [ 'video_codec' => 'theora', 'audio_codec' => 'vorbis' ],

        // =audio
        'ogg' => [ 'audio_codec' => 'vorbis' ],
        'mp3' => [ 'audio_codec' => 'mp3' ],
    ];

    /**
     * Maps formatting video resolution definitions to video specs
     * @see https://docs.coconut.co/references/formats#basics
     */

    const RESOLUTION_DEFINITION_SPECS = [
        '240p' => [ 'resolution' => '0x240', 'video_bitrate' => '500K' ],
        '360p' => [ 'resolution' => '0x360', 'video_bitrate' => '800K' ],
        '480p' => [ 'resolution' => '0x480', 'video_bitrate' => '1000k' ],
        '540p' => [ 'resolution' => '0x540', 'video_bitrate' => '1000k' ], // @todo: verify width of 540p resolution
        '576p' => [ 'resolution' => '0x576', 'video_bitrate' => '1000k' ], // @todo: verify width of 576p resolution
        '720p' => [ 'resolution' => '1280x720', 'video_bitrate' => '2000k' ],
        '1080p' => [ 'resolution' => '1920x1080', 'video_bitrate' => '4000k' ],
        '2140p' => [ 'resolution' => '3840x2140', 'video_bitrate' => '8000k' ],  // @todo: verify width of 2140p resolution
        '2160p' => [ 'resolution' => '3840x2160', 'video_bitrate' => '8000k' ],
    ];
    
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

    // =Methods
    // -------------------------------------------------------------------------

    /**
     * @param string|array|VolumeInterface $storage
     * 
     * @return Storage|null
     */

    public static function parseStorage( $storage )
    {
        if (is_string($storage))
        {
            // check if this is a named storage handle
            $storage = Coconut::$plugin->getSettings()
                ->getNamedStorage($storage);
            
            if (!$storage) { // or, assume this is a volume handle
                $storage = Craft::$app->getVolumes()
                    ->getVolumeByHandle($storage);
            }
        }

        if ($storage instanceof VolumeInterface) {
            return Coconut::$plugin->resolveVolumeStorage($storage);
        }

        if (is_array($storage))
        {
            if (!array_key_exists('class', $storage)) {
                $storage['class'] = Storage::class;
            }

            return Craft::createObject($storage);
        }

        return null;
    }

    /**
     * 
     */

    public static function parseFormat( $format ): array
    {
        if (is_string($format)) {
            return static::decodeFormat($format);
        }


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
        $type = static::outputContainerType($container);

        $specs = [
            'container' => $container,
        ];

        switch ($type)
        {
            case 'video':
                $options = $segments[3] ?? '';
                $specs = array_merge( $specs,
                    static::parseFormatVideoSpecs($container, $segments[1] ?? '', $options),
                    static::parseFormatAudioSpecs($container, $segments[2] ?? '', $options)
                );
                break;
            case 'audio':
                $specs = array_merge( $specs,
                    static::parseFormatAudioSpecs($container, $segments[1] ?? ''));
                break;
            case 'image':
                $specs = array_merge( $specs,
                    static::parseFormatImageSpecs($container, $segments[1] ?? ''));
                break;
        }

        return $specs;
    }

    /**
     * Returns the media type of given output file container
     * 
     * @return string|null
     */

    public function outputContainerType( $container )
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
     * Parses image specification segment from format string
     * 
     * @param string $container The format's output file container
     * @param string $segment The image specification segment to parse
     * 
     * @return array All corresponding image specification values (implicit and explicit)
     */

    public static function parseFormatImageSpecs( string $container, string $segment )
    {
        // include default image specs
        $specs = array_merge([], self::DEFAULT_IMAGE_SPECS);

        // include container's default specs
        $baseContainer = self::CONTAINER_ALIASES[$container] ?? $container;
        if (in_array($baseContainer, self::CONTAINER_SPECS)) {
            $specs = array_merge($specs, self::CONTAINER_SPECS[$baseContainer]);
        }

        if ($segment != 'x')
        {
            // normally this should just be a single resolution spec, but hey...
            foreach (explode('_', $segment) as $spec)
            {
                if (array_key_exists($spec, self::RESOLUTION_DEFINITION_SPECS)) {
                    // image formats only support the 'resolution' spec
                    $specs['resolution'] = self::RESOLUTION_DEFINITION_SPECS[$spec]['resolution'];
                }

                else if (preg_match(self::RESOLUTION_DEFINITION_PATTERN, $spec)) {
                    $data['specs']['resolution'] = $spec;
                }
            }
        }

        return $specs;
    }

    /**
     * Parses video specification segment from format string
     * 
     * @param string $container The format's output file container
     * @param string $segment The video specification segment to parse
     * @param string $options The format options segment to parse
     * 
     * @return array All corresponding video specification values (implicit and explicit)
     */

    public static function parseFormatVideoSpecs( string $container, string $segment, string $options = null )
    {
        // support disabling video by using 'x'
        if ($segment == 'x') return [];

        // include default video specs
        $specs = array_merge([], self::DEFAULT_VIDEO_SPECS);

        // include container's default specs
        $base = self::CONTAINER_ALIASES[$container] ?? $container;
        if (in_array($base, self::CONTAINER_SPECS)) {
            $specs = array_merge($specs, self::CONTAINER_SPECS[$base]);
        }

        foreach (explode('_', $segment) as $spec)
        {
            if (array_key_exists($spec, self::RESOLUTION_DEFINITION_SPECS)) {
                $specs = array_merge($specs, self::RESOLUTION_DEFINITION_SPECS[$spec]);
            }

            else if (preg_match(self::RESOLUTION_PATTERN, $spec)) {
                $specs['resolution'] = $spec;
            }

            else if (in_array($spec, self::VIDEO_CODECS)) {
                $specs['video_codec'] = $spec;
            }

            else if (preg_match(self::VIDEO_BITRATE_PATTERN, $spec)) {
                $specs['video_bitrate'] = $spec;
            }

            else if (preg_match(self::FPS_PATTERN, $spec)) {
                $specs['fps'] = $spec;
            }
        }

        if (!empty($options))
        {
            $codec = $specs['video_codec'];
            $optionSpecs = static::parseFormatOptions($container, $codec, $options);
            $specs = array_merge($specs, $optionSpecs);
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

    public function parseFormatAudioSpecs( string $container, string $segment = null )
    {
        // support disabling audio by using 'x'
        if ($segment == 'x') return [];

        // include default specs
        $specs = array_merge([], self::DEFAULT_AUDIO_SPECS);

        // include container's default specs
        $base = self::CONTAINER_ALIASES[$container] ?? $container;
        if (in_array($base, self::CONTAINER_SPECS)) {
            $specs = array_merge($specs, self::CONTAINER_SPECS[$base]);
        }

        foreach (explode('_', $segment) as $spec)
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

        if (!empty($options))
        {
            $codec = $specs['audio_codec'];
            $optionSpecs = static::parseFormatOptions($container, $codec, $options);
            $specs = array_merge($specs, $optionSpecs);
        }

        return $specs;
    }

    /**
     * Parses options segment from format string
     * 
     * @param string $container 
     */

    public function parseFormatOptions( string $container, string $codec, string $segment )
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

            // common format options
            if ($name == '2pass' && $value) {
                $options['2pass'] = true;
            }

            if ($name == 'pix_fmt' && preg_match(self::PIX_FMT_OPTION_PATTERN, $value)) {
                $options['pix_fmt'] = $value;
            }

            // container/codec specific format options
            $pattern = (self::CONTAINER_OPTION_PATTERNS[$base][$name] ?? 
                self::CODEC_OPTION_PATTERNS[$codec][$name] ?? null);

            if ($pattern)
            {
                // support boolean options
                if ($pattern === true) {
                    $options[$name] = (bool)$value;
                } else if (preg_match($pattern, $value)) {
                    $options[$name] = $value;
                }
            }
        }

        return $options;
    }

    /**
     * Returns formatted string for given format specs
     * 
     * @param string|array $specs Formatting specs to encode
     * @param string|null $path Path to output
     * 
     * @return string
     */

    public static function encodeFormat( $specs, string $path = null ): string
    {
        if (is_string($specs)) {
            $specs = static::decodeFormat($specs);
        }

        $specs = array_filter($specs);
        $container = $specs['container'] ?? ($path ? pathinfo($path, PATHINFO_EXTENSION) : null);

        if (empty($container)) {
            throw new InvalidCallException("Could not resolve container from given arguments");
        }

        // resolve resolution definition specs
        $resolution = $specs['resolution'] ?? null;
        if (array_key_exists($resolution, self::RESOLUTION_DEFINITION_SPECS)) {
            $specs = array_merge($specs, self::RESOLUTION_DEFINITION_SPECS[$resolution]);
        }

        $type = static::outputContainerType($container);

        // image containers only support the `resolution` spec
        if ($type == 'image')
        {
            $resolution = $specs['resolution'] ?? null;
            return trim(':', $container.':'.$resolution);
        }

        $videoSpecs = [];
        $audioSpecs = [];
        $formatOptions = [];

        foreach (self::VIDEO_SPECS as $spec) {
            if (array_key_exists($spec, $specs)) {
                $videoSpecs[] = $specs[$spec];
            }
        }

        foreach (self::AUDIO_SPECS as $spec) {
            if (array_key_exists($spec, $specs)) {
                $audioSpecs[] = $specs[$spec];
            }
        }

        foreach (self::FORMAT_OPTIONS as $option) {
            if (array_key_exists($option, $specs)) {
                $formatOptions[] = $option.'='.$specs[$option];
            }
        }

        // if there are no video/audio specs, then video/audio should be disabled
        $videoSpecs = empty($videoSpecs) ? 'x' : implode('_', $videoSpecs);
        $audioSpecs = empty($audioSpecs) ? 'x' : implode('_', $audioSpecs);
        $formatOptions = empty($formatOptions) ? '' : implode(',', $formatOptions);

        return trim(':', $container.':'.$videoSpecs.':'.$audioSpecs.':'.$formatOptions);
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

    public static function normalizeFormatSpecs( array $specs, string $container = null )
    {
        if (!$container) $container = $specs['container'] ?? null;
        if (empty($container)) {
            throw new InvalidCallException(
                "Could not determine the container from given arguments");
        }

        $defaults = [];

        // get default specs from container type
        $type = static::outputContainerType($container);
        switch ($type)
        {
            case 'video':
                $defaults = array_merge([], 
                    self::DEFAULT_VIDEO_SPECS, self::DEFAULT_AUDIO_SPECS);
                break;
            case 'audio':
                $defaults = self::DEFAULT_AUDIO_SPECS;
                break;
            case 'image':
                $defaults = self::DEFAULT_IMAGE_SPECS;
                break;
        }

        // include defaults for container
        $base = self::CONTAINER_ALIASES[$container] ?? $container;
        $defaults = array_merge($defaults, self::CONTAINER_SPECS[$base] ?? []);

        // resolve resolution definitions
        if (array_key_exists('resolution', $specs))
        {
            $resolutionSpecs = self::RESOLUTION_DEFINITION_SPECS[$specs['resolution']] ?? null;
            if ($resolutionSpecs) $specs = array_merge($specs, $resolutionSpecs);
        }

        $normalized = [];

        // include only specs that are not the default
        foreach ($specs as $spec => $value)
        {
            $default = $defaults[$spec] ?? null;
            if ($spec != $default) $normalized[$spec] = $value;
        }

        return $normalized;
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
     * @param string $path The format path (as returned by `ConfigHelper::keyPath()`)
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
     * Turns given volume path private, so Craft-CMS does not index it as an asset
     * 
     * @param string $path
     * 
     * @return string
     */

    public function privatisePath( string $path ): string
    {
        return preg_replace(self::PATH_PRIVATISATION_PATTERN, '$1_$2', $path);
    }
}