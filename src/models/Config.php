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

use yii\base\InvalidConfigException;
use yii\base\InvalidArgumentException;
use yii\validators\InlineValidator;

use Craft;
use craft\base\VolumeInterface;
use craft\base\Model;
use craft\validators\HandleValidator;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use craft\helpers\FileHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\base\VolumeAdapterInterface;
use yoannisj\coconut\helpers\ConfigHelper;

/**
 * 
 */

class Config extends Model
{
    // =Properties
    // =========================================================================

    /**
     * @var array
     */

    private $_vars;

    /**
     * @var int | null
     */

    private $_sourceAssetId;

    /**
     * @var \craft\elements\Asset
     */

    private $_sourceAsset;

    /**
     * @var string Public url of source video to transcode
     */

    private $_source;

    /**
     * @var array
     */

    private $_sourceVariables;

    /**
     * @var string The volume where output files should be stored.
     *  Defaults to 'auto', which will use same volume as asset sources,
     *  or the global `outputVolume` setting if the source is a url.
     */

    public $outputVolume;

    /**
     * @var \craft\base\VolumeInterface
     */

    private $_outputVolumeModel;

    /**
     * @var \yoannisj\coconut\base\VolumeAdapterInterface
     */

    private $_outputVolumeAdapter;

    /**
     * @var string The path where output files should be stored in the volume.
     *  Defaults to the global `outputPath` setting.
     */

    private $_outputPathFormat = null;

    /**
     * @var bool
     */

    private $_isStaleOutputPath = true;

    /**
     * @var array
     */

    private $_outputs;

    /**
     * @var array
     */

    private $_normalizedOutputs;

    /**
     * @var aray
     */

    private $_outputUrls;

    // =Public Methods
    // =========================================================================

    /**
     * 
     */

    // public function __serialize()
    // {
    //     return $this->getAttributes();
    // }

    /**
     * 
     */

    public function __sleep()
    {
        $fields = $this->fields();
        $props = [];

        foreach ($fields as $field)
        {
            if (property_exists($this, $field)) {
                $props[] = $field;
            } else if (property_exists($this, '_' . $field)) {
                $props[] = '_'.$field;
            }
        }

        return $props;
        // return $this->toArray();
    }

    // =Attributes
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function attributes()
    {
        $attributes = parent::attributes();

        $attributes[] = 'vars';
        $attributes[] = 'sourceAssetId';
        $attributes[] = 'source';
        $attributes[] = 'outputs';
        $attributes[] = 'outputPathFormat';

        return $attributes;
    }

    /**
     * @param array | null $value
     */

    public function setVars( array $value = null )
    {
        $this->_vars = $value;
    }

    /**
     * @return array
     */

    public function getVars(): array
    {
        return $this->_vars ?? [];
    }

    /**
     * @param int | null
     */

    public function setSourceAssetId( int $value = null )
    {
        $this->_sourceAssetId = $value;
        $this->_sourceAsset = null;
        $this->_source = null;

        if ($this->outputVolume == 'auto') {
            $this->_outputVolumeModel = null;
        }

        /* output path depends on source variables */
        $this->_sourceVariables = null;
        $this->_isStaleOutputPath = true;
    }

    /**
     * @return int | null
     */

    public function getSourceAssetId()
    {
        return $this->_sourceAssetId;
    }

    /**
     * @param string | \craft\elements\Asset | int | null $value
     */

    public function setSource( $value )
    {
        if (is_numeric($value))
        {
            $this->_sourceAssetId = (int)$value;
            $this->_sourceAsset = null;
            $this->_source = null;
        }

        else if (is_string($value))
        {
            $this->_sourceAssetId = null;
            $this->_sourceAsset = null;
            $this->_source = $value;
        }

        else if ($value instanceof Asset)
        {
            $this->_sourceAssetId = $value->id;
            $this->_sourceAsset = $value;
            $this->_source = $value->url;
        }

        if ($this->outputVolume == 'auto') {
            $this->_outputVolumeModel = null;
        }

        /* output path and urls depend on source variables */
        $this->_sourceVariables = null;
        $this->_isStaleOutputPath = true;
    }

    /**
     * @return string | null
     */

    public function getSource()
    {
        if (!isset($this->_source)
            && ($asset = $this->getSourceAsset()))
        {
            $this->_source = $asset->url;
        }

        return $this->_source;
    }

    /**
     * @param string $vlaue
     */

    public function setOutputPathFormat( string $value = null )
    {
        if (empty($value)) {
            $value = Coconut::$plugin->getSettings()->outputPathFormat;
        }

        $this->_outputPathFormat = $value;
        $this->_isStaleOutputPath = true;
    }

    /**
     * @return string
     */

    public function getOutputPathFormat(): string
    {
        return $this->_outputPathFormat ?? Coconut::$plugin->getSettings()->outputPathFormat;
    }

    /**
     * @param array | null $value
     */

    public function setOutputs( array $value = null )
    {
        $this->_outputs = $value;
        $this->_normalizedOutputs = null;
        $this->_outputUrls = null;
    }

    /**
     * @return array | null
     */

    public function getOutputs()
    {
        if ($this->_isStaleOutputPath
            || !isset($this->_normalizedOutputs)
        ) {
            $outputs = $this->normalizeOutputs($this->_outputs);
            $this->_normalizedOutputs = $outputs;
        }

        return $this->_normalizedOutputs;
    }

    // =Validation
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function rules()
    {
        $rules = parent::rules();

        $rules['attrsRequired'] = [ ['source', 'outputs'], 'required' ];

        $rules['attrsInteger'] = [ ['sourceAssetId'], 'integer' ];
        $rules['attrsString'] = [ ['outputPathFormat'], 'string' ];
        $rules['attrsEachString'] = [ ['vars', 'outputs'], 'each', 'rule' => 'string' ];

        return $rules;
    }

    // =Fields
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function extraFields()
    {
        $fields = parent::extraFields();

        $fields[] = 'webhook';
        $fields[] = 'sourceAsset';
        $fields[] = 'sourceVariables';
        $fields[] = 'outputVolumeModel';
        $fields[] = 'outputVolumeAdapter';
        $fields[] = 'outputUrls';
        $fields[] = 'jobParams';
        $fields[] = 'outputCriteria';

        return $fields;
    }

    /**
     * 
     */

    public function getWebhook(): string
    {
        return UrlHelper::actionUrl('coconut/jobs/complete');
    }

    /**
     * @return \craft\elements\Asset | null
     */

    public function getSourceAsset()
    {
        if (!isset($this->_sourceAsset))
        {
            $asset = null;

            if (isset($this->_sourceAssetId))
            {
                $asset = Asset::find()
                    ->id($this->_sourceAssetId)
                    ->one();            
            }

            $this->_sourceAsset = $asset;
        }

        return $this->_sourceAsset;
    }

    /**
     * @return array
     */

    public function getSourceVariables(): array
    {
        if (!isset($this->_sourceVariables))
        {
            $this->_sourceVariables = $this->resolveSourceVariables();
        }

        return $this->_sourceVariables;
    }

    /**
     * @return \craft\base\volumeInterface
     */

    public function getOutputVolumeModel(): VolumeInterface
    {
        if (!isset($this->_outputVolumeModel)) {
            $this->_outputVolumeModel =  $this->resolveOutputVolumeModel();
        }

        return $this->_outputVolumeModel;
    }

    /**
     * 
     */

    public function getOutputVolumeAdapter(): VolumeAdapterInterface
    {
        if (!isset($this->_outputVolumeAdapter))
        {
            $volume = $this->getOutputVolumeModel();
            $adapter = Coconut::$plugin->getVolumeAdapter($volume);

            $this->_outputVolumeAdapter = $adapter;
        }

        return $this->_outputVolumeAdapter;
    }

    /**
     * @return array
     */

    public function getOutputUrls(): array
    {
        if ($this->_isStaleOutputPath
            || !isset($this->_outputUrls))
        {
            $outputs = $this->_outputs;
            $this->_outputUrls = $this->normalizeOutputUrls($outputs);
        }

        return $this->_outputUrls;
    }

    /**
     * @return array 
     */

    public function getJobParams(): array
    {
        return [
            'vars' => $this->getVars(),
            'source' => $this->getSource(),
            'webhook' => $this->getWebhook(),
            'outputs' => $this->getOutputs(),
        ];
    }

    /**
     * @return array
     */

    public function getOutputCriteria(): array
    {
        $criteria = [];

        $asset = $this->getSourceAsset();

        if ($asset) {
            $criteria['sourceAssetId'] = $asset->id;
        } else {
            $criteria['source'] = $this->getSource();
        }

        $volume = $this->getOutputVolumeModel();
        $criteria['volumeId'] = $volume->id;

        $outputUrls = $this->getOutputUrls();
        $criteria['format'] = array_keys($outputUrls);
        // $criteria['url'] = array_values($outputUrls);

        return $criteria;
    }

    // =Operations
    // -------------------------------------------------------------------------

    /**
     * Returns a new config model, which only includes given list of output
     * formats.
     *
     * @param array $formats
     *
     * @return \yoannisj\coconut\models\Config
     */

    function forFormats( array $formats )
    {
        $rawOutputs = $this->_outputs;
        $formatOutputs = [];

        foreach ($formats as $format => $options)
        {
            if (is_numeric($format)) {
                $format = $options;
                $options = null;
            }

            if (in_array($format, $rawOutputs)) {
                $formatOutputs[] = $format;
            }

            else if (array_key_exists($format, $rawOutputs)) {
                $formatOutputs[$format] = $rawOutputs[$format];
            }
        }

        $newConfig = clone $this;
        $newConfig->outputs = $formatOutputs;

        return $newConfig;
    }

    // =Protected Method
    // =========================================================================

    /**
     * @return
     */

    protected function resolveOutputVolumeModel(): VolumeInterface
    {
        $model = null;
        $volume = $this->outputVolume;

        if ($volume === 'auto')
        {
            $asset = $this->getSourceAsset();
            if ($asset) $model = $asset->getVolume();
        }

        else if (is_numeric($volume)) {
            $model = Craft::$app->getVolumes()->getVolumeById($volume);
        } else if (is_string($volume)) {
            $model = Craft::$app->getVolumes()->getVolumeByHandle($volume);
        }

        if (!$model) {
            $model = Coconut::$plugin->getSettings()->getOutputVolume();
        }

        return $model;
    }

    /**
     * @return array
     */

    protected function resolveSourceVariables(): array
    {
        $sourceUrl = $this->getSource();
        $sourceAsset = $this->getSourceAsset();

        if (empty($sourceUrl)) {
            return [];
        }

        if ($sourceAsset)
        {
            $volume = $sourceAsset->getVolume()->handle;
            $folderPath = $sourceAsset->folderPath;
            $filename = $sourceAsset->getFilename(false);
        }

        else
        {
            $host = parse_url($sourceUrl, PHP_URL_HOST);
            $path = parse_url($sourceUrl, PHP_URL_PATH);
            $parts = explode('/', $path);

            $count = count($parts);

            $volume = str_replace('.', '_', $host);
            $folderPath = implode('/', array_slice($parts, 0, $count - 1));
            $filename = $parts[$count - 1];
        }

        return [
            'volume' => $volume,
            'folderPath' => $folderPath,
            'filename' => $filename,
            'hash' => md5($sourceUrl),
        ];
    }

    /**
     * @return array
     */

    protected function normalizeOutputs( array $value = null ): array
    {
        $outputs = [];

        if (empty($value)) {
            return $outputs;
        }

        $vars = $this->getVars();
        $volume = $this->getOutputVolumeModel();
        $adapter = $this->getOutputVolumeAdapter();

        foreach ($value as $format => $options)
        {
            if (is_numeric($format))
            {
                $format = $options;
                $options = null;
            }

            // render and output path template
            $outputPath = $this->renderOutputPath($format, $options);

            // transform path into public url
            $output = $adapter::outputUploadUrl($volume, $outputPath);
            $output = ConfigHelper::resolveOutput($format, $output, $options, $vars);

            $outputs[$format] = $output;
        }

        return $outputs;
    }

    /**
     * @return array
     */

    protected function normalizeOutputUrls( array $value = null ): array
    {
        $urls = [];

        if (empty($value)) {
            return $urls;
        }

        $vars = $this->getVars();
        $volume = $this->getOutputVolumeModel();
        $adapter = $this->getOutputVolumeAdapter();

        foreach ($value as $format => $options)
        {
            if (is_numeric($format))
            {
                $format = $options;
                $options = null;
            }

            // render and output path template
            $outputPath = $this->renderOutputPath($format, $options);
            // transform path into public url
            $url = $adapter::outputPublicUrl($volume, $outputPath);

            // resolve public url into resulting output urls
            $urls[$format] = ConfigHelper::resolveOutputUrls($format, $url, $options, $vars);
        }

        return $urls;
    }

    /**
     * @return string
     */

    protected function renderOutputPath( string $format, string $options = null ): string
    {
        // 1. render path based on config template
        $pathFormat = $this->getOutputPathFormat();
        $sourceVariables = $this->getSourceVariables();

        $props = (object)array_merge($sourceVariables, [
            'format' => ConfigHelper::getFormatSegment($format),
            'ext' => ConfigHelper::getFormatExtension($format),
        ]);

        $path = Craft::$app->getView()->renderObjectTemplate($pathFormat, $props);

        // 2. Remove references to vars and fix missing `#num#`, extension, etc.
        $path = ConfigHelper::formatPath($format, $path, $options);

        return $path;
    }
}