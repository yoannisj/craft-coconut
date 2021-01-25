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

use craft\base\VolumeInterface;

/**
 * Interface for volume adapters
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
     * @param \craft\base\VolumeInterface $volume The coconut format key 
     * @param string $outputPath Path to the folder where output will be saved
     *
     * @return string The url used to upload the output file into the volume
     */

    public static function outputUploadUrl( VolumeInterface $volume, string $outputPath ): string;

    /**
     * Returns the url for the output
     *
     * @param \craft\base\VolumeInterface $volmume The coconut format key 
     * @param string $outputPath The path to the folder where the will be uploaded
     *
     * @return string The public url for the output file
     */

    public static function outputPublicUrl( VolumeInterface $volume, string $outputPath ): string;

}