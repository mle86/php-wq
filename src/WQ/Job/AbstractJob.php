<?php
namespace mle86\WQ\Job;


/**
 * This base class implements some of the {@see Job} interface's requirements.
 *
 * To build a working Job class,
 * simply extend this class
 * and implement the {@see execute()} method
 * to do something.
 *
 * - If the jobs should be re-tried after an initial failure,
 *   override the {@see MAX_RETRY} constant.
 * - To change the retry delay interval,
 *   override the {@see jobRetryDelay()} method
 *   (you'll probably want to have an increasing delay,
 *    so base it on the {@see jobTryIndex()} counter value).
 */
abstract class AbstractJob
	implements Job
{

	/**
	 * @var int  How often a job of this type can be retried if it fails.
	 *           Override this as necessary in subclasses.
	 *           Zero or negative values mean that this job can only be tried once, never re-tried.
	 */
	const MAX_RETRY = 0;

	/**
	 * @var int  Job classes who override {@see MAX_RETRY} or {@see jobCanRetry()} to allow retrying
	 *           get a 10 minute default retry delay.
	 *           Change this by overriding {@see jobRetryDelay()}.
	 */
	private const DEFAULT_RETRY_DELAY = 60 * 10;  // 10 minutes

	/**
	 * @var int  The current try index.
	 * The default {@see serialize()} implementation
	 * will increase this by 1 before serializing it,
	 * so that the serialization always contains
	 * the correct next value.
	 * @internal This should not be accessed directly, except for a custom {@see serialize()} override.
	 * @see jobTryIndex()
	 */
	protected $_try_index = 0;


	/**
	 * This default implementation stores all public and protected properties.
	 *
	 * Override this method if that's not enough or if you want to do some additional pre-serialize processing,
	 * but don't forget to include <tt>{@see $_try_index}+1</tt> in the serialization!
	 */
	public function serialize () {
		$raw = [];
		foreach ($this->listProperties() as $propName) {
			$raw[$propName] = $this->{$propName};
		}
		$raw['_try_index'] = $this->_try_index + 1;  // !
		return serialize($raw);
	}

	/**
	 * This default implementation simply writes all serialized values
	 * to their corresponding object property.
	 * That includes the {@see $_try_index} counter.
	 * Private and/or static properties will never be written.
	 *
	 * @param string $serialized
	 */
	public function unserialize ($serialized) {
		$raw = unserialize($serialized);
		foreach ($this->listProperties() as $propName) {
			if (array_key_exists($propName, $raw)) {
				$this->{$propName} = $raw[$propName];
			}
		}
	}

	/**
	 * Whether this job can be retried later.
	 *
	 * The WorkServerAdapter implementation will check this if {@see execute()} has failed.
	 * If it returns true, the job will be stored in the Work Queue again
	 * to be re-executed after {@see jobRetryDelay()} seconds;
	 * if it returns false, the job will be buried for later inspection.
	 */
	public function jobCanRetry () : bool {
		return ($this->jobTryIndex() <= static::MAX_RETRY);
	}

	/**
	 * How many seconds this job should be delayed in the Work Queue
	 * before the next retry.
	 * If {@see jobCanRetry()} is true,
	 * this must return a positive integer.
	 */
	public function jobRetryDelay () : ?int {
		return self::DEFAULT_RETRY_DELAY;
	}

	/**
	 * On the first try, this must return 1,
	 * on the first retry, this must return 2,
	 * and so on.
	 */
	public function jobTryIndex () : int {
		/* Before first serialization, _try_index is zero.
		 * On every serialization, this value will be increased by 1.
		 * Still, someone could call execute() on a never-serialized job
		 * and still expect a reasonable result,
		 * so we'll never return zero here.  */
		return $this->_try_index ?: 1;
	}


	/**
	 * @return string[]  An array of public and protected object properties.
	 */
	private function listProperties () : array {
		$rc = new \ReflectionClass ($this);

		$filter = \ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED;

		$list = [ ];
		foreach ($rc->getProperties($filter) as $prop) {
			if ($prop->isStatic())
				continue;
			$list[] = $prop->getName();
		}

		return $list;
	}

}

