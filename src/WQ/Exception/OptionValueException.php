<?php
namespace mle86\WQ\Exception;


/**
 * Thrown by {@see WorkProcessor::setOption} and {@see WorkProcessor::setOptions}
 * in case of an invalid option value.
 */
class OptionValueException
    extends \InvalidArgumentException
    implements WQException
{
}

