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
use craft\models\Volume;
use yoannisj\coconut\models\Storage;

/**
 * Event
 */

class VolumeStorageEvent extends Event
{
    /**
     * @var Volume The storage volume
     */
    public $volume;

    /**
     * @var Storage|null The Coconut storage settings model
     */

    public $storage = [];
}
