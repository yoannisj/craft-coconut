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

use DateTime;

use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\validators\InlineValidator;

use Craft;
use craft\base\Model;
use craft\base\VolumeInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\validators\DateTimeValidator;
use craft\helpers\ArrayHelper;
use craft\helpers\Json as JsonHelper;
use craft\helpers\FileHelper;
use craft\helpers\Assets as AssetsHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\behaviors\PropertyAliasBehavior;
use yoannisj\coconut\models\Job;
use yoannisj\coconut\validators\AssociativeArrayValidator;
use yoannisj\coconut\helpers\JobHelper;

/**
 * Model representing Coconut job outputs
 *
 * @property Job $job The job that was used to transcode this output
 * @property string|null $progress Progress of the output transcoding (in percentage)
 * @property array $format Parsed output format
 * @property string $formatString String representation of the output format
 * @property string $explicitPath The output path that was explicitly set (i.e. if not the default path)
 * @property string $type The output type (i.e. 'video', 'image' or 'audio')
 * @property string $mimeType The output file mimeType
 * @property string $extension The output file extension
 * @property bool $isDefaultPath Whether this output uses the default path or note
 * @property bool $isPending Wether transcoding is waiting on Coconut resources
 * @property bool $isProcessing Wether Coconut is currently transcoding
 * @property bool $isSkipped Wether the output was skipped (due to if condition)
 * @property bool $isAborted Wether transcoding was aborted
 * @property bool $isDiscontinued Wether Coconut skipped or aborted transcoding the output
 * @property bool $isCompleted Wether transcoding the output has come to an end
 * @property bool $isSuccessfull Wether transcoding did not result in an output file
 * @property bool $isFailed Wether transcoding failed
 *
 * @todo Set Output properties to their default?
 * @body Since we only expot the Output parameters that correspond to the output type, we could set all properties to their default value without risking errors from the Coconut API. This would help with comparing output models if their media type is set dynamically. However, it would only work if the defaults are always the same (i.e. they don't depend/change based on the media type).
 */
class Output extends Model
{
    // =Static
    // =========================================================================

    /**
     * @var string
     */
    const TYPE_VIDEO = 'video';

    /**
     * @var string
     */
    const TYPE_AUDIO = 'audio';

    /**
     * @var string
     */
    const TYPE_IMAGE = 'image';

    /**
     * @todo Verify that httpstream type gets returned by Coconut API
     *
     * @var string
     */
    const TYPE_HTTPSTREAM = 'httpstream';

    /**
     * @var string
     */
    const FIT_PAD = 'pad';

    /**
     * @var string
     */
    const FIT_CROP = 'crop';

    /**
     * @var string
     */
    const STATUS_VIDEO_WAITING = 'video.waiting';

    /**
     * @var string
     */
    const STATUS_VIDEO_QUEUED = 'video.queued';

    /**
     * @var string
     */
    const STATUS_VIDEO_ENCODING = 'video.encoding';

    /**
     * @var string
     */
    const STATUS_VIDEO_ENCODED = 'video.encoded';

    /**
     * @var string
     */
    const STATUS_VIDEO_FAILED = 'video.failed';

    /**
     * @var string
     */
    const STATUS_VIDEO_SKIPPED = 'video.skipped';

    /**
     * @var string
     */
    const STATUS_VIDEO_ABORTED = 'video.aborted';

    /**
     * @var string
     */
    const STATUS_IMAGE_WAITING = 'image.waiting';

    /**
     * @var string
     */
    const STATUS_IMAGE_QUEUED = 'image.queued';

    /**
     * @var string
     */
    const STATUS_IMAGE_PROCESSING = 'image.processing';

    /**
     * @var string
     */
    const STATUS_IMAGE_CREATED = 'image.created';

    /**
     * @var string
     */
    const STATUS_IMAGE_FAILED = 'image.failed';

    /**
     * @var string
     */
    const STATUS_IMAGE_SKIPPED = 'image.skipped';

    /**
     * @var string
     */
    const STATUS_IMAGE_ABORTED = 'image.aborted';

    /**
     * @var string
     */
    const STATUS_HTTPSTREAM_WAITING = 'httpstream.waiting';

    /**
     * @var string
     */
    const STATUS_HTTPSTREAM_QUEUED = 'httpstream.queued';

    /**
     * @var string
     */
    const STATUS_HTTPSTREAM_VARIANTS_WAITING = 'httpstream.variants.waiting';

    /**
     * @var string
     */
    const STATUS_HTTPSTREAM_VARIANTS_QUEUED = 'httpstream.variants.queued';

    /**
     * @var string
     */
    const STATUS_HTTPSTREAM_VARIANTS_ENCODING = 'httpstream.variants.encoding';

    /**
     * @var string
     */
    const STATUS_HTTPSTREAM_PACKAGING = 'httpstream.packaging';

    /**
     * @var string
     */
    const STATUS_HTTPSTREAM_PACKAGED = 'httpstream.packaged';

    /**
     * @var string
     */
    const STATUS_HTTPSTREAM_FAILED = 'httpstream.failed';

    /**
     * @var string
     */
    const STATUS_HTTPSTREAM_SKIPPED = 'httpstream.skipped';

    /**
     * @var string
     */
    const STATUS_HTTPSTREAM_ABORTED = 'httpstream.aborted';

    /**
     * @var string[]
     */
    const PENDING_STATUSES = [
        'video.waiting', 'video.queued',
        'image.waiting', 'image.queued',
        'httpstream.waiting', 'httpstream.queued',
        'httpstream.variants.waiting', 'httpstream.variants.queued',
    ];

    /**
     * @var string[]
     */
    const PROCESSING_STATUSES = [
        'video.encoding',
        'image.processing',
        'httpstream.variants.encoding',
    ];

    /**
     * @var string[]
     */
    const SKIPPED_STATUSES = [
        'video.skipped', 'image.skipped', 'httpstream.skipped',
    ];

    /**
     * @var string[]
     */
    const ABORTED_STATUSES = [
        'video.aborted', 'image.aborted', 'httpstream.aborted',
    ];

    /**
     * @var string[]
     */
    const COMPLETED_STATUSES = [
        'video.encoded', 'video.failed', 'video.skipped', 'video.aborted',
        'image.created', 'image.failed', 'image.skipped', 'image.aborted',
        'httpstream.packaged', 'httpstream.failed', 'httpstream.skipped', 'httpstream.aborted',
    ];

    /**
     * @var string[]
     */
    const SUCCESSFUL_STATUSES = [
        'video.encoded', 'image.created', 'httpstream.packaged',
    ];

    /**
     * @var string[]
     */
    const FAILED_STATUSES = [
        'video.failed', 'image.failed', 'httpstream.failed',
    ];

    // =Properties
    // =========================================================================

    /**
     * ID of the transcoding Output in Craft's database
     *
     * @var int|null
     */
    public ?int $id = null;

    /**
     * ID in Craft's database of the Coconut job transcoding this output
     *
     * @var ?int
     */
    public ?int $jobId = null;

    /**
     * Transcoding Coconut Job model
     *
     * @var Job|null
     */
    private ?Job $_job = null;

    /**
     * Output key
     *
     * @var string|null
     */
    private ?string $_key = null;

    /**
     * Output format specs (decoded and normalized)
     *
     * @var array
     */
    private ?array $_format = null;

    /**
     * Output format string
     *
     * @var string
     */
    private ?string $_formatString = null;

    /**
     * Index for output's format in the job's list of outputs (1-indexed)
     *
     * @var int|null
     */
    public ?int $formatIndex = null;

    /**
     * Output format container
     *
     * @var string|null
     */
    private ?string $_container = null;

    /**
     * Output media type (i.e. 'video', 'image', 'audio', 'httpstream')
     *
     * @var string|null
     */
    private ?string $_type = null;

    /**
     * Output file extension
     *
     * @var string|null
     */
    private ?string $_extension = null;

    /**
     * Output file mimeType
     *
     * @var string|null
     */
    private ?string $_mimeType = null;

    /**
     * Explicit output path
     *
     * @var string|null
     */
    private ?string $_explicitPath = null;

    /**
     * Format used to resolve output path
     *
     * @var string|null
     */
    private ?string $_pathFormat = null;

    /**
     * Conditional expression to determine whether the output should be
     * created or not.
     *
     * @see https://docs.coconut.co/jobs/api#conditional-outputs
     *
     * @var string|null
     */
    public ?string $if = null;

    /**
     * Whether to deinterlace the video output
     *
     * @var bool
     */
    public bool $deinterlace = false;

    /**
     * Whether to crop the resulting output image to a squae
     *
     * @var bool
     */
    public bool $square = false;

    /**
     * Intensity of blur effect to apply to resulting image output.
     * Value must range from `1` to `5`.
     *
     * @var int|null
     */
    public ?int $blur = null;

    /**
     * Whether to 'crop' or 'pad' the resulting output
     *
     * @var string|null
     */
    public ?string $fit = null;

    /**
     * The rotation to apply to the output.
     *
     * Supports values:
     * - `0` => 90CounterCLockwise and Vertical Flip (default)
     * - `1` => 90Clockwise
     * - `2` => 90CounterClockwise
     * - `3` => 90Clockwise and Vertical Flip
     *
     * @var int
     */
    public int $transpose = 0;

    /**
     * Whether to flip the resulting output vertically
     *
     * @var bool
     */
    public bool $vflip = false;

    /**
     * Whether to flip the resulting output horizontally
     *
     * @var bool
     */
    public bool $hflip = false;

    /**
     * The duration (in seconds) after which the resulting output should start
     *
     * @var int|null
     */
    public ?int $offset;

    /**
     * The duration (in seconds) at which resulting output should be cut
     *
     * @var int|null
     */
    private ?int $_duration;

    /**
     * Whether `duration` property has already been normalized
     *
     * @var bool
     */
    protected bool $isNormalizedDuration = false;

    /**
     * Number of image outputs generated
     *
     * @var int|null
     */
    public ?int $number = null;

    /**
     * Interval (in seconds) between each image output to generate
     *
     * @var int|null
     */
    public ?int $interval = null;

    /**
     * Offsets (in seconds) at which to generate an image output
     *
     * @var int[]|null
     */
    private ?array $_offsets = null;

    /**
     * Whether to combine resulting image outputs into a single sprite image
     * of 4 columns (useful for network optimisation).
     *
     * @var bool
     */
    public bool $sprite = false;

    /**
     * Whether to generate a WebVTT file that includes a list of cues with
     * either individual thumbnail or sprite with the right coordinates
     *
     * @var bool
     */
    public bool $vtt = false;

    /**
     * Settings to generate an animated GIF image file.
     *
     * This must be an array with the following keys:
     * - 'number' => The number of images in the GIF animation (default is `1,` max 10)
     * - 'duration' => The duration (in seconds) of the resulting GIF animation (default is `5`)
     *
     * ::: warning
     * For this to work, the Output's format width must be <= 500px
     * :::
     *
     * @see https://docs.coconut.co/jobs/outputs-images#gif-animation
     *
     * @var array|null
     */
    private ?array $_scene = null;

    /**
     * Url and position of watermark image to add to the resulting output.
     *
     * This must be an array with the following keys:
     * - 'url' => URL to the PNG watermark image file (transparency supported)
     * - `position` => Either 'topleft', 'topright', 'bottomleft' or 'bottomright'
     *
     * @see https://docs.coconut.co/jobs/outputs-videos#watermark
     *
     * @var array|null
     */
    private ?array $_watermark = null;

    /**
     * Latest Output status, as communicated by the Coconut API
     * and Notifications.
     *
     * @see https://docs.coconut.co/jobs/api#job-status
     *
     * @var string|null
     */
    public ?string $status = null;

    /**
     * Transcoding progress (in percentage) of this Output, as communicated by
     * the Coconut API and Notifications.
     *
     * @var string|null
     */
    private ?string $_progress = '0%';

    /**
     * Error message associated with this output
     *
     * ::: tip
     * This is only relevant if output has failed `status`
     * :::
     *
     * @var string|null
     */
    public ?string $error = null;

    /**
     * The URL to the generated output file (once stored)
     *
     * @var string|null
     */
    public ?string $url = null;

    /**
     * The list of URL's to the generated output files (once stored)
     *
     * @var string[]|null
     */
    private ?array $_urls = null;

    /**
     * The metadata for this Output, as communicated by the Coconut API
     * and Notifications.
     *
     * @var array|null
     */
    private ?array $_metadata = null;

    /**
     * Width dimension of output (in pixels)
     *
     * @var int|null
     */
    private ?int $_width = null;

    /**
     * Height dimension of output (in pixels)
     *
     * @var int|null
     */
    private ?int $_height = null;

    /**
     * Aspect ratio of output (i.e. `width / height`, rounded up to 4 decimal points)
     *
     * @var float|null
     */
    private ?float $_ratio = null;

    /**
     * Whether dimension properties have been already computed/normalized
     *
     * @var bool
     */
    protected bool $isNormalizedDimensions = false;

    /**
     * Date at which the job was created in Craft's database
     *
     * @var DateTime|null
     */
    public ?DateTime $dateCreated = null;

    /**
     * Date at which the job was last updated in Craft's database
     * @var DateTime|null
     */
    public ?DateTime $dateUpdated = null;

    /**
     * @var string
     */
    public ?string $uid = null;

    // =Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        if (!isset($this->fit)) {
            $this->fit = self::FIT_PAD;
        }
    }

    // =Properties
    // -------------------------------------------------------------------------

    /**
     * @param Job|null $job
     *
     * @return static Back-reference for method chaining
     */
    public function setJob( Job|null $job ): static
    {
        $this->jobId = $job ? $job->id : null;
        $this->_job = $job;

        return $this;
    }

    /**
     * Returns Coconut job that generates this output
     *
     * @return Job|null
     */
    public function getJob(): ?Job
    {
        if (!$this->_job && $this->jobId)
        {
            $this->_job = Coconut::$plugin->getJobs()
                ->getJobById($this->jobId);
        }

        return $this->_job;
    }

    /**
     * Setter method for defaulted `progress` property
     *
     * @param string|null $progress
     *
     * @return static Back-reference for method chaining
     */
    public function setProgress( string|null $progress ): static
    {
        $this->_progress = $progress;
        return $this;
    }

    /**
     * Getter method for defaulted `progress` property
     *
     * @return string|null
     */
    public function getProgress(): ?string
    {
        if (!isset($this->_progress))
        {
            if ($this->getIsCompleted()) {
                $this->_progress = '100%';
            } else if ($this->getIsPending()) {
                $this->_progress = '0%';
            }
        }

        return $this->_progress;
    }

    /**
     * Setter method for normalized `format` property
     *
     * @param string|array|null $format
     *
     * @return static Back-reference for method chaining
     */
    public function setFormat( string|array|null $format ): static
    {
        $isString = is_string($format);

        // support getting format as a JSON string
        if ($isString) {
            $format = JsonHelper::decodeIfJson($format);
        }

        if (!empty($format)) {
            // Parse given format string or array of format specs
            $format = JobHelper::parseFormat($format);
        }

        else if (!$isString && !is_array($format) && !is_null($format))
        {
            throw new InvalidConfigException(
                "Property `format` must be set to a format string, an array of format specs or `null`");
        }

        $this->_format = $format;
        $this->_formatString = null;
        $this->_container = null;

        return $this;
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
                    $this->_format = JobHelper::parseFormat($this->_key);
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
     *
     * @return static Back-reference for method chaining
     */
    public function setKey( string|null $key ): static
    {
        $this->_key = $key;
        return $this;
    }

    /**
     * Getter method for normalized `key` string
     *
     * @return string
     */
    public function getKey(): string
    {
        if (empty($this->_key) && !empty($this->_format)) {
            $this->_key = JobHelper::formatAsKey($this->getFormatString());
        }

        if ($this->formatIndex) {
            return ltrim($this->_key . '--'.$this->formatIndex, '--');
        }

        return $this->_key ?? '';
    }

    /**
     * Setter method for resolved `path` property
     *
     * @param string|null $path
     *
     * @return static Back-reference for method chaining
     */
    public function setPath( string|null $path ): static
    {
        // support unsetting path
        if (!$path) {
            $this->_explicitPath = null;
            // `path` property can still potentially be determined by resolving path format
        }

        // is this a template ?
        else if (preg_match(JobHelper::PATH_EXPRESSION_PATTERN, $path))
        {
            $this->_explicitPath = null;
            $this->_pathFormat = $path;
        }

        else // this is an explicit path
        {
            $this->_explicitPath = $path;
            $this->_pathFormat = null;
        }

        return $this;
    }

    /**
     * Getter method for resolved `path` property
     *
     * @return string|null
     */
    public function getPath(): ?string
    {
        $path = null;

        // raw path has priority and should be returned as is
        if (!empty($this->_explicitPath)) {
            $path = $this->_explicitPath;
        }

        // @todo: we need to define a default path here!

        else
        {
            // use output's path format, or fall back to defaultOutputPathFormat setting
            $pathFormat = $this->getPathFormat();

            // @todo: support resolving Coconut input variables in paths
            // @see: https://docs.coconut.co/jobs/api#built-in-variables

            $vars = $this->pathVars();

            $path = preg_replace_callback(
                JobHelper::PATH_EXPRESSION_PATTERN,
                function($matches) use ($vars) {
                    return $vars[ $matches[1] ] ?? '';
                },
                $pathFormat
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
        ) {
            $path = Jobhelper::sequencePath($path);
        }

        return JobHelper::privatisePath(JobHelper::normalizePath($path));
    }

    /**
     * Setter method for normalized `offsets` property
     *
     * @param string|array|null $offsets
     *
     * @return static Back-reference for method chaining
     */
    public function setOffsets( string|array|null $offsets ): static
    {
        if (is_string($offsets)) {
            $offsets = explode(',', $offsets);
        }

        $this->_offsets = $offsets;

        return $this;
    }

    /**
     * Getter method for normalized `offsets` property
     *
     * @return array|null
     */
    public function getOffsets(): ?array
    {
        return $this->_offsets;
    }

    /**
     * Setter method for normalized `scene` property
     *
     * @param string|array|null  $scene
     *
     * @return static Back-reference for method chaining
     */

    public function setScene( string|array|null $scene ): static
    {
        if (is_string($scene)) {
            $scene = JsonHelper::decode($scene);
        }

        $this->_scene = $scene;

        return $this;
    }

    /**
     * Getter method for normalized `scene` property
     *
     * @return array|null
     */
    public function getScene(): ?array
    {
        return $this->_scene;
    }

    /**
     * Setter method for normalized `watermark` property
     *
     * @param string|array|null $watermark
     *
     * @return static Back-reference for method chaining
     */
    public function setWatermark( string|array|null $watermark ): static
    {
        if (is_string($watermark)) {
            $watermark = JsonHelper::decode($watermark);
        }

        $this->_watermark = $watermark;

        return $this;
    }

    /**
     * Getter method for normalized `watermark` property
     *
     * @return array|null
     */
    public function getWatermark(): ?array
    {
        return $this->_watermark;
    }

    /**
     * Setter method for the normalized `metadata` property
     *
     * @param string|array|null $metadata
     *
     * @return static Back-reference for method chaining
     */
    public function setMetadata( string|array|null $metadata ): static
    {
        if (is_string($metadata)) {
            $metadata = JsonHelper::decodeIfJson($this->_metadata) ?? [];
        }

        $this->_metadata = $metadata;

        return $this;
    }

    /**
     * Getter method for the normalized `metadata` property
     *
     * @return array|null
     */
    public function getMetadata(): ?array
    {
        return $this->_metadata;
    }

    /**
     * Setter method for normalized `urls` property
     *
     * @param string|array|null $urls
     *
     * @return static Back-reference for method chaining
     */
    public function setUrls( string|array|null $urls ): static
    {
        if (is_string($urls)) {
            $urls = JsonHelper::decode($urls);
        }

        $this->_urls = $urls;

        return $this;
    }

    /**
     * Getter method for normalized `urls` property
     *
     * @return array|null
     */
    public function getUrls(): ?array
    {
        return $this->_urls;
    }

    /**
     * Getter method for read-only `container` property
     *
     * @return string|null
     */
    public function getContainer(): ?string
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
     * Getter method for the defaulted `type` property
     *
     * @return string|null
     */
    public function getType(): ?string
    {
        if (!isset($this->_type)
            && !empty($container = $this->getContainer())
        ) {
            $this->_type = JobHelper::containerType($container);
        }

        return $this->_type;
    }

    /**
     * Getter method for computed `extension` property
     *
     * @return string|null
     */
    public function getExtension(): ?string
    {
        if (!isset($this->_extension))
        {
            if ($this->url) {
                $this->_extension = pathinfo($this->url, PATHINFO_EXTENSION);
            }

            else if (($format = $this->getFormat())) {
                $this->_extension = JobHelper::formatExtension($format);
            }
        }

        return $this->_extension;
    }

    /**
     * Getter method for computed `mimeType` property
     *
     * @return string|null
     */
    public function getMimeType(): ?string
    {
        if (!isset($this->_mimeType)
            && !empty($extension = $this->getExtension()))
        {
            $file = 'foo.'.$extension; // fake file is good enough here
            $this->_mimeType = FileHelper::getMimeTypeByExtension($file);
        }

        return $this->_mimeType;
    }

    /**
     * Getter for read-only `formatString` property
     *
     * @return string
     */
    public function getFormatString(): string
    {
        if (!isset($this->_formatString))
        {
            try {
                $this->_formatString =  JobHelper::encodeFormat($this->getFormat());
            } catch (InvalidArgumentException $e) {
                $this->_formatString = '';
            }
        }

        return $this->_formatString ?? '';
    }

    /**
     * Setter method for defaulted `duration` property
     *
     * @param float|null $duration
     *
     * @return static Back-reference for method chaining
     */
    public function setDuration( float|null $duration ): static
    {
        $this->_duration = $duration;

        if ($duration === null) {
            $this->isNormalizedDuration = false;
        }

        return $this;
    }

    /**
     * Getter method for defaulted `duration` property
     *
     * @return float|null
     */
    public function getDuration(): ?float
    {
        if (!$this->isNormalizedDuration)
        {
            // get duration from metadata
            if ($this->type != 'image'
                && !empty($metadata = $this->getMetadata())
                && !empty($format = $metadata['format'] ?? null))
            {
                $this->_duration = floatval($format['duration']);
            }

            // get duration from input metadata
            else if (($job = $this->getJob())
                && ($input = $job->getInput())
                && !empty($metadata = $input->getMetadata())
                && !empty($format = $metadata['format'] ?? null))
            {
                $this->_duration = floatval($format['duration']);
            }

            $this->isNormalizedDuration = true;
        }

        return $this->_duration;
    }

    /**
     * Getter method for read-only `explicitPath` property
     *
     * @return string|null
     */
    public function getExplicitPath(): ?string
    {
        return $this->_explicitPath;
    }

    /**
     * Getter method for read-only `pathFormat` property
     *
     * @return string|null
     */
    public function getPathFormat(): ?string
    {
        if ($this->_pathFormat) {
            return $this->_pathFormat;
        }

        if (($job = $this->getJob())) {
            return $job->getOutputPathFormat();
        }

        return Coconut::$plugin->getSettings()->defaultOutputPathFormat;
    }

    /**
     * Getter method for computed `isDefaultPath` property
     *
     * @return bool
     */
    public function getIsDefaultPath(): bool
    {
        return (!isset($this->_explicitPath) && !isset($this->_pathFormat));
    }

    /**
     * Getter method for computed `isPending` property
     *
     * @return bool
     */
    public function getIsPending(): bool
    {
        return in_array($this->status, static::PENDING_STATUSES);
    }

    /**
     * Getter method for computed `isSkipped` property
     *
     * Note: will return `false` if output transcoding is still pending,
     *  but the output could later end up being skipped.
     *
     * @return bool
     */
    public function getIsSkipped(): bool
    {
        return in_array($this->status, static::SKIPPED_STATUSES);
    }

    /**
     * Getter method for computed `isAborted` property
     *
     * Note: will return `false` if output transcoding is still in progress,
     *  but the output could later end up being aborted.
     *
     * @return bool
     */
    public function getIsAborted(): bool
    {
        return in_array($this->status, static::ABORTED_STATUSES);
    }

    /**
     * Getter method for computed `isDiscontinued` property
     *
     * @return bool
     */
    public function getIsDiscontinued(): bool
    {
        return $this->getIsSkipped() || $this->getIsAborted();
    }

    /**
     * Getter method for computed `isProcessing` property
     *
     * @return bool
     */
    public function getIsProcessing(): bool
    {
        return in_array($this->status, static::PROCESSING_STATUSES);
    }

    /**
     * Getter method for computed `isCompleted` property
     *
     * @return bool
     */
    public function getIsCompleted(): bool
    {
        return in_array($this->status, static::COMPLETED_STATUSES);
    }

    /**
     * Getter method for computed `isSuccessfull` property
     *
     * Note: will return false if output transcoding is still in progress,
     *  but the output could later end up being successful.
     *
     * @return bool
     */
    public function getIsSuccessfull(): bool
    {
        return in_array($this->status, static::SUCCESSFUL_STATUSES);
    }

    /**
     * Getter method for computed `isFailed` property
     *
     * Note: will return false if output transcoding is still in progress,
     *  but the output could later end up being failed.
     *
     * @return bool
     */
    public function getIsFailed(): bool
    {
        return in_array($this->status, static::FAILED_STATUSES);
    }

    /**
     * Getter method for computed `width` property
     *
     * @return int|null
     */
    public function getWidth(): ?int
    {
        if (!$this->isNormalizedDimensions) {
            $this->computeDimensions();
        }

        return $this->_width;
    }

    /**
     * Getter method for computed `height` property
     *
     * @return int|null
     */
    public function getHeight(): ?int
    {
        if (!$this->isNormalizedDimensions) {
            $this->computeDimensions();
        }

        return $this->_height;
    }

    /**
     * Getter method for computed `ratio` property
     *
     * @return float|null
     */
    public function getRatio(): ?float
    {
        if (!$this->isNormalizedDimensions) {
            $this->computeDimensions();
        }

        return $this->_ratio;
    }

    // =Attributes
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    public function attributes()
    {
        $attributes = parent::attributes();

        $attributes[] = 'progress';
        $attributes[] = 'format';
        $attributes[] = 'key';
        $attributes[] = 'path';
        $attributes[] = 'offsets';
        $attributes[] = 'scene';
        $attributes[] = 'watermark';
        $attributes[] = 'urls';
        $attributes[] = 'type';
        $attributes[] = 'metadata';

        return $attributes;
    }

    // =Validation
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules['attrRequired'] = [ [
            'key',
            'format',
            'path',
            'type',
        ], 'required' ];

        $rules['attrBoolean'] = [ [
            'deinterlace',
            'square',
            'vflip',
            'hflip',
            'sprite',
            'vtt',
        ], 'boolean' ];

        $rules['attrNumber'] = [ [
            'duration',
        ], 'number' ];

        $rules['attrint'] = [ [
            'id',
            'jobId',
            'formatIndex',
            'blur',
            'transpose',
            'offset',
            'number',
            'interval',
        ], 'int', 'min' => 0 ];

        $rules['attrString'] = [ [
            'key',
            'status',
            'progress',
            'path',
            'if',
            'fit',
            'url',
            'type',
            'error',
            'uid',
        ], 'string' ];

        $rules['attrDateTime'] = [ [
            'dateCreated',
            'dateUpdated'
        ], DateTimeValidator::class ];

        $rules['urlsEachString'] = [ 'urls', 'each',
            'rule' => [ 'string' ] ];

        $rules['offsetsEachint'] = [ 'offsets', 'each',
            'rule' => ['int', 'min' => 0] ];

        $rules['fitInRange'] = [ 'fit', 'in', 'range' => [
            self::FIT_PAD,
            self::FIT_CROP,
        ] ];

        $rules['sceneArrayKeys'] = [ 'scene', AssociativeArrayValidator::class,
            'allowedKeys' => [ 'number', 'x' ],
            'requiredKeys' => [ 'number', 'duration' ],
        ];

        $rules['watermarkArrayKeys'] = [ 'watermark', AssociativeArrayValidator::class,
            'allowedKeys' => [ 'url', 'position' ],
            'requiredKeys' => [ 'url', 'position' ],
        ];

        $rules['typeInRange'] = [ 'type', 'in', 'range' => [
            self::TYPE_VIDEO,
            self::TYPE_AUDIO,
            self::TYPE_IMAGE,
            self::TYPE_HTTPSTREAM,
        ] ];

        $rules['urlUrl'] = [ 'url', 'url' ];
        $rules['urlsEachUrl'] = [ 'urls', 'each', 'rule' => ['url'] ];

        return $rules;
    }

    // =Fields
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    public function fields(): array
    {
        $fields = parent::fields();

        ArrayHelper::removeValue($fields, 'metadata');

        $fields[] = 'extension';
        $fields[] = 'mimeType';

        $fields[] = 'isPending';
        $fields[] = 'isSkipped';
        $fields[] = 'isAborted';
        $fields[] = 'isDiscontinued';
        $fields[] = 'isProcessing';
        $fields[] = 'isCompleted';
        $fields[] = 'isSuccessfull';
        $fields[] = 'isFailed';

        $fields[] = 'width';
        $fields[] = 'height';
        $fields[] = 'ratio';

        return $fields;
    }

    /**
     * @inheritdoc
     */
    public function extraFields(): array
    {
        $fields = parent::extraFields();

        $fields[] = 'job';
        $fields[] = 'explicitPath';
        $fields[] = 'pathFormat';
        $fields[] = 'container';

        $fields[] = 'metadata';

        return $fields;
    }

    /**
     * Returns Coconut API params for this output
     *
     * @return array
     */
    public function toParams(): array
    {
        $paramFields = $this->paramFields();
        $params = $this->toArray($paramFields);

        // 'container' is not a format param supported by Coconut
        ArrayHelper::remove($params['format'], 'container');

        // when sent as parameter, duration must be an int
        $duration = $params['duration'] ?? null;
        if ($duration) $params['duration'] = round($duration, 0);

        return JobHelper::cleanParams($params);
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
        $inputAsset = $input ? $input->getAsset() : null;
        $inputUrl = $input ? $input->getUrl() : null;

        $path = null;
        $filename = null;

        if ($inputAsset)
        {
            // prepend with input asset's volume handle,
            // but only if the output will not be stored in the same volume
            $storage = $job->getStorage();

            if ($storage->volumeId
                && $storage->volumeId == $inputAsset->volumeId)
            {
                $path = $inputAsset->folderPath;
            } else {
                $path = $inputAsset->volume->handle.'/'.$inputAsset->folderPath;
            }

            $filename = pathinfo($inputAsset->filename, PATHINFO_FILENAME);
        }

        else if ($inputUrl)
        {
            $path = parse_url($inputUrl, PHP_URL_PATH);
            $path = (pathinfo($path, PATHINFO_DIRNAME).'/'.
                pathinfo($path, PATHINFO_FILENAME));

            $filename = pathinfo($path, PATHINFO_FILENAME);
        }

        if ($path) { // normalize separators in path
            $path = JobHelper::normalizePath($path);
        }

        $vars = [
            'key' => $this->getKey(),
            'hash' => $input ? $input->getUrlHash() : null,
            'path' => $path,
            'filename' => $filename,
            'ext' => $format ? JobHelper::formatExtension($format) : null,
        ];

        return $vars;
    }

    /**
     * Returns parameter field names supported by the Coconut API
     *
     * @return array
     */
    protected function paramFields(): array
    {
        $fields = [
            'key',
            'path',
            'if',
            'format',
        ];

        if ($this->type == 'video' || $this->type == 'image') {
            $fields[] = 'fit';
            $fields[] = 'transpose';
            $fields[] = 'vflip';
            $fields[] = 'hflip';
            $fields[] = 'watermark';
        }

        if ($this->type == 'video' || $this->type == 'audio') {
            $fields[] = 'offset';
            $fields[] = 'duration';
        }

        if ($this->type == 'image')
        {
            $fields[] = 'square';
            $fields[] = 'blur';

            if ($this->getContainer() == 'gif') {
                $fields[] = 'scene';
            } else {
                $fields[] = 'offsets';
                $fields[] = 'interval';
                $fields[] = 'number';
                $fields[] = 'sprite';
                $fields[] = 'vtt';
            }
        }

        return $fields;
    }

    /**
     * Computes Output dimensions, based on Output params submitted to Coconut
     * and Output metadata pulled from the Coconut API and Notifications.
     */
    protected function computeDimensions(): void
    {
        $width = null;
        $height = null;
        $ratio = null;

        // get dimensions from metadata
        if ($this->type == 'video'
            && !empty($metadata = $this->getMetadata())
            && !empty($vstream = $this->videoStream($metadata)))
        {
            $width = $vstream['width'] ?? null;
            $height = $vstream['height'] ?? null;
            $ratio = $this->calcRatio($width, $height);
        }

        else if ($this->type != 'audio')
        {
            // get dimensions from output format params
            $format = $this->getFormat();
            $resolution = explode('x', $format['resolution'] ?? 'x');
            $width = (int)$resolution[0];
            $height = (int)$resolution[1];

            // calculate missing dimension(s) based on input metadata
            if ((!$width || !$height)
                && ($job = $this->getJob())
                && ($input = $job->getInput())
                && !empty($metadata = $input->getMetadata())
                && !empty($vstream = $this->videoStream($metadata)))
            {
                $inputWidth = $vstream['width'] ?? null;
                $inputHeight = $vstream['height'] ?? null;
                $inputRatio = $this->calcRatio($inputWidth, $inputHeight);

                if ($inputRatio)
                {
                    if (!$width) $width = round($height * $inputRatio);
                    if (!$height) $height = round($width / $inputRatio);
                }
            }

            $ratio = $this->calcRatio($width, $height);
        }

        $this->_width = $width;
        $this->_height = $height;
        $this->_ratio = $ratio;

        $this->isNormalizedDimensions = true;
    }

    /**
     * Extract (first) video stream from given metadata
     *
     * @param array $metadata
     *
     * @return array|null
     */
    protected function videoStream( array $metadata ): ?array
    {
        if (empty($streams = $metadata['streams'] ?? null)) {
            return null;
        }

        return ArrayHelper::firstWhere($streams, 'codec_type', 'video');
    }

    /**
     * Calculates rounded ratio for given width and height.
     * The result will be rounded to 3 decimal places
     *
     * @param int|float|null $width
     * @param int|float|null $height
     *
     * @return float|null
     */
    protected function calcRatio(
        int|float|null $width,
        int|float|null $height
    ): ?float
    {
        if ($width && $height) {
            return ceil(1000 * ($width / $height)) / 1000;
        }

        return null;
    }
}
