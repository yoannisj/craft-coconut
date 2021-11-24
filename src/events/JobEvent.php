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

class JobEvent extends Event
{
    /**
     * @var |yoannisj\coconut\models\Job | null
     */

    public $job;

    /**
     * @var bool|null
     */

    public $isNew;
}
