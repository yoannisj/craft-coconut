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
use yoannisj\coconut\models\Output;

/**
 * Model for transcoding Output events
 */
class OutputEvent extends Event
{
    /**
     * @var Output|null Output associated with the event
     */
    public Output $output;

    /**
     * @var bool Whether the associated Output is new (i.e. it was never saved yet)
     */
    public bool $isNew = false;
}
