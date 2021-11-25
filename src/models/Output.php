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
 * @property Job $job
 * @property string|null $progress Progress of the output transcoding (in percentage)
 * @property array $format
 * @property string $formatString
 * @property string $explicitPath
 * @property string $type
 * @property bool $isDefaultPath Whether this output uses the default path or note
 * @property bool $isFinished Wether transcoding the output has come to an end
 * @property bool $isFruitfull Wether transcoding resulted in an output file
 * @property bool $isFruitless Wether transcoding failed to result in an output file
 */

class Output extends Model
{
    // =Static
    // =========================================================================

    const TYPE_VIDEO = 'video';
    const TYPE_AUDIO = 'audio';
    const TYPE_IMAGE = 'image';

    const FIT_PAD = 'pad';
    const FIT_CROP = 'crop';

    const STATUS_VIDEO_WAITING = 'video.waiting';
    const STATUS_VIDEO_QUEUED = 'video.queued';
    const STATUS_VIDEO_ENCODING = 'video.encoding';
    const STATUS_VIDEO_ENCODED = 'video.encoded';
    const STATUS_VIDEO_FAILED = 'video.failed';
    const STATUS_VIDEO_SKIPPED = 'video.skipped';
    const STATUS_VIDEO_ABORTED = 'video.aborted';

    const STATUS_IMAGE_WAITING = 'image.waiting';
    const STATUS_IMAGE_QUEUED = 'image.queued';
    const STATUS_IMAGE_PROCESSING = 'image.processing';
    const STATUS_IMAGE_CREATED = 'image.created';
    const STATUS_IMAGE_FAILED = 'image.failed';
    const STATUS_IMAGE_SKIPPED = 'image.skipped';
    const STATUS_IMAGE_ABORTED = 'image.aborted';

    const STATUS_HTTPSTREAM_WAITING = 'httpstream.waiting';
    const STATUS_HTTPSTREAM_QUEUED = 'httpstream.queued';
    const STATUS_HTTPSTREAM_VARIANTS_WAITING = 'httpstream.variants.waiting';
    const STATUS_HTTPSTREAM_VARIANTS_QUEUED = 'httpstream.variants.queued';
    const STATUS_HTTPSTREAM_VARIANTS_ENCODING = 'httpstream.variants.encoding';
    const STATUS_HTTPSTREAM_PACKAGING = 'httpstream.packaging';
    const STATUS_HTTPSTREAM_PACKAGED = 'httpstream.packaged';
    const STATUS_HTTPSTREAM_FAILED = 'httpstream.failed';
    const STATUS_HTTPSTREAM_SKIPPED = 'httpstream.skipped';
    const STATUS_HTTPSTREAM_ABORTED = 'httpstream.aborted';

    const FINAL_STATUSES = [
        'video.encoded', 'video.failed', 'video.skipped', 'video.aborted',
        'image.created', 'image.failed', 'image.skipped', 'image.aborted',
        'httpstream.packaged', 'httpstream.failed', 'httpstream.skipped', 'httpstream.aborted',
    ];

    const FRUITFULL_STATUSES = [
        'video.encoded', 'image.created', 'httpstream.packaged',
    ];

    const FRUITLESS_STATUSES = [
        'video.failed', 'video.skipped', 'video.aborted',
        'image.failed', 'image.skipped', 'image.aborted',
        'httpstream.failed', 'httpstream.skipped', 'httpstream.aborted',
    ];

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
     * @var string Conditional expression to determine whether the
     *  output should be created or not
     *
     * @see https://docs.coconut.co/jobs/api#conditional-outputs
     */

    public $if;

    /**
     * @var boolean Whether to deinterlace the video output
     */

    public $deinterlace = false;

    /**
     * @var boolean Whether to crop the resulting output image to a squae
     */

    public $square = false;

    /**
     * @var integer Intensity of blur effect to apply to resulting image output.
     *  Value must range from `1` to `5`.
     */

    public $blur;

    /**
     * @var string Whether to 'crop' or 'pad' the resulting output
     */

    public $fit;

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
     * @var integer The duration (in seconds) after which the resulting output should start
     */

    public $offset;

    /**
     * @var integer The duration (in seconds) at which resulting output should be cut
     */

    private $_duration;

    /**
     * @var bool Whether `duration` property has already been normalized
     */

    protected $isNormalizedDuration;

    /**
     * @var integer Number of image outputs generated
     */

    public $number;

    /**
     * @var integer Interval (in seconds) between each image output to generate
     */

    public $interval;

    /**
     * @var integer[] Offsets (in seconds) at which to generate an image output
     */

    private $_offsets;

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

    private $_scene;

    /**
     * @var array Url and position of watermark image to add to the resulting output.
     *  Supported keys are:
     * - 'url' => URL to the PNG watermark image file (transparency supported)
     * - `position` => Either 'topleft', 'topright', 'bottomleft' or 'bottomright'
     *
     * @see https://docs.coconut.co/jobs/outputs-videos#watermark
     */

    private $_watermark;

    /**
     * @var string Latest output status from Coconut job
     * @see https://docs.coconut.co/jobs/api#job-status
     */

    public $status;

    /**
     * @var string|null Progress (in percentage) for creation of this output
     */

    private $_progress = '0%';

    /**
     * @var string Error message associated with this output
     * @note This is only relevant if output has failed `status`
     */

    public $error;

    /**
     * @var string The URL to the generated output file (once stored)
     */

    public $url;

    /**
     * @var string[] The list of URL's to the generated output files (once stored)
     */

    private $_urls;

    /**
     * @var array|null
     */

    private $_metadata;

    /**
     * @var integer Width dimension of output
     */

    private $_width;

    /**
     * @var integer Height dimension of output
     */

    private $_height;

    /**
     * @var float Aspect ratio of output (i.e. `width / height`, rounded up to 4 decimal points)
     */

    private $_ratio;

    /**
     * @var bool Whether dimension properties have been already computed/normalized
     */

    protected $isNormalizedDimensions;

    /**
     * @var \Datetime
     */

    public $dateCreated;

    /**
     * @var \Datetime
     */

    public $dateUpdated;

    /**
     * @var string
     */

    public $uid;

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
     */

    public function setJob( Job $job = null )
    {
        $this->jobId = $job ? $job->id : null;
        $this->_job = $job;
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
     * Setter method for defaulted `progress` property
     *
     * @param string|null $progress
     */

    public function setProgress( string $progress = null )
    {
        $this->_progress = $progress;
    }

    /**
     * Getter method for defaulted `progress` property
     *
     * @return string
     */

    public function getProgress()
    {
        if (!isset($this->_progress))
        {
            if ($this->getIsFinished()) {
                $this->_progress = '100%';
            }
        }

        return $this->_progress;
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
     */

    public function setOffsets( $offsets )
    {
        if (is_string($offsets)) {
            $offsets = explode(',', $offsets);
        }

        $this->_offsets = $offsets;
    }

    /**
     * Getter method for normalized `offsets` property
     *
     * @return array|null
     */

    public function getOffsets()
    {
        return $this->_offsets;
    }

    /**
     * Setter method for normalized `scene` property
     *
     * @param string|array|null  $scene
     */

    public function setScene( $scene )
    {
        if (is_string($scene)) {
            $scene = JsonHelper::decode($scene);
        }

        $this->_scene = $scene;
    }

    /**
     * Getter method for normalized `scene` property
     *
     * @return array|null
     */

    public function getScene()
    {
        return $this->_scene;
    }

    /**
     * Setter method for normalized `watermark` property
     *
     * @param string|array|null $watermark
     */

    public function setWatermark( $watermark )
    {
        if (is_string($watermark)) {
            $watermark = JsonHelper::decode($watermark);
        }

        $this->_watermark = $watermark;
    }

    /**
     * Getter method for normalized `watermark` property
     *
     * @return array|null
     */

    public function getWatermark()
    {
        return $this->_watermark;
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
     * Setter method for normalized `urls` property
     *
     * @param string|array|null $urls
     */

    public function setUrls( $urls )
    {
        if (is_string($urls)) {
            $urls = JsonHelper::decode($urls);
        }

        $this->_urls = $urls;
    }

    /**
     * Getter method for normalized `urls` property
     *
     * @return array
     */

    public function getUrls()
    {
        return $this->_urls;
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
     * Setter method for defaulted `type` property
     *
     * @param string|null $type
     */

    public function setType( string $type = null )
    {
        $this->_type = $type;
    }

    /**
     * Getter method for the defaulted `type` property
     *
     * @return string|null
     */

    public function getType()
    {
        if (!isset($this->_type)
            && !empty($container = $this->getContainer())
        ) {
            $this->_type = JobHelper::containerType($container);
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
     */

    public function setDuration( float $duration = null )
    {
        $this->_duration = $duration;

        if ($duration === null) {
            $this->isNormalizedDuration = false;
        }
    }

    /**
     * Getter method for defaulted `duration` property
     *
     * @return float|null
     */

    public function getDuration()
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
     * Getter method for computed `isFinished` property
     *
     * @return bool
     */

    public function getIsFinished(): bool
    {
        return in_array($this->status, static::FINAL_STATUSES);
    }


    /**
     * Getter method for computed `isFruitfull` property
     *
     * Note: will return `false` if output transcoding is still in progress,
     *  but the output could later end up being fruitfull.
     *
     * @return bool
     */

    public function getIsFruitfull(): bool
    {
        return in_array($this->status, static::FRUITFULL_STATUSES);
    }

    /**
     * Getter method for computed `isFruitless` property
     *
     * Note: will return false if output transcoding is still in progress,
     *  but the output could later end up being fruitless.
     *
     * @return bool
     */

    public function getIsFruitless(): bool
    {
        return in_array($this->status, static::FRUITLESS_STATUSES);
    }

    /**
     * Getter method for computed `width` property
     *
     * @return integer|null
     */

    public function getWidth()
    {
        if (!$this->isNormalizedDimensions) {
            $this->computeDimensions();
        }

        return $this->_width;
    }

    /**
     * Getter method for computed `height` property
     *
     * @return integer|null
     */

    public function getHeight()
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

    public function getRatio()
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

    public function rules()
    {
        $rules = parent::rules();

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

        $rules['attrInteger'] = [ [
            'id',
            'jobId',
            'formatIndex',
            'blur',
            'transpose',
            'offset',
            'number',
            'interval',
        ], 'integer', 'min' => 0 ];

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

        $rules['offsetsEachInteger'] = [ 'offsets', 'each',
            'rule' => ['integer', 'min' => 0] ];

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

    public function fields()
    {
        $fields = parent::fields();

        ArrayHelper::removeValue($fields, 'metadata');

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

        // when sent as parameter, duration must be an integer
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
            'key' => JobHelper::keyAsPath($key),
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
     *
     */

    protected function computeDimensions()
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
            $resolution = explode('x', $format['resolution'] ?? '');
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
     * @param array $metadata
     *
     * @return array|null
     */

    protected function videoStream( array $metadata )
    {
        if (empty($streams = $metadata['streams'] ?? null)) {
            return null;
        }

        return ArrayHelper::firstWhere($streams, 'codec_type', 'video');
    }

    /**
     * @param integer|null $width
     * @param integer|null $height
     *
     * @return float|null
     */

    protected function calcRatio( $width, $height )
    {
        return ($width && $height ?
            ceil(1000 * ($width / $height)) / 1000 : null);
    }
}
