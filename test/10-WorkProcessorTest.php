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
        $ret = $wp->executeNextJob($q, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);

        $this->assertNull($ret,
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

        $ret = $wp->executeNextJob(self::QUEUE, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);
        $this->assertNull($ret,
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

        $ret = $wp->executeNextJob($queues, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);

        $this->assertNull($ret,
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

        /** Returns a new LoggingWorkProcessor instance that contains one job in MULTI_QUEUES[0], nothing else. */
        $fn_store = function () use($job) : LoggingWorkProcessor {
            $wp = wp();
            $wp->getWorkServerAdapter()->storeJob(
                "mq1",
                clone $job);
            return $wp;
        };

        /**
         * Checks that a number of queues to poll
         * returns exactly the correct amount of queued jobs.
         *
         * @param LoggingWorkProcessor $wp
         * @param array $pollQueues
         * @param int $n_expected
         */
        $fn_check = function (LoggingWorkProcessor $wp, array $pollQueues, int $n_expected = 1) use($job) {
            $expected_log = [];

            for ($n = 0; $n < $n_expected; $n++) {
                $ret = $wp->executeNextJob($pollQueues, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);

                $expected_log[] = ["JOB", $job->getMarker()];
                $expected_log[] = ["SUCCESS", $job->getMarker(), SimpleJob::EXECUTE_RETURN_VALUE];

                $this->assertSame($expected_log, $wp->log,
                    "Could not execute a job in one of multiple queues! " .
                    "(There should have been {$n_expected} jobs, found only {$n})");

                $this->assertSame(SimpleJob::EXECUTE_RETURN_VALUE, $ret,
                    "Return value of job in one of multiple queues was not correctly returned! " .
                    "(There should have been {$n_expected} jobs, found only {$n})");
            }

            $expected_log[] = ["NOJOBS", join("|", $pollQueues)];
            $this->assertNull($wp->executeNextJob($pollQueues, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK),
                "Polling multiple queues after retrieving {$n_expected} jobs from them " .
                "still returned something!");
            $this->assertSame($expected_log, $wp->log,
                "Polling multiple queues after retrieving {$n_expected} jobs from them " .
                "did not clear all those queues!");
            $expected_log[] = ["NOJOBS", join("|", $pollQueues)];
            $this->assertNull($wp->executeNextJob($pollQueues, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK),
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


    private function expectSuccess (LoggingWorkProcessor $wp, int $marker, array &$expect_log) {
        $ret = $wp->executeNextJob(self::QUEUE, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);

        $this->assertContains("EXECUTE-" . $marker, SimpleJob::$log,
            "Job was not executed!");
        $this->assertSame(SimpleJob::EXECUTE_RETURN_VALUE, $ret,
            "Job was executed, but its return value was not returned by executeNextJob()!");
        $this->assertSame(
            ($expect_log = array_merge($expect_log, [
                ["JOB", $marker],
                ["SUCCESS", $marker, $ret],
            ])),
            $wp->log,
            "Job was executed, but the hook functions were called incorrectly!"
        );
    }

    private function expectFail (LoggingWorkProcessor $wp, string $desc) {
        $e = null;
        try {
            $wp->executeNextJob(self::QUEUE, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);
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
                ["REQUEUE", $marker, $e->getMessage(), $requeue_delay],
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
        $ret          = $wp->executeNextJob(self::QUEUE, __NAMESPACE__.'\\xsj', WorkServerAdapter::NOBLOCK);
        $expect_log[] = ["NOJOBS", self::QUEUE];
        $this->assertNull($ret,
            "There still was a job in the wq! Previous job ({$desc}) not removed or re-queued without delay?");
        $this->assertSame($expect_log, $wp->log,
            "Empty WQ did not result in the correct hook call!");
    }

}

