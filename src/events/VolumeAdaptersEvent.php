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
use yoannisj\coconut\base\VolumeAdapterInterface;

/**
 * Model for Volume Adapter events
 */
class VolumeAdaptersEvent extends Event
{
    // =Properties
    // =========================================================================

    /**
     * @var VolumeAdapterInterface[] Map associating volume types with a volume adapter class
     */
    public $adapters = [];

}
