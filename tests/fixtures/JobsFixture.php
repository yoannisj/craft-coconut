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

use yoannisj\coconut\records\JobRecord;

/**
 *
 */

class JobsFixture extends ActiveFixture
{
    // =Properties
    // ========================================================================

    /**
     * @inheritdoc
     */

    public $modelClass = JobRecord::class;

    /**
     * @inheritdoc
     */

    public $dataFile = __DIR__. '/data/jobs.php';

    /**
     * @inheritdoc
     */

    public $depends = [];

    // =Public Methods
    // ========================================================================

    // =Protected Methods
    // ========================================================================


}
