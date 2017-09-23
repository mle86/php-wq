<?php
namespace mle86\WQ\Tests;

use mle86\WQ\Job\Job;
use mle86\WQ\Job\QueueEntry;
use mle86\WQ\WorkServerAdapter\WorkServerAdapter;

require_once __DIR__ . '/misc.php';
require_once __DIR__ . '/SimpleJob.php';

/**
 * This base class contains a series of tests
 * for the abstract WorkServerAdapter interface.
 *
 * It is intended to serve as a template
 * for implementation tests.
 * Implementation need to require this package anyway,
 * so they should be able to use this file.
 */
abstract class AbstractWorkServerAdapterTest
    extends \PHPUnit_Framework_TestCase
{

    /**
     * Implement this method to return a properly-configured instance
     * of your {@see WorkServerAdapter} implementation.
     *
     * For example, this could be a BeanstalkdServer instance
     * set up to connect to the temporary Beanstalkd server
     * running in the test container.
     *
     * @return WorkServerAdapter
     */
    abstract public function getWorkServerAdapter(): WorkServerAdapter;

    /**
     * If your test class needs to verify something in the environment
     * before any other test methods are run,
     * override this empty method and put those checks here.
     *
     * @return void
     */
    public function checkEnvironment(): void
    {
    }


    // Helper methods and data providers:  ////////////////////////////////////////


    /**
     * This method ensures that we're running in some kind of Docker container.
     * You may call it in your {@see checkEnvironment()} implementation.
     */
    final protected function checkInDocker()
    {
        $this->assertFileExists("/.dockerenv",
            "This test script needs to be run inside a Docker container!");
    }

    final protected function checkWQEmpty(WorkServerAdapter $ws, $queues, string $message = '')
    {
        if ($message !== '') {
            $message = " ({$message})";
        }

        foreach ((array)$queues as $queue) {
            $this->assertNull($ws->getNextQueueEntry($queue, $ws::NOBLOCK),
                "Work queue '{$queue}' should have been empty!" . $message);
        }
    }

    protected static $ws_classname = null;

    final protected function getWSClass()
    {
        // set by testGetServerInstance()
        return static::$ws_classname;
    }

    final private function jobQueueData()
    {
        $queues = [];
        foreach ($this->jobData() as $jd) {
            $queue_name = $jd[0];
            $job_marker = $jd[1];
            $queues[ $queue_name ][] = $job_marker;
        }
        return $queues;
    }

    public function jobData()
    {
        return [
            // [ queue_name, marker_id ]
            ["twos",  204],
            ["nines", 900],
            ["nines", 960],
            ["ones",  144],
            ["twos",  203],
            ["nines", 930],
            ["nines", 930],
        ];
    }


    // This is where the actual test method sequence begins:  /////////////////////


    /**
     * This is the very first test to run.
     */
    final public function testEnvironment()
    {
        $this->checkEnvironment();
    }

    /**
     * This is the second test to run.
     * It ensures that we have a working instance of the target implementation.
     *
     * @depends testEnvironment
     * @return WorkServerAdapter
     */
    final public function testGetServerInstance(): WorkServerAdapter
    {
        $ws = $this->getWorkServerAdapter();

        // Stores class name for better error messages.
        // Use getWSClass() to retrieve it
        $name = get_class($ws);
        $p    = strrpos($name, '\\');
        if ($p !== false) {
            // remove namespace prefix
            $name = substr($name, $p + 1);
        }
        static::$ws_classname = $name;

        return $ws;
    }

    /**
     * @dataProvider jobData
     * @depends      testGetServerInstance
     * @param string $queue_name
     * @param int $job_marker
     * @param WorkServerAdapter $ws
     */
    public function testQueuesEmpty(string $queue_name, int $job_marker, WorkServerAdapter $ws)
    {
        $this->checkWQEmpty($ws, $queue_name);

        unset($job_marker);  // suppress "unused" warning
    }

    /**
     * @dataProvider jobData
     * @depends      testGetServerInstance
     * @depends      testQueuesEmpty
     * @param string $queue_name
     * @param int $job_marker
     * @param WorkServerAdapter $ws
     */
    public function testQueueJobs(string $queue_name, int $job_marker, WorkServerAdapter $ws)
    {
        $j = new SimpleJob ($job_marker);

        $this->assertSame($job_marker, $j->getMarker(),
            "job constructor broken, wrong marker saved");

        $ws->storeJob($queue_name, $j);
    }

    /**
     * @depends testGetServerInstance
     * @depends testQueueJobs
     * @param WorkServerAdapter $ws
     * @return array [ queue_name => [ QueueEntry, ... ], ... ]
     */
    public function testGetQueuedJobs(WorkServerAdapter $ws): array
    {
        $knownQueues = $this->jobQueueData();

        $n_jobs = count($this->jobData());
        $n      = 0;

        $queues = [];

        foreach (array_keys($knownQueues) as $known_queue_name) {
            while (($qe = $ws->getNextQueueEntry($known_queue_name, $ws::NOBLOCK)) !== null) {
                $queue_name = $qe->getWorkQueue();
                $this->assertEquals($known_queue_name, $queue_name,
                    "{$this->getWSClass()}::getNextQueueEntry() returned a Job from a different work queue than requested!");
                $queues[ $queue_name ][] = $qe;
                $n++;
            }
        }

        // Ok, the WorkServerAdapter seems to be empty now.
        // Time to check the total returned job count:

        $this->assertGreaterThanOrEqual($n_jobs, $n,
            "Repeated {$this->getWSClass()}::getNextQueueEntry() (with all previously-used queue names) did not return enough Jobs!");
        $this->assertEquals($n_jobs, $n,
            "Repeated {$this->getWSClass()}::getNextQueueEntry() (with all previously-used queue names) returned TOO MANY Jobs!");

        return $queues;
    }

    /**
     * We know that the WorkServerAdapter is now empty (or at least there are no ready jobs)
     * and that it returned the correct total number of jobs.
     * Did it return the correct queue names?
     *
     * @depends testGetQueuedJobs
     * @param array $queues
     */
    public function testStoredQueueNames(array $queues)
    {
        $known_queue_names  = array_keys($this->jobQueueData());
        $stored_queue_names = array_keys($queues);
        sort($known_queue_names);
        sort($stored_queue_names);

        $this->assertSame($known_queue_names, $stored_queue_names,
            "{$this->getWSClass()} did not store the correct queue names!");
    }

    /**
     * The WorkServerAdapter is now empty
     * and returned the correct number of jobs (total)
     * and the correct queue names.
     * What about the actual stored Jobs?
     *
     * @depends testGetQueuedJobs
     * @depends testStoredQueueNames
     * @param array $queues
     */
    public function testStoredJobs(array $queues)
    {
        $known = $this->jobQueueData();

        foreach ($queues as $queue_name => $qelist) {
            $this->assertContainsOnlyInstancesOf(QueueEntry::class, $qelist,
                "{$this->getWSClass()}::getNextQueueEntry() returned an unexpected object!");

            $this->assertEquals(count($known[ $queue_name ]), count($qelist),
                "{$this->getWSClass()}::getNextQueueEntry('{$queue_name}') returned the wrong number of jobs!");

            $known_markers  = $known[ $queue_name ];
            $stored_markers = array_map(
                function (QueueEntry $qe) {
                    /** @var Job|SimpleJob $job */
                    $job = $qe->getJob();
                    return $job->getMarker();
                },
                $qelist);

            sort($known_markers);
            sort($stored_markers);

            $this->assertSame($known_markers, $stored_markers,
                "{$this->getWSClass()} did not store the correct Jobs! (The marker IDs don't match.)");
        }
    }

    /**
     * @depends testGetQueuedJobs
     * @depends testGetServerInstance
     * @depends testStoredJobs
     * @param array $queues
     * @param WorkServerAdapter $ws
     */
    public function testExecuteAndDeleteJobs(array $queues, WorkServerAdapter $ws)
    {
        $markers = [];

        /** @var QueueEntry[] $qelist */
        foreach ($queues as $qelist) {
            foreach ($qelist as $qe) {
                /** @var Job|SimpleJob $job */
                $job    = $qe->getJob();
                $marker = $job->getMarker();

                $markers[ $marker ] = 1 + ($markers[ $marker ] ?? 0);

                $job->execute();
                $ws->deleteEntry($qe);
            }
        }

        // Okay, we should now have one log entry for every known marker id:
        foreach ($markers as $marker => $n_entries) {
            $fn_matching_marker = function ($log) use ($marker) {
                return ($log === "EXECUTE-{$marker}");
            };
            $n_executions       = count(array_filter(SimpleJob::$log, $fn_matching_marker));

            $this->assertGreaterThanOrEqual($n_entries, $n_executions,
                "Job with marker '{$marker}' was not executed as often as it should have been!");
            $this->assertEquals($n_entries, $n_executions,
                "Job with marker '{$marker}' was executed too many times!");
        }
    }

    /**
     * @depends testGetServerInstance
     * @depends testStoredJobs
     * @depends testExecuteAndDeleteJobs
     *   this way the WorkServerAdapter is empty again
     * @param WorkServerAdapter $ws
     */
    public function testDelayedJob(WorkServerAdapter $ws)
    {
        $j     = new SimpleJob (555);
        $queue = "test";
        $delay = 1;

        $this->assertNull($ws->getNextQueueEntry($queue, $ws::NOBLOCK),
            "There already is something in the test queue!");

        // We want at least 0.5s remaining in the current second:
        wait_for_subsecond();

        $ws->storeJob($queue, $j, $delay);

        $this->assertNull($ws->getNextQueueEntry($queue, $ws::NOBLOCK),
            "The delayed job was immediately available!");

        usleep(1000 * 100);  // wait a little, but definitely less than the delay
        // It should NOT be available right now, the second is not over yet.

        $this->assertNull($ws->getNextQueueEntry($queue, $ws::NOBLOCK),
            "The delayed job was available too soon!");

        // But within one full second, it will become available:
        $qe = $ws->getNextQueueEntry($queue, 1);

        $this->assertNotNull($qe,
            "getNextQueueEntry(timeout=1) did not return our Job which had a 1s delay!");
        $this->assertInstanceOf(Job::class, $qe->getJob(),
            "Delayed job is not actually a Job object!");
        $this->assertSame($queue, $qe->getWorkQueue(),
            "Delayed job could be retrieved, but has incorrect origin reference!");

        /** @var Job|SimpleJob $job */
        $job = $qe->getJob();
        $this->assertSame($j->getMarker(), $job->getMarker(),
            "Delayed job did not match the original job object!");

        $ws->deleteEntry($qe);
    }

    /**
     * @depends testGetServerInstance
     * @depends testStoredJobs
     * @depends testExecuteAndDeleteJobs
     *   this way the WorkServerAdapter is empty again
     * @param WorkServerAdapter $ws
     */
    public function testRequeueJob(WorkServerAdapter $ws)
    {
        $j              = new SimpleJob (566);
        $queue          = "test2";
        $delay          = 0;
        $requeued_delay = 1;

        wait_for_subsecond();

        $ws->storeJob($queue, $j, $delay);

        // take it out again:
        /** @var Job|SimpleJob $job */
        $qe  = $ws->getNextQueueEntry($queue, $ws::NOBLOCK);
        $job = $qe->getJob();
        $this->assertSame($j->getMarker(), $job->getMarker());
        $this->assertEquals(1, $job->jobTryIndex(),
            "Dequeued job has wrong try index!");
        $this->assertSame($queue, $qe->getWorkQueue(),
            "Dequeued job has incorrect origin reference!");

        // requeue it:
        $ws->requeueEntry($qe, $requeued_delay);

        $this->assertNull($ws->getNextQueueEntry($queue, $ws::NOBLOCK),
            "Re-queued job with delay was immediately available!");
        usleep(1000 * 100);
        $this->assertNull($ws->getNextQueueEntry($queue, $ws::NOBLOCK),
            "Re-queued job with delay became available too soon!");

        // take it out again:
        /** @var Job|SimpleJob $job */
        $qe = $ws->getNextQueueEntry($queue, 1);
        $this->assertNotNull($qe,
            "Re-queued job could not be retrieved with getNextQueueEntry()!");
        $this->checkWQEmpty($ws, $queue,
            "Re-queued job did not disappear from wq after successful getNextQueueEntry()!");

        $job = $qe->getJob();
        $this->assertSame($j->getMarker(), $job->getMarker());
        $this->assertEquals(2, $job->jobTryIndex(),
            "Dequeued job (was re-queued once) has wrong try index!");
        $this->assertSame($queue, $qe->getWorkQueue(),
            "Dequeued job (was re-queued once) has incorrect origin reference!");

        $ws->deleteEntry($qe);
    }

    /**
     * @depends testGetServerInstance
     * @depends testQueuesEmpty
     * @param WorkServerAdapter $ws
     */
    public function testMultipleEmptyQueues(WorkServerAdapter $ws)
    {
        $queues = array_keys($this->jobQueueData());

        // this checks the queues one at a time
        $this->checkWQEmpty($ws, $queues);

        // now poll them all at once:
        $this->assertNull($ws->getNextQueueEntry($queues, $ws::NOBLOCK),
            "Polling multiple empty queues one at a time worked correctly, " .
            "but polling them all at once returned something?!");
    }

    /**
     * @depends testGetServerInstance
     * @depends testMultipleEmptyQueues
     * @param WorkServerAdapter $ws
     */
    public function testPollMultipleQueues(WorkServerAdapter $ws)
    {
        $job = new SimpleJob (4711);

        $fn_store = function (string $into_queue = "multi1", Job $store_job = null) use ($ws, $job) {
            $ws->storeJob($into_queue, ($store_job ?? (clone $job)));
        };
        $fn_clear = function (string $from_queue = "multi1") use ($ws) {
            while (($qe = $ws->getNextQueueEntry($from_queue, $ws::NOBLOCK))) {
                $ws->deleteEntry($qe);
            }
        };
        $fn_check = function (array $poll_queues, int $n_expected = 1, $check_origins = null) use ($ws, $job) {
            if (is_string($check_origins)) {
                $check_origins = [$check_origins];
            }

            $qes = [];
            $n   = 0;
            while ($n < $n_expected) {
                $ret = $ws->getNextQueueEntry($poll_queues, $ws::NOBLOCK);
                $this->assertInstanceOf(QueueEntry::class, $ret,
                    "Could not retrieve job by polling multiple queues! ({$n}/{$n_expected})");

                /** @var Job|SimpleJob $qjob */
                $qjob = $ret->getJob();

                $this->assertSame($job->getMarker(), $qjob->getMarker(),
                    "Retrieved wrong from by polling multiple queues!? ({$n}/{$n_expected})");

                $qes[] = $ret;
                $n++;

                if (isset($check_origins)) {
                    $this->assertContains($ret->getWorkQueue(), $check_origins,
                        "Job's origin reference (qe->getWorkQueue) is not in the list of expected origin queues!");
                    $check_origins = array_delete_one($check_origins, $ret->getWorkQueue());
                }
            }

            $this->assertNull($ws->getNextQueueEntry($poll_queues, $ws::NOBLOCK),
                "Polling multiple empty queues returned something!");
            $this->assertNull($ws->getNextQueueEntry($poll_queues, $ws::NOBLOCK),
                "Polling multiple empty queues A SECOND TIME returned something!?");

            foreach ($qes as $qe) {
                $ws->deleteEntry($qe);
            }
        };

        $fn_store();
        $fn_check(["multiX9", "multiX9"], 0);
        $fn_clear();

        $fn_store();
        $fn_check(["multi1", "multi1"], 1);
        $fn_clear();

        $fn_store();
        $fn_check(["multi1", "multiX3"], 1);
        $fn_clear();

        $fn_store();
        $fn_check(["multiX3", "multi1"], 1);
        $fn_clear();

        $fn_store();
        $fn_check(["multiX3", "multi1"], 1);
        $fn_clear();

        $fn_store();
        $fn_check(["multi1", "multiX4", "multi1", "multiX4"], 1);
        $fn_clear();

        $fn_store();
        $fn_check(["multiX5", "multi5", "multiX3", "multi1"], 1, "multi1");
        $fn_clear();


        // Special cases:
        // What if there's really multiple identical jobs in one or more queues,
        // and we poll them at once?

        $fn_store("mq1");
        $fn_store("mq1");
        $fn_check(["mq1", "mq1"], 2, ["mq1", "mq1"]);
        $fn_clear("mq1");

        $fn_store("mq1");
        $fn_store("mq1");
        $fn_check(["mq1", "mqX9"], 2, ["mq1", "mq1"]);
        $fn_clear("mq1");

        $fn_store("mq1");
        $fn_store("mq2");
        $fn_check(["mqX6", "mq1", "mqX7", "mq2", "mqX8"], 2, ["mq1", "mq2"]);
        $fn_clear("mq1");
        $fn_clear("mq2");
    }

    /**
     * @depends testGetServerInstance
     * @depends testStoredJobs
     * @param WorkServerAdapter $ws
     */
    public function testQueueInterference(WorkServerAdapter $ws)
    {
        $j1a = new SimpleJob (5011);
        $j1b = new SimpleJob (5022);
        $j2  = new SimpleJob (7011);

        $ws->storeJob("qi1", $j1a);
        $ws->storeJob("qi2", $j2);
        $ws->storeJob("qi1", $j1b);

        // Now we should be able get j1a back from qi1.

        $qe1a = $ws->getNextQueueEntry("qi1", $ws::NOBLOCK);
        $this->assertInstanceOf(QueueEntry::class, $qe1a);

        /** @var Job|SimpleJob $qj1a */
        $qj1a = $qe1a->getJob();
        $this->assertSame($j1a->getMarker(), $qj1a->getMarker());

        // Now we know that j1b is still in qi1,
        //         and that j2  is in qi2.

        $qe2  = $ws->getNextQueueEntry("qi2", $ws::NOBLOCK);
        $qe1b = $ws->getNextQueueEntry("qi1", $ws::NOBLOCK);
        $this->assertInstanceOf(QueueEntry::class, $qe2,
            "Queue interference: unable to get unrelated job from qi2!");
        $this->assertInstanceOf(QueueEntry::class, $qe1b,
            "Queue interference: unable to get second queued job from qi1 after polling qi2 first!");

        /** @var Job|SimpleJob $qj1b */
        $qj1b = $qe1b->getJob();
        /** @var Job|SimpleJob $qj2 */
        $qj2 = $qe2->getJob();

        $this->assertNotEquals($j2->getMarker(), $qj1b->getMarker(),
            "Queue interference: after polling qi1 once, we got the next qi1 job from polling qi2!");
        $this->assertSame($j1b->getMarker(), $qj1b->getMarker(),
            "Queue interference: after polling both qi1 and qi2 once, we got something UNEXPECTED from polling qi1 AGAIN!");
        $this->assertSame($j2->getMarker(), $qj2->getMarker(),
            "Queue interference: after polling both qi1 and qi2, we got something UNEXPECTED from polling qi2!");

        $ws->deleteEntry($qe1a);
        $ws->deleteEntry($qe1b);
        $ws->deleteEntry($qe2);
    }

    /**
     * LAST TEST METHOD IN THIS BASE CLASS!
     *
     * @depends testGetServerInstance
     * @depends testRequeueJob
     * @depends testPollMultipleQueues
     * @depends testExecuteAndDeleteJobs
     * @see     additionalTests
     * @param WorkServerAdapter $ws
     */
    final public function testSpecificImplementation(WorkServerAdapter $ws)
    {
        $this->additionalTests($ws);
    }

    /**
     * PHPUnit does not deal well with test method overrides.
     * Also, class methods usually have priority over inherited methods,
     * so custom test methods would always run _before_ these inherited standard test methods,
     * making proper test dependencies hard to maintain.
     *
     * Instead, put all your implementation-specific additional tests
     * into this method –
     * it will be called last.
     *
     * This default implementation is empty.
     *
     * @param WorkServerAdapter $ws
     * @see testSpecificImplementation  last-called test method, calls this method in turn
     * @return void
     */
    public function additionalTests(WorkServerAdapter $ws): void
    {
    }

}

