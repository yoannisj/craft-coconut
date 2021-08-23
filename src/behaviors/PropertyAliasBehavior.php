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

namespace yoannisj\coconut\behaviors;

use yii\base\InvalidConfigException;
use yii\base\Behavior;

use craft\helpers\StringHelper;

/**
 *
 */

class PropertyAliasBehavior extends Behavior
{
    // =Properties
    // =========================================================================

    /**
     * @var boolean|string[] Whether properties can be aliased by their camelCase counterpart
     *
     * This can be set to a list of non-camelcase property names
     */

    public $camelCasePropertyAliases = false;

    /**
     * @var arary Resolved list of camel case property aliases to check against
     */

    private $_camelPropertyAliasesMap = [];

    /**
     * @var array Map of property aliases
     *
     * Each key is the target property name, and its value can be an alias property name (string),
     * or a list of alias property names (array)
     */

    public $propertyAliases = [];

    /**
     * @var array Resolved list of property aliases to check against
     */

    private $_propertyAliasesMap = [];

    // =Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */

    public function init()
    {
        if (is_array($this->camelCasePropertyAliases)) {
            $this->_camelPropertyAliasesMap = $this->camelCasePropertyAliases;
        }

        foreach ($this->propertyAliases as $target => $aliases )
        {
            // support a single alias string
            if (!is_array($aliases)) $aliases = [ $aliases ];

            foreach ($aliases as $alias)
            {
                if (!is_string($alias))
                {
                    throw new InvalidConfigException(
                        "Property alias must for `$target` must be a string");
                }

                $this->_propertyAliasesMap[$alias] = $target;
            }
        }

        parent::init();
    }

    /**
     * @inheritdoc
     */

    public function canSetProperty( $name, $checkVars = true )
    {
        $name = $this->camelCasePropertyAlias($name);

        if (array_key_exists($name, $this->_propertyAliasesMap)) {
            return true;
        }

        return parent::canSetProperty($name, $checkVars);
    }

    /**
     * @inheritdoc
     */

    public function canGetProperty( $name, $checkVars = true )
    {
        $name = $this->camelCasePropertyAlias($name);

        if (array_key_exists($name, $this->_propertyAliasesMap)) {
            return true;
        }

        return parent::canGetProperty($name, $checkVars);
    }

    /**
     * @inheritdoc
     */

    public function __set( $name, $value )
    {
        $name = $this->camelCasePropertyAlias($name);

        if (array_key_exists($name, $this->_propertyAliasesMap)) {
            $name = $this->_propertyAliasesMap[$name];
        }

        parent::__set($name, $value);
    }

    /**
     * @inheritdoc
     */

    public function __get( $name )
    {
        $name = $this->camelCasePropertyAlias($name);

        if (array_key_exists($name, $this->_propertyAliasesMap)) {
            $name = $this->_propertyAliasesMap[$name];
        }

        return parent::__get($name);
    }

    // =Protected Methods
    // =========================================================================

    /**
     * Transforms given property name into camel case format if it should be
     * aliases as such.
     *
     * @param string $name
     *
     * @return string
     */

    protected function camelCasePropertyAlias( string $name ): string
    {
        if ($this->camelCasePropertyAliases === true
            || array_key_exists($name, $this->_camelPropertyAliasesMap)
        ) {
            return StringHelper::camelCase($name);
        }

        return $name;
    }
}
