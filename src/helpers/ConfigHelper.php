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

/**
 * Static helper class to work with coconut configs
 */

class ConfigHelper
{
    // =Constants
    // -------------------------------------------------------------------------

    /**
     * List of source video file extensions
     */

    const SOURCE_FILE_EXTENSIONS = [
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
     * Maps formatting video definitions to video specs
     */

    const VIDEO_DEFINITION_SPECS = [
        '240p' => [ 'resolution' => '0x240', 'aspect' => '*', 'bitrate' => '500K' ],
        '360p' => [ 'resolution' => '0x360', 'aspect' => '*', 'bitrate' => '800K' ],
        '480p' => [ 'resolution' => '0x480', 'aspect' => '*', 'bitrate' => '1000k' ],
        '720p' => [ 'resolution' => '1280x720', 'aspect' => '16:9', 'bitrate' => '2000k' ],
        '1080p' => [ 'resolution' => '1980x1080', 'aspect' => '16:9', 'bitrate' => '4000k' ],
        '2160p' => [ 'resolution' => '3840x2160', 'aspect' => '16:9', 'bitrate' => '8000k' ],
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