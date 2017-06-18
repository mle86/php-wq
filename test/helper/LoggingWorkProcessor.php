<?php
namespace mle86\WQ\Tests;

use mle86\WQ\Job\Job;
use mle86\WQ\Job\QueueEntry;
use mle86\WQ\WorkProcessor;

/**
 * @internal This is part of the unit tests.
 */
class LoggingWorkProcessor
    extends WorkProcessor
{

    public $log = [];


    protected function onNoJobAvailable (array $workQueues) {
        $this->log[] = ["NOJOBS", join("|", $workQueues)];
    }

    protected function onJobAvailable (QueueEntry $qe) {
        /** @var Job|SimpleJob $job */
        $job = $qe->getJob();

        $this->log[] = ["JOB", $job->getMarker()];
    }

    protected function onSuccessfulJob (QueueEntry $qe) {
        /** @var Job|SimpleJob $job */
        $job = $qe->getJob();

        $this->log[] = ["SUCCESS", $job->getMarker()];
    }

    protected function onJobRequeue (QueueEntry $qe, int $delay, \Throwable $e = null) {
        /** @var Job|SimpleJob $job */
        $job = $qe->getJob();

        $this->log[] = ["REQUEUE", $job->getMarker(), $delay, (($e) ? $e->getMessage() : null)];
    }

    protected function onFailedJob (QueueEntry $qe, \Throwable $e = null) {
        /** @var Job|SimpleJob $job */
        $job = $qe->getJob();

        $this->log[] = ["FAILED", $job->getMarker(), (($e) ? $e->getMessage() : null)];
    }

    protected function onExpiredJob (QueueEntry $qe) {
        /** @var Job|SimpleJob $job */
        $job = $qe->getJob();

        $this->log[] = ["EXPIRED", $job->getMarker()];
    }

}

