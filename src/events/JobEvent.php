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
     * @var bool
     */

    public $updateOutputs = true;

    /**
     * @var object
     */

    public $jobInfo;

    /**
     * @var \yoannisj\coconut\models\Output[] | null
     */

    public $jobOutputs;
}
