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

namespace yoannisj\coconut\exceptions;

use yii\web\BadRequestHttpException;

/**
 * Class for exceptions raised when issues occur with requests to the Coconut service
 */

class CoconutRequestException extends BadRequestHttpException
{
    /**
     * @var string The request error code string
     * 
     * @see https://docs.coconut.co/references/errors
     */   

    public $errorCode;
}