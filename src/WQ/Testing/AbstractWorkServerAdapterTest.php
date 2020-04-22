<?php

namespace mle86\WQ\Testing;

use mle86\WQ\Job\Job;
use mle86\WQ\Job\QueueEntry;
use mle86\WQ\WorkServerAdapter\WorkServerAdapter;
use PHPUnit\Framework\TestCase;

/**
 * This base class contains a series of tests
 * for the abstract WorkServerAdapter interface.
 *
 * It is intended to serve as a template
 * for implementation tests.
 * Implementation need to require this package anyway,
 * so they should be able to use this file.
 */
abstract class AbstractWorkServerAdapterTest extends TestCase
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


    public static function setUpBeforeClass()
    {
        SimpleTestJob::$log = [];
    }

    /**
     * This method ensures that we're running in some kind of Docker container.
     * You may call it in your {@see checkEnvironment()} implementation.
     */
    final protected function checkInDocker(): void
    {
        $this->assertFileExists("/.dockerenv",
            "This test script needs to be run inside a Docker container!");
    }

    /**
     * This method ensures that we're running in some kind of testing environment.
     * You may call it in your {@see checkEnvironment()} implementation.
     */
    final protected function checkInDockerOrTravis(): void
    {
        if (getenv('TRAVIS') === 'true') {
            return;  // ok
        }

        $this->assertFileExists("/.dockerenv",
            "This test script needs to be run inside a Docker container or Travis CI environment!");
    }

    final protected function checkWQEmpty(WorkServerAdapter $ws, $queues, string $message = ''): void
    {
        if ($message !== '') {
            $message = " ({$message})";
        }

        foreach ((array)$queues as $queue) {
            $this->assertNull($ws->getNextQueueEntry($queue, $ws::NOBLOCK),
                "Work queue '{$queue}' should have been empty!" . $message);
        }
    }

    protected static $wsClassName = null;

    final protected function getWSClass(): ?string
    {
        // set by testGetServerInstance()
        return static::$wsClassName;
    }

    final private function jobQueueData(): array
    {
        $queues = [];
        foreach ($this->jobData() as $jd) {
            $queueName = $jd[0];
            $jobMarker = $jd[1];
            $queues[ $queueName ][] = $jobMarker;
        }
        return $queues;
    }

    public function jobData(): array
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
    final public function testEnvironment(): void
    {
        $this->checkEnvironment();
    }

    /**
     * This is the second test to run.
     * It ensures that we have a working instance of the target implementation.
     *
     * After the tests in this class have been run,
     * that instance will be disconnected
     * so you should NOT use it in your custom test methods.
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
        static::$wsClassName = $name;

        return $ws;
    }

    /**
     * @dataProvider jobData
     * @depends      testGetServerInstance
     * @param string $queueName
     * @param int $jobMarker
     * @param WorkServerAdapter $ws
     */
    public function testQueuesEmpty(string $queueName, int $jobMarker, WorkServerAdapter $ws): void
    {
        $this->checkWQEmpty($ws, $queueName);

        unset($jobMarker);  // suppress "unused" warning
    }

    /**
     * @depends testGetServerInstance
     * @depends testQueuesEmpty
     * @param WorkServerAdapter $ws
     */
    public function testQueueEmptyWithTimeout(WorkServerAdapter $ws): void
    {
        $queueName = $this->jobData()[0][0];

        $this->assertNull($ws->getNextQueueEntry($queueName, 1));
    }

    /**
     * @dataProvider jobData
     * @depends      testGetServerInstance
     * @depends      testQueuesEmpty
     * @param string $queueName
     * @param int $jobMarker
     * @param WorkServerAdapter $ws
     */
    public function testQueueJobs(string $queueName, int $jobMarker, WorkServerAdapter $ws): void
    {
        $j = new SimpleTestJob($jobMarker);

        $this->assertSame($jobMarker, $j->getMarker(),
            "job constructor broken, wrong marker saved");

        $ws->storeJob($queueName, $j);
    }

    /**
     * @depends testGetServerInstance
     * @depends testQueueJobs
     * @param WorkServerAdapter $ws
     * @return array [ queueName => [ QueueEntry, ... ], ... ]
     */
    public function testGetQueuedJobs(WorkServerAdapter $ws): array
    {
        $knownQueues = $this->jobQueueData();

        $n_jobs = count($this->jobData());
        $n      = 0;

        $queues = [];

        foreach (array_keys($knownQueues) as $knownQueueName) {
            while (($qe = $ws->getNextQueueEntry($knownQueueName, $ws::NOBLOCK)) !== null) {
                $queueName = $qe->getWorkQueue();
                $this->assertEquals($knownQueueName, $queueName,
                    "{$this->getWSClass()}::getNextQueueEntry() returned a Job from a different work queue than requested!");
                $queues[ $queueName ][] = $qe;
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
    public function testStoredQueueNames(array $queues): void
    {
        $knownQueueNames  = array_keys($this->jobQueueData());
        $storedQueueNames = array_keys($queues);
        sort($knownQueueNames);
        sort($storedQueueNames);

        $this->assertSame($knownQueueNames, $storedQueueNames,
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
    public function testStoredJobs(array $queues): void
    {
        $known = $this->jobQueueData();

        foreach ($queues as $queueName => $qelist) {
            $this->assertContainsOnlyInstancesOf(QueueEntry::class, $qelist,
                "{$this->getWSClass()}::getNextQueueEntry() returned an unexpected object!");

            $this->assertEquals(count($known[ $queueName ]), count($qelist),
                "{$this->getWSClass()}::getNextQueueEntry('{$queueName}') returned the wrong number of jobs!");

            $knownMarkers  = $known[ $queueName ];
            $storedMarkers = array_map(
                function(QueueEntry $qe) {
                    /** @var Job|SimpleTestJob $job */
                    $job = $qe->getJob();
                    return $job->getMarker();
                },
                $qelist);

            sort($knownMarkers);
            sort($storedMarkers);

            $this->assertSame($knownMarkers, $storedMarkers,
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
    public function testExecuteAndDeleteJobs(array $queues, WorkServerAdapter $ws): void
    {
        $markers = [];

        /** @var QueueEntry[] $qelist */
        foreach ($queues as $qelist) {
            foreach ($qelist as $qe) {
                /** @var Job|SimpleTestJob $job */
                $job    = $qe->getJob();
                $marker = $job->getMarker();

                $markers[ $marker ] = 1 + ($markers[ $marker ] ?? 0);

                $job->execute();
                $ws->deleteEntry($qe);
            }
        }

        // Okay, we should now have one log entry for every known marker id:
        foreach ($markers as $marker => $n_entries) {
            $fnMatchingMarker = function($log) use($marker): bool {
                return ($log === "EXECUTE-{$marker}");
            };
            $n_executions = count(array_filter(SimpleTestJob::$log, $fnMatchingMarker));

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
    public function testDelayedJob(WorkServerAdapter $ws): void
    {
        $j     = new SimpleTestJob(555);
        $queue = "test";
        $delay = 1;

        $this->assertNull($ws->getNextQueueEntry($queue, $ws::NOBLOCK),
            "There already is something in the test queue!");

        // We want at least 0.5s remaining in the current second:
        self::waitForSubsecond();

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

        /** @var Job|SimpleTestJob $job */
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
    public function testRequeueJob(WorkServerAdapter $ws): void
    {
        $j             = new SimpleTestJob(566);
        $queue         = "test2";
        $delay         = 0;
        $requeuedDelay = 1;

        self::waitForSubsecond();

        $ws->storeJob($queue, $j, $delay);

        // take it out again:
        /** @var Job|SimpleTestJob $job */
        $qe  = $ws->getNextQueueEntry($queue, $ws::NOBLOCK);
        $job = $qe->getJob();
        $this->assertSame($j->getMarker(), $job->getMarker());
        $this->assertEquals(1, $job->jobTryIndex(),
            "Dequeued job has wrong try index!");
        $this->assertSame($queue, $qe->getWorkQueue(),
            "Dequeued job has incorrect origin reference!");

        // requeue it:
        $ws->requeueEntry($qe, $requeuedDelay);

        $this->assertNull($ws->getNextQueueEntry($queue, $ws::NOBLOCK),
            "Re-queued job with delay was immediately available!");
        usleep(1000 * 100);
        $this->assertNull($ws->getNextQueueEntry($queue, $ws::NOBLOCK),
            "Re-queued job with delay became available too soon!");

        // take it out again:
        /** @var Job|SimpleTestJob $job */
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
    public function testMultipleEmptyQueues(WorkServerAdapter $ws): void
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
    public function testPollMultipleQueues(WorkServerAdapter $ws): void
    {
        $job = new SimpleTestJob(4711);

        $fnStore = static function(string $intoQueue = "multi1", Job $storeJob = null) use($ws, $job): void {
            $ws->storeJob($intoQueue, ($storeJob ?? clone $job));
        };
        $fnClear = static function(string $fromQueue = "multi1") use($ws): void {
            while (($qe = $ws->getNextQueueEntry($fromQueue, $ws::NOBLOCK))) {
                $ws->deleteEntry($qe);
            }
        };
        $fnCheck = function(array $pollQueues, int $n_expected = 1, $checkOrigins = null) use($ws, $job): void {
            if (is_string($checkOrigins)) {
                $checkOrigins = [$checkOrigins];
            }

            $qes = [];
            $n   = 0;
            while ($n < $n_expected) {
                $ret = $ws->getNextQueueEntry($pollQueues, $ws::NOBLOCK);
                $this->assertInstanceOf(QueueEntry::class, $ret,
                    "Could not retrieve job by polling multiple queues! ({$n}/{$n_expected})");

                /** @var Job|SimpleTestJob $qjob */
                $qjob = $ret->getJob();

                $this->assertSame($job->getMarker(), $qjob->getMarker(),
                    "Retrieved wrong from by polling multiple queues!? ({$n}/{$n_expected})");

                $qes[] = $ret;
                $n++;

                if (isset($checkOrigins)) {
                    $this->assertContains($ret->getWorkQueue(), $checkOrigins,
                        "Job's origin reference (qe->getWorkQueue) is not in the list of expected origin queues!");
                    $checkOrigins = self::array_delete_one($checkOrigins, $ret->getWorkQueue());
                }
            }

            $this->assertNull($ws->getNextQueueEntry($pollQueues, $ws::NOBLOCK),
                "Polling multiple empty queues returned something!");
            $this->assertNull($ws->getNextQueueEntry($pollQueues, $ws::NOBLOCK),
                "Polling multiple empty queues A SECOND TIME returned something!?");

            foreach ($qes as $qe) {
                $ws->deleteEntry($qe);
            }
        };

        $fnStore();
        $fnCheck(["multiX9", "multiX9"], 0);
        $fnClear();

        $fnStore();
        $fnCheck(["multi1", "multi1"], 1);
        $fnClear();

        $fnStore();
        $fnCheck(["multi1", "multiX3"], 1);
        $fnClear();

        $fnStore();
        $fnCheck(["multiX3", "multi1"], 1);
        $fnClear();

        $fnStore();
        $fnCheck(["multiX3", "multi1"], 1);
        $fnClear();

        $fnStore();
        $fnCheck(["multi1", "multiX4", "multi1", "multiX4"], 1);
        $fnClear();

        $fnStore();
        $fnCheck(["multiX5", "multi5", "multiX3", "multi1"], 1, "multi1");
        $fnClear();


        // Special cases:
        // What if there's really multiple identical jobs in one or more queues,
        // and we poll them at once?

        $fnStore("mq1");
        $fnStore("mq1");
        $fnCheck(["mq1", "mq1"], 2, ["mq1", "mq1"]);
        $fnClear("mq1");

        $fnStore("mq1");
        $fnStore("mq1");
        $fnCheck(["mq1", "mqX9"], 2, ["mq1", "mq1"]);
        $fnClear("mq1");

        $fnStore("mq1");
        $fnStore("mq2");
        $fnCheck(["mqX6", "mq1", "mqX7", "mq2", "mqX8"], 2, ["mq1", "mq2"]);
        $fnClear("mq1");
        $fnClear("mq2");
    }

    /**
     * Checks that when pulling a job from one or multiple queues,
     * the caller gets the correct source queue reference.
     *
     * @depends testGetServerInstance
     * @depends testPollMultipleQueues
     */
    public function testSourceQueue(WorkServerAdapter $ws): void
    {
        $jobs = [
            // This creates a bunch of jobs with a known markerId => queueName mapping.
            // Each retrieved job's source queue reference is then checked against this map.
            8101 => 'sq1',
            8300 => 'sq3',
            8102 => 'sq1',
            8500 => 'sq5',  // will be delayed by 1s
            8103 => 'sq1',
            8200 => 'sq2',
            8402 => 'sq4',
            8600 => 'sq6',  // will be delayed by 1s
            8401 => 'sq4',
        ];

        $fnExpectJob = function(array $fetchFromQueues, int $fetchTimeout = WorkServerAdapter::NOBLOCK) use($ws, &$jobs) {
            $_queueNames  = implode('|', $fetchFromQueues);
            $_expectedIds = implode('|', array_keys($jobs));

            $qe = $ws->getNextQueueEntry($fetchFromQueues, $fetchTimeout);
            $this->assertNotNull($qe,
                "Expected to find job {$_expectedIds} in {$_queueNames}, but got nothing!");

            $qj = $qe->getJob();
            $ws->deleteEntry($qe);

            // We expect to fetch something! WorkServerAdapter makes no promises about job order,
            // so it might be any of the remaining IDs.
            $this->assertNotNull($qj,
                "Expected to fetch a job from {$_queueNames}, but got nothing!");
            $this->assertArrayHasKey($qj->getMarker(), $jobs,
                "Found a job in {$_queueNames}, but it had marker {$qj->getMarker()} instead of expected {$_expectedIds}!");
            $this->assertSame(
                $jobs[$qj->getMarker()],
                $qe->getWorkQueue(),
                "Found job {$qj->getMarker()} in {$_queueNames}, but QueueEntry::getWorkQueue names an incorrect source queue!");
            unset($jobs[$qj->getMarker()]);
        };

        foreach ($jobs as $marker => $toQueue) {
            $delay = ($marker <= 8499) ? 0 : 1;
            $ws->storeJob($toQueue, new SimpleTestJob($marker), $delay);
        }

 
        $fnExpectJob(['sq1']);
        $fnExpectJob(['sq2', 'sq6', 'sq5', 'sq3']);
        $fnExpectJob(['sq1', 'sq3', 'sq2']);
        $fnExpectJob(['sq2', 'sq6', 'sq3', 'sq1', 'sq0'], 1);
        $fnExpectJob(['sq2', 'sq6', 'sq3', 'sq1', 'sq0'], 1);

        // After 5 calls, only the two jobs in sq4 (never queried before) should remain
        $fnExpectJob(['sq4']);
        $fnExpectJob(['sq6', 'sq4', 'sq1', 'sq5']);

        // Now all queues should be empty:
        $this->assertNull($ws->getNextQueueEntry(['sq6', 'sq4', 'sq1', 'sq0', 'sq5'], $ws::NOBLOCK));

        usleep(1000 * 1000 * 0.3);
        // By now, the two delayed jobs should be available!
        $fnExpectJob(['sq6', 'sq5', 'sq1', 'sq0'], 1);
        $fnExpectJob(['sq5', 'sq6'], 1);

        // Now all queues should be empty again:
        $this->assertNull($ws->getNextQueueEntry(['sq6', 'sq4', 'sq1', 'sq0', 'sq5'], $ws::NOBLOCK));
    }

    /**
     * @depends testGetServerInstance
     * @depends testStoredJobs
     */
    public function testQueueInterference(WorkServerAdapter $ws): void
    {
        $j1a = new SimpleTestJob(5011);
        $j1b = new SimpleTestJob(5022);
        $j2  = new SimpleTestJob(7011);

        $ws->storeJob("qi1", $j1a);
        $ws->storeJob("qi2", $j2);
        $ws->storeJob("qi1", $j1b);

        // Now we should be able get j1a back from qi1.

        $qe1a = $ws->getNextQueueEntry("qi1", $ws::NOBLOCK);
        $this->assertInstanceOf(QueueEntry::class, $qe1a);

        /** @var Job|SimpleTestJob $qj1a */
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

        /** @var Job|SimpleTestJob $qj1b */
        $qj1b = $qe1b->getJob();
        /** @var Job|SimpleTestJob $qj2 */
        $qj2 = $qe2->getJob();

        $this->assertNotEquals($j2->getMarker(), $qj1b->getMarker(),
            "Queue interference: after polling qi1 once, we got the next qi1 job from polling qi2!");
        $this->assertSame($j1b->getMarker(), $qj1b->getMarker(),
            "Queue interference: after polling both qi1 and qi2 once, we got something UNEXPECTED from polling qi1 AGAIN!");
        $this->assertSame($j2->getMarker(), $qj2->getMarker(),
            "Queue interference: after polling both qi1 and qi2, we got something UNEXPECTED from polling qi2!");

        $ws->deleteEntry($qe1a);
        $ws->deleteEntry($qe2);
        $ws->deleteEntry($qe1b);
    }

    /**
     * Repeat all tests but with separate connections.
     *
     * All tests so far have used the same {@see WorkServerAdapter} instance over and over.
     * Now we'll repeat all test methods at least once
     * but give them all separate {@see WorkServerAdapter} instances where possible.
     *
     * @depends testGetServerInstance
     * @depends testRequeueJob
     * @depends testPollMultipleQueues
     * @depends testExecuteAndDeleteJobs
     * @depends testSourceQueue
     */
    public function testIsolation(WorkServerAdapter $originalWs): void
    {
        // Make sure SimpleJob::$log is cleaned up:
        self::setUpBeforeClass();

        /* PHPUnit still has a copy of the very first WorkServerAdapter,
         * so the actual TCP connection is still open as well.
         * This means that some of our tests with new connections might fail --
         * the work server might decide to send incoming jobs only to some of the active clients.
         * To avoid this problem, we'll manually close that original connection:  */
        $originalWs->disconnect();
        unset($originalWs);

        /**
         * This helper function runs a callback
         * and supplies it with a newly-created WorkServerAdapter connection,
         * taking care to manually disconnect() that instance afterwards.
         *
         * @param callable $callback  The callback to execute. Expected signature:  function({@see WorkServerAdapter}).
         */
        $withSeparateConnection = function(callable $callback): void {
            $ws = $this->getWorkServerAdapter();
            $callback($ws);
            $ws->disconnect();
        };


        foreach ($this->jobData() as $jobData) {
            $withSeparateConnection(function(WorkServerAdapter $ws) use($jobData) {
                $this->testQueuesEmpty($jobData[0], $jobData[1], $ws);
            });
        }

        $withSeparateConnection(function(WorkServerAdapter $ws) {
            foreach ($this->jobData() as $jobData) {
                $this->testQueueJobs($jobData[0], $jobData[1], $ws);
            }

            $queues = $this->testGetQueuedJobs($ws);
            $this->testStoredQueueNames($queues);
            $this->testStoredJobs($queues);
            $this->testExecuteAndDeleteJobs($queues, $ws);
        });

        $withSeparateConnection(function(WorkServerAdapter $ws) {
            $this->testDelayedJob($ws);
        });

        $withSeparateConnection(function(WorkServerAdapter $ws) {
            $this->testRequeueJob($ws);
        });

        $withSeparateConnection(function(WorkServerAdapter $ws) {
            $this->testMultipleEmptyQueues($ws);
        });

        $withSeparateConnection(function(WorkServerAdapter $ws) {
            $this->testPollMultipleQueues($ws);
        });

        $withSeparateConnection(function(WorkServerAdapter $ws) {
            $this->testQueueInterference($ws);
        });

        $withSeparateConnection(function(WorkServerAdapter $ws) {
            $this->testSourceQueue($ws);
        });
    }

    /**
     * LAST TEST METHOD IN THIS BASE CLASS!
     *
     * @depends testRequeueJob
     * @depends testPollMultipleQueues
     * @depends testExecuteAndDeleteJobs
     * @depends testSourceQueue
     * @depends testIsolation
     * @see     additionalTests
     */
    final public function testSpecificImplementation(): void
    {
        $this->additionalTests($this->getWorkServerAdapter());
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
     * @param WorkServerAdapter $ws  A newly-created connection instance
     * @see testSpecificImplementation  last-called test method, calls this method in turn
     * @return void
     */
    public function additionalTests(WorkServerAdapter $ws): void
    {
    }


    private static function waitForSubsecond(float $requiredRemainingSubsecond = 0.3): void
    {
        $mt        = microtime(true);
        $subsecond = $mt - (int)$mt;
        if ($subsecond > (1 - $requiredRemainingSubsecond)) {
            usleep(1000 * 1000 * (1.01 - $subsecond));
        }
    }

    private static function array_delete_one(array $input, $valueToDelete, bool $strict = true): array
    {
        foreach ($input as $idx => $value) {
            if ($value === $valueToDelete || (!$strict && $value == $valueToDelete)) {
                unset($input[$idx]);
                break;  // only delete one
            }
        }
        return $input;
    }

}
