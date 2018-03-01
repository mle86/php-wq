<?php
namespace mle86\WQ\WorkServerAdapter;

use mle86\WQ\Job\Job;
use mle86\WQ\Job\QueueEntry;


/**
 * This adapter serves to manipulate the work queue names
 * used by another {@see WorkServerAdapter}.
 *
 * It might be useful if your application prefixes all of its work queues with the app name
 * or appends the environment name to all work queue names.
 *
 * In such a case, you can use the {@see setPrefix}/{@see setSuffix} methods
 * to specify the site-wide prefix/suffix.
 * All other methods of this class are just proxy methods to the "real" instance's methods
 * which prepend/append something to the work queue name(s).
 *
 * Example:
 * Instead of
 *   `$workServer->getNextQueueEntry("myapp-email-PROD")`,
 * you could wrap the existing WorkServerAdapter instance in an AffixAdapter...
 *   `$workServer = new AffixAdapter($workServer)->withPrefix("myapp-")->withSuffix("-" . ENVIRONMENT);`,
 * then use it with a simpler call:
 *   `$workServer->getNextQueueEntry("email")`.
 */
class AffixAdapter
    implements WorkServerAdapter
{

    private $server;
    private $prefix;
    private $suffix;

    /**
     * @param WorkServerAdapter $server  The actual WorkServerAdapter to wrap.
     */
    public function __construct(WorkServerAdapter $server)
    {
        $this->server = $server;
    }

    /**
     * Sets the prefix to prepend to all work queue names.
     *
     * (The empty string has the same effect as `null`.)
     *
     * @param string|null $prefix
     * @return self
     */
    public function withPrefix(?string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * Sets the suffix to append to all work queue names.
     *
     * (The empty string has the same effect as `null`.)
     *
     * @param string|null $suffix
     * @return self
     */
    public function withSuffix(?string $suffix): self
    {
        $this->suffix = $suffix;
        return $this;
    }


    private function fixQueueName(?string $wq): ?string
    {
        if ($wq === null) {
            return null;
        }
        return $this->prefix . $wq . $this->suffix;
    }


    public function getNextQueueEntry($workQueue, int $timeout = self::DEFAULT_TIMEOUT): ?QueueEntry
    {
        $workQueue = (array)$workQueue;
        foreach ($workQueue as &$wq) {
            $wq = $this->fixQueueName($wq);
        }
        unset($wq);

        return $this->server->getNextQueueEntry($workQueue, $timeout);
    }

    public function storeJob(string $workQueue, Job $job, int $delay = 0): void
    {
        $this->server->storeJob($this->fixQueueName($workQueue), $job, $delay);
    }

    public function buryEntry(QueueEntry $entry): void
    {
        $this->server->buryEntry($entry);
    }

    public function requeueEntry(QueueEntry $entry, int $delay, string $workQueue = null): void
    {
        $this->server->requeueEntry($entry, $delay, $this->fixQueueName($workQueue));
    }

    public function deleteEntry(QueueEntry $entry): void
    {
        $this->server->deleteEntry($entry);
    }

}
