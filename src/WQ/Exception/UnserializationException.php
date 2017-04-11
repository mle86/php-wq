<?php
namespace mle86\WQ\Exception;


/**
 * Thrown by {@see QueueEntry::fromSerializedJob()}
 * in case of invalid job data:
 *
 *  - invalid serialization
 *  - non-object
 *  - object, but not a Job implementation
 */
class UnserializationException
	extends \UnexpectedValueException
	implements WQException
{
}
