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
use craft\base\Model;
use craft\helpers\ArrayHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\behaviors\PropertyAliasBehavior;
use yoannisj\coconut\models\Credentials;

/**
 * Class representing notification params for the Coconut API
 */

class Notification extends Model
{
    // =Properties
    // =========================================================================

    /**
     * @var string The notification type (either 'http' or 'sns')
     */

    public $type;

    /**
     * @var string The URL Coconut should send notifications to
     */

    public $url;

    /**
     * @var array Additional query parameters that Coconut should send along
     *  with notification requests
     */

    public $params = [];

    /**
     * @var Credentials|null Credentials for SNS service notifications
     *  Applies only if `type` is set to 'sns'
     */

    private $_credentials;

    /**
     * @var string Region used by SNS service notifications
     *  Applies only if `type` is set to 'sns'
     */

    public $region;

    /**
     * @var string Topci ARN for SNS service notifications
     *  Applies only if `type` is set to 'sns'
     */

    public $topicArn;

    /**
     * @var boolean Whether the payload of Coconut notifications should include
     *  metadata information
     */

    public $metadata = false;

    /**
     * @var boolean Whether Coconut should send a notification for each and
     *  every event
     */

    public $events = false;

    // =Public Methods
    // =========================================================================

    // =Properties
    // -------------------------------------------------------------------------

    // =Behaviors
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function behaviors()
    {
        $behaviors = parent::behaviors();

        $behaviors[] =  [
            'class' => PropertyAliasBehavior::class,
            'camelCasePropertyAliases' => true, // e.g. `$this->topic_arn => $this->topicArn`
        ];

        return $behaviors;
    }

    // =Fields
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */

    public function fields()
    {
        $fields = [
            'type',
            'metadata',
            'events',
        ];

        switch ($this->type)
        {
            case 'http':
                $fields[] = 'url';
                break;
            case 'sns':
                $fields[] = 'region';
                $fields[] = 'credentials';
                $fields[] = 'topic_arn';
                break;
        }

        return $fields;
    }

    // =Protected Methods
    // =========================================================================

    // =Private Methods
    // =========================================================================

}


