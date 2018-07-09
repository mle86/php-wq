<?php
namespace mle86\WQ\WorkServerAdapter;

use mle86\WQ\Job\Job;
use mle86\WQ\Job\QueueEntry;

/**
 * This Work Server Adapter
 * does not connect to anything.
 *
 * The {@see getNextQueueEntry} method always returns null.
 * The {@see storeJob}, {@see buryEntry}, {@see requeueEntry}, {@see deleteEntry} methods do nothing at all.
 */
class BlackHoleWorkServer
    implements WorkServerAdapter
{

    public function getNextQueueEntry($workQueue, int $timeout = self::DEFAULT_TIMEOUT): ?QueueEntry
    {
        if ($timeout > 0) {
            sleep($timeout);
        }
        return null;
    }

    public function storeJob(string $workQueue, Job $job, int $delay = 0): void
    {
    }

    public function buryEntry(QueueEntry $entry): void
    {
    }

    public function requeueEntry(QueueEntry $entry, int $delay, string $workQueue = null): void
    {
    }

    public function deleteEntry(QueueEntry $entry): void
    {
    }

    public function disconnect(): void
    {
    }

}
