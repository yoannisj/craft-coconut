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
 * 
 */

class CancellableJobEvent extends JobEvent
{
    /**
     * @var bool
     */

    public $isValid = true;
}