<?php

namespace mle86\WQ\Job;

use mle86\WQ\WorkProcessor;
use mle86\WQ\WorkServerAdapter\WorkServerAdapter;

/**
 * Contains metadata about the job currently being processed.
 */
final class JobContext
{

    private $queueEntry;
    private $workProcessor;

    /**
     * @internal Only the {@see WorkProcessor} class should create instances.
     */
    public function __construct(QueueEntry $queueEntry, WorkProcessor $workProcessor)
    {
        $this->queueEntry    = $queueEntry;
        $this->workProcessor = $workProcessor;
    }


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

}
