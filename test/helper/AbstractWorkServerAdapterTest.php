<?php
namespace mle86\WQ\Tests;

use mle86\WQ\Job\Job;
use mle86\WQ\Job\QueueEntry;
use mle86\WQ\WorkServerAdapter\WorkServerAdapter;

require_once 'misc.php';
require_once 'SimpleJob.php';

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
	abstract public function getWorkServerAdapter () : WorkServerAdapter;

	/**
	 * If your test class needs to verify something in the environment
	 * before any other test methods are run,
	 * override this empty method and put those checks here.
	 *
	 * @return void
	 */
	public function checkEnvironment () {
	}


	// Helper methods and data providers:  ////////////////////////////////////////


	/**
	 * This method ensures that we're running in some kind of Docker container.
	 * You may call it in your {@see checkEnvironment()} implementation.
	 */
	final protected function checkInDocker () {
		$this->assertFileExists("/.dockerenv",
			"This test script needs to be run inside a Docker container!");
	}

	final protected function checkWQEmpty (WorkServerAdapter $ws, $queues) {
		foreach ((array)$queues as $queue) {
			$this->assertNull($ws->getNextQueueEntry($queue, $ws::NOBLOCK),
				"Work queue '{$queue}' should have been empty!");
		}
	}

	protected static $ws_classname = null;
	final protected function getWSClass () {
		// set by testGetServerInstance()
		return static::$ws_classname;
	}

	final private function jobQueueData () {
		$queues = [ ];
		foreach ($this->jobData() as $jd) {
			$queue_name = $jd[0];
			$job_marker = $jd[1];
			$queues[ $queue_name ][] = $job_marker;
		}
		return $queues;
	}

	public function jobData () { return [
		// [ queue_name, marker_id ]
		[ "twos",  204 ],
		[ "nines", 900 ],
		[ "nines", 960 ],
		[ "ones",  144 ],
		[ "twos",  203 ],
		[ "nines", 930 ],
		[ "nines", 930 ],
	]; }


	// This is where the actual test method sequence begins:  /////////////////////


	/**
	 * This is the very first test to run.
	 */
	final public function testEnvironment () {
		$this->checkEnvironment();
	}

	/**
	 * This is the second test to run.
	 * It ensures that we have a working instance of the target implementation.
	 *
	 * @depends testEnvironment
	 * @return WorkServerAdapter
	 */
	final public function testGetServerInstance () : WorkServerAdapter {
		$ws = $this->getWorkServerAdapter();

		// Stores class name for better error messages.
		// Use getWSClass() to retrieve it
		$name = get_class($ws);
		$p = strrpos($name, '\\');
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
	public function testQueuesEmpty (string $queue_name, int $job_marker, WorkServerAdapter $ws) {
		$this->checkWQEmpty($ws, $queue_name);
	}

	/**
	 * @dataProvider jobData
	 * @depends      testGetServerInstance
	 * @depends      testQueuesEmpty
	 * @param string $queue_name
	 * @param int $job_marker
	 * @param WorkServerAdapter $ws
	 */
	public function testQueueJobs (string $queue_name, int $job_marker, WorkServerAdapter $ws) {
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
	public function testGetQueuedJobs (WorkServerAdapter $ws) {
		$knownQueues = $this->jobQueueData();

		$n_jobs = count($this->jobData());
		$n      = 0;

		$queues = [ ];

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
	public function testStoredQueueNames (array $queues) {
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
	public function testStoredJobs (array $queues) {
		$known = $this->jobQueueData();

		foreach ($queues as $queue_name => $qelist) {
			$this->assertContainsOnlyInstancesOf(QueueEntry::class, $qelist,
				"{$this->getWSClass()}::getNextQueueEntry() returned an unexpected object!");

			$this->assertEquals(count($known[$queue_name]), count($qelist),
				"{$this->getWSClass()}::getNextQueueEntry('{$queue_name}') returned the wrong number of jobs!");

			$known_markers  = $known[$queue_name];
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
	 * @depends testStoredJobs
	 * @param array $queues
	 */
	public function testStoredJobsUniqueHandle (array $queues) {
		$handles = [ ];

		/** @var QueueEntry[] $qelist */
		foreach ($queues as $qelist) {
			foreach ($qelist as $qe) {
				$this->assertNotContains($qe->getHandle(), $handles,
					"The stored QueueEntries' handles are not unique!");
				$handles[] = $qe->getHandle();
			}
		}
	}

	/**
	 * @depends testGetQueuedJobs
	 * @depends testGetServerInstance
	 * @depends testStoredJobs
	 * @param array $queues
	 * @param WorkServerAdapter $ws
	 */
	public function testExecuteAndDeleteJobs (array $queues, WorkServerAdapter $ws) {
		$markers = [ ];

		/** @var QueueEntry[] $qelist */
		foreach ($queues as $qelist) {
			foreach ($qelist as $qe) {
				/** @var Job|SimpleJob $job */
				$job = $qe->getJob();
				$marker = $job->getMarker();

				$markers[ $marker ] = 1 + ($markers[$marker] ?? 0);

				$job->execute();
				$ws->deleteEntry($qe);
			}
		}

		// Okay, we should now have one log entry for every known marker id:
		foreach ($markers as $marker => $n_entries) {
			$fn_matching_marker = function ($log) use($marker) { return ($log === "EXECUTE-{$marker}"); };
			$n_executions = count(array_filter(SimpleJob::$log, $fn_matching_marker));

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
	public function testDelayedJob (WorkServerAdapter $ws) {
		$j = new SimpleJob (555);
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

		/** @var Job|SimpleJob $job */
		$job = $qe->getJob();
		$this->assertSame($j->getMarker(), $job->getMarker(),
			"Delayed job did not match the original job object!");
	}

	/**
	 * @depends testGetServerInstance
	 * @depends testStoredJobs
	 * @depends testExecuteAndDeleteJobs
	 *   this way the WorkServerAdapter is empty again
	 * @param WorkServerAdapter $ws
	 */
	public function testRequeueJob (WorkServerAdapter $ws) {
		$j = new SimpleJob (566);
		$queue          = "test2";
		$delay          = 0;
		$requeued_delay = 1;

		wait_for_subsecond();

		$ws->storeJob($queue, $j, $delay);

		// take it out again:
		/** @var Job|SimpleJob $job */
		$qe = $ws->getNextQueueEntry($queue, $ws::NOBLOCK);
		$job = $qe->getJob();
		$this->assertSame($j->getMarker(), $job->getMarker());
		$this->assertEquals(1, $job->jobTryIndex(),
			"Dequeued job has wrong try index!");

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
		$job = $qe->getJob();
		$this->assertSame($j->getMarker(), $job->getMarker());
		$this->assertEquals(2, $job->jobTryIndex(),
			"Dequeued job (was re-queued once) has wrong try index!");
	}

}

