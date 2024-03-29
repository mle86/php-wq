<?php

namespace mle86\WQ;

use mle86\WQ\Exception\JobCallbackReturnValueException;
use mle86\WQ\Job\Job;
use mle86\WQ\Job\JobContext;
use mle86\WQ\Job\JobResult;
use mle86\WQ\Job\QueueEntry;
use mle86\WQ\WorkServerAdapter\WorkServerAdapter;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * This class implements a wrapper around
 * {@see WorkServerAdapter::getNextQueueEntry()}
 * called {@see processNextJob()}
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
    public function __construct(WorkServerAdapter $workServer, LoggerInterface $logger = null, array $options = [])
    {
        $this->server  = $workServer;
        $this->logger  = $logger ?? new NullLogger();
        $this->options = self::$defaultOptions + $options;
    }

    /**
     * @return WorkServerAdapter
     *   Returns the WorkServerAdapter instance this WorkProcessor operates on.
     */
    public function getWorkServerAdapter(): WorkServerAdapter
    {
        return $this->server;
    }

    /**
     * Executes the next job in the Work Queue
     * by passing it to the callback function.
     *
     * If that results in a {@see \RuntimeException}
     * or the {@see JobResult::FAILED} return value,
     * the method will try to re-queue the job
     * and re-throw the exception.
     *
     * If the execution results in any other {@see \Throwable}
     * or the {@see JobResult::ABORT} return value,
     * no re-queueing will be attempted;
     * the job will be buried immediately.
     *
     * If the next job in the Work Queue is expired,
     * it will be silently deleted.
     *
     * @param string|string[] $workQueue See {@see WorkServerAdapter::getNextQueueEntry()}.
     * @param callable $callback         The handler callback to execute each Job.
     *                                   Expected signature: <tt>function(Job, JobContext): ?int|void</tt>.
     *                                   See {@see JobResult} for possible return values.
     * @param int $timeout               See {@see WorkServerAdapter::getNextQueueEntry()}.
     * @throws \Throwable  Will re-throw on any Exceptions/Throwables from the <tt>$callback</tt>.
     * @throws JobCallbackReturnValueException  in case of an unexpected callback return value (should be a {@see JobResult} constant or NULL or void).
     */
    public function processNextJob(
        $workQueue,
        callable $callback,
        int $timeout = WorkServerAdapter::DEFAULT_TIMEOUT
    ): void {
        $qe = $this->server->getNextQueueEntry($workQueue, $timeout);
        if (!$qe) {
            $this->onNoJobAvailable((array)$workQueue);
            return;
        }

        $job = $qe->getJob();
        $jobContext = new JobContext($qe, $this);

        if ($job->jobIsExpired()) {
            $this->handleExpiredJob($jobContext);
            return;
        }

        $this->log(LogLevel::INFO, "got job", $qe);
        $this->onJobAvailable($qe);

        $ret = null;
        try {
            $ret = $callback($job, $jobContext);
        } catch (\Throwable $e) {
            // The job failed.
            $this->handleFailedJob($jobContext, $e);

            if ($this->options[self::WP_RETHROW_EXCEPTIONS]) {
                // pass exception to caller
                throw $e;
            } else {
                // drop it
                return;
            }
        }

        switch ($ret ?? JobResult::DEFAULT) {
            case JobResult::SUCCESS:
                // The job succeeded!
                $this->handleFinishedJob($jobContext);
                break;
            case JobResult::FAILED:
                // The job failed.
                $this->handleFailedJob($jobContext);
                break;
            case JobResult::ABORT:
                // The job failed and should not be retried.
                $this->handleFailedJob($jobContext, null, true);
                break;
            case JobResult::EXPIRED:
                // The job handler considers this job expired.
                $this->handleExpiredJob($jobContext);
                break;
            default:
                // We'll assume the job went well.
                $this->handleFinishedJob($jobContext);
                throw new JobCallbackReturnValueException('unexpected job handler return value, should be JobResult::... or null or void');
        }
    }

    /**
     * If the current job were to fail
     * (either by throwing some {@see \RuntimeException}
     *  or by returning {@see JobResult::FAILED}),
     * would it by re-queued by the WorkProcessor?
     *
     * This depends mostly on the {@see Job::jobCanRetry()} flag
     * but also on the {@see WorkProcessor::WP_ENABLE_RETRY} option.
     *
     * @param Job $job
     * @return bool
     */
    public function canRetry(Job $job): bool
    {
        return
            $this->options[self::WP_ENABLE_RETRY] &&
            $job->jobCanRetry();
    }


    private function handleFailedJob(JobContext $jobContext, \Throwable $e = null, bool $abort = false): void
    {
        $qe  = $jobContext->getQueueEntry();
        $job = $jobContext->getJob();

        if ($e) {
            $reason = get_class($e);
        } elseif ($abort) {
            $reason = 'JobResult::ABORT';
        } else {
            $reason = 'JobResult::FAILED';
        }

        $doRetry =
            !$abort &&
            ($e === null || $e instanceof \RuntimeException) &&
            $this->canRetry($job);

        if ($doRetry) {
            // re-queue:
            $delay = $job->jobRetryDelay();
            $this->onJobRequeue($qe, $delay, $e);
            $jobContext->handleTemporaryFailure($job, $jobContext, $e);
            $this->server->requeueEntry($qe, $delay);
            $this->log(LogLevel::NOTICE, "job failed, re-queued with {$delay}s delay ({$reason})", $qe);
        } elseif ($this->options[self::WP_ENABLE_BURY]) {
            $this->onFailedJob($qe, $e);
            $jobContext->handleFailure($job, $jobContext, $e);
            $this->server->buryEntry($qe);
            $this->log(LogLevel::WARNING, "job failed, buried ({$reason})", $qe);
        } else {
            $this->onFailedJob($qe, $e);
            $jobContext->handleFailure($job, $jobContext, $e);
            $this->server->deleteEntry($qe);
            $this->log(LogLevel::WARNING, "job failed, deleted ({$reason})", $qe);
        }
    }

    private function handleFinishedJob(JobContext $jobContext): void
    {
        $qe = $jobContext->getQueueEntry();
        $this->onSuccessfulJob($qe);
        $jobContext->handleSuccess($jobContext->getJob(), $jobContext);

        // Make sure the finished job is really gone before returning:
        if ($this->options[self::WP_DELETE] === self::DELETE_FINISHED) {
            $this->server->deleteEntry($qe);
            $this->log(LogLevel::INFO, "success, job deleted", $qe);
        } else {
            // move it to a different wq
            $this->server->requeueEntry($qe, 0, $this->options[self::WP_DELETE]);
            $this->log(LogLevel::NOTICE, "success, job moved to {$this->options[self::WP_DELETE]}", $qe);
        }
    }

    private function handleExpiredJob(JobContext $jobContext): void
    {
        $qe = $jobContext->getQueueEntry();
        $this->onExpiredJob($qe);

        // We'll never execute expired jobs.
        if ($this->options[self::WP_EXPIRED] === self::DELETE_EXPIRED) {
            $this->server->deleteEntry($qe);
            $this->log(LogLevel::NOTICE, "expired, job deleted", $qe);
        } elseif ($this->options[self::WP_EXPIRED] === self::BURY_EXPIRED) {
            $this->server->buryEntry($qe);
            $this->log(LogLevel::NOTICE, "expired, job buried", $qe);
        } else {
            // move it to a different wq
            $this->server->requeueEntry($qe, 0, $this->options[self::WP_EXPIRED]);
            $this->log(LogLevel::NOTICE, "expired, job moved to {$this->options[self::WP_EXPIRED]}", $qe);
        }
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
    public const WP_ENABLE_RETRY = 1;

    /**
     * If this option is TRUE (default), failed jobs will be buried;
     * if it is FALSE, failed jobs will be deleted.
     *
     * @see setOptions()
     */
    public const WP_ENABLE_BURY = 2;

    /**
     * If this option is set to {@see WorkProcessor::DELETE_FINISHED} (default),
     * finished jobs will be deleted.
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
    public const WP_DELETE = 3;

    /**
     * If this option is set to {@see WorkProcessor::DELETE_EXPIRED} (default),
     * expired jobs will be deleted.
     * If this option is set to {@see WorkProcessor::BURY_EXPIRED},
     * expired jobs will be buried instead.
     * Otherwise, its value is taken as a Work Queue name
     * where all expired jobs will be moved to.
     *
     * (It's possible to put the origin work queue name here,
     *  resulting in an infinite loop
     *  as soon as an expired job is encountered.
     *  Probably not what you want.)
     *
     * @see setOptions()
     */
    public const WP_EXPIRED = 4;

    /**
     * If this option is TRUE (default),
     *  all exceptions thrown by handler callback
     *  will be re-thrown so that the caller
     *  receives them as well.
     * If this option is FALSE,
     *  {@see processNextJob()} will silently return instead.
     *
     * @see setOptions()
     */
    public const WP_RETHROW_EXCEPTIONS = 5;


    /** @see WorkProcessor::WP_DELETE */
    public const DELETE_FINISHED = true;
    /** @see WorkProcessor::WP_EXPIRED */
    public const DELETE_EXPIRED = true;
    /** @see WorkProcessor::WP_EXPIRED */
    public const BURY_EXPIRED = false;


    protected static $defaultOptions = [
        self::WP_ENABLE_RETRY       => true,
        self::WP_ENABLE_BURY        => true,
        self::WP_DELETE             => self::DELETE_FINISHED,
        self::WP_EXPIRED            => self::DELETE_EXPIRED,
        self::WP_RETHROW_EXCEPTIONS => true,
    ];

    protected $options = [];

    /**
     * Sets one of the configuration options.
     *
     * @param int $option  One of the <tt>WP_</tt> constants.
     * @param mixed $value The option's new value. The required type depends on the option.
     *
     * @see setOptions()  to change multiple options at once.
     * @return self
     */
    public function setOption(int $option, $value): self
    {
        $this->options[$option] = $value;
        return $this;
    }

    /**
     * Sets one or more of the configuration options.
     *
     * Available options are:
     *   - {@see WorkProcessor::WP_ENABLE_RETRY}
     *   - {@see WorkProcessor::WP_ENABLE_BURY}
     *   - {@see WorkProcessor::WP_DELETE}
     *   - {@see WorkProcessor::WP_EXPIRED}
     *   - {@see WorkProcessor::WP_RETHROW_EXCEPTIONS}
     *
     * @param array $options
     *   Example:
     *   <tt>[ WP_ENABLE_RETRY => false, WP_DELETE => 'finished-jobs' ]</tt>
     *
     * @see setOption()  to change just one option.
     * @see __construct()  to set options on class instantiation.
     * @return self
     */
    public function setOptions(array $options): self
    {
        $this->options += $options;
        return $this;
    }

    protected function log($logLevel, $message, $context = null): void
    {
        $prefix = null;
        if ($context instanceof QueueEntry) {
            $prefix = "wq {$context->getWorkQueue()}: ";
        } elseif (is_string($context)) {
            $prefix = "{$context}: ";
        }

        $this->logger->log($logLevel, $prefix . $message);
    }


    /**
     * This method is called by {@see processNextJob()}
     * if there is currently no job to be executed in the work queue.
     *
     * This is a hook method for sub-classes.
     *
     * @param string[] $workQueues The work queues that were polled.
     * @return void
     */
    protected function onNoJobAvailable(array $workQueues): void
    {
    }

    /**
     * This method is called if there is a job ready to be executed,
     * right before {@see processNextJob()} actually executes it.
     *
     * This is a hook method for sub-classes.
     *
     * @param QueueEntry $qe The unserialized job.
     * @return void
     */
    protected function onJobAvailable(QueueEntry $qe): void
    {
    }

    /**
     * This method is called after a job has been successfully executed,
     * right before {@see processNextJob()} deletes it from the work queue.
     *
     * This is a hook method for sub-classes.
     *
     * @param QueueEntry $qe The executed job.
     * @return void
     */
    protected function onSuccessfulJob(QueueEntry $qe): void
    {
    }

    /**
     * This method is called if an expired job is encountered,
     * right before it gets deleted.
     *
     * This is a hook method for sub-classes.
     *
     * @param QueueEntry $qe The expired job.
     * @return void
     */
    protected function onExpiredJob(QueueEntry $qe): void
    {
    }

    /**
     * This method is called after a job that can be re-tried at least one more time
     * has failed (thrown an exception),
     * right before {@see processNextJob()} re-queues it
     * and re-throws the exception.
     *
     * (If the failed job can _not_ be re-queued, {@see onFailedJob()} is called instead.)
     *
     * This is a hook method for sub-classes.
     *
     * @param QueueEntry $qe      The failed job.
     * @param int $delay          The delay before the next retry, in seconds.
     * @param \Throwable|null $t  The exception that was thrown by the job handler callback
     *                            or NULL if it returned {@see JobResult::FAILED}.
     * @return void
     */
    protected function onJobRequeue(QueueEntry $qe, int $delay, \Throwable $t = null): void
    {
    }

    /**
     * This method is called after a job has permanently failed (thrown an exception and cannot be re-tried),
     * right before {@see processNextJob()} buries/deletes it
     * and re-throws the exception.
     *
     * (If the failed job can be re-tried at least one more time,
     *  {@see onJobRequeue()} will be called instead.)
     *
     * This is a hook method for sub-classes.
     *
     * @param QueueEntry $qe      The job that could not be executed correctly.
     * @param \Throwable|null $e  The exception that was thrown by the job handler callback
     *                            or NULL if it returned {@see JobResult::FAILED}.
     * @return void
     */
    protected function onFailedJob(QueueEntry $qe, \Throwable $e = null): void
    {
    }

}
