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
     * @return array [ \yoannisj\coconut\models\Output ]
     */

    public function getOutputs( $source ): array
    {
        return Coconut::$plugin->getOutputs()->getOutputs($source);
    }

    /**
     * @return \yoannisj\coconut\models\Output
     */

    public function getOutput( $source, string $key )
    {
        return Coconut::$plugin->getOutputs()->getOutput($source, $key);
    }

    /**
     * @return string | null
     */

    public function getOutputUrl( $source, string $key )
    {
        return Coconut::$plugin->getOutputs()->getOutputUrl($source, $key);
    }

    // =Protected Methods
    // =========================================================================

    // =Private Methods
    // =========================================================================

}