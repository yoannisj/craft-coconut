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

/**
 *
 */

class OutputEvent extends Event
{
    /**
     * @var Output|null
     */

    public $output;

    /**
     * @var bool|null
     */

    public $isNew;
}
