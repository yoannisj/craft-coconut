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
 * Model for Volume Storage events
 */
class VolumeStorageEvent extends Event
{
    /**
     * @var Volume The Craft Volume associated with the event
     */
    public Volume $volume;

    /**
     * @var Storage|null The Coconut Storage model associated with the event
     */
    public $storage = [];
}
