<?php

namespace mle86\WQ\Job;

use mle86\WQ\WorkProcessor;
use mle86\WQ\WorkServerAdapter\WorkServerAdapter;

/**
 * Contains metadata about the job currently being processed
 * and various setters to attach one-off event handlers to the job.
 *
 * Instances of this class are available in job callbacks
 * run by {@see WorkProcessor::processNextJob()}
 * (their expected signature is `function(Job, JobContext): ?int|void`).
 */
final class JobContext
{

    private $queueEntry;
    private $workProcessor;

    private $failureCallback;
    private $temporaryFailureCallback;
    private $successCallback;

    /**
     * @internal Only the {@see WorkProcessor} class should create instances.
     */
    public function __construct(QueueEntry $queueEntry, WorkProcessor $workProcessor)
    {
        $this->queueEntry    = $queueEntry;
        $this->workProcessor = $workProcessor;
    }


    // Simple getters:  ////////////////////////////////////////////////////////

    /**
     * @return QueueEntry  The queue entry DTO containing the current job instance.
     */
    public function getQueueEntry(): QueueEntry
    {
        return $this->queueEntry;
    }

    /**
     * @return Job  The job currently being executed.
     */
    public function getJob(): Job
    {
        return $this->queueEntry->getJob();
    }

    /**
     * @return string  The name of the work queue from which the current job was received.
     */
    public function getSourceQueue(): string
    {
        return $this->queueEntry->getWorkQueue();
    }

    /**
     * @return WorkProcessor  The WorkProcessor that created this instance.
     */
    public function getWorkProcessor(): WorkProcessor
    {
        return $this->workProcessor;
    }

    /**
     * @return WorkServerAdapter  The work server from which the current job was received.
     */
    public function getWorkServer(): WorkServerAdapter
    {
        return $this->workProcessor->getWorkServerAdapter();
    }


    // Callback accessors:  ////////////////////////////////////////////////////

    /**
     * Sets up a callback that will be called once
     * if and when the current job is being re-queued
     * because it failed and should be re-tried.
     *
     * This happens if {@see WorkProcessor::WP_ENABLE_RETRY} is set,
     * if {@see Job::jobCanRetry()} is true,
     * and if the job handler returned {@see JobResult::FAILED} or threw a {@see \RuntimeException}.
     *
     * (This callback will be run by the {@see WorkProcessor}
     *  after it calls its internal {@see WorkProcessor::onJobRequeue()} hook,
     *  immediately before calling {@see WorkServerAdapter::requeueEntry()}.)
     *
     * @param callable|null $callback Expected signature:
     *                                function({@see Job}, {@see JobContext}): void.
     * @return $this
     */
    public function onTemporaryFailure(?callable $callback): self
    {
        $this->temporaryFailureCallback = $callback;
        return $this;
    }

    /**
     * Sets up a callback that will be called once
     * if and when the current job is being buried/deleted
     * because it failed and should not (or cannot) be re-tried later.
     *
     * This happens if {@see WorkProcessor::WP_ENABLE_RETRY} is not set
     *  or if {@see Job::jobCanRetry()} returns false
     * and if the job handler returned {@see JobResult::ABORT}
     *  or threw a non-{@see \RuntimeException Runtime} exception.
     *
     * (This callback will be run by the {@see WorkProcessor}
     *  after it calls its internal {@see WorkProcessor::onFailedJob()} hook,
     *  immediately before calling {@see WorkServerAdapter::buryEntry()}/{@see WorkServerAdapter::deleteEntry() deleteEntry()}.)
     *
     * @param callable|null $callback Expected signature:
     *                                function({@see Job}, {@see JobContext}): void.
     * @return $this
     */
    public function onFailure(?callable $callback): self
    {
        $this->failureCallback = $callback;
        return $this;
    }

    /**
     * Sets up a callback that will be called once
     * if and when the current job is being deleted/movied
     * because it succeeded!
     *
     * This happens if the job handler returns {@see JobResult::SUCCESS}/null/void.
     *
     * (This callback will be run by the {@see WorkProcessor}
     *  after it calls its internal {@see WorkProcessor::onSuccessfulJob()} hook,
     *  immediately before calling {@see WorkServerAdapter::deleteEntry()}/{@see WorkServerAdapter::requeueEntry() requeueEntry()}.)
     *
     * @param callable|null $callback Expected signature:
     *                                function({@see Job}, {@see JobContext}): void.
     * @return $this
     */
    public function onSuccess(?callable  $callback): self
    {
        $this->successCallback = $callback;
        return $this;
    }


    // Event entry points:  ////////////////////////////////////////////////////

    /**
     * Runs the {@see onTemporaryFailure()} callback.
     * @internal Only {@see WorkProcessor::processNextJob()} should call this!
     * @param Job $currentJob
     * @param JobContext $currentContext
     */
    public function handleTemporaryFailure(Job $currentJob, JobContext $currentContext): void
    {
        if ($this->temporaryFailureCallback) {
            ($this->temporaryFailureCallback)($currentJob, $currentContext);
        }
    }

    /**
     * Runs the {@see onFailure()} callback.
     * @internal Only {@see WorkProcessor::processNextJob()} should call this!
     * @param Job $currentJob
     * @param JobContext $currentContext
     */
    public function handleFailure(Job $currentJob, JobContext $currentContext): void
    {
        if ($this->failureCallback) {
            ($this->failureCallback)($currentJob, $currentContext);
        }
    }

    /**
     * Runs the {@see onSuccess()} callback.
     * @internal Only {@see WorkProcessor::processNextJob()} should call this!
     * @param Job $currentJob
     * @param JobContext $currentContext
     */
    public function handleSuccess(Job $currentJob, JobContext $currentContext): void
    {
        if ($this->successCallback) {
            ($this->successCallback)($currentJob, $currentContext);
        }
    }

}
