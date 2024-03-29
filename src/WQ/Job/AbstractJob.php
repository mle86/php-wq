<?php

namespace mle86\WQ\Job;

/**
 * This base class implements some of the {@see Job} interface's requirements.
 *
 * To build a working Job class,
 * simply extend this class.
 *
 * - It's your choice
 *   whether to put your job execution logic in the Job class
 *   or somewhere else entirely, like the queue worker script.
 * - If the jobs should be re-tried after an initial failure,
 *   override the {@see MAX_RETRY} constant.
 * - If the retry condition is more complex,
 *   override the {@see jobCanRetry()} method instead.
 * - To change the retry delay interval,
 *   override the {@see jobRetryDelay()} method
 *   (you'll probably want to have an increasing delay,
 *    so base it on the {@see jobTryIndex()} counter value).
 * - If your queued jobs can expire before being executed,
 *   override {@see jobIsExpired()} so that it returns true
 *   if the expiry condition is reached. You may need to
 *   add a job creation timestamp property for that.
 */
abstract class AbstractJob implements Job, \Serializable
{

    /**
     * @var int  How often a job of this type can be retried if it fails.
     *           Override this as necessary in subclasses.
     *           Zero or negative values mean that this job can only be tried once, never re-tried.
     * @see jobCanRetry()  This constant is used in the jobCanRetry() default implementation only,
     *                     so if you override that method in your class the constant is unused.
     */
    public const MAX_RETRY = 0;

    /**
     * @var int  Job classes who override {@see MAX_RETRY} or {@see jobCanRetry()} to allow retrying
     *           get a 10 minute default retry delay.
     *           Change this by overriding {@see jobRetryDelay()}.
     */
    private const DEFAULT_RETRY_DELAY = 60 * 10;  // 10 minutes

    /**
     * @var int  The current try index.
     *           The default {@see __serialize()} implementation
     *           will increase this by 1 before serializing it,
     *           so that the serialization always contains
     *           the correct next value.
     * @internal This should not be accessed directly, except for a custom {@see __serialize()} override.
     * @see      jobTryIndex()
     */
    protected $_try_index = 0;


    /**
     * This default implementation stores all public and protected properties.
     *
     * Override this method if that's not enough or if you want to do some additional pre-serialize processing,
     * but don't forget to include <tt>{@see $_try_index}+1</tt> in the serialization!
     */
    public function __serialize(): array
    {
        $raw = [];
        foreach ($this->listProperties() as $propName) {
            $raw[$propName] = $this->{$propName};
        }
        $raw['_try_index'] = $this->_try_index + 1;  // !
        return $raw;
    }

    /**
     * @deprecated This is here to fulfil the older {@see \Serializable} interface.
     */
    public function serialize()
    {
        return serialize($this->__serialize());
    }

    /**
     * This default implementation simply writes all serialized values
     * to their corresponding object property.
     * That includes the {@see $_try_index} counter.
     * Private and/or static properties will never be written to.
     *
     * @param array $raw
     */
    public function __unserialize(array $raw): void
    {
        foreach ($this->listProperties() as $propName) {
            if (array_key_exists($propName, $raw)) {
                $this->{$propName} = $raw[$propName];
            }
        }
    }

    /**
     * @deprecated This is here to fulfil the older {@see \Serializable} interface.
     *             It may still be needed when unserializing jobs that were serialized prior to v0.21.0.
     */
    public function unserialize($data): void
    {
        $this->__unserialize(unserialize($data, ['allowed_classes' => [Job::class]]));
    }


    public function jobCanRetry(): bool
    {
        return ($this->jobTryIndex() <= static::MAX_RETRY);
    }

    public function jobRetryDelay(): ?int
    {
        return self::DEFAULT_RETRY_DELAY;
    }

    public function jobTryIndex(): int
    {
        /* Before first serialization, _try_index is zero.
         * On every serialization, this value will be increased by 1.
         * Still, someone could try to execute a never-serialized job
         * and still expect a reasonable result,
         * so we'll never return zero here.  */
        return $this->_try_index ?: 1;
    }

    public function jobIsExpired(): bool
    {
        return false;
    }


    /**
     * @return string[]  An array of public and protected object properties.
     */
    private function listProperties(): array
    {
        $rc = new \ReflectionClass($this);

        $filter = \ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED;

        $list = [];
        foreach ($rc->getProperties($filter) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $list[] = $prop->getName();
        }

        return $list;
    }

}
