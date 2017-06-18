<?php
namespace mle86\WQ\Tests;

use mle86\WQ\WorkServerAdapter\MemoryWorkServer;
use mle86\WQ\WorkServerAdapter\WorkServerAdapter;

require_once 'helper/misc.php';
require_once 'helper/SimpleJob.php';
require_once 'helper/ConfigurableJob.php';
require_once 'helper/LoggingWorkProcessor.php';

function wp () : LoggingWorkProcessor {
    $wsa = new MemoryWorkServer ();
    $wp  = new LoggingWorkProcessor ($wsa);
    return $wp;
}

class WorkProcessorTest
    extends \PHPUnit_Framework_TestCase
{

    public function testInstance () {
        wp();
    }

    /**
     * @depends testInstance
     */
    public function testPollWithoutJobs () {
        $wp  = wp();
        $q   = "some-queue-name";

        xsj_called();  // clear the flag
        $wp->processNextJob($q, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);

        $this->assertFalse(xsj_called(),
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
    public function testInsertOneSimpleJob () {
        $wp = wp();

        $wp->getWorkServerAdapter()->storeJob(
            self::QUEUE,
            new SimpleJob (self::SIMPLE_JOB_MARKER));

        return $wp;
    }

    /**
     * @depends testInsertOneSimpleJob
     * @param LoggingWorkProcessor $wp
     */
    public function testExecuteOneSimpleJob (LoggingWorkProcessor $wp) {
        // Now there is one ready job. It should be executed right away:
        $expect_log = [];
        $this->expectSuccess($wp, self::SIMPLE_JOB_MARKER, $expect_log);

        xsj_called();  // clear the flag
        $wp->processNextJob(self::QUEUE, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);
        $this->assertFalse(xsj_called(),
            "Finished job was not removed from the queue!");
    }

    public function testExecuteFailingJob () {
        $expect_log = [];
        $wp         = wp();
        $m          = 2608;

        $wp->getWorkServerAdapter()->storeJob(self::QUEUE,
            new ConfigurableJob (
                $m,
                0,  // no retries
                0   // never succeeds
            ));

        $this->expectFailAndEnd($wp, $m, $expect_log, "non-requeueable");
        $this->expectEmptyWQ($wp, $expect_log, "non-requeueable");
    }

    /**
     * This one allows a few retries, but it will never succeed.
     *
     * @depends testExecuteFailingJob
     */
    public function testExecuteUnrecoverableJob () {
        $expect_log = [];
        $wp         = wp();
        $m          = 2604;

        $wp->getWorkServerAdapter()->storeJob(self::QUEUE,
            new ConfigurableJob (
                $m,
                1,  // up to one retry
                0,  // never succeeds
                1   // retry delay: 1s
            ));

        $this->expectFailAndRequeue($wp, $m, 1, $expect_log, "first try");
        $this->expectEmptyWQ($wp, $expect_log, "first try");

        wait_for_subsecond(0.98);  // wait until the current second is over...
        $this->expectFailAndEnd($wp, $m, $expect_log, "second and last try");
        $this->expectEmptyWQ($wp, $expect_log, "second and last try");
    }

    /**
     * This one succeeds on the third try!
     *
     * @depends testExecuteUnrecoverableJob
     */
    public function testRecoverableJob () {
        $expect_log = [];
        $wp         = wp();
        $m          = 2601;

        $wp->getWorkServerAdapter()->storeJob(self::QUEUE,
            new ConfigurableJob (
                $m,
                5,  // up to five retries!
                3,  // succeeds on the third try!
                1   // retry delay: 1s
            ));

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
    public function testMultipleEmptyQueues () {
        $wp = wp();

        $queues = ["emptymq1", "emptymq1", "emptymq5"];

        xsj_called();  // clear the flag
        $wp->processNextJob($queues, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);

        $this->assertFalse(xsj_called(),
            "Polling multiple queues at once returned some result when they should all have been empty!");
        $this->assertSame([["NOJOBS", join("|", $queues)]], $wp->log,
            "Polling multiple empty WQs did not result in the correct hook call!");
    }

    /**
     * @depends testMultipleEmptyQueues
     * @depends testInsertOneSimpleJob
     */
    public function testRetrieveJobFromMultipleQueues () {
        $job = new SimpleJob (3355);

        /** Returns a new LoggingWorkProcessor instance that contains one job in mq1, nothing else. */
        $fn_store = function () use($job) : LoggingWorkProcessor {
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
        $fn_check = function (LoggingWorkProcessor $wp, array $pollQueues, int $n_expected = 1) use($job) {
            $expected_log = [];

            for ($n = 0; $n < $n_expected; $n++) {
                $wp->processNextJob($pollQueues, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);

                $expected_log[] = ["JOB", $job->getMarker()];
                $expected_log[] = ["SUCCESS", $job->getMarker()];

                $this->assertSame($expected_log, $wp->log,
                    "Could not execute a job in one of multiple queues! " .
                    "(There should have been {$n_expected} jobs, found only {$n})");
            }

            $expected_log[] = ["NOJOBS", join("|", $pollQueues)];
            xsj_called();  // clear the flag
            $wp->processNextJob($pollQueues, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);
            $this->assertFalse(xsj_called(),
                "Polling multiple queues after retrieving {$n_expected} jobs from them " .
                "still returned something!");
            $this->assertSame($expected_log, $wp->log,
                "Polling multiple queues after retrieving {$n_expected} jobs from them " .
                "did not clear all those queues!");
            $expected_log[] = ["NOJOBS", join("|", $pollQueues)];

            xsj_called();  // clear the flag
            $wp->processNextJob($pollQueues, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);
            $this->assertFalse(xsj_called(),
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
    public function testExpiredJob () {
        $marker = 9102;
        $job = new ConfigurableJob (
            $marker,
            1,  // one retry
            2,  // succeeds on retry
            0
        );

        $wp = wp();
        $wp->getWorkServerAdapter()->storeJob(self::QUEUE, $job);

        ConfigurableJob::$expired_marker = $marker;  // this job is expired already!

        xsj_called();
        $wp->processNextJob(self::QUEUE, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);
        $this->assertFalse(xsj_called(),
            "An expired job was executed (or processNextJob() returned something for some other reason)!");
        $this->assertNotContains("EXECUTE-" . $marker, SimpleJob::$log,
            "Expired job was executed!");
        $this->assertSame(
            ($expect_log = [
                ["EXPIRED", $marker],
            ]),
            $wp->log,
            "The hook functions were called incorrectly for an expired job!"
        );

        ConfigurableJob::$expired_marker = null;

        $this->expectEmptyWQ($wp, $expect_log, "expired");
    }

    /**
     * @depends testExpiredJob
     * @depends testExecuteUnrecoverableJob
     */
    public function testRetryExpiredJob () {
        $marker = 9103;
        $job = new ConfigurableJob (
            $marker,
            1,  // one retry
            2,  // succeeds on retry
            0
        );

        $wp = wp();
        $wp->getWorkServerAdapter()->storeJob(self::QUEUE, $job);
        $expect_log = [];

        // is not expired yet, but will fail
        $this->expectFailAndRequeue($wp, $marker, 0, $expect_log, "first try, before expiry");

        // first retry would succeed, but it's expired now:
        ConfigurableJob::$expired_marker = $marker;

        xsj_called();  // clear the flag
        $wp->processNextJob(self::QUEUE, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);
        $this->assertFalse(xsj_called(),
            "An expired-on-retry job was executed (or processNextJob() returned something for some other reason)!");
        $this->assertNotContains("EXECUTE-" . $marker, SimpleJob::$log,
            "Expired-on-retry job was executed!");
        $this->assertSame(
            ($expect_log = array_merge($expect_log, [
                ["EXPIRED", $marker],
            ])),
            $wp->log,
            "The hook functions were called incorrectly for an expired-on-retry job!"
        );

        ConfigurableJob::$expired_marker = null;

        $this->expectEmptyWQ($wp, $expect_log, "expired-on-retry");
    }


    private function expectSuccess (LoggingWorkProcessor $wp, int $marker, array &$expect_log) {
        $wp->processNextJob(self::QUEUE, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);

        $this->assertContains("EXECUTE-" . $marker, SimpleJob::$log,
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

    private function expectFail (LoggingWorkProcessor $wp, string $desc) {
        $e = null;
        try {
            $wp->processNextJob(self::QUEUE, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);
        } catch (\Throwable $e) {
            // ok!
        }
        $this->assertInstanceOf(\RuntimeException::class, $e,
            "Failing job's RuntimeException ({$desc}) was not re-thrown!");
        return $e;
    }

    private function expectFailAndRequeue (LoggingWorkProcessor $wp, int $marker, int $requeue_delay, array &$expect_log, string $desc) {
        $e = $this->expectFail($wp, $desc);
        $this->assertSame(
            ($expect_log = array_merge($expect_log, [
                ["JOB", $marker],
                ["REQUEUE", $marker, $requeue_delay, $e->getMessage()],
            ])),
            $wp->log,
            "Failing job ({$desc}) did not cause the correct hook calls!");
    }

    private function expectFailAndEnd (LoggingWorkProcessor $wp, int $marker, array &$expect_log, string $desc) {
        $e = $this->expectFail($wp, $desc);
        $this->assertSame(
            ($expect_log = array_merge($expect_log, [
                ["JOB", $marker],
                ["FAILED", $marker, $e->getMessage()],
            ])),
            $wp->log,
            "Failing job ({$desc}) did not cause the correct hook calls!");
    }

    private function expectEmptyWQ (LoggingWorkProcessor $wp, array &$expect_log, string $desc) {
        xsj_called();  // clear the flag
        $wp->processNextJob(self::QUEUE, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);
        $expect_log[] = ["NOJOBS", self::QUEUE];
        $this->assertFalse(xsj_called(),
            "There still was a job in the wq! Previous job ({$desc}) not removed or re-queued without delay?");
        $this->assertSame($expect_log, $wp->log,
            "Empty WQ did not result in the correct hook call!");
    }

}

