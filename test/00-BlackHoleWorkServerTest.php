<?php

namespace mle86\WQ\Tests;

use mle86\WQ\Job\Job;
use mle86\WQ\Testing\SimpleTestJob;
use mle86\WQ\WorkServerAdapter\BlackHoleWorkServer;
use mle86\WQ\WorkServerAdapter\WorkServerAdapter;
use PHPUnit\Framework\TestCase;

/**
 * This one does not return anything ever,
 * so our standard {@see AbstractWorkServerAdapterTest} is not a good fit.
 */
class BlackHoleWorkServerTest extends TestCase
{

    private const QUEUE = "A";

    public function getWorkServerAdapter(): WorkServerAdapter
    {
        return new BlackHoleWorkServer();
    }

    public function testInstance(): WorkServerAdapter
    {
        return $this->getWorkServerAdapter();
    }

    /**
     * @depends testInstance
     * @param WorkServerAdapter $ws
     */
    public function testEmpty(WorkServerAdapter $ws): void
    {
        $this->assertNull($ws->getNextQueueEntry(self::QUEUE, $ws::NOBLOCK));
        $this->assertNull($ws->getNextQueueEntry(self::QUEUE, 1));
    }

    /**
     * @depends testInstance
     * @param WorkServerAdapter $ws
     */
    public function testStoreAndForget(WorkServerAdapter $ws): void
    {
        $createJob = function(): Job {
            static $index = 1;
            $marker = 800 + $index++;
            return new SimpleTestJob($marker);
        };

        $ws->storeJob(self::QUEUE, $createJob());
        $ws->storeJob(self::QUEUE, $createJob(), 1);
        $ws->storeJob(self::QUEUE, $createJob(), 9999);
    }

    /**
     * @depends testInstance
     * @depends testStoreAndForget
     * @param WorkServerAdapter $ws
     */
    public function testStillEmptyAfterStoringJobs(WorkServerAdapter $ws): void
    {
        $this->testEmpty($ws);
    }

}
