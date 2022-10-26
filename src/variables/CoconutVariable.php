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

namespace yoannisj\coconut\variables;

use Craft;
use craft\base\Component;

use yoannisj\coconut\Coconut;

/**
 *
 */

class CoconutVariable extends Component
{
    // =Properties
    // =========================================================================

    // =Public Methods
    // =========================================================================

    /**
     * @see yoannisj\coconut\services\Outputs::getOutputs()
     *
     * @return Output[]
     */

    public function transcodeVideo( $video, $outputs = null ): array
    {
        return Coconut::$plugin->transcodeVideo($video, $outputs);
    }

    // =Protected Methods
    // =========================================================================

    // =Private Methods
    // =========================================================================

}
