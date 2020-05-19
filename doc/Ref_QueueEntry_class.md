# `QueueEntry` Wrapper Class

This class wraps a [Job] instance
recently fetched and unserialized from some Work Queue.

The instance also records the name of the job's original work queue
and the Work Server's opaque handle for that job.
(That is why this class exists in the first place:
we don't want to force [Job] implementors
to have to record that stuff.)

In case of the Beanstalkd adapter,
that handle is the original [`\Pheanstalk\Job`](https://github.com/pda/pheanstalk/blob/master/src/Job.php) instance.

Instances of this class are
returned by <code>[WorkServerAdapter]::getNextQueueEntry()</code>
and used by <code>[WorkProcessor]::processNextJob()</code>.
Unless you process your jobs manually using `getNextQueueEntry()`,
you won't need to use this class in any way.


## Constructors

* <code>public function <b>__construct</b> (Job $job, string $workQueue, $handle)</code>
* <code>public static function <b>fromSerializedJob</b> (string $serializedData, string $originWorkQueue, $handle, string $jobId): self</code>  
    Unserializes a stored [Job] instance
    from a Work Queue entry's raw data
    and wraps it in a `QueueEntry` instance.
    Throws an [UnserializationException] if `$serializedData` corresponded to a non-object or to a non-[Job] object.
    * `$serializedData`: The serialized raw data.
    * `$handle`: The Work Server adapter's representation of this job.
    * `$jobId`: A unique ID for this job. Only used for logging. Not every [WorkServerAdapter] implementation provides this!


## Methods

* <code>public function <b>getJob</b> (): Job</code>
* <code>public function <b>getWorkQueue</b> (): string</code>
* <code>public function <b>getHandle</b> (): mixed</code>  
    _Internal:_
    This is an opaque handle
    which belongs to the originating [WorkServerAdapter].
    Don't use this value unless you know exactly what you're doing.


[Job]: Ref_Job_interface.md
[WorkServerAdapter]: Ref_WorkServerAdapter_interface.md
[WorkProcessor]: Ref_WorkProcessor_class.md
[UnserializationException]: Ref_Exceptions.php
