<?php

namespace mle86\WQ\WorkServerAdapter;

use mle86\WQ\Job\Job;
use mle86\WQ\Job\QueueEntry;

/**
 * This Work Server Adapter
 * uses an array for "persistence".
 *
 * @internal  This is here for testing purposes.
 */
class MemoryWorkServer implements WorkServerAdapter
{

    private const RESERVE_SECONDS = 60;

    /**
     * @var array  <pre>[ workQueueName => [
     *       idx  => [ activeTimestamp,  jobData  ],
     *       idx2 => [ activeTimestamp2, job2Data ],
     *       ...
     *     ],
     *     ...
     *   ] </pre>
     */
    protected $storage = [];

    private static $index = 0;


    public function getNextQueueEntry($workQueues, int $timeout = self::DEFAULT_TIMEOUT): ?QueueEntry
    {
        $areAllEmpty = true;
        foreach ((array)$workQueues as $workQueue) {
            if (!empty($this->storage[ $workQueue ])) {
                $areAllEmpty = false;
                break;
            }
        }
        if ($areAllEmpty) {
            if ($timeout > 0) {
                sleep($timeout);
            }
            return null;
        }

        foreach ((array)$workQueues as $workQueue) {
            foreach (($this->storage[ $workQueue ] ?? []) as $idx => $jobInfo) {
                $activeTimestamp = $jobInfo[0];
                $jobData         = $jobInfo[1];

                if ($activeTimestamp <= time()) {
                    // reserve and return:
                    $activeTimestamp += self::RESERVE_SECONDS;
                    $this->storage[ $workQueue ][ $idx ][0] = $activeTimestamp;

                    return QueueEntry::fromSerializedJob($jobData, $workQueue, $idx, $idx);
                } else {
                    // this job is delayed, skip
                }
            }
        }

        if ($timeout > 0) {
            sleep(1);
            return $this->getNextQueueEntry($workQueues, $timeout - 1);
        }

        return null;
    }

    public function storeJob(string $workQueue, Job $job, int $delay = 0): void
    {
        $index = self::nextIndex();
        $this->storage[ $workQueue ][ $index ] = [time() + $delay, serialize($job)];
    }

    public function buryEntry(QueueEntry $entry): void
    {
        // TODO?
        $this->deleteEntry($entry);
    }

    public function requeueEntry(QueueEntry $entry, int $delay, string $workQueue = null): void
    {
        $index = self::nextIndex();
        $this->storage[ $workQueue ?? $entry->getWorkQueue() ][ $index ] = [
            time() + $delay,
            serialize($entry->getJob()),
        ];
        $this->deleteEntry($entry);
    }

    public function deleteEntry(QueueEntry $entry): void
    {
        unset($this->storage[ $entry->getWorkQueue() ][ $entry->getHandle() ]);
    }

    private static function nextIndex(): string
    {
        return "M" . self::$index++;
    }

    public function disconnect(): void
    {
        $this->storage = [];
    }

}
