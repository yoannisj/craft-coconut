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

use yii\base\InvalidConfigException;

use Craft;
use craft\db\ActiveRecord;
use craft\helpers\Json as JsonHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Notification;

/**
 * Active record for transcoding Jobs in database
 *
 * @property int id
 * @property string coconutId
 * @property string status
 * @property string progress
 * @property int inputAssetId
 * @property string inputUrl
 * @property string inputUrlHash
 * @property string inputStatus
 * @property string inputMetadata
 * @property Datetime inputExpires
 * @property string storageHandle
 * @property int storageVolumeId
 * @property array storageParams
 * @property array notification
 * @property Datetime createdAt
 * @property Datetime completedAt
 * @property Datetime dateCreated
 * @property Datetime dateUpdated
 */
class JobRecord extends ActiveRecord
{
    // =Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Coconut::TABLE_JOBS;
    }

    // =Public Methods
    // =========================================================================

}
