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
use yoannisj\coconut\helpers\JobHelper;

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

    // =Operations
    // -------------------------------------------------------------------------

    /**
     * Returns Coconut API params for this job output
     *
     * @return array
     */

    public function toParams(): array
    {
        $params = [
            'type' => $this->type,
            'events' => $this->events,
            'metadata' => $this->metadata,
        ];

        switch ($this->type)
        {
            case 'http':
                $params['url'] = JobHelper::publicUrl($this->url);
                break;
            case 'sns':
                $params['region'] = $this->region;
                $params['credentials'] = $this->credentials;
                $params['topic_arn'] = $this->topic_arn;
                break;
        }

        return $params;
    }

    // =Protected Methods
    // =========================================================================

    // =Private Methods
    // =========================================================================

}


