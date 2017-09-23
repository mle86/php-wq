<?php
namespace mle86\WQ\Job;

/**
 * This class exists only to hold the JOB_ return status constants.
 *
 * - {@see JobResult::JOB_SUCCESS}
 * - {@see JobResult::JOB_FAILED}
 *
 * The {@see WorkProcessor} class
 * assumes that the job handler function
 * returns one of these constants
 * (or NULL/no value, in which case {@see JobResult::DEFAULT_STATUS} will be used).
 */
class JobResult
{

    /**
     * This status indicates that the job has been processed correctly
     * and that it should now be deleted from the work queue.
     *
     * (It triggers the behavior set through the {@see WorkProcessor::WP_DELETE} setting.)
     */
    const SUCCESS = 0;

    /**
     * This status indicates that the job has failed.
     * If the job can be re-tried (according to its {@see Job::jobCanRetry()} result),
     * it will be re-queued for later re-execution.
     * If not, it will be *buried*.
     *
     * (That behavior may be changed through the {@see WorkProcessor::WP_ENABLE_RETRY} and
     *  {@see WorkProcessor::WP_ENABLE_BURY} settings.)
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
