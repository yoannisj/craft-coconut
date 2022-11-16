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
use yoannisj\coconut\models\ServiceCredentials;
use yoannisj\coconut\helpers\JobHelper;

/**
 * Class representing notification params for the Coconut API
 */
class Notification extends Model
{
    // =Static
    // =========================================================================

    /**
     * @var string
     */
    const EVENT_INPUT_TRANSFERRED = 'input.transferred';

    /**
     * @var string
     */
    const EVENT_OUTPUT_COMPLETED = 'output.completed';

    /**
     * @var string
     */
    const EVENT_OUTPUT_FAILED = 'output.failed';

    /**
     * @var string
     */
    const EVENT_JOB_COMPLETED = 'job.completed';

    /**
     * @var string
     */
    const EVENT_JOB_FAILED = 'job.failed';

    // =Properties
    // =========================================================================

    /**
     * The notification type (either 'http' or 'sns')
     *
     * @var string|null
     */
    public ?string $type = null;

    /**
     * The URL Coconut should send notifications to.
     *
     * @var string|null
     */
    public ?string $url = null;

    /**
     * Additional query parameters that Coconut should send along with
     * notification requests.
     *
     * @var array
     */
    public array $params = [];

    /**
     * @var ServiceCredentials|null Credentials for SNS service notifications
     *  Applies only if `type` is set to 'sns'
     */
    private ?ServiceCredentials $_credentials;

    /**
     * Region used by SNS service notifications.
     * Applies only if `type` is set to 'sns'.
     *
     * @var string|null
     */
    public ?string $region = null;

    /**
     * Topic ARN for SNS service notifications.
     * Applies only if `type` is set to 'sns'
     *
     * @var string|null
     */
    public ?string $topicArn = null;

    /**
     * Whether the payload of Coconut notifications should include
     * metadata information.
     *
     * @var bool
     */
    public bool $metadata = false;

    /**
     * Whether Coconut should send a notification for each and every event.
     *
     * @var bool
     */
    public bool $events = false;

    // =Public Methods
    // =========================================================================

    // =Properties
    // -------------------------------------------------------------------------

    // =Behaviors
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    public function defineBehaviors(): array
    {
        $behaviors = parent::defineBehaviors();

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

        return JobHelper::cleanParams($params);
    }

    // =Protected Methods
    // =========================================================================

    // =Private Methods
    // =========================================================================

}


