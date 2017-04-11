<?php
namespace mle86\WQ;

use mle86\WQ\Job\QueueEntry;
use mle86\WQ\WorkServerAdapter\WorkServerAdapter;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;


/**
 * This class implements a wrapper around
 * {@see WorkServerAdapter::getNextJob()}
 * called {@see executeNextJob()}
 * that does not only execute the next job immediately
 * but will also try to re-queue it if it fails.
 */
class WorkProcessor
{

	/** @var WorkServerAdapter */
	protected $server;

	/** @var LoggerInterface */
	protected $logger;

	/**
	 * Instantiates a new WorkProcessor.
	 * This causes no side effects yet.
	 *
	 * @param WorkServerAdapter $workServer  The work server adapter to work with.
	 * @param LoggerInterface $logger  A logger. The WorkProcessor will report job success status here.
	 * @param array $options  Options to set, overriding {@see $defaultOptions}.
	 *                        Works the same as a {@see setOptions()} call right after instantiation.
	 *
	 * @see setOptions()  for a list of available configuration options.
	 */
	public function __construct (WorkServerAdapter $workServer, LoggerInterface $logger = null, array $options = []) {
		$this->server  = $workServer;
		$this->logger  = $logger ?? new NullLogger;
		$this->options = self::$defaultOptions + $options;
	}

	/**
	 * Executes the next job in the Work Queue.
	 *
	 * If that results in a {@see \RuntimeException},
	 * the method will try to re-queue the job
	 * and re-throw the exception.
	 *
	 * If the execution results in any other {@see \Throwable},
	 * no re-queueing will be attempted;
	 * the job will be buried immediately.
	 *
	 * @param string $workQueue  See {@see WorkServerAdapter::getNextJob()}.
	 * @param int $timeout       See {@see WorkServerAdapter::getNextJob()}.
	 * @throws \Throwable  Will pass on any Exceptions/Throwables from the {@see Job} class.
	 * @return mixed|null Returns the {@see Job::execute()}'s return value on success (which might be NULL).
	 *                    Returns NULL if there was no job in the work queue to be executed.
	 */
	public function executeNextJob (string $workQueue, int $timeout = WorkServerAdapter::DEFAULT_TIMEOUT) {
		$qe = $this->server->getNextQueueEntry($workQueue, $timeout);
		if (!$qe) {
			return null;
		}

		$this->log(LogLevel::INFO, "got job");

		$job = $qe->getJob();
		$ret = null;

		try {
			$ret = $job->execute();
		} catch (\Throwable $e) {
			// The job failed.
			$this->handleFailedJob($qe, $e);
			throw $e;
		}

		// The job succeeded!
		$this->handleFinishedJob($qe);
		return $ret;
	}

	private function handleFailedJob (QueueEntry $qe, \Throwable $e) {
		$job = $qe->getJob();

		$exception_class = get_class($e);
		$do_retry = ($e instanceof \RuntimeException) &&
			$this->options[self::WP_ENABLE_RETRY] &&
			$job->jobCanRetry();

		if ($do_retry) {
			// re-queue:
			$delay = $job->jobRetryDelay();
			$this->server->requeueEntry($qe, $delay);
			$this->log(LogLevel::NOTICE, "failed, re-queued with {$delay}s delay ({$exception_class})", $qe);
		} elseif ($this->options[self::WP_ENABLE_BURY]) {
			$this->log(LogLevel::WARNING, "failed, buried ({$exception_class})", $qe);
		} else {
			$this->server->deleteEntry($qe);
			$this->log(LogLevel::WARNING, "failed, deleted ({$exception_class})", $qe);
		}
	}

	private function handleFinishedJob (QueueEntry $qe) {
		// Make sure the finished job is really gone before returning:
		if ($this->options[self::WP_DELETE] === true) {
			$this->server->deleteEntry($qe);
			$this->log(LogLevel::INFO, "success, deleted", $qe);
		} else {
			// move it to a different wq
			$this->server->requeueEntry($qe, 0, $this->options[self::WP_DELETE]);
			$this->log(LogLevel::NOTICE, "success, moved to {$this->options[self::WP_DELETE]}", $qe);
		}
	}


	/**
	 * If this option is TRUE (default),
	 * failed jobs will be re-queued (if their {@see Job::jobCanRetry()} return value says so).
	 *
	 * This option can be used to disable retries for all jobs if set to FALSE;
	 * jobs will then be handled as if their {@see Job::jobCanRetry()} methods always returned FALSE,
	 * i.e. they'll be buried or deleted (depending on the WS_ENABLE_BURY option).

	 * @see setOptions()
	 */
	const WP_ENABLE_RETRY = 1;

	/**
	 * If this option is TRUE (default), failed jobs will be buried;
	 * if it is FALSE, failed jobs will be deleted.

	 * @see setOptions()
	 */
	const WP_ENABLE_BURY = 2;

	/**
	 * If this option is TRUE (default), finished jobs will be deleted.
	 * Otherwise, its value is taken as a Work Queue name
	 * where all finished jobs will be moved to.
	 *
	 * (It's possible to put the origin work queue name here,
	 *  resulting in an infinite loop
	 *  as all jobs in the queue will be executed over and over.
	 *  Probably not what you want.)
	 *
	 * @see setOptions()
	 */
	const WP_DELETE = 3;


	protected static $defaultOptions = [
		self::WP_ENABLE_RETRY   => true,
		self::WP_ENABLE_BURY    => true,
		self::WP_DELETE         => true,
	];

	protected $options = [];

	/**
	 * Sets one of the configuration options.
	 *
	 * @param int $option  One of the <tt>WP_</tt> constants.
	 * @param mixed $value  The option's new value. The required type depends on the option.
	 *
	 * @see setOptions()  to change multiple options at once.
	 */
	public function setOption (int $option, $value) {
		$this->options[$option] = $value;
	}

	/**
	 * Sets one or more of the configuration options.
	 *
	 * Available options are:
	 *   - {@see WorkProcessor::WP_ENABLE_RETRY}
	 *   - {@see WorkProcessor::WP_ENABLE_BURY}
	 *   - {@see WorkProcessor::WP_DELETE}
	 *
	 * @param array $options
	 *   Example:
	 *   <tt>[ WP_ENABLE_RETRY => false, WP_DELETE => 'finished-jobs' ]</tt>
	 *
	 * @see setOption()  to change just one option.
	 * @see __construct()  to set options on class instantiation.
	 */
	public function setOptions (array $options) {
		$this->options += $options;
	}

	protected function log ($logLevel, $message, $context = null) {
		$prefix = null;
		if ($context instanceof QueueEntry) {
			$prefix = "{$context->getWorkQueue()}: ";
		} elseif (is_string($context)) {
			$prefix = "{$context}: ";
		}

		$this->logger->log($logLevel, $prefix . $message);
	}

}
