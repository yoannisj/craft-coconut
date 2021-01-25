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
 * RegisterVoluemAdaptersEvent class.
 */

class VolumeAdaptersEvent extends Event
{
    // =Properties
    // =========================================================================

    /**
     * @var array Associates volume types with a volume adapter class
     */

    public $adapters = [];

}