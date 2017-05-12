# `WorkServerAdapter` interface

Declaration: <code>interface mle86\WQ\WorkServerAdapter\\<b>WorkServerAdapter</b></code>  
Source file: [src/WQ/WorkServerAdapter/WorkServerAdapter.php](/src/WQ/WorkServerAdapter/WorkServerAdapter.php)

A Work Server stores jobs inside one or more Work Queues.

A `WorkServerAdapter` implementation
uses a connection handle to an existing Work Server:
for example, the `RedisWorkServer` implementation
takes a `\Redis` instance from the [phpredis extension](https://github.com/phpredis/phpredis).

A Beanstalkd server or a Redis server might be such a Work Server.
In case of Beanstalkd, Work Queues are Tubes;
in case of Redis, Work Queues are Lists.


## Methods:

<a name="getNextQueueEntry"></a>
* <code>public function <b>getNextQueueEntry</b> ($workQueue, int $timeout = DEFAULT_TIMEOUT) : ?QueueEntry</code>  
    This takes the next job from the named work queue(s)
    and returns it.  
    This method will reserve the returned job for a short time.
    If you want to delete/bury/re-queue the job,
    use the `deleteEntry`/`buryEntry`/`requeueEntry` methods.
    Keep in mind to check the `Job::jobIsExpired()` flag
    before executing the job.  
    If you don't want to do all of this manually,
    use <code>[WorkProcessor]::processNextJob()</code> instead.  
    Returns `null` if no job was available after waiting for `$timeout` seconds.
    * `$workQueue`: The name of the Work Queue to poll (string) or an array of Work Queues to poll.
      In the latter case, the first job in any of these Work Queues will be returned.
    * `$timeout`: How many seconds to wait for a job to arrive, if none is available immediately.
      Set this to `NOBLOCK` if the method should return immediately.
      Set this to `FOREVER` if the call should block until a job becomes available, no matter how long it takes.

<a name="storeJob"></a>
* <code>public function <b>storeJob</b> (string $workQueue, Job $job, int $delay = 0)</code>  
    Stores a job in the work queue for later processing.
    * `$workQueue`: The name of the Work Queue to store the job in.
    * `$job`: The job to store.
    * `$delay`:  The job delay in seconds after which it will become available to `getNextQueueEntry()`.
      Set to zero (default) for jobs which should be processed as soon as possible.

<a name="buryEntry"></a>
* <code>public function <b>buryEntry</b> (QueueEntry $entry)</code>  
    Buries an existing job
    so that it won't be returned by `getNextQueueEntry()` again
    but is still present in the system for manual inspection.  
    This is what happens to failed jobs.

<a name="requeueEntry"></a>
* <code>public function <b>requeueEntry</b> (QueueEntry $entry, int $delay, string $workQueue = null)</code>  
    Re-queues an existing job
    so that it can be returned by `getNextQueueEntry()`
    again at some later time.
    A `$delay` is required
    to prevent the job from being returned right after it was re-queued.  
    This is what happens to failed jobs which can still be re-queued for a retry.  
    * `$entry`: The job to re-queue. The instance should not be used anymore after this call.
    * `$delay`: The job delay in seconds. It will become available to `getNextQueueEntry()` after this delay.
    * `$workQueue`: By default, to job is re-queued into its original Work Queue.
      With this parameter, a different Work Queue can be chosen.

<a name="deleteEntry"></a>
* <code>public function <b>deleteEntry</b> (QueueEntry $entry)</code>  
    Permanently deletes a job entry for its work queue.  
    This is what happens to finished jobs.


## Constants:

<a name="DEFAULT_TIMEOUT"></a>
* <code>const int <b>DEFAULT_TIMEOUT</b> = 5</code>  
    The default timeout for `getNextQueueEntry()`, in seconds.
* <code>const <b>NOBLOCK</b></code>  
    Causes `getNextQueueEntry()` to return immediately.
* <code>const <b>FOREVER</b></code>  
    Causes `getNextQueueEntry()` to block indefinitely, until a job becomes available.


[WorkProcessor]: Ref_WorkProcessor_class.md

