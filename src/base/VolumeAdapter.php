<?php

/**
 * Coconut plugin for Craft
 *
 * @author Yoannis Jamar
 * @copyright Copyright (c) 2020 Yoannis Jamar
 * @link https://github.com/yoannisj/
 * @package craft-coconut
 */

namespace yoannisj\coconut\base;

use Craft;
use craft\models\Volume;
use craft\helpers\UrlHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\base\VolumeAdapterInterface;
use yoannisj\coconut\helpers\JobHelper;

/**
 * Base volume adapter used to store coconut output files
 */
class VolumeAdapter implements VolumeAdapterInterface
{
    /**
     * @inheritdoc
     */
    public static function outputUploadUrl(
        Volume $volume,
        string $outputPath
    ): string
    {
        return JobHelper::publicActionUrl('coconut/jobs/upload', [
            'volume' => $volume->handle,
            'outputPath' => $outputPath
        ]);
    }

    /**
     * @inheritdoc
     */
    public static function outputPublicUrl(
        Volume $volume,
        string $outputPath
    ): string
    {
        $url = $volume->getRootUrl().$outputPath;
        return rtrim(JobHelper::publicUrl($url), '/');
    }
}
