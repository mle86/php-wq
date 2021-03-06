<?php

namespace mle86\WQ\Tests;

use mle86\WQ\Job\Job;
use mle86\WQ\Job\JobContext;
use mle86\WQ\Job\JobResult;
use mle86\WQ\Testing\SimpleTestJob;
use mle86\WQ\Tests\Helper\ConfigurableTestJob;
use mle86\WQ\Tests\Helper\LoggingWorkProcessor;
use mle86\WQ\WorkProcessor;
use mle86\WQ\WorkServerAdapter\MemoryWorkServer;
use mle86\WQ\WorkServerAdapter\WorkServerAdapter;
use PHPUnit\Framework\TestCase;

function wp(): LoggingWorkProcessor
{
    $wsa = new MemoryWorkServer();
    $wp  = new LoggingWorkProcessor($wsa);
    return $wp;
}

class WorkProcessorTest extends TestCase
{

    public function testInstance(): WorkProcessor
    {
        return wp();
    }

    /**
     * @depends testInstance
     */
    public function testPollWithoutJobs(): void
    {
        $wp = wp();
        $q  = "some-queue-name";

        self::xsj_called();  // clear the flag
        $wp->processNextJob($q, [$this, 'xsj'], WorkServerAdapter::NOBLOCK);

        $this->assertFalse(self::xsj_called(),
            "We got a job from a non-existent work queue?!?");
        $this->assertSame(
            [["NOJOBS", $q]],
            $wp->log,
            "WorkProcessor::onNoJobAvailable() was not called!");
    }

    private const SIMPLE_JOB_MARKER = 2231;
    private const QUEUE             = "test";

    /**
     * @depends testInstance
     */
    public function testInsertOneSimpleJob(LoggingWorkProcessor $wp = null, SimpleTestJob $job = null): LoggingWorkProcessor
    {
        if (!$wp) {
            $wp = wp();
        }

        $wp->getWorkServerAdapter()->storeJob(
            self::QUEUE,
            $job ?? new SimpleTestJob(self::SIMPLE_JOB_MARKER));

        return $wp;
    }

    /**
     * @depends testInsertOneSimpleJob
     * @param LoggingWorkProcessor $wp
     */
    public function testExecuteOneSimpleJob(LoggingWorkProcessor $wp): void
    {
        // Now there is one ready job. It should be executed right away:
        $expect_log = [];
        $this->expectSuccess($wp, self::SIMPLE_JOB_MARKER, $expect_log);

        self::xsj_called();
        $wp->processNextJob(self::QUEUE, [$this, 'xsj'], WorkServerAdapter::NOBLOCK);
        $this->assertFalse(self::xsj_called(),
            "Finished job was not removed from the queue!");
    }

    /**
     * @depends testExecuteOneSimpleJob
     */
    public function testRethrowExceptionSetting(): void
    {
        $wp = wp();
        $ws = $wp->getWorkServerAdapter();

        $executed = null;
        $job = new SimpleTestJob(601);
        $handler = static function(SimpleTestJob $job) use(&$executed): void {
            $executed = $job->getMarker();
            throw new \DomainException("will this get re-thrown?");
        };

        $executed = null;
        $wp->setOption($wp::WP_RETHROW_EXCEPTIONS, false);
        $ws->storeJob('TQ-601', new SimpleTestJob(601));
        $wp->processNextJob('TQ-601', $handler, $ws::NOBLOCK);
        $this->assertSame(601, $executed);

        $wp->setOption($wp::WP_RETHROW_EXCEPTIONS, true);
        $ws->storeJob('TQ-602', new SimpleTestJob(602));
        $this->expectException(\DomainException::class);
        $wp->processNextJob('TQ-602', $handler, $ws::NOBLOCK);
    }

    /**
     * @depends testInsertOneSimpleJob
     * @depends testRethrowExceptionSetting
     */
    public function testExecuteFailingJob(): void
    {
        $expect_log = [];
        $wp         = wp();
        $m          = 2608;

        $wp->getWorkServerAdapter()->storeJob(self::QUEUE,
            (new ConfigurableTestJob($m))
                ->withMaxRetries(0)  // no retries
                ->succeedOn(0)  // never succeeds
        );

        $this->expectFailAndEnd($wp, $m, $expect_log, "non-requeueable");
        $this->expectEmptyWQ($wp, $expect_log, "non-requeueable");
    }

    /**
     * This one allows a few retries, but it will never succeed.
     *
     * @depends testExecuteFailingJob
     */
    public function testExecuteUnrecoverableJob(): void
    {
        $expect_log = [];
        $wp         = wp();
        $m          = 2604;

        $wp->getWorkServerAdapter()->storeJob(self::QUEUE,
            (new ConfigurableTestJob($m))
                ->withMaxRetries(1)  // up to one retry
                ->withRetryDelay(1)   // retry delay: 1s
                ->succeedOn(0)  // never succeeds
        );

        $this->expectFailAndRequeue($wp, $m, 1, $expect_log, "first try");
        $this->expectEmptyWQ($wp, $expect_log, "first try");

        sleep(1);  // wait until the current second is over...
        $this->expectFailAndEnd($wp, $m, $expect_log, "second and last try");
        $this->expectEmptyWQ($wp, $expect_log, "second and last try");
    }

    /**
     * This one looks like it allows retries --
     * but then it throws a non-{@see \RuntimeException Runtime} exception
     * which causes an abort.
     *
     * @depends testExecuteUnrecoverableJob
     */
    public function testExecuteAbortingJob(): void
    {
        $expect_log = [];
        $wp         = wp();
        $m          = 2609;

        $wp->getWorkServerAdapter()->storeJob(self::QUEUE,
            (new ConfigurableTestJob($m))
                ->withMaxRetries(4)  // four retries -- maybe?
                ->withRetryDelay(0)
                ->abortOn(2)  // nope, aborts on first retry
                ->succeedOn(0)  // ...and never succeeds
        );

        $this->expectFailAndRequeue($wp, $m, 0, $expect_log, "self-aborting job");
        $this->expectFailAndEnd($wp, $m, $expect_log, "self-aborting job");
        $this->expectEmptyWQ($wp, $expect_log, "self-aborting job");
    }

    /**
     * This one succeeds on the third try!
     *
     * @depends testExecuteUnrecoverableJob
     */
    public function testRecoverableJob(): void
    {
        $expect_log = [];
        $wp         = wp();
        $m          = 2601;

        $wp->getWorkServerAdapter()->storeJob(self::QUEUE,
            (new ConfigurableTestJob($m))
                ->withMaxRetries(5)  // up to five retries!
                ->withRetryDelay(1)   // retry delay: 1s
                ->succeedOn(3)  // succeeds on the third try!
        );

        $this->expectFailAndRequeue($wp, $m, 1, $expect_log, "first try");
        $this->expectEmptyWQ($wp, $expect_log, "first try");

        sleep(1);
        $this->expectFailAndRequeue($wp, $m, 1, $expect_log, "second try");
        $this->expectEmptyWQ($wp, $expect_log, "second try");

        sleep(1);
        $this->expectSuccess($wp, $m, $expect_log);
        $this->expectEmptyWQ($wp, $expect_log, "third try, success");
    }

    /**
     * @depends testPollWithoutJobs
     */
    public function testMultipleEmptyQueues(): void
    {
        $wp = wp();

        $queues = ["emptymq1", "emptymq1", "emptymq5"];

        self::xsj_called();  // clear the flag
        $wp->processNextJob($queues, [$this, 'xsj'], WorkServerAdapter::NOBLOCK);

        $this->assertFalse(self::xsj_called(),
            "Polling multiple queues at once returned some result when they should all have been empty!");
        $this->assertSame([["NOJOBS", join("|", $queues)]], $wp->log,
            "Polling multiple empty WQs did not result in the correct hook call!");
    }

    /**
     * @depends testMultipleEmptyQueues
     * @depends testInsertOneSimpleJob
     */
    public function testRetrieveJobFromMultipleQueues(): void
    {
        $job = new SimpleTestJob(3355);

        /** Returns a new LoggingWorkProcessor instance that contains one job in mq1, nothing else. */
        $fn_store = function() use($job): LoggingWorkProcessor {
            $wp = wp();
            $wp->getWorkServerAdapter()->storeJob(
                "mq1",
                clone $job);
            return $wp;
        };

        /**
         * Checks polling multiple queues at once
         * returns exactly the correct amount of queued jobs.
         *
         * @param LoggingWorkProcessor $wp
         * @param array $pollQueues
         * @param int $n_expected
         */
        $fn_check = function(LoggingWorkProcessor $wp, array $pollQueues, int $n_expected = 1) use($job) {
            $expected_log = [];

            for ($n = 0; $n < $n_expected; $n++) {
                $wp->processNextJob($pollQueues, [$this, 'xsj'], WorkServerAdapter::NOBLOCK);

                $expected_log[] = ["JOB", $job->getMarker()];
                $expected_log[] = ["SUCCESS", $job->getMarker()];

                $this->assertSame($expected_log, $wp->log,
                    "Could not execute a job in one of multiple queues! " .
                    "(There should have been {$n_expected} jobs, found only {$n})");
            }

            $expected_log[] = ["NOJOBS", join("|", $pollQueues)];
            self::xsj_called();  // clear the flag
            $wp->processNextJob($pollQueues, [$this, 'xsj'], WorkServerAdapter::NOBLOCK);
            $this->assertFalse(self::xsj_called(),
                "Polling multiple queues after retrieving {$n_expected} jobs from them " .
                "still returned something!");
            $this->assertSame($expected_log, $wp->log,
                "Polling multiple queues after retrieving {$n_expected} jobs from them " .
                "did not clear all those queues!");
            $expected_log[] = ["NOJOBS", join("|", $pollQueues)];

            self::xsj_called();  // clear the flag
            $wp->processNextJob($pollQueues, [$this, 'xsj'], WorkServerAdapter::NOBLOCK);
            $this->assertFalse(self::xsj_called(),
                "Polling multiple empty queues A SECOND TIME " .
                "after retrieving {$n_expected} jobs from them " .
                "still returned something!");
            $this->assertSame($expected_log, $wp->log,
                "Polling multiple empty queues A SECOND TIME " .
                "after retrieving {$n_expected} jobs from them " .
                "did not cause the correct hook calls!");
        };

        $fn_check($fn_store(), ["mq1"], 1);
        $fn_check($fn_store(), ["mq2"], 0);
        $fn_check($fn_store(), ["mq3"], 0);
        $fn_check($fn_store(), ["mq1", "mq1"], 1);
        $fn_check($fn_store(), ["mq2", "mq2"], 0);
        $fn_check($fn_store(), ["mq3", "mq3", "mq3"], 0);
        $fn_check($fn_store(), ["mq3", "mq1", "mq3", "mq3"], 1);
        $fn_check($fn_store(), ["mq3", "mq1", "mq3", "mq1", "mq3"], 1);
    }

    /**
     * @depends testRecoverableJob
     */
    public function testExpiredJob(): void
    {
        $marker = 9102;
        $job    = (new ConfigurableTestJob($marker))
            ->withMaxRetries(1)  // one retry
            ->withRetryDelay(0)
            ->succeedOn(2)  // succeeds on retry
            ;

        $wp = wp();
        $wp->getWorkServerAdapter()->storeJob(self::QUEUE, $job);

        ConfigurableTestJob::$expired_marker = $marker;  // this job is expired already!

        self::xsj_called();
        $wp->processNextJob(self::QUEUE, [$this, 'xsj'], WorkServerAdapter::NOBLOCK);
        $this->assertFalse(self::xsj_called(),
            "An expired job was executed (or processNextJob() returned something for some other reason)!");
        $this->assertNotContains("EXECUTE-" . $marker, SimpleTestJob::$log,
            "Expired job was executed!");
        $this->assertSame(
            ($expect_log = [
                ["EXPIRED", $marker],
            ]),
            $wp->log,
            "The hook functions were called incorrectly for an expired job!"
        );

        ConfigurableTestJob::$expired_marker = null;

        $this->expectEmptyWQ($wp, $expect_log, "expired");
    }

    /**
     * @depends testExpiredJob
     * @depends testExecuteUnrecoverableJob
     */
    public function testRetryExpiredJob(): void
    {
        $marker = 9103;
        $job    = (new ConfigurableTestJob($marker))
            ->withMaxRetries(1)  // one retry
            ->withRetryDelay(0)
            ->succeedOn(2)  // succeeds on retry
            ;

        $wp = wp();
        $wp->getWorkServerAdapter()->storeJob(self::QUEUE, $job);
        $expect_log = [];

        // is not expired yet, but will fail
        $this->expectFailAndRequeue($wp, $marker, 0, $expect_log, "first try, before expiry");

        // first retry would succeed, but it's expired now:
        ConfigurableTestJob::$expired_marker = $marker;

        self::xsj_called();  // clear the flag
        $wp->processNextJob(self::QUEUE, [$this, 'xsj'], WorkServerAdapter::NOBLOCK);
        $this->assertFalse(self::xsj_called(),
            "An expired-on-retry job was executed (or processNextJob() returned something for some other reason)!");
        $this->assertNotContains("EXECUTE-" . $marker, SimpleTestJob::$log,
            "Expired-on-retry job was executed!");
        $this->assertSame(
            ($expect_log = array_merge($expect_log, [
                ["EXPIRED", $marker],
            ])),
            $wp->log,
            "The hook functions were called incorrectly for an expired-on-retry job!"
        );

        ConfigurableTestJob::$expired_marker = null;

        $this->expectEmptyWQ($wp, $expect_log, "expired-on-retry");
    }

    /**
     * The previous test methods have already established that the default behavior on a void return works,
     * because our {@see xsj()} helper function is of void type.
     * But let's make sure with an explicit NULL return, then try all other possible values.
     *
     * @depends testExecuteOneSimpleJob
     * @depends testExecuteFailingJob
     */
    public function testCallbackReturnValue(): void
    {
        $marker = false;
        $make_callback_with_return_value = static function($return_value) use(&$marker): callable {
            return static function(SimpleTestJob $job) use($return_value, &$marker) {
                $marker = true;
                return $return_value;  // !
            };
        };
        $assert_job_state = function(LoggingWorkProcessor $wp, string $state) {
            $this->assertSame(
                $state,
                (end($wp->log))[0]);
        };

        $retryableJob = (new ConfigurableTestJob(self::SIMPLE_JOB_MARKER))
            ->withRetryDelay(0)
            ->withMaxRetries(99)
            ->succeedOn(99);

        // return NULL:
        $marker = false;
        $wp = $this->testInsertOneSimpleJob();
        $wp->processNextJob(
            self::QUEUE,
            $make_callback_with_return_value(null),
            WorkServerAdapter::NOBLOCK);
        $this->assertTrue($marker);
        $assert_job_state($wp, 'SUCCESS');

        // return JobResult::SUCCESS:
        $marker = false;
        $wp = $this->testInsertOneSimpleJob();
        $wp->processNextJob(
            self::QUEUE,
            $make_callback_with_return_value(JobResult::SUCCESS),
            WorkServerAdapter::NOBLOCK);
        $this->assertTrue($marker);
        $assert_job_state($wp, 'SUCCESS');

        // return JobResult::FAILED:
        $marker = false;
        $wp = $this->testInsertOneSimpleJob(wp(), clone $retryableJob);
        $wp->processNextJob(
            self::QUEUE,
            $make_callback_with_return_value(JobResult::FAILED),
            WorkServerAdapter::NOBLOCK);
        $this->assertTrue($marker);
        // We used FAILED, so the job should now be requeued:
        $assert_job_state($wp, 'REQUEUE');

        // return JobResult::ABORT:
        $marker = false;
        $wp = $this->testInsertOneSimpleJob(wp(), clone $retryableJob);
        $wp->processNextJob(
            self::QUEUE,
            $make_callback_with_return_value(JobResult::ABORT),
            WorkServerAdapter::NOBLOCK);
        $this->assertTrue($marker);
        // We used ABORT, so the job should now be buried instead of being requeued:
        $assert_job_state($wp, 'FAILED');

        // return JobResult::EXPIRED:
        $marker = false;
        $wp = $this->testInsertOneSimpleJob();
        $wp->processNextJob(
            self::QUEUE,
            $make_callback_with_return_value(JobResult::EXPIRED),
            WorkServerAdapter::NOBLOCK);
        $this->assertTrue($marker);
        // We used EXPIRED, so the job should now be deleted:
        $assert_job_state($wp, 'EXPIRED');

        // return something invalid:
        $marker = false;
        $wp = $this->testInsertOneSimpleJob();
        $exception = null;
        try {
            $wp->processNextJob(
                self::QUEUE,
                $make_callback_with_return_value("99324879387584 ~ invalid!"),
                WorkServerAdapter::NOBLOCK);
        } catch (\Throwable $exception) {
            // continue
        }
        // The handler should have been run:
        $this->assertTrue($marker);
        // The WorkProcessor should have noticed the invalid return value:
        $this->assertInstanceOf(\UnexpectedValueException::class, $exception);
        // And the job should still have been deleted!
        $assert_job_state($wp, 'SUCCESS');
    }

    /**
     * All of our job callbacks so far have taken only one argument,
     * but {@see WorkProcessor} actually provides two.
     * This method makes sure the second argument
     * is a correct {@see JobContext} DTO.
     *
     * @depends testRetrieveJobFromMultipleQueues
     * @depends testCallbackReturnValue
     */
    public function testJobContext(): void
    {
        $wp = wp();
        $ws = $wp->getWorkServerAdapter();

        $executed = false;
        $callback = function(Job $job, $context) use(&$executed, $wp, $ws): void {
            $executed = true;
            $this->assertInstanceOf(JobContext::class, $context,
                "WorkProcessor::processNextJob did not pass a JobContext object as second argument to job callback hnandler!");
            /** @var JobContext $context */
            $this->assertSame($job, $context->getJob());
            $this->assertSame($job, $context->getQueueEntry()->getJob());
            $this->assertSame($wp,  $context->getWorkProcessor());
            $this->assertSame($ws,  $context->getWorkServer());
        };

        $ws->storeJob('TQ-641', new SimpleTestJob(641));
        $wp->processNextJob('TQ-641', $callback, $ws::NOBLOCK);
        $this->assertTrue($executed);
    }

    /**
     * This method makes sure the {@see JobContext} callback argument
     * contains the job's source queue name.
     *
     * @depends testJobContext
     */
    public function testSourceQueue(): void
    {
        $executed = false;
        $makeCallback = function($expectedQueueName) use(&$executed): callable {
            return function(Job $job, JobContext $context) use($expectedQueueName, &$executed): void {
                $executed = true;
                $this->assertContains(
                    $context->getSourceQueue(),
                    (array)$expectedQueueName,
                    "job handler's second argument is not the correct source queue name!");
            };
        };

        $wp = wp();
        $ws = $wp->getWorkServerAdapter();

        $executed = false;
        $ws->storeJob('TQ-651', new SimpleTestJob(651));
        $wp->processNextJob('TQ-651', $makeCallback('TQ-651'), WorkServerAdapter::NOBLOCK);
        $this->assertTrue($executed);

        $executed = false;
        $ws->storeJob('TQ-652', new SimpleTestJob(652));
        $wp->processNextJob(['TQ-9952', 'TQ-652', 'TQ-99952'], $makeCallback('TQ-652'), WorkServerAdapter::NOBLOCK);
        $this->assertTrue($executed);
    }

    /**
     * Make sure the {@see JobContext} callbacks work as expected
     * and that at most one of them is called per job.
     *
     * @depends testJobContext
     * @depends testExecuteOneSimpleJob
     * @depends testExecuteFailingJob
     * @depends testExecuteUnrecoverableJob
     * @depends testExecuteAbortingJob
     * @depends testRethrowExceptionSetting
     */
    public function testJobContextCallbacks(): void
    {
        $wp = wp();
        $wp->setOption($wp::WP_RETHROW_EXCEPTIONS, false);
        $ws = $wp->getWorkServerAdapter();

        $executedJob        = null;
        $executedOnSuccess  = null;
        $executedOnFailure  = null;
        $executedOnTempfail = null;
        $makeJobCallback = function(?int $callbackReturnValue, \Throwable $throwThis = null) use(&$executedJob, &$executedOnSuccess, &$executedOnFailure, &$executedOnTempfail): callable {
            // When building a new callback, re-set all counters and flags:
            $executedJob        = false;
            $executedOnSuccess  = 0;
            $executedOnFailure  = 0;
            $executedOnTempfail = 0;

            return function(Job $job, JobContext $context) use($callbackReturnValue, $throwThis, &$executedJob, &$executedOnSuccess, &$executedOnFailure, &$executedOnTempfail): ?int {
                // This is our job handler.
                // Set the flag that it actually ran:
                $executedJob = true;

                // Set up multiple callbacks now.
                // They don't do much except increment a counter so we know which callbacks ran and which didn't.
                $context->onSuccess(function(Job $cbJob, JobContext $cbContext) use($job, $context, &$executedOnSuccess): void {
                    $this->assertSame($job,     $cbJob);
                    $this->assertSame($context, $cbContext);
                    $executedOnSuccess++;
                });
                $context->onFailure(function(Job $cbJob, JobContext $cbContext, ?\Throwable $cause) use($job, $context, $throwThis, &$executedOnFailure): void {
                    $this->assertSame($job,     $cbJob);
                    $this->assertSame($context, $cbContext);
                    $this->assertSame($throwThis, $cause);
                    $executedOnFailure++;
                });
                $context->onTemporaryFailure(function(Job $cbJob, JobContext $cbContext, ?\Throwable $cause) use($job, $context, $throwThis, &$executedOnTempfail): void {
                    $this->assertSame($job,     $cbJob);
                    $this->assertSame($context, $cbContext);
                    $this->assertSame($throwThis, $cause);
                    $executedOnTempfail++;
                });

                // Either return one of the JobResult constants now or throw something:
                if ($throwThis) {
                    throw $throwThis;
                }

                return $callbackReturnValue;
            };
        };


        // Try callbacks with a job that simply works:
        $ws->storeJob('TQ-661', new SimpleTestJob(661));
        $wp->processNextJob('TQ-661', $makeJobCallback(JobResult::SUCCESS), $ws::NOBLOCK);
        $this->assertTrue($executedJob);
        $this->assertEquals(1, $executedOnSuccess);
        $this->assertEquals(0, $executedOnFailure + $executedOnTempfail);

        // Try callbacks with a job that fails once, then succeeds:
        $ws->storeJob('TQ-662', (new ConfigurableTestJob(662))
            ->withRetryDelay(0)
            ->withMaxRetries(1)
        );
        $wp->processNextJob('TQ-662', $makeJobCallback(JobResult::FAILED), $ws::NOBLOCK);
        $this->assertTrue($executedJob);
        $this->assertEquals(1, $executedOnTempfail);
        $this->assertEquals(0, $executedOnSuccess + $executedOnFailure);
        $wp->processNextJob('TQ-662', $makeJobCallback(JobResult::SUCCESS), $ws::NOBLOCK);
        $this->assertTrue($executedJob);
        $this->assertEquals(1, $executedOnSuccess);
        $this->assertEquals(0, $executedOnFailure + $executedOnTempfail);

        // Once more, with exceptions:
        $ws->storeJob('TQ-663', (new ConfigurableTestJob(663))
            ->withRetryDelay(0)
            ->withMaxRetries(1)
        );
        $wp->processNextJob('TQ-663', $makeJobCallback(null, new \RuntimeException('please retry')), $ws::NOBLOCK);
        $this->assertTrue($executedJob);
        $this->assertEquals(1, $executedOnTempfail);
        $this->assertEquals(0, $executedOnSuccess + $executedOnFailure);
        $wp->processNextJob('TQ-663', $makeJobCallback(null, new \LogicException('nope')), $ws::NOBLOCK);
        $this->assertTrue($executedJob);
        $this->assertEquals(1, $executedOnFailure);
        $this->assertEquals(0, $executedOnSuccess + $executedOnTempfail);

        // Try callbacks with a job that aborts immediately:
        $ws->storeJob('TQ-664', new SimpleTestJob(663));
        $wp->processNextJob('TQ-664', $makeJobCallback(JobResult::ABORT), $ws::NOBLOCK);
        $this->assertTrue($executedJob);
        $this->assertEquals(1, $executedOnFailure);
        $this->assertEquals(0, $executedOnSuccess + $executedOnTempfail);

        // Try callbacks with an expired job:
        $ws->storeJob('TQ-665', new ConfigurableTestJob(665));
        ConfigurableTestJob::$expired_marker = 665;
        $wp->processNextJob('TQ-665', $makeJobCallback(JobResult::SUCCESS), $ws::NOBLOCK);
        $this->assertFalse($executedJob);
        $this->assertEquals(0, $executedOnSuccess + $executedOnFailure + $executedOnTempfail);
        ConfigurableTestJob::$expired_marker = null;

        // Once more, with JobResult::EXPIRED instead:
        $ws->storeJob('TQ-666', new SimpleTestJob(666));
        ConfigurableTestJob::$expired_marker = 666;
        $wp->processNextJob('TQ-666', $makeJobCallback(JobResult::EXPIRED), $ws::NOBLOCK);
        $this->assertEquals(0, $executedOnSuccess + $executedOnFailure + $executedOnTempfail);
        ConfigurableTestJob::$expired_marker = null;
    }


    private function expectSuccess(LoggingWorkProcessor $wp, int $marker, array &$expect_log): void
    {
        $wp->processNextJob(self::QUEUE, [$this, 'xsj'], WorkServerAdapter::NOBLOCK);

        $this->assertContains("EXECUTE-" . $marker, SimpleTestJob::$log,
            "Job was not executed!");
        $this->assertSame(
            ($expect_log = array_merge($expect_log, [
                ["JOB", $marker],
                ["SUCCESS", $marker],
            ])),
            $wp->log,
            "Job was executed, but the hook functions were called incorrectly!"
        );
    }

    private function expectFail(LoggingWorkProcessor $wp, string $desc): \Throwable
    {
        $e = null;
        try {
            $wp->processNextJob(self::QUEUE, [$this, 'xsj'], WorkServerAdapter::NOBLOCK);
        } catch (\Throwable $e) {
            // ok!
        }
        $this->assertInstanceOf(\Exception::class, $e,
            "Failing job's exception ({$desc}) was not re-thrown!");
        return $e;
    }

    private function expectFailAndRequeue(
        LoggingWorkProcessor $wp,
        int $marker,
        int $requeue_delay,
        array &$expect_log,
        string $desc
    ): void {
        $e = $this->expectFail($wp, $desc);
        $this->assertSame(
            ($expect_log = array_merge($expect_log, [
                ["JOB", $marker],
                ["REQUEUE", $marker, $requeue_delay, $e->getMessage()],
            ])),
            $wp->log,
            "Failing job ({$desc}) did not cause the correct hook calls!");
    }

    private function expectFailAndEnd(LoggingWorkProcessor $wp, int $marker, array &$expect_log, string $desc): void
    {
        $e = $this->expectFail($wp, $desc);
        $this->assertSame(
            ($expect_log = array_merge($expect_log, [
                ["JOB", $marker],
                ["FAILED", $marker, $e->getMessage()],
            ])),
            $wp->log,
            "Failing job ({$desc}) did not cause the correct hook calls!");
    }

    private function expectEmptyWQ(LoggingWorkProcessor $wp, array &$expect_log, string $desc): void
    {
        self::xsj_called();  // clear the flag
        $wp->processNextJob(self::QUEUE, [$this, 'xsj'], WorkServerAdapter::NOBLOCK);
        $expect_log[] = ["NOJOBS", self::QUEUE];
        $this->assertFalse(self::xsj_called(),
            "There still was a job in the wq! Previous job ({$desc}) not removed or re-queued without delay?");
        $this->assertSame($expect_log, $wp->log,
            "Empty WQ did not result in the correct hook call!");
    }


    private static $_xsj_called = false;

    /**
     * @return bool
     *   Returns true once after {@see xsj()} has been called at least once.
     */
    public static function xsj_called(): bool
    {
        if (self::$_xsj_called) {
            self::$_xsj_called = false;
            return true;
        } else {
            return false;
        }
    }

    /**
     * This function executes any {@see SimpleTestJob}'s built-in {@see execute()} method.
     * It's only here to shorten our test {@see WorkProcessor::processNextJob()} calls.
     *
     * @param SimpleTestJob $job
     * @return void  No return value -- this should trigger the {@see JobResult::DEFAULT} behavior.
     */
    public static function xsj(SimpleTestJob $job): void
    {
        global $_xsj_called;
        $_xsj_called = true;
        $job->execute();
    }


}
