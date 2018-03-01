<?php
namespace mle86\WQ\Job;

/**
 * This class exists only to hold the job callback return status constants.
 *
 * - {@see JobResult::SUCCESS}
 * - {@see JobResult::FAILED}
 *
 * The {@see WorkProcessor} class
 * assumes that the job handler function
 * returns one of these constants
 * (or NULL/no value, in which case {@see JobResult::DEFAULT} will be used).
 *
 * If you don't use the {@see WorkProcessor} class,
 * you won't need this class either.
 * If you do use the {@see WorkProcessor} class
 * but your job handlers always either succeed or throw some {@see \RuntimeException},
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
    const SUCCESS = 0;

    /**
     * This status indicates that the job has failed.
     * If the job can be re-tried (according to its {@see Job::jobCanRetry()} result),
     * it will be re-queued for later re-execution.
     * If not, it will be *buried*.
     *
     * (That behavior may be changed through the {@see WorkProcessor::WP_ENABLE_RETRY} and
     *  {@see WorkProcessor::WP_ENABLE_BURY} options.)
     */
    const FAILED = 1;


    /**
     * If the handler function returns NULL or no value at all,
     * the {@see WorkProcessor} will use the default behavior
     * set by this constant.
     */
    const DEFAULT = self::SUCCESS;


    private function __construct()
    {
    }

}
