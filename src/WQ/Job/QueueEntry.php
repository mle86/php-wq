<?php
namespace mle86\WQ\Job;

use mle86\WQ\Exception\UnserializationException;


/**
 * This class wraps a {@see Job} instance
 * recently fetched and unserialized from some Work Queue.
 *
 * The instance also records the name of the job's original work queue
 * and the Work Server's opaque handle for that job.
 * (That is why this class exists in the first place:
 *  we don't want to force {@see Job} implementors
 *  to have to record that stuff.)
 * In case of the Beanstalkd adapter,
 * that handle is the original {@see \Pheanstalk\Job} instance.
 *
 * Returned by {@see WorkServerAdapter::getNextQueueEntry()}
 * and used by {@see WorkProcessor::processNextJob()}.
 */
final class QueueEntry
{

    private $job;
    private $handle;
    private $workQueue;

    public function __construct (Job $job, string $workQueue, $handle) {
        $this->job       = $job;
        $this->handle    = $handle;
        $this->workQueue = $workQueue;
    }

    public function getJob () : Job {
        return $this->job;
    }

    /**
     * @internal This is an opaque handle
     *           which belongs to the originating {@see WorkServerAdapter}.
     *           Don't use this value unless you know exactly what you're doing.
     */
    public function getHandle () {
        return $this->handle;
    }

    public function getWorkQueue () : string {
        return $this->workQueue;
    }


    /**
     * Unserializes a stored {@see Job} instance
     * from a Work Queue entry's raw data
     * and wraps it in a {@see QueueEntry} instance.
     *
     * @param string $serializedData The serialized raw data.
     * @param string $originWorkQueue
     * @param mixed $handle          The Work Server adapter's representation of this job.
     * @param string $jobId          A unique ID for this job. Only used for logging. Not every WorkServerAdapter implementation provides this!
     * @return QueueEntry
     * @throws UnserializationException  if $serializedData corresponded to a non-object or to a non-{@see Job} object
     */
    public static function fromSerializedJob (string $serializedData, string $originWorkQueue, $handle, string $jobId) : self {
        /** @var Job $job */
        $job = unserialize($serializedData);

        if ($job === false) {
            throw new UnserializationException ("job {$jobId} (wq {$originWorkQueue}) contained an invalid serialization!");
        }
        if (!is_object($job)) {
            throw new UnserializationException ("job {$jobId} (wq {$originWorkQueue}) contained a non-object serialization!");
        }
        if (!($job instanceof Job)) {
            throw new UnserializationException ("job {$jobId} (wq {$originWorkQueue}) contained a non-Job object serialization!");
        }

        return new self ($job, $originWorkQueue, $handle);
    }

}

