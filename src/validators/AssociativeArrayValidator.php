<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace yoannisj\coconut\validators;

use yii\validators\Validator;

use Craft;
use craft\helpers\ArrayHelper;

/**
 * Validator class to validate associative array values.
 */
class AssociativeArrayValidator extends Validator
{
    // =Properties
    // =========================================================================

    /**
     * @var array The keys that must exist in the associative array value
     */
    public array $requiredKeys = [];

    /**
     * @var array The keys that can not exist in the associative array value
     */
    public array $forbiddenKeys = [];

    /**
     * @var array The keys that can exist in the associative array value
     */
    public array $allowedKeys = [];

    /**
     * @var string|null
     */
    public ?string $keyNotFound = null;

    /**
     * @var string|null
     */
    public ?string $keyNotAllowed = null;

    /**
     * @var bool Wether all required/forbidden keys should be checked by validation
     */
    public bool $checkAllKeys = false;

    // =Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        if ($this->message === null) {
            $this->message = Craft::t('coconut', '{attribute} must be an associative array.');
        }

        if ($this->keyNotFound == null) {
            $this->keyNotFound = Craft::t('coconut', '{attribute} must contain required key(s) "{key}"');
        }

        if ($this->keyNotAllowed == null) {
            $this->keyNotAllowed = Craft::t('coconut', '{attribute} can not contain forbidden key(s) "{key}"');
        }
    }

    // =Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function validateValue( $value )
    {
        if (!ArrayHelper::isAssociative($value)) {
            return [ $this->message, [] ];
        }

        if (!empty($this->requiredKeys))
        {
            $missingKeys = [];

            foreach ($this->requiredKeys as $key)
            {
                if (!array_key_exists($key, $value))
                {
                    if ($this->checkAllKeys) {
                        $missingKeys[] = $key;
                    } else {
                        return [
                            $this->keyNotFound,
                            [ 'key' => $key ]
                        ];
                    }
                }
            }

            if (!empty($missingKeys))
            {
                return [
                    $this->keyNotFound,
                    [ 'key' => implode(', ', $missingKeys) ]
                ];
            }

            if ($this->forbidOtherKeys)
            {
                $keys = array_keys($value);
                $forbiddenKeys = array_diff($keys, $this->requiredKeys);

                if (!empty($forbiddenKeys))
                {
                    return [
                        $this->keyNotAllowed,
                        [ 'key' => implode(', ', $forbiddenKeys) ]
                    ];
                }
            }
        }

        if (!empty($this->forbiddenKeys))
        {
            $forbiddenKeys = [];

            foreach ($this->forbiddenKeys as $key)
            {
                if (array_key_exists($key, $value))
                {
                    if ($this->checkAllKeys) {
                        $forbiddenKeys[] = $key;
                    } else {
                        return [
                            $this->keyNotAllowed,
                            [ 'key' => $key ]
                        ];
                    }
                }
            }

            if (!empty($forbiddenKeys))
            {
                return [
                    $this->keyNotAllowed,
                    [ 'key' => $forbiddenKeys ]
                ];
            }

            if (!$this->allowOtherKeys)
            {

            }
        }

        if (!empty($this->allowedKeys))
        {
            $forbiddenKeys = [];

            foreach (array_keys($value) as $key)
            {
                if (!in_array($key, $this->allowedKeys))
                {
                    if ($this->checkAllKeys) {
                        $forbiddenKeys[] = $key;
                    } else {
                        return [
                            $this->keyNotAllowed,
                            [ 'key' => $key ]
                        ];
                    }
                }
            }

            if (!empty($forbiddenKeys))
            {
                return [
                    $this->keyNotAllowed,
                    [ 'key' => implode(', ', $key) ]
                ];
            }
        }

        return null;
    }

}
