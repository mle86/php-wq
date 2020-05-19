<?php

namespace mle86\WQ\Job;

/**
 * A Job is a representation of some task do to.
 * It can be stored in a Work Queue with {@see WorkServerAdapter::storeJob()}.
 *
 * This interface extends {@see \Serializable},
 * because all Jobs have to be serializable
 * in order to be stored in a Work Queue.
 *
 * For your own Job classes,
 * see the {@see AbstractJob} base class instead;
 * it is easier to work with
 * as it provides default implementations
 * for the required methods.
 *
 * This interface does not specify how a Job should be executed
 * or how the responsible method(s) should be named,
 * if they are part of the Job implementation at all.
 */
interface Job extends \Serializable
{

    /**
     * Whether this job can be retried later.
     * The {@see WorkProcessor} helper class will check this if job execution has failed.
     * If it returns true, the job will be stored in the Work Queue again
     * to be re-executed after {@see jobRetryDelay()} seconds;
     * if it returns false, the job will be buried for later inspection.
     */
    public function jobCanRetry(): bool;

    /**
     * How many seconds the job should be delayed in the Work Queue
     * before being re-tried.
     * If {@see jobCanRetry()} is true,
     * this must return a positive integer
     * (or zero, if the job should be re-tried as soon as possible).
     */
    public function jobRetryDelay(): ?int;

    /**
     * On the first try, this must return 1,
     * on the first retry, this must return 2,
     * and so on.
     */
    public function jobTryIndex(): int;

    /**
     * Return `true` here if the instance should be considered expired.
     *
     * The {@see WorkServerAdapter} implementations don't care about this flag
     * and will still return expired instances
     * but the {@see WorkProcessor} class won't process them –
     * they will be deleted as soon as they are encountered.
     * Always return `false` here if your job class cannot expire.
     */
    public function jobIsExpired(): bool;

}
