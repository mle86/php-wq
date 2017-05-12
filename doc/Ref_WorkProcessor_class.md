# `WorkProcessor` class

Declaration: <code>class mle86\WQ\\<b>WorkProcessor</b></code>  
Source file: [src/WQ/WorkProcessor.php](/src/WQ/WorkProcessor.php)

This class implements a wrapper around
`WorkServerAdapter::getNextJob()`
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

* <code>public function <b>processNextJob</b> ($workQueue, callable $callback, int $timeout = WorkServerAdapter::DEFAULT_TIMEOUT) : ?mixed</code>  
    Executes the next job in the Work Queue
    by passing it to the callback function.  
    If that results in a `\RuntimeException`,
    the method will try to re-queue the job
    and re-throw the exception.  
    If the execution results in any other `\Throwable`,
    no re-queueing will be attempted;
    the job will be buried immediately.  
    If the next job in the Work Queue is expired,
    it will be silently deleted
    and the method will return `null`.  
    Returns `$callback(Job)`'s return value on success (which might be `null`).
    Returns `null` if there was no job in the work queue to be executed.  
    Will re-throw any exceptions/throwables from the `Job` class.
    * `$workQueue`: See `WorkServerAdapter::getNextJob()`.
    * `$callback`: The handler callback to execute each Job.  
      Expected signature: `function(Job)`.
      Its return value will be returned by this method.
    * `$timeout`: See `WorkServerAdapter::getNextJob()`.

* <code>public function <b>setOption</b> (int $option, $value)</code>  
    Sets one of the configuration options.
    * `$option`: One of the `WP_` constants.
    * `$value`: The option's new value. The required type depends on the option.

* <code>public function <b>setOptions</b> (array $options)</code>  
    Sets one or more of the configuration options.


## Option keys:

* <code>const <b>WP_ENABLE_RETRY</b></code>  
    If this option is `true` (default),
    failed jobs will be re-queued (if their `Job::jobCanRetry()` return value says so).  
    This option can be used to disable retries for all jobs if set to `false`;
    jobs will then be handled as if their `Job::jobCanRetry` methods always returned `false`,
    i.e. they'll be buried or deleted (depending on the `WS_ENABLE_BURY` option).
* <code>const <b>WP_ENABLE_BURY</b></code>  
    If this option is `true` (default),
    permanently failed jobs will be buried;
    if it is `false`,
    failed jobs will be deleted.
* <code>const <b>WP_DELETE</b></code>  
    If this option is set to `DELETE_FINISHED` (default),
    finished jobs will be deleted.
    Otherwise, its value is taken as a Work Queue name
    where all finished jobs will be moved to.  
    (It's possible to put the origin work queue name here,
     resulting in an infinite loop
     as all jobs in the queue will be executed over and over.
     Probably not what you want.)
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


## Hook methods:

Usually, tasks like logging or stats collection should be done in the custom worker script.
If multiple worker scripts share the same logging/stats code,
it can be put into these hook functions instead
by extending the `WorkProcessor` class.

All of these hook methods are called by the `processNextJob()` method.
In the provided base class, they are empty.
Their return value is ignored.

* <code>protected function <b>onNoJobAvailable</b> (array $workQueues)</code>  
    This method is called if there is currently no job to be executed in any of the polled work queues.
* <code>protected function <b>onJobAvailable</b> (QueueEntry $qe)</code>  
    This method is called if there is a job ready to be executed,
    right before it is actually executed.
* <code>protected function <b>onSuccessfulJob</b> (QueueEntry $qe, $returnValue)</code>  
    This method is called after a job has been successfully executed,
    right before it is deleted from the work queue.
* <code>protected function <b>onExpiredJob</b> (QueueEntry $qe)</code>  
    This method is called if an expired job is encountered,
    right before it gets deleted.
* <code>protected function <b>onJobRequeue</b> (QueueEntry $qe, \Throwable $e, int $delay)</code>  
    This method is called after a job that can be re-tried at least one more time
    has failed (thrown an exception),
    right before `processNextJob()` re-queues it
    and re-throws the exception.
* <code>protected function <b>onFailedJob</b> (QueueEntry $qe, \Throwable $e)</code>  
    This method is called after a job has permanently failed (thrown an exception and cannot be re-tried),
    right before `processNextJob()` buries/deletes it
    and re-throws the exception.

