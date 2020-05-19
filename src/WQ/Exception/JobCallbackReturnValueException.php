<?php

namespace mle86\WQ\Exception;

use mle86\WQ\WorkProcessor;

/**
 * Thrown by {@see WorkProcessor::processNextJob()}
 * if the job callback returns an unexpected value
 * (it should be a {@see JobResult} constant or `null` or void).
 */
class JobCallbackReturnValueException extends \UnexpectedValueException implements WQException
{

}
