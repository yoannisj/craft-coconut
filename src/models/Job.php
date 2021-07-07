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

use Craft;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Input;
use yoannisj\coconut\models\Storage;
use yoannisj\coconut\models\Output;
use yoannisj\coconut\helpers\ConfigHelper;

/**
 * Model representing a Coconut job and its data
 * 
 * @property Input $input
 * @property integer $inputAssetId
 * @property string $inputUrl
 * @property string $inputUrlHash
 * @property string $inputStatus
 * @property string $inputMetadata
 * @property string $inputExpires
 * @property integer $storageVolumeId
 * @property Volume $storageVolume
 * @property string $storageHandle
 * @property array $storageSettings
 * @property Storage $storage
 * @property Output[] $outputs
 * @property Config $config
 */

class Job extends Model
{
    // =Static
    // =========================================================================

    // =Properties
    // =========================================================================

    /**
     * @var integer
     */

    private $_inputAssetId;

    /**
     * @var integer
     */

    private $_inputUrl;

    /**
     * @var Input|null
     */

    private $_input;

    /**
     * @var integer
     */

    public $storageVolumeId;

    /**
     * @var Volume|null
     */

    private $_storageVolume;

    /**
     * @var boolean
     */

    protected $isNormalizedStorageVolume;

    /**
     * @var string
     */

    private $storageHandle;

    /**
     * @var array|null
     */

    private $_storageSettings;

    /**
     * @var Storage|null
     */

    private $_storage;

    /**
     * @var boolean
     */

    protected $isNormalizedStorage = false;

    // =Public Methods
    // =========================================================================

    // =Properties
    // -------------------------------------------------------------------------

    /**
     * Setter method for nested `input` model property
     * 
     * @param Input|null $input;
     */

    public function setInput( Input $input = null )
    {
        $this->_input = $input;
    }

    /**
     * Getter method for nested `input` model property
     * 
     * @return Input
     */

    public function getInput(): Input
    {
        if (!isset($this->_input)) {
            $this->_input = new Input();
        }

        return $this->_input;
    }

    /**
     * Setter method for delegated `inputAssetId` property
     * 
     * @param integer|null $assetId
     */

    public function setInputAssetId( int $assetId = null )
    {
        $this->getInput()->assetId = $assetId;
    }

    /**
     * Getter method for delegated `inputAssetId` property
     * 
     * @return integer|null
     */

    public function getInputAssetId()
    {
        return $this->getInput()->assetId ?? null;
    }

    /**
     * Setter method for delegated `inputUrl` property
     * 
     * @param string|null $url
     */

    public function setInputUrl( string $url = null )
    {
        $this->getInput()->url = $url;
    }

    /**
     * Getter method for delegated `inputUrl` property
     * 
     * @return string|null
     */

    public function getInputUrl()
    {
        return $this->getInput()->url ?? null;
    }

    /**
     * Setter method for delegated `inputUrl` property
     * 
     * @param string|null $urlHash
     */

    public function setInputUrlHash( string $urlHash = null )
    {
        $this->getInput()->urlHash = $urlHash;
    }

    /**
     * Getter method for delegated `inputUrlHash` property
     * 
     * @return string|null
     */

    public function getInputUrlHash()
    {
        return $this->getInput()->urlHash ?? null;
    }

    /**
     * Setter method for delegated `inputMetadata` property
     * 
     * @param string|null $status
     */

    public function setInputStatus( string $status = null )
    {
        $this->getInput()->status = $status;
    }

    /**
     * Getter method for delegated `inputMetadata` property
     * 
     * @return string|null
     */

    public function getInputStatus()
    {
        return $this->getInput()->status ?? null;
    }

    /**
     * Setter method for delegated `inputMetadata` property
     * 
     * @param string|array|null $metadata
     */

    public function setInputMetadata( $metadata = null )
    {
        $this->getInput()->metadata = $metadata;
    }

    /**
     * Getter method for delegated `inputMetadata` property
     * 
     * @return array|null
     */

    public function getInputMetadata()
    {
        return $this->getInput()->metadata ?? null;
    }

    /**
     * Setter method for delegated `inputExpires` property
     * 
     * @param string|Datetime $expires
     */

    public function setInputExpires( $expires = null )
    {
        $this->getInput()->expires = $expires;
    }

    /**
     * Getter method for delegated `inputExpires` property
     * 
     * @return Datetime|null
     */

    public function getInputExpires()
    {
        return $this->getInput()->expires ?? null;
    }

    /**
     * Setter method for resolved `storageVolume` property
     * 
     * @param string|Volume\null $volume
     */

    public function setStorageVolume( $volume = null )
    {
        if (is_string($volume))
        {
            $this->storageHandle = $volume;

            // use storageHandle to resolve volume next time it is accessed
            $this->storageVolumeId = null;
            // force re-calculation of storage volume next time it is accessed
            $this->isNormalizedStorageVolume = false;
        }

        else if ($volume instanceof Volume)
        {
            $this->storageVolumeId = $volume->id;
            $this->storageHandle = $volume->handle;
            $this->_storageVolume = $volume;
            $this->isNormalizedStorageVolume = true;
        }

        else if ($volume === null)
        {
            $this->storageVolumeId = null;
            $this->_storageVolume = null;
            $this->isNormalizedStorageVolume = true;
        }
    }

    /**
     * Getter method for resolved `storageVolume` property
     * 
     * @return Volume|null
     */

    public function getStorageVolume()
    {
        if (!$this->isNormalizedStorageVolume)
        {
            $craftVolumes = Craft::$app->getVolumes();
            $storageVolume = null;

            if (!empty($this->storageVolumeId)) {
                $storageVolume = $craftVolumes->getVolumeById($this->storageVolumeId);
            }

            else if (!empty($this->storageHandle))
            {
                // resolve to a volume only if this is not a named storage handle
                $namedStorage = Coconut::$plugin->getSettings()
                    ->storages[$this->storageHandle];

                if (!$namedStorage) {
                    $storageVolume = $craftVolumes->getVolumeByHandle($this->storageVolumeId);
                }

                if ($storageVolume) { // keep storageVolumeId in sync
                    $this->storageVolumeId = $storageVolume->id;
                }
            }

            $this->_storageVolume = $storageVolume;
            $this->isNormalizedStorageVolume = true;
        }

        return $this->_storageVolume;
    }

    /**
     * @param array|null $settings
     */

    public function setStorageSettings( array $settings = null )
    {
        $this->_storageSettings = $settings;
        $this->isNormalizedStorage = false;
    }

    /**
     * @return array|null
     */

    public function getStorageSettings()
    {
        return $this->getStorage()->toArray() ?? null;
    }

    /**
     * Getter method for computed `storage` property
     * 
     * @return Storage|null
     */

    public function getStorage()
    {
        if (!$this->isNormalizedStorage)
        {
            $storage = null;

            // prioritize storage handle (could be a named storage handle or a volume handle)
            if (!empty($this->storageHandle)) {
                $storage = ConfigHelper::parseStorage($this->storageHandle);
            }
            
            // than check if storage settings were set directly
            else if (!empty($this->_storageSettings)) {
                $storage = new Storage($this->_storageSettings);
            }

            else { // or check if a storage volume was set
                $storageVolume = $this->getStorageVolume();
                if ($storageVolume) $storage = Coconut::volumeStorage($storageVolume);
            }

            $this->_storage = $storage;
            $this->isNormalizedStorage = true;
        }

        return $this->_storage;
    }

    /**
     * Getter method for read-only `outputs` property
     * 
     * @return Output[]
     */

    public function getOutputs(): array
    {
        if ($this->id) {
            return Coconut::$plugin->getOutputs()->getOutputsByJobId($this->id);
        }

        return [];
    }

    /**
     * Getter method for read-only `config` property
     * 
     * @return Config
     */

    public function getConfig()
    {
        if (!isset($this->_config))
        {
            $this->_config = new Config([
                'input' => $this->getInput(),
                'storage' => $this->getStorage(),
                'outputs' => $this->getOutputs(),
            ]);
        }

        return $this->_config;
    }

    // =Attributes
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function attributes()
    {
        $attributes = parent::attributes();

        $attributes[] = 'inputAssetId';
        $attributes[] = 'inputUrl';
        $attributes[] = 'storageVolumeId';
        $attributes[] = 'storageHandle';
        $attributes[] = 'storageSettings';

        return $attributes;
    }

    // =Fields
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function fields()
    {
        $fields = parent::fields();

        $fields[] = 'inputUrlHash';

        return $fields;
    }

    /**
     * @inheritdoc
     */

    public function extraFields()
    {
        $fields = parent::extraFields();

        $fields[] = 'input';
        $fields[] = 'storage';
        $fields[] = 'outputs';
        $fields[] = 'config';

        return $fields;
    }

    // =Protected Methods
    // =========================================================================

    // =Private Methods
    // =========================================================================
}