<?php
namespace mle86\WQ\Tests;

use mle86\WQ\Job\Job;
use mle86\WQ\Job\QueueEntry;
use mle86\WQ\WorkProcessor;

class LoggingWorkProcessor
	extends WorkProcessor
{

	public $log = [];


	protected function onNoJobAvailable (string $workQueue) {
		$this->log[] = ["NOJOBS", $workQueue];
	}

	protected function onJobAvailable (QueueEntry $qe) {
		/** @var Job|SimpleJob $job */
		$job = $qe->getJob();

		$this->log[] = ["JOB", $job->getMarker()];
	}

	protected function onSuccessfulJob (QueueEntry $qe, $returnValue) {
		/** @var Job|SimpleJob $job */
		$job = $qe->getJob();

		$this->log[] = ["SUCCESS", $job->getMarker(), $returnValue];
	}

	protected function onJobRequeue (QueueEntry $qe, \Throwable $e, int $delay) {
		/** @var Job|SimpleJob $job */
		$job = $qe->getJob();

		$this->log[] = ["REQUEUE", $job->getMarker(), $e->getMessage(), $delay];
	}

	protected function onFailedJob (QueueEntry $qe, \Throwable $e) {
		/** @var Job|SimpleJob $job */
		$job = $qe->getJob();

		$this->log[] = ["FAILED", $job->getMarker(), $e->getMessage()];
	}

}

