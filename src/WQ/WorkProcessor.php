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
     * @param WorkServerAdapter $workServer The work server adapter to work with.
     * @param LoggerInterface $logger       A logger. The WorkProcessor will report job success status here.
     * @param array $options                Options to set, overriding {@see $defaultOptions}.
     *                                      Works the same as a {@see setOptions()} call right after instantiation.
     *
     * @see setOptions()  for a list of available configuration options.
     */
    public function __construct (WorkServerAdapter $workServer, LoggerInterface $logger = null, array $options = []) {
        $this->server  = $workServer;
        $this->logger  = $logger ?? new NullLogger;
        $this->options = self::$defaultOptions + $options;
    }

    /**
     * @return WorkServerAdapter
     *   Returns the WorkServerAdapter instance this WorkProcessor operates on.
     */
    public function getWorkServerAdapter () : WorkServerAdapter {
        return $this->server;
    }

    /**
     * Executes the next job in the Work Queue
     * by passing it to the callback function.
     *
     * If that results in a {@see \RuntimeException},
     * the method will try to re-queue the job
     * and re-throw the exception.
     *
     * If the execution results in any other {@see \Throwable},
     * no re-queueing will be attempted;
     * the job will be buried immediately.
     *
     * If the next job in the Work Queue is expired,
     * it will be silently deleted
     * and the method will return NULL.
     *
     * @param string|string[] $workQueue See {@see WorkServerAdapter::getNextJob()}.
     * @param callable $callback         The handler callback to execute each Job.
     *                                   Expected signature: <tt>function(Job)</tt>.
     *                                   Its return value will be returned by this method.
     * @param int $timeout               See {@see WorkServerAdapter::getNextJob()}.
     * @throws \Throwable  Will pass on any Exceptions/Throwables from the <tt>$callback</tt>.
     * @return mixed|null Returns <tt>$callback(Job)</tt>'s return value on success (which might be NULL).
     *                    Also returns NULL if there was no job in the work queue to be executed.
     */
    public function executeNextJob ($workQueue, callable $callback, int $timeout = WorkServerAdapter::DEFAULT_TIMEOUT) {
        $qe = $this->server->getNextQueueEntry($workQueue, $timeout);
        if (!$qe) {
            $this->onNoJobAvailable((array)$workQueue);
            return null;
        }

        $this->log(LogLevel::INFO, "got job");
        $this->onJobAvailable($qe);

        $job = $qe->getJob();

        if ($job->jobIsExpired()) {
            $this->handleExpiredJob($qe);
            return null;
        }

        $ret = null;
        try {
            $ret = $callback($job);
        } catch (\Throwable $e) {
            // The job failed.
            $this->handleFailedJob($qe, $e);
            throw $e;
        }

        // The job succeeded!
        $this->onSuccessfulJob($qe, $ret);
        $this->handleFinishedJob($qe);
        return $ret;
    }

    private function handleFailedJob (QueueEntry $qe, \Throwable $e) {
        $job = $qe->getJob();

        $exception_class = get_class($e);
        $do_retry        = ($e instanceof \RuntimeException) &&
            $this->options[self::WP_ENABLE_RETRY] &&
            $job->jobCanRetry();

        if ($do_retry) {
            // re-queue:
            $delay = $job->jobRetryDelay();
            $this->onJobRequeue($qe, $e, $delay);
            $this->server->requeueEntry($qe, $delay);
            $this->log(LogLevel::NOTICE, "failed, re-queued with {$delay}s delay ({$exception_class})", $qe);
        } elseif ($this->options[self::WP_ENABLE_BURY]) {
            $this->onFailedJob($qe, $e);
            $this->server->buryEntry($qe);
            $this->log(LogLevel::WARNING, "failed, buried ({$exception_class})", $qe);
        } else {
            $this->onFailedJob($qe, $e);
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

    private function handleExpiredJob (QueueEntry $qe) {
        // We'll never execute expired jobs.
        $this->server->deleteEntry($qe);
        $this->log(LogLevel::NOTICE, "expired, deleted", $qe);
    }


    /**
     * If this option is TRUE (default),
     * failed jobs will be re-queued (if their {@see Job::jobCanRetry()} return value says so).
     *
     * This option can be used to disable retries for all jobs if set to FALSE;
     * jobs will then be handled as if their {@see Job::jobCanRetry()} methods always returned FALSE,
     * i.e. they'll be buried or deleted (depending on the WS_ENABLE_BURY option).
     *
     * @see setOptions()
     */
    const WP_ENABLE_RETRY = 1;

    /**
     * If this option is TRUE (default), failed jobs will be buried;
     * if it is FALSE, failed jobs will be deleted.
     *
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
        self::WP_ENABLE_RETRY => true,
        self::WP_ENABLE_BURY  => true,
        self::WP_DELETE       => true,
    ];

    protected $options = [];

    /**
     * Sets one of the configuration options.
     *
     * @param int $option  One of the <tt>WP_</tt> constants.
     * @param mixed $value The option's new value. The required type depends on the option.
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


    /**
     * This method is called by {@see executeNextJob()}
     * if there is currently no job to be executed in the work queue.
     *
     * This is a hook method for sub-classes.
     *
     * @param string[] $workQueues The work queues that were polled.
     * @return void
     */
    protected function onNoJobAvailable (array $workQueues) { }

    /**
     * This method is called if there is a job ready to be executed,
     * right before {@see executeNextJob()} actually executes it.
     *
     * This is a hook method for sub-classes.
     *
     * @param QueueEntry $qe The unserialized job.
     * @return void
     */
    protected function onJobAvailable (QueueEntry $qe) { }

    /**
     * This method is called after a job has been successfully executed,
     * right before {@see executeNextJob()} deletes it from the work queue.
     *
     * This is a hook method for sub-classes.
     *
     * @param QueueEntry $qe The executed job.
     * @param mixed $ret     The return value of the job handler callback.
     * @return void
     */
    protected function onSuccessfulJob (QueueEntry $qe, $ret) { }

    /**
     * This method is called after a job that can be re-tried at least one more time
     * has failed (thrown an exception),
     * right before {@see executeNextJob()} re-queues it
     * and re-throws the exception.
     *
     * (If the failed job can _not_ be re-queued, {@see onFailedJob()} is called instead.)
     *
     * This is a hook method for sub-classes.
     *
     * @param QueueEntry $qe The failed job.
     * @param \Throwable $t  The exception that was thrown by the job.
     * @param int $delay     The delay before the next retry, in seconds.
     * @return void
     */
    protected function onJobRequeue (QueueEntry $qe, \Throwable $t, int $delay) { }

    /**
     * This method is called after a job has permanently failed (thrown an exception and cannot be re-tried),
     * right before {@see executeNextJob()} buries/deletes it
     * and re-throws the exception.
     *
     * (If the failed job can be re-tried at least one more time,
     *  {@see onJobRequeue()} will be called instead.)
     *
     * This is a hook method for sub-classes.
     *
     * @param QueueEntry $qe The job that could not be executed correctly.
     * @param \Throwable $e  The exception that was thrown by the job or the job handler callback.
     * @return void
     */
    protected function onFailedJob (QueueEntry $qe, \Throwable $e) { }

}

