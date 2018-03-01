# `WorkProcessor` class

Declaration: <code>class mle86\WQ\\<b>WorkProcessor</b></code>  
Source file: [src/WQ/WorkProcessor.php](/src/WQ/WorkProcessor.php)

This class implements a wrapper around
<code>[WorkServerAdapter]::getNextJob()</code>
called `processNextJob()`
that does not only execute the next job immediately
but will also try to re-queue it if it fails.


## Methods:

* <code>public function <b>__construct</b> (WorkServerAdapter $workServer, LoggerInterface $logger = null, array $options = [])</code>  
    Instantiates a new WorkProcessor.
    This causes no side effects yet.
    * `$workServer`: The work server adapter to work with.
    * `$logger`: A [PSR-3](http://www.php-fig.org/psr/psr-3/) logger.
      The WorkProcessor will report job success status here.
    * `$options`: Options to set, overriding the default options.
      Works the same as a `setOptions()` call right after instantiation.

<a name="processNextJob"></a>
* <code>public function <b>processNextJob</b> ($workQueue, callable $callback, int $timeout = WorkServerAdapter::DEFAULT_TIMEOUT) : void</code>  
    Executes the next job in the Work Queue
    by passing it to the callback function.  
    If that results in a `\RuntimeException`,
    the method will try to re-queue the job
    and re-throw the exception.  
    If the execution results in any other `\Throwable`,
    no re-queueing will be attempted;
    the job will be buried immediately.  
    If the next job in the Work Queue is expired,
    it will be silently deleted.
    Will re-throw on any Exceptions/Throwables from the `$callback`.
    * `$workQueue`: See `WorkServerAdapter::getNextJob()`.
    * `$callback`: The handler callback to execute each Job.  
      Expected signature:
      <code>function([Job]): ?int|void</code>.
      See the [JobResult] enum class for possible return values.
    * `$timeout`: See `WorkServerAdapter::getNextJob()`.

<a name="setOption"></a>
* <code>public function <b>setOption</b> (int $option, $value) : self</code>  
    Sets one of the configuration options.
    * `$option`: One of the [`WP_` constants](#option-keys).
    * `$value`: The option's new value. The required type depends on the option.

<a name="setOptions"></a>
* <code>public function <b>setOptions</b> (array $options) : self</code>  
    Sets one or more of the configuration options.


## Option keys:

<a name="WP_ENABLE_RETRY"></a>
* <code>const <b>WP_ENABLE_RETRY</b></code>  
    If this option is `true` (default),
    failed jobs will be re-queued (if their `Job::jobCanRetry()` return value says so).  
    This option can be used to disable retries for all jobs if set to `false`;
    jobs will then be handled as if their `Job::jobCanRetry` methods always returned `false`,
    i.e. they'll be buried or deleted (depending on the `WS_ENABLE_BURY` option).
<a name="WP_ENABLE_BURY"></a>
* <code>const <b>WP_ENABLE_BURY</b></code>  
    If this option is `true` (default),
    permanently failed jobs will be buried;
    if it is `false`,
    failed jobs will be deleted.
<a name="WP_DELETE"></a>
* <code>const <b>WP_DELETE</b></code>  
    If this option is set to `DELETE_FINISHED` (default),
    finished jobs will be deleted.
    Otherwise, its value is taken as a Work Queue name
    where all finished jobs will be moved to.  
    (It's possible to put the origin work queue name here,
     resulting in an infinite loop
     as all jobs in the queue will be executed over and over.
     Probably not what you want.)
<a name="WP_EXPIRED"></a>
* <code>const <b>WP_EXPIRED</b></code>  
    If this option is set to `DELETE_EXPIRED` (default),
    expired jobs will be deleted.
    If this option is set to `BURY_EXPIRED`,
    expired jobs will be buried instead.
    Otherwise, its value is taken as a Work Queue name
    where all expired jobs will be moved to.  
    (It's possible to put the origin work queue name here,
     resulting in an infinite loop
     as soon as an expired job is encountered.
     Probably not what you want.)
<a name="WP_RETHROW_EXCEPTIONS"></a>
* <code>const <b>WP_RETHROW_EXCEPTIONS</b></code>  
    If this option is `true` (default),
    all exceptions thrown by handler callback
    will be re-thrown so that the caller
    receives them as well.
    If this option is `false`,
    `processNextJob()` will silently return instead.


## Hook methods:

Usually, tasks like logging or stats collection should be done in the custom worker script.
If multiple worker scripts share the same logging/stats code,
it can be put into these hook functions instead
by extending the `WorkProcessor` class.

All of these hook methods are called by the `processNextJob()` method.
In the provided base class, they are empty.

<a name="onNoJobAvailable"></a>
* <code>protected function <b>onNoJobAvailable</b> (array $workQueues) : void</code>  
    This method is called if there is currently no job to be executed in any of the polled work queues.
<a name="onJobAvailable"></a>
* <code>protected function <b>onJobAvailable</b> (QueueEntry $qe) : void</code>  
    This method is called if there is a job ready to be executed,
    right before it is actually executed.
<a name="onSuccessfulJob"></a>
* <code>protected function <b>onSuccessfulJob</b> (QueueEntry $qe) : void</code>  
    This method is called after a job has been successfully executed,
    right before it is deleted from the work queue.
<a name="onExpiredJob"></a>
* <code>protected function <b>onExpiredJob</b> (QueueEntry $qe) : void</code>  
    This method is called if an expired job is encountered,
    right before it gets deleted.
<a name="onJobRequeue"></a>
* <code>protected function <b>onJobRequeue</b> (QueueEntry $qe, \Throwable $e, int $delay) : void</code>  
    This method is called after a job that can be re-tried at least one more time
    has failed (thrown an exception),
    right before `processNextJob()` re-queues it
    and re-throws the exception.
<a name="onFailedJob"></a>
* <code>protected function <b>onFailedJob</b> (QueueEntry $qe, \Throwable $e) : void</code>  
    This method is called after a job has permanently failed (thrown an exception and cannot be re-tried),
    right before `processNextJob()` buries/deletes it
    and re-throws the exception.

[WorkServerAdapter]: Ref_WorkServerAdapter_interface.md
[Job]: Ref_Job_interface.md
[JobResult]: Ref_JobResult_class.md
