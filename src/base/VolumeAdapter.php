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
use craft\base\VolumeInterface;
use craft\helpers\UrlHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\base\VolumeAdapterInterface;

/**
 * Base volume adapter used to transfer coconut output files
 */

class VolumeAdapter implements VolumeAdapterInterface
{
    /**
     * @inheritdoc
     */

    public static function outputUploadUrl( VolumeInterface $volume, string $outputPath ): string
    {
        return UrlHelper::actionUrl('coconut/jobs/upload', [
            'volumeId' => $volume->id,
            'outputPath' =>$outputPath
        ]);
    }

    /**
     * @inheritdoc
     */

    public static function outputPublicUrl( VolumeInterface $volume, string $outputPath ): string
    {
        $url = $volume->getRootUrl().$outputPath;
        return rtrim($url, '/');
    }
} 