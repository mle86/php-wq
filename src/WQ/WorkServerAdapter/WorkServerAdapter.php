<?php

namespace mle86\WQ\WorkServerAdapter;

use mle86\WQ\Job\Job;
use mle86\WQ\Job\QueueEntry;

/**
 * A Work Server stores jobs inside one or more Work Queues.
 *
 * A Beanstalkd server or a Redis server might be such a Work Server.
 * In case of Beanstalkd, Work Queues are Tubes;
 * in case of Redis, Work Queues are Lists.
 */
interface WorkServerAdapter
{

    /** @var int  The default timeout for {@see getNextQueueEntry()}, in seconds. */
    public const DEFAULT_TIMEOUT = 5;

    /** Causes {@see getNextQueueEntry()} to return immediately. */
    public const NOBLOCK = 0;
    /** Causes {@see getNextQueueEntry()} to block indefinitely, until a job becomes available. */
    public const FOREVER = -1;

    /**
     * This takes the next job from the named work queue
     * and returns it.
     *
     * This method will reserve the returned job for a short time.
     * If you want to delete/bury/re-queue the job,
     * use the {@see deleteEntry}/{@see buryEntry}/{@see requeueEntry} methods.
     * Keep in mind to check the {@see Job::jobIsExpired()} flag
     * before executing the job.
     *
     * **If you don't want to do all of this manually,
     * use {@see WorkProcessor::processNextJob()} instead.**
     *
     * @param string|string[] $workQueue The name or names of the Work Queue(s) to poll.
     *                                   If it's an array of Work Queue names,
     *                                   the first job in any of these Work Queues will be returned.
     * @param int $timeout               How many seconds to wait for a job to arrive, if none is available immediately.
     *                                   Set this to NOBLOCK if the method should return immediately.
     *                                   Set this to FOREVER if the call should block until a job becomes available, no matter how long it takes.
     * @return QueueEntry  Returns the next job in the work queue(s),
     *                     or NULL if no job was available after waiting for $timeout seconds.
     */
    public function getNextQueueEntry($workQueue, int $timeout = self::DEFAULT_TIMEOUT): ?QueueEntry;

    /**
     * Stores a job in the work queue for later processing.
     *
     * @param string $workQueue The name of the Work Queue to store the job in.
     * @param Job $job          The job to store.
     * @param int $delay        The job delay in seconds after which it will become available to {@see getNextQueueEntry()}.
     *                          Set to zero (default) for jobs which should be processed as soon as possible.
     */
    public function storeJob(string $workQueue, Job $job, int $delay = 0): void;

    /**
     * Buries an existing job
     * so that it won't be returned by {@see getNextQueueEntry()} again
     * but is still present in the system for manual inspection.
     *
     * This is what happens to failed jobs.
     *
     * @param QueueEntry $entry
     */
    public function buryEntry(QueueEntry $entry): void;

    /**
     * Re-queues an existing job
     * so that it can be returned by {@see getNextQueueEntry()}
     * again at some later time.
     * A {@see $delay} is required
     * to prevent the job from being returned right after it was re-queued.
     *
     * This is what happens to failed jobs which can still be re-queued for a retry.
     *
     * @param QueueEntry $entry       The job to re-queue. The instance should not be used anymore after this call.
     * @param int $delay              The job delay in seconds. It will become available to {@see getNextQueueEntry()} after this delay.
     * @param string|null $workQueue  By default, to job is re-queued into its original Work Queue ({@see QueueEntry::getWorkQueue}).
     *                                With this parameter, a different Work Queue can be chosen.
     */
    public function requeueEntry(QueueEntry $entry, int $delay, string $workQueue = null): void;

    /**
     * Permanently deletes a job entry for its work queue.
     *
     * This is what happens to finished jobs.
     *
     * @param QueueEntry $entry The job to delete.
     */
    public function deleteEntry(QueueEntry $entry): void;

    /**
     * Explicitly closes the connection to the work server.
     *
     * The instance should not be used anymore after calling this method;
     * calling other methods afterwards is likely to lead to unexpected behaviour
     * such as connection-related exceptions.
     *
     * Repeated calls to this method have no effect.
     *
     * The class destructor should also call this,
     * so there's rarely a good reason for calling this method
     * outside of testing.
     */
    public function disconnect(): void;

}
