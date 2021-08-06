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

namespace yoannisj\coconut\models;

use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\validators\InlineValidator;

use Craft;
use craft\base\Model;
use craft\base\VolumeInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\ArrayHelper;
use craft\helpers\Json as JsonHelper;
use craft\helpers\FileHelper;
use craft\helpers\Assets as AssetsHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Job;
use yoannisj\coconut\helpers\ConfigHelper;

/**
 * Model representing Coconut job outputs
 *
 * @property Job $job
 * @property array $format
 * @property string $formatString
 * @property string $explicitPath
 * @property string $type
 */

class Output extends Model
{
    // =Static
    // =========================================================================

    const SCENARIO_DEFAULT = 'default';
    const SCENARIO_CONFIG = 'config';

    const STATUS_VIDEO_WAITING = 'video.waiting';
    const STATUS_VIDEO_QUEUED = 'video.queued';
    const STATUS_VIDEO_ENCODING = 'video.encoding';
    const STATUS_VIDEO_ENCODED = 'video.encoded';
    const STATUS_VIDEO_FAILED = 'video.failed';
    const STATUS_VIDEO_SKIPPED = 'video.skipped';

    const STATUS_IMAGE_WAITING = 'image.waiting';
    const STATUS_IMAGE_QUEUED = 'image.queued';
    const STATUS_IMAGE_PROCESSING = 'image.processing';
    const STATUS_IMAGE_CREATED = 'image.created';
    const STATUS_IMAGE_FAILED = 'image.failed';
    const STATUS_IMAGE_SKIPPED = 'image.skipped';

    // =Properties
    // =========================================================================

    /**
     * @var integer|null
     */

    public $id;

    /**
     * @var integer ID in Craft database of the Coconut job that created this output
     */

    public $jobId;

    /**
     * @var Job|null Coconut job model
     */

    private $_job;

    /**
     * @var string
     */

    private $_key;

    /**
     * @var array
     */

    private $_format;

    /**
     * @var string
     */

    private $_formatString;

    /**
     * @var integer Index for output's format in the job's list of outputs (1-indexed)
     */

    public $formatIndex;

    /**
     * @var string Output format container
     */

    private $_container;

    /**
     * @var string
     */

    private $_type;

    /**
     * @var string Explicit output path
     */

    private $_explicitPath;

    /**
     * @var string Format used to resolve output path
     */

    private $_pathFormat;

    /**
     * @var array|null
     */

    private $_metadata;

    /**
     * @var string Conditional expression to determine whether the
     *  output should be created or not
     *
     * @see https://docs.coconut.co/jobs/api#conditional-outputs
     */

    public $if;

    /**
     * @var boolean Whether to deinterlace the video output
     */

    public $deinterlace;

    /**
     * @var boolean Whether to crop the resulting output image to a squae
     */

    public $square = false;

    /**
     * @var string Whether to 'crop' or 'pad' the resulting output
     */

    public $fit = 'pad';

    /**
     * @var integer The rotation to apply to the output
     *
     * Supports values:
     * - `0` => 90CounterCLockwise and Vertical Flip (default)
     * - `1` => 90Clockwise
     * - `2` => 90CounterClockwise
     * - `3` => 90Clockwise and Vertical Flip
     */

    public $transpose = 0;

    /**
     * @var boolean Whether to flip the resulting output vertically
     */

    public $vflip = false;

    /**
     * @var boolean Whether to flip the resulting output horizontally
     */

    public $hflip = false;

    /**
     * @var integer Intensity of blur effect to apply to resulting image output.
     *  Value must range from `1` to `5`.
     */

    public $blur;

    /**
     * @var integer The duration (in seconds) at which resulting output should be cut
     */

    public $duration;

    /**
     * @var integer The duration (in seconds) after which the resulting output should start
     */

    public $offset;

    /**
     * @var integer Number of image outputs generated
     */

    public $number;

    /**
     * @var float Interval (in seconds) between each image output to generate
     */

    public $interval;

    /**
     * @var float[] Offsets (in seconds) at which to generate an image output
     */

    public $offsets;

    /**
     * @var boolean Whether to combine resulting image outputs into a single
     *  sprite image of 4 columns (useful for network optimisation)
     */

    public $sprite = false;

    /**
     * @var boolean Whether to generate a WebVTT file that includes a list of cues
     *  with either individual thumbnail or sprite with the right coordinates
     */

    public $vtt = false;

    /**
     * @var array Settings to generate an animated GIF image file.
     *  Format width must be <= 500px. Supported keys are:
     * - 'number' => The number of images in the GIF animation (default is `1,` max 10)
     * - 'duration' => The duration (in seconds) of the resulting GIF animation (default is `5`)
     *
     * @see https://docs.coconut.co/jobs/outputs-images#gif-animation
     */

    public $scene;

    /**
     * @var array Url and position of watermark image to add to the resulting output.
     *  Supported keys are:
     * - 'url' => URL to the PNG watermark image file (transparency supported)
     * - `position` => Either 'topleft', 'topright', 'bottomleft' or 'bottomright'
     *
     * @see https://docs.coconut.co/jobs/outputs-videos#watermark
     */

    public $watermark;

    /**
     * @var string The URL to the generated output file (once stored)
     */

    public $url;

    /**
     * @var string[] The list of URL's to the generated output files (once stored)
     */

    public $urls;

    /**
     * @var string Latest output status from Coconut job
     * @see https://docs.coconut.co/jobs/api#job-status
     */

    public $status;

    /**
     * @var \Datetime
     */

    public $dateCreated;

    /**
     * @var \Datetime
     */

    public $dateUpdated;

    // =Public Methods
    // =========================================================================

    // =Properties
    // -------------------------------------------------------------------------

    /**
     * @param Job|null $job
     */

    public function setJob( Job $job = null )
    {
        if ($job instanceof Job) {
            $this->jobId = $job->id;
            $this->_job = $job;
        }

        else {
            $this->jobId = null;
            $this->_job = null;
        }
    }

    /**
     * Returns Coconut job that generates this output
     *
     * @return Job|null
     */

    public function getJob()
    {
        if (!$this->_job && $this->jobId)
        {
            $this->_job = Coconut::$plugin->getJobs()
                ->getJobById($this->jobId);
        }

        return $this->_job;
    }

    /**
     * Setter method for normalized `format` property
     *
     * @param string|array|null $format
     */

    public function setFormat( $format )
    {
        $isString = is_string($format);

        // support getting format as a JSON string
        if ($isString) {
            $format = JsonHelper::decodeIfJson($format);
        }

        if (!empty($format)) {
            // Parse given format string or array of format specs
            $format = ConfigHelper::parseFormat($format);
        }

        else if (!$isString && !is_array($format) && !is_null($format))
        {
            throw new InvalidConfigException(
                "Property `format` must be set to a format string, an array of format specs or `null`");
        }

        $this->_format = $format;
        $this->_formatString = null;
        $this->_container = null;
    }

    /**
     * Getter method for normalized `format` property
     *
     * @return array
     */

    public function getFormat(): array
    {
        if (empty($this->_format))
        {
            if ($this->_key)
            {
                try {
                    $this->_format = ConfigHelper::parseFormat($this->_key);
                } catch (\Throwable $e) {
                    $this->_format = [];
                }
            }
        }

        return $this->_format ?? [];
    }

    /**
     * Setter method for normalized `key` property
     *
     * @param string|null $key
     */

    public function setKey( string $key = null )
    {
        $this->_key = $key;
    }

    /**
     * Getter method for normalized `key` string
     *
     * @return string
     */

    public function getKey(): string
    {
        if (empty($this->_key) && !empty($this->_format)) {
            $this->_key = $this->getFormatString();
        }

        if ($this->formatIndex) {
            return ltrim($this->_key . ':'.$this->formatIndex, ':');
        }

        return $this->_key ?? '';
    }

    /**
     * Setter method for resolved `path` property
     *
     * @param string|null $path
     */

    public function setPath( string $path = null )
    {
        // support unsetting path
        if (!$path) {
            $this->_explicitPath = null;
            // `path` property can still potentially be determined by resolving path format
        }

        // is this a template ?
        else if (preg_match(ConfigHelper::PATH_EXPRESSION_PATTERN, $path))
        {
            $this->_explicitPath = null;
            $this->_pathFormat = $path;
        }

        else // this is an explicit path
        {
            $this->_explicitPath = $path;
            $this->_pathFormat = null;
        }
    }

    /**
     * Getter method for resolved `path` property
     *
     * @return string|null
     */

    public function getPath()
    {
        $path = null;

        // raw path has priority and should be returned as is
        if (!empty($this->_explicitPath)) {
            $path = $this->_explicitPath;
        }

        // is current path a template?
        else if ($this->_pathFormat)
        {
            // @todo: support resolving Coconut input variables in paths
            // @see: https://docs.coconut.co/jobs/api#built-in-variables

            $vars = $this->pathVars();

            $path = preg_replace_callback(
                ConfigHelper::PATH_EXPRESSION_PATTERN,
                function($matches) use ($vars) {
                    return $vars[ $matches[1] ] ?? '';
                },
                $this->_pathFormat
            );

            // reduce conflicts if two outputs are resolved to the same path
            // by including the formatIndex in the filename
            if (!empty($this->formatIndex))
            {
                $extension = pathinfo($path, PATHINFO_EXTENSION);
                $pattern = '/\-'.$this->formatIndex.'\.'.$extension.'$/';

                if (!preg_match($pattern, $path))
                {
                    $path = (
                        pathinfo($path, PATHINFO_DIRNAME).'/'.
                        pathinfo($path, PATHINFO_FILENAME).
                        '-'.$this->formatIndex.'.'.$extension
                    );
                }
            }
        }

        // make sure paths for image sequence outputs include a numbering placeholder
        if ($this->getType() == 'image'
            && ($this->number || $this->interval || !empty($this->offsets))
            && !preg_match(ConfigHelper::SEQUENTIAL_PLACEHOLDER_PATTERN, $path)
        ) {
            $path = (
                pathinfo($path, PATHINFO_DIRNAME).'/'.
                pathinfo($path, PATHINFO_FILENAME).
                '-%.2d.'.pathinfo($path, PATHINFO_EXTENSION)
            );
        }

        return ConfigHelper::privatisePath($path);
    }

    /**
     * Setter method for the normalized `metadata` property
     *
     * @param string|array|null $metadata
     */

    public function setMetadata( $metadata )
    {
        if (is_string($metadata)) {
            $metadata = JsonHelper::decodeIfJson($this->_metadata) ?? [];
        }

        $this->_metadata = $metadata;
    }

    /**
     * Getter method for the normalized `metadata` property
     *
     * @return array|null
     */

    public function getMetadata()
    {
        return $this->_metadata;
    }

    /**
     * Getter method for read-only `container` property
     *
     * @return string|null
     */

    public function getContainer()
    {
        if (!isset($this->_container))
        {
            $format = $this->_format ?? [];

            if (array_key_exists('container', $format)) {
                $this->_container = $format['container'];
            }

            else if ($this->_explicitPath) {
                $this->_container = pathinfo($this->_explicitPath, PATHINFO_EXTENSION);
            }
        }

        return $this->_container;
    }

    /**
     * Getter method for the read-only `type` property
     *
     * @return string|null
     */

    public function getType()
    {
        if (!isset($this->_type)
            && !empty($container = $this->getContainer())
        ) {
            $this->_type = ConfigHelper::containerType($container);
        }

        return $this->_type;
    }

    /**
     * Getter for read-only `formatString` property
     *
     * @return string|null
     */

    public function getFormatString(): string
    {
        if (!isset($this->_formatString))
        {
            try {
                $this->_formatString =  ConfigHelper::encodeFormat($this->getFormat());
            } catch (\Throwable $e) {
                $this->_formatString = '';
            }
        }

        return $this->_formatString ?? '';
    }

    /**
     * Getter method for read-only `explicitPath` property
     *
     * @return string|null
     */

    public function getExplicitPath()
    {
        return $this->_explicitPath;
    }

    /**
     * Getter method for read-only `pathFormat` property
     *
     * @return string|null
     */

    public function getPathFormat()
    {
        return $this->_pathFormat;
    }

    // =Attributes
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function attributes()
    {
        $attributes = parent::attributes();

        $attributes[] = 'format';
        $attributes[] = 'key';
        $attributes[] = 'path';

        return $attributes;
    }

    // =Validation
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function rules()
    {
        $rules = parent::rules();

        return $rules;
    }

    // =Fields
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function fields()
    {
        $fields = parent::fields();

        return $fields;
    }

    /**
     * @inheritdoc
     */

    public function extraFields()
    {
        $fields = parent::extraFields();

        $fields[] = 'job';
        $fields[] = 'explicitPath';
        $fields[] = 'pathFormat';
        $fields[] = 'container';
        $fields[] = 'type';
        $fields[] = 'metadata';

        return $fields;
    }

    // =Protected Methods
    // ========================================================================

    /**
     * @return array
     */

    protected function pathVars(): array
    {
        $key = $this->getKey();
        $format = $this->getFormat();
        $job = $this->getJob();

        $input = $job ? $job->getInput() : null;
        $inputUrl = $input ? $input->getUrl() : null;
        $inputPath = null;

        if ($inputUrl)
        {
            $inputPath = parse_url($inputUrl, PHP_URL_PATH);
            $inputPath = trim((
                pathinfo($inputPath, PATHINFO_DIRNAME).'/'.
                pathinfo($inputPath, PATHINFO_FILENAME)
            ), '/');
        }

        return [
            'key' => ConfigHelper::keyAsPath($key),
            'ext' => $format ? ConfigHelper::formatExtension($format) : null,
            'path' => $inputPath,
            'filename' => ($inputPath ? pathinfo($inputPath, PATHINFO_FILENAME) : null),
            'hash' => $input ? $input->getUrlHash() : null,
        ];
    }

}
