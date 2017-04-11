<?php
namespace mle86\WQ\Job;


/**
 * A Job is a representation of some task do to.
 * It can be {@see execute}d immediately,
 * or it can be stored in a Work Queue for later processing.
 *
 * This interface extends {@see \Serializable},
 * because all Jobs have to be serializable
 * in order to be stored in a Work Queue.
 *
 * For your own Job classes,
 * see the {@see AbstractJob} base class instead;
 * it implements many of these functions already
 * and is easier to work with.
 */
interface Job
	extends \Serializable
{

	/**
	 * This method should implement the job's functionality.
	 *
	 * {@see WorkProcessor::executeNextJob()} will call this and return its return value.
	 * If it throws some Exception, it will bury the job;
	 * if it was a RuntimeException and {@see jobCanRetry} returns true,
	 * it will re-queue the job with a {@see jobRetryDelay}.
	 */
	public function execute ();

	/**
	 * Whether this job can be retried later.
	 * The WorkServerAdapter implementation will check this if {@see execute()} has failed.
	 * If it returns true, the job will be stored in the Work Queue again
	 * to be re-executed after {@see jobRetryDelay()} seconds;
	 * if it returns false, the job will be buried for later inspection.
	 */
	public function jobCanRetry () : bool;

	/**
	 * How many seconds the job should be delayed in the Work Quere before being re-tried.
	 * If {@see jobCanRetry()} is true,
	 * this must return a positive integer.
	 */
	public function jobRetryDelay () : ?int;

	/**
	 * On the first try, this must return 1,
	 * on the first retry, this must return 2,
	 * and so on.
	 */
	public function jobTryIndex () : int;

}
