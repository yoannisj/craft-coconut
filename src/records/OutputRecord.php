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

namespace yoannisj\coconut\records;

use yii\db\ActiveQueryInterface;

use Craft;
use craft\db\ActiveRecord;
use craft\errors\VolumeException;
use craft\helpers\Json as JsonHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\records\JobRecord;

/**
 * Active record for transcoding Outputs in database
 *
 * @property $id
 * @property $inputId
 * @property $coconutJobId
 * @property $format
 * @property $url
 * @property $metadata
 * @property $volumeId
 * @property $status
 * @property $dateCreated
 * @property $dateUpdated
 * @property $uid
 */
class OutputRecord extends ActiveRecord
{
    // =Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Coconut::TABLE_OUTPUTS;
    }

    // =Properties
    // =========================================================================

    // =Public Methods
    // =========================================================================

    // =Attributes
    // -------------------------------------------------------------------------

    /**
     * @param string|array|null $offsets
     *
     * @return static Back-reference for method chaining
     */
    public function setOffsets( string|array|null $offsets ): static
    {
        if (is_array($offsets)) {
            $offsets = implode(',', $offsets);
        }

        $this->offsets = $offsets;

        return $this;
    }

    /**
     * @param string|array|null $urls
     *
     * @return static Back-reference for method chaining
     */
    public function setUrls( string|array|null $urls ): static
    {
        if (is_array($urls)) {
            $this->urls = JsonHelper::encode($urls);
        }

        return $this;
    }

    /**
     * @param string|array|null $metadata
     *
     * @return static Back-reference for method chaining
     */
    public function setMetadata( string|array|null $metadata ): static
    {
        if ($metadata && is_array($metadata)) {
            $metadata = JsonHelper::encode($metadata);
        }

        $this->metadata = $metadata;

        return $this;
    }

    /**
     * @return array
     */
    public function getMetadata(): array
    {
        $metadata = $this->metadata ?? [];

        if (is_string($metadata)) {
            $metadata = JsonHelper::decode($metadata);
        }

        return $metadata ;
    }

    // =Relations
    // -------------------------------------------------------------------------

    /**
     * Returns the coconut job
     *
     * @return ActiveQueryInterface The relational query object
     */
    public function getJob(): ActiveQueryInterface
    {
        return $this->hasOne(JobRecord::class, ['id' => 'jobId']);
    }

    // =Events
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    public function afterDelete()
    {
        if (isset($this->volumeId))
        {
            $volume = Craft::$app->getVolumes()->getVolumeById($this->volumeId);
            if ($volume)
            {
                $volumeUrl = $volume->getRootUrl();
                $url = $this->url;

                $path = trim(str_replace($volumeUrl, '', $url), '/');

                try {
                    $volume->deleteFile($path);
                } catch (VolumeException $e) {
                    // Fail silently (log a warning instead of throwing an error)
                    Craft::warning($e->getMessage());
                }
            }
        }

        parent::afterDelete();
    }

    // =Protected Methodss
    // =========================================================================

    // =Private Methodss
    // =========================================================================

}
