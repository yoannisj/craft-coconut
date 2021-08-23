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
use craft\helpers\FileHelper;
use craft\test\fixtures\elements\AssetFixture as BaseAssetFixture;

/**
 *
 */

class AssetsFixture extends BaseAssetFixture
{
    // =Properties
    // ========================================================================

    /**
     * @inheritdoc
     */

    public $dataFile = __DIR__.'/data/assets.php';

    /**
     * @inheritdoc
     */

    public $depends = [
        VolumesFixture::class,
        VolumeFoldersFixture::class,
    ];

    // =Public Methods
    // ========================================================================

    // =Protected Methods
    // ========================================================================
}
