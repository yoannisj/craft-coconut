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

namespace yoannisj\coconut\base;

use craft\models\Volume;

/**
 * Interface for Asset Volume adapters
 */
interface VolumeAdapterInterface
{
    // =Static
    // =========================================================================

    /**
     * Returns the output url used in Coconut config files, to upload the output
     * file into the volume.
     * **Note**: the `$filename` parameter may contain special segments such as "#num#".
     *
     * @param Volume $volume The coconut format key
     * @param string $outputPath Path to the folder where output will be saved
     *
     * @return string The url used to upload the output file into the volume
     */
    public static function outputUploadUrl(
        Volume $volume,
        string $outputPath
    ): string;

    /**
     * Returns the public url to see and/or download the output file
     *
     * @param Volume $volmume The coconut format key
     * @param string $outputPath The path to the folder where the will be uploaded
     *
     * @return string The public url for the output file
     */
    public static function outputPublicUrl(
        Volume $volume,
        string $outputPath
    ): string;

}
