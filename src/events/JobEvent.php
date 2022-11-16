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

namespace yoannisj\coconut\events;

use yii\base\Event;
use yoannisj\coconut\models\Job;

/**
 * Model for transcoding Job events
 */
class JobEvent extends Event
{
    /**
     * @var Job Job associated with the event
     */
    public Job $job;

    /**
     * @var bool Whether the associated job is new (i.e. it was never saved yet)
     */
    public bool $isNew = false;
}
