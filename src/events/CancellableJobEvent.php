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

use yoannisj\coconut\events\JobEvent;

/**
 * Model for cancellable transcoding Job events
 */
class CancellableJobEvent extends JobEvent
{
    /**
     * @var bool Whether the event is valid or should be cancelled.
     */
    public $isValid = true;
}
