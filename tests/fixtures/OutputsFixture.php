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

namespace yoannisj\coconuttests\fixtures;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\test\ActiveFixture;
use craft\helpers\FileHelper;

use yoannisj\coconut\records\OutputRecord;

/**
 *
 */

class OutputsFixture extends ActiveFixture
{
    // =Properties
    // ========================================================================

    /**
     * @inheritdoc
     */

    public $modelClass = OutputRecord::class;

    /**
     * @inheritdoc
     */

    public $dataFile = __DIR__. '/data/outputs.php';

    /**
     * @inheritdoc
     */

    public $depends = [
        JobsFixture::class
    ];

    // =Public Methods
    // ========================================================================

    // =Protected Methods
    // ========================================================================


}
