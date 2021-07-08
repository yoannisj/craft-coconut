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

use Craft;
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
     */

    const VIDEO_SPEC_KEYS = [
        'video_codec', 'resolution', 'video_bitrate', 'fps',
        'pix_fmt', '2pass', 'frag', 'vprofile', 'level', 'quality', 'maxrate',
    ];

    /**
     * List of format specs that are relevant for audio encoding
     */

    const AUDIO_SPEC_KEYS = [
        'audio_codec', 'audio_bitrate', 'sample_rate', 'audio_channel',
    ];

    /**
     * 
     */

    const PREVIEW_SPEC_KEYS = [
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
     * 
     */

    const AUDIO_CODECS = [
        'mp3', 'mp2', 'aac', 'amr_nb', 'ac3', 'vorbis', 'flac',
        'pcm_u8', 'pcm_s16le', 'pcm_alaw', 'wmav2',
    ];

    /**
     * Regex patterns used to identify specification values
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
    const PIX_FMT_PATTERN = '/^(yuv420p|yuv422p)$/';
    const VPROFILE_PATTERN = '/^(baseline|main|high|high10|high422|high444)$/';
    const VPROFILE_PATTERN_PRORES = '/^[0-3]{1}$/';
    const LEVEL_PATTERN = '/^(10|11|12|13|20|21|22|30|31|32|40|41|42|50|51)$/';
    const QUALITY_PATTERN = '/^[1-5]{1}$/';

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
    ];

    /**
     * List of source file containers (i.e. extensions) supported for input files
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
     * List of video containers
     */

    const VIDEO_CONTAINERS = [
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
     *
     */

    const AUDIO_CONTAINERS = [
        'mp3', 'ogg',
    ];

    /**
     * List of preview containers
     */

    const PREVIEW_CONTAINERS = [
        'jpg', 'png', 'gif',
    ];

    /**
     * Default format specification values for given container
     * @see https://docs.coconut.co/references/formats#basics
     */

    const CONTAINER_SPECS = [

        // =defaults
        '*' => [ // (value of container keys below gets merged in with these)
            'resolution' => '0x0', 'video_bitrate' => '1000k', 'fps' => '0fps', // video
            'audio_bitrate' => '44100hz', 'sample_rate' => '128k', 'audio_channel' => 'stereo', // audio
            'pix_fmt' => null, '2pass' => false, // common options
            'frag' => false, // mp4 options
            'vprofile' => null, 'level' => null, // h264, hevc options ('vprofile' also for prores)
            'quality' => null, 'maxrate' => null, // h264, hevc, vp8, vp9 options
        ],

        // =video
        'mp4' => [ 'video_codec' => 'h264', 'audio_codec' => 'aac' ],
        'webm' => [ 'video_codec' => 'vp8', 'audio_codec' => 'vorbis' ],
        'avi' => [ 'video_codec' => 'mpeg4', 'audio_codec' => 'mp3' ],
        'asf' => [ 'video_codec' => 'wmv2', 'audio_codec' => 'wmav2' ],
        'mpegts' => [ 'video_codec' => 'h264', 'audio_codec' => 'aac' ],
        'mov' => [ 'video_codec' => 'h364',  'audio_codec' => 'aac' ],
        'flv' => [ 'video_codec' => 'flv',  'audio_codec' => 'mp3' ],
        'mkv' => [ 'video_codec' => 'h264',  'audio_codec' => 'aac' ],
        '3gp' => [ 'video_codec' => 'h263',  'audio_codec' => 'aac', 'sample_rate' => '32k' ],
        'ogv' => [ 'video_codec' => 'theora',  'audio_codec' => 'vorbis' ],

        // =audio
        'ogg' => [ 'audio_codec' => 'vorbis' ],
        'mp3' => [ 'audio_codec' => 'mp3' ],
    ];

    /**
     * Maps formatting video definitions to video specs
     */

    const RESOLUTION_DEFINITION_SPECS = [
        '240p' => [ 'resolution' => '0x240', 'video_bitrate' => '500K' ],
        '360p' => [ 'resolution' => '0x360', 'video_bitrate' => '800K' ],
        '480p' => [ 'resolution' => '0x480', 'video_bitrate' => '1000k' ],
        '720p' => [ 'resolution' => '1280x720', 'video_bitrate' => '2000k' ],
        '1080p' => [ 'resolution' => '1980x1080', 'video_bitrate' => '4000k' ],
        '2160p' => [ 'resolution' => '3840x2160', 'video_bitrate' => '8000k' ],
    ];
    
    /**
     * Regex patterns to parse file paths and urls
     */

    const FILE_EXTENSION_PATTERN = '/\.([a-z0-9]{2,4}$)/';

    /**
     * Regex patterns to parse sequence options
     */

    const SEQUENCE_IDENTIFIER_PATTERN = '/#num#/';
    const NUMBER_URL_OPTION_PATTERN = '/(?:^|,)\s*number=(\d+)/';
    const EVERY_URL_OPTION_PATTERN = '/(?:^|,)\s*offsets=([\d,]+)/';
    const OFFSETS_URL_OPTION_PATTERN = '/(?:^|,)\s*every=(\d+)/';

    /**
     * Regex patterns to normalize output paths
     */

    const OUTPUT_PARTS_PATTERN = '/^(\S+)\s*(,.+)?$/'; 

    // =Methods
    // -------------------------------------------------------------------------

    /**
     * @param string|array|Volume $storage
     * 
     * @return Storage|null
     */

    public static function parseStorage( $storage )
    {
        if (is_string($storage))
        {
            // check if this is a named storage handle
            $storages = static::getSettings()->storages;

            if (array_key_exists($storage, $storages)) {
                $storage = $storages[$storage];
            }
            
            else { // or, assume this is a volume handle
                $storage = Craft::$app->getVolumes()->getVolumeByHandle($storage);
            }
        }

        if ($storage instanceof Volume) {
            return static::getVolumeStorageSettings($storage);
        }

        if (is_array($storage)) {
            return Craft::configure(new Storage(), $storage);
        }

        return null;
    }

    /**
     * @param string $format
     * @return array
     */

    public static function parseFormat( string $format ): array
    {
        $data = [
            'outputType' => null,
            'container' => null,
            'videoSpecs' => null,
            'audioSpecs' => null,
            'fomatOptions' => null,
            'imageSpecs' => null,
        ];

        $segments = explode(':', $format);
        $container = $segments[0];
        $outputType = null;

        if (in_array($container, self::VIDEO_CONTAINERS)) {
            $outputType = 'video';
        } else if (in_array($container, self::PREVIEW_CONTAINERS)) {
            $outputType = 'preview';
        } else if (in_array($container, self::AUDIO_CONTAINERS)) {
            $outputType = 'audio';
        }

        $data['container'] = $container;
        $data['outputType'] = $outputType;

        switch ($outputType)
        {
            case 'video':
            case 'audio':
                $data['videoSpecs'] = $segments[1] ?? null;
                $data['audioSpecs'] = $segments[2] ?? null;
                $data['formatOptions'] = $segments[3] ?? null;
                break;
            case 'preview':
                $data['videoSpecs'] = $data['imageSpecs'] = $segments[1] ?? null;
                break;
        }

        return $data;
    }

    /**
     * Formats given output path, making sure the '#num#' string is included
     * when required by given options, and fixing the path extension based on
     * given output format.
     *
     * @param string $format
     * @param string $path
     * @param string $options
     *
     * @return string
     */

    public static function formatPath( string $format, string $path = '', string $options = null )
    {
        // default to the format segment
        if (empty($path)) {
            $path = static::getFormatSegment($format);
        }

        $suffix = '.' . static::getFormatExtension($format);

        // @todo: support other printf sequence formats
        if ($options && strpos($options, 'number') !== false
            && strpos($path, '#num#') === false)
        {
            $suffix = '-#num#' . $suffix;
        }

        // make sure path ends with suffix (while avoiding double extensions)
        $path = preg_replace(self::FILE_EXTENSION_PATTERN, '', $path);
        $path .= $suffix;

        return $path;
    }

    /**
     * Resolve the config output for given output format, url, options and config vars.
     *
     * @param string $format
     * @param string $url
     * @param string $options
     * @param array $variables
     *
     * @return string
     */

    public static function resolveOutput( string $format, string $url, string $options = null, array $variables = [] ): string
    {
        // add options to the end of the url
        $output = empty($options) ? $url : ($url . ', ' . $options);
        // parse variables in options
        $output = static::parseVariables($output, $variables);

        return $output;
    }

    /**
     * @param string $outputUrl
     * @param array $variables
     *
     * @return string | array
     *
     * @todo: Support 'every' and 'offsets' via "output_duration" variable (use getid3 package)
     */

    public static function resolveOutputUrls( string $format, string $url, string $options = null, array $variables = [] )
    {
        // parse variables in options
        if (!empty($options)) {
            $options = static::parseVariables($options, $variables);
        }

        // apply number sequence option
        $numberMatch = [];
        if (preg_match(self::NUMBER_URL_OPTION_PATTERN, $options, $numberMatch))
        {
            $urls = [];

            $url = str_replace('#num#','%02d', $url);
            $count = (int)$numberMatch[1];
            $index = 0;

            while ($index++ < $count)
            {
                $indexUrl = sprintf($url, $index);
                $urls[] = trim(explode(',', $indexUrl)[0]);
            }

            return $urls;
        }

        // apply every sequence option
        $everyMatch = [];
        if (preg_match(self::EVERY_URL_OPTION_PATTERN, $options, $everyMatch))
        {
            throw new InvalidArgumentException('Can not parse output urls with the "every" option');
            // $every = (int)$numberMatch[2];
        }

        // apply offsets sequence option
        $offsetsMatch = [];
        if (preg_match(self::OFFSETS_URL_OPTION_PATTERN, $options, $offsetsMatch))
        {
            throw new InvalidArgumentException('Can not parse output urls with the "offsets" option');
            // $offsets = explode(',', $numberMatch[2]);
        }

        return static::parseVariables($url, $variables);
    }

    /**
     *
     */

    public static function getFormatSegment( string $format )
    {
        return str_replace([':', '*', '=', ','], ['-', '', '_', ''], $format);
    }

    /**
     * @param string $format
     *
     * @return string
     */

    public static function getFormatExtension( string $format ): string
    {
        $container = explode(':', $format)[0] ;

        switch ($container)
        {
            case 'divx':
            case 'xvid':
                $container = 'avi';
                break;
            case 'wmv':
                $container = 'asf';
                break;
            case 'flash':
                $container = 'flv';
                break;
            case 'theora':
                $container = 'ogv';
                break;
        }

        return $container;
    }

    /**
     * @param string $format
     *
     * @return string
     */

    public static function getFileExtension( string $file )
    {
        $match = [];
        if (!preg_match(self::FILE_EXTENSION_PATTERN, $filename, $match)) {
            return null;
        }

        return $match[1];
    }

    /**
     * @param string $str
     * @param array $variables
     *
     * @return string
     */

    public static function parseVariables( string $str, array $variables = [] ): string
    {
        foreach ($variables as $name => $value)
        {
            $pattern = '/\$'.preg_quote($name).'/';
            $str = preg_replace($pattern, $value, $str);        
        }

        return $str;
    }
}