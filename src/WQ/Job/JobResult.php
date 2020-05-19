<?php

namespace mle86\WQ\Job;

use mle86\WQ\WorkProcessor;

/**
 * This class exists only to hold the job callback return status constants.
 *
 * - {@see JobResult::SUCCESS}
 * - {@see JobResult::FAILED}
 * - ({@see JobResult::ABORT})
 * - ({@see JobResult::EXPIRED})
 *
 * The {@see WorkProcessor} class
 * assumes that the job handler function
 * returns one of these constants
 * (or NULL/no value, in which case {@see JobResult::DEFAULT} will be used).
 *
 * If you don't use the {@see WorkProcessor} class,
 * you won't need this class either.
 * If you do use the {@see WorkProcessor} class
 * but your job handlers always either succeed or throw some {@see Exception},
 * you won't need this class either –
 * it's useful only if you want additional control over the re-try mechanism without throwing exceptions.
 */
class JobResult
{

    /**
     * This status indicates that the job has been processed correctly
     * and that it should now be deleted from the work queue.
     *
     * (It triggers the behavior set through the {@see WorkProcessor::WP_DELETE} option.)
     */
    public const SUCCESS = 0;

    /**
     * This status indicates that the job has failed.
     * If the job can be re-tried (according to its {@see Job::jobCanRetry()} result),
     * it will be re-queued for later re-execution.
     * If not, it will be *buried*.
     *
     * (That behavior may be changed through the {@see WorkProcessor::WP_ENABLE_RETRY} and
     *  {@see WorkProcessor::WP_ENABLE_BURY} options.)
     *
     * (The same thing happens if the job handler callback throws some {@see RuntimeException}.)
     */
    public const FAILED = 1;

    /**
     * This status indicates that the job has failed
     * and that it should _not_ be re-tried,
     * regardless of its {@see Job::jobCanRetry()} result
     * and the {@see WorkProcessor::WP_ENABLE_RETRY} setting.
     *
     * The job will immediately be buried/deleted
     * (according to the {@see WorkProcessor::WP_ENABLE_BURY} setting).
     *
     * (The same thing happens if the job handler callback throws some non-{@see RuntimeException Runtime} exception.)
     */
    public const ABORT = 2;

    /**
     * This status indicates that the job is actually expired
     * and that it should be deleted
     * (or whatever other action is indicated by the {@see WorkProcessor::WP_EXPIRED} setting).
     *
     * Usually, an expired job's {@see Job::jobIsExpired} method should return `true` right away
     * which is the preferred way to indicate job expiration.
     * But there might be situations in which the newly-unserialized {@see Job}
     * cannot determine its own expiration without additional dependencies.
     * The job handler callback however may be able to
     * and that's why this constant exists.
     */
    public const EXPIRED = 3;


    /**
     * If the handler function returns NULL or no value at all,
     * the {@see WorkProcessor} will use the default behavior
     * set by this constant.
     */
    public const DEFAULT = self::SUCCESS;


    private function __construct()
    {
    }

}
