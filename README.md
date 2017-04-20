# WQ  (`mle86/wq`)

This package provides an easy way
to put PHP tasks of any kind
into a work queue
such as Beanstalkd or Redis
to execute them at a later time.

This is
**version 0.3**.


# Installation

```
$ composer require mle86/wq
```

It requires PHP 7.1
and has no other dependencies
(apart from PHPUnit for development
 and the PSR-3 interfaces).

You'll also want to install at least one other package
which contains a `WorkServerAdapter` implementation,
such as:

* [mle86/wq-beanstalkd](https://github.com/mle86/php-wq-beanstalkd)
    (Beanstalkd server adapter),
* [mle86/wq-redis](https://github.com/mle86/php-wq-redis)
    (Redis server adapter).


# Basic Concepts

A *Job* is something which should be done exactly once.
Maybe it's sending an e-mail,
maybe it's an external API call like a webhook,
maybe it's some slow clean-up process.
In any case, we're talking about a unit of work
that could be executed right away
but it would be better for the application's performance
to put it in a *Work Queue* instead, so it can be done asynchronously.

A *Work Queue* is a list of jobs that should be executed at some other time.
They are stored in some kind of *Work Server*.
One work server well-known in the PHP world is [Beanstalkd](http://kr.github.io/beanstalkd/).
It can store any number of work queues, although it calls them “tubes”.

Different work queues, or tubes, are commonly used to separate job types.
For example, the same work server might have
one “`mail`” queue for outgoing mails to be sent,
one “`cleanup`” queue for all kinds of clean-up jobs,
and one “`webhook`” queue for outgoing web-hook calls.

This package provides some helpful classes
to set up a simple work queue system.


## Implementing a Job class

As an example, we'll implement a simple e-mail class.
Each instance represents one e-mail to be sent.
The application can decide whether it should be sent immediately
or if it should be put in a work queue.

We could start by writing a class that implements the [`Job`](#job-interface) interface,
but it has rather a lot of required methods.
It's easier to extend the provided [`AbstractJob`](#abstractjob-base-class) class,
which requires only an `execute()` method:

```php
use mle86\WQ\Job\AbstractJob;

class EMail
    extends AbstractJob
{
    protected $recipient;
    protected $subject;
    protected $message;
    
    public function __construct (string $recipient, string $subject, string $message) {
        $this->recipient = $recipient;
        $this->subject   = $subject;
        $this->message   = $message;
    }
    
    public function execute () {
        if (mail($this->recipient, $this->subject, $this->message)) {
            // ok, has been sent!
        } else {
            throw new \RuntimeException ("mail() failed");
        }
    }
}
```

And that's it.
(Obviously, that's an extremely simplified example for the sake of brevity.)

The `AbstractJob` class already implements the `Job` and the built-in `\Serializable` interfaces.


## Execute or Queue

Now if our application wants to send an e-mail...

```php
$mail = new EMail ("test@myproject.xyz", "Hello?", "This is a test mail.");
```

...then it can either do that right away,
delaying the application's response and requiring exception handling:

```php
$mail->execute();  // this might throw a RuntimeException!
```

Or it can put the job in a work queue for later execution:

```php
use mle86\WQ\WorkServerAdapter\BeanstalkdWorkServer;

$workServer = BeanstalkdWorkServer::connect("localhost");
$workServer->storeJob("mail", $mail);
```

This serializes the `$mail` job
and puts in into the “`mail`” work queue
of the local Beanstalkd work server.

(`BeanstalkdWorkServer` is an implementation of the [`WorkServerAdapter`](#workserveradapter-interface) interface.
 It is provided in the [mle86/wq-beanstalkd](https://packagist.org/packages/mle86/wq-beanstalkd) package.)

Alright, the job is now in the work queue.
What next?


## Work the Queue

We still have to execute it somewhere,
but not in the same application script.
We need a process that checks the work queue regularly,
executing any jobs it finds.

We could write a cronjob script
that regularly calls `$workServer->getNextQueueEntry("mail")->getJob()->execute()`,
but we'd need to add some custom error handling.
There's a [`WorkProcessor`](#workprocessor-class) class that already does all of that:

```php
use mle86\WQ\WorkProcessor;

$processor = new WorkProcessor ($workServer);
$processor->executeNextJob("mail");
```

This will fetch the next job from the “`mail`” queue
(waiting up to 5 seconds until a job arrives if there's no job currently available),
execute it,
and then delete it from the work queue if it did not throw an exception.

We could easily wrap that `executeNextJob()` call in a `while(true)` loop,
call that script once on system boot,
and be done.


## Error handling

So what happens if `Job::execute()` throws an Exception?

* If the `Job::execute()` implementation
  throws a `\RuntimeException`
  (or some subclass of that),
  the `WorkProcessor` will attempt to *retry* the job later.
  That means putting it back into the work queue with a delay (*re-queue*).  
  Retrying only works if the Job class is okay with that
  (see `Job::canRetry()` and `AbstractJob::MAX_RETRY`).
* If the `Job::execute()` implementation
  throws any other exception,
  the job will be *buried* immediately.
* If the unserialization of a job in the work queue fails for any reason,
  the job will also be *buried* immediately,
  because retrying would not change anything.  
  (This is done in `WorkServerAdapter::getNextQueueEntry()`.
   It will throw an `UnserializationException`.)


# Minimal example

This is our Job implementation.
It represents an e-mail that can be sent.

```php
<?php
use mle86\WQ\AbstractJob;

class EMail
    extends AbstractJob
{
    protected $recipient;
    protected $subject;
    protected $message;
    
    public function __construct (string $recipient, string $subject, string $message) {
        $this->recipient = $recipient;
        $this->subject   = $subject;
        $this->message   = $message;
    }
    
    public function execute () {
        if (mail($this->recipient, $this->subject, $this->message)) {
            // ok, has been sent!
        } else {
            throw new \RuntimeException ("mail() failed");
        }
    }
}
```

We have some code using that e-mail class.

```php
<?php
use mle86\WQ\WorkServerAdapter\BeanstalkdWorkServer;

$mail = new EMail ("test@myproject.xyz", "Hello?", "This is a test mail.");

$workServer = BeanstalkdWorkServer::connect("localhost");
$workServer->storeJob("mail", $mail);
```

And finally,
we have our background worker script
which regularly checks the work server
for new e-mail jobs.

```php
<?php
use mle86\WQ\WorkServerAdapter\BeanstalkdWorkServer;
use mle86\WQ\WorkProcessor;

$queue = "mail";
printf("%s worker %d starting.\n", $queue, getmypid());

$processor = new WorkProcessor (BeanstalkdWorkServer::connect("localhost"));

while (true) {
    try {
        $processor->executeNextJob($queue);
    } catch (\Throwable $e) {
        echo $e . "\n";
    }
}
```


# Class reference

## `Job` interface

(<code>interface mle86\WQ\Job\\<b>Job</b></code>)

A Job is a representation of some task to do.
It can be `execute`'d immediately,
or it can be stored in a Work Queue for later processing.

This interface extends [`\Serializable`](https://secure.php.net/manual/en/class.serializable.php),
because all Jobs have to be serializable
in order to be stored in a Work Queue.

For your own Job classes,
see the `AbstractJob` base class instead;
it implements many of these functions already
and is easier to work with.

* <code>public function <b>execute</b> ()</code>  
    This method should implement the job's functionality.  
    `WorkProcessor::executeNextJob()` will call this and return its return value.
    If it throws some Exception, it will bury the job;
    if it was a RuntimeException and `jobCanRetry` returns true,
    it will re-queue the job with a `jobRetryDelay`.

* <code>public function <b>jobCanRetry</b> () : bool</code>  
    Whether this job can be retried later.
    The `WorkServerAdapter` implementation will check this if `execute()` has failed.  
    If it returns true, the job will be stored in the Work Queue again
    to be re-executed after `jobRetryDelay()` seconds;
    if it returns false, the job will be buried for later inspection.

* <code>public function <b>jobRetryDelay</b> () : ?int</code>  
    How many seconds the job should be delayed in the Work Quere before being re-tried.
    If `jobCanRetry()` is true,
    this must return a positive integer.

* <code>public function <b>jobTryIndex</b> () : int</code>  
    On the first try, this must return `1`,
    on the first retry, this must return `2`,
    and so on.


## `AbstractJob` base class

(<code>abstract class mle86\WQ\Job\\<b>AbstractJob</b> implements Job</code>)

To build a working Job class,
simply extend this class
and implement the `execute()` method
to do something.

* If the jobs should be re-tried after an initial failure,
  override the `MAX_RETRY` constant.
* To change the retry delay interval,
  override the `jobRetryDelay()` method
  (you'll probably want to have an increasing delay,
   so base it on the `jobTryIndex()` counter value).

It implements the `Job` interface (partially).

* <code>abstract public function <b>execute</b> ()</code>  
    See `Job::execute()`.

* <code>const int <b>MAX_RETRY</b> = 0</code>  
    How often a job of this type can be retried if it fails.
    Override this as necessary in subclasses.
    Zero or negative values mean that this job can only be tried once, never re-tried.

* <code>public function <b>jobRetryDelay</b> (): ?int { … }</code>  
    See `Job::jobRetryDelay()`.
    This default implementation
    always returns 10 minutes.

* <code>public function <b>jobTryIndex</b> (): int { … }</code>  
    See `Job::jobTryIndex()`.
    This default implementation
    always returns the `$_try_index` value
    or `1`, whichever is greater.

* <code>protected <b>$_try_index</b> = 0</code>  
    The current try index.  
    The default `serialize()` implementation
    will increase this by 1 before serializing it,
    so that the serialization always contains
    the correct next value.  
    *Internal:*
    This should not be accessed directly,
    except for a custom `serialize()` override.

* <code>public function <b>serialize</b> () { … }</code>  
    This default implementation stores all public and protected properties.
    Override this method if that's not enough or if you want to do some additional pre-serialization processing,
    but don't forget to include `$_try_index + 1` in the serialization!

* <code>public function <b>unserialize</b> (string $serialized) { … }</code>  
    This default implementation simply writes all serialized values
    to their corresponding object property.
    That includes the `$_try_index` counter.
    Private and/or static properties will never be written.

* <code>public function <b>jobCanRetry</b> (): bool { … }</code>  
    See `Job::jobCanRetry()`.
    This default implementation
    always returns `true`
    if `jobTryIndex() ≤ MAX_RETRY`.


## `WorkProcessor` class

(<code>class mle86\WQ\\<b>WorkProcessor</b></code>)

This class implements a wrapper around
`WorkServerAdapter::getNextJob()`
called `executeNextJob()`
that does not only execute the next job immediately
but will also try to re-queue it if it fails.

* <code>public function <b>__construct</b> (WorkServerAdapter $workServer, LoggerInterface $logger = null, array $options = [])</code>  
    Instantiates a new WorkProcessor.
    This causes no side effects yet.
    * `$workServer`: The work server adapter to work with.
    * `$logger`: A [PSR-3](http://www.php-fig.org/psr/psr-3/) logger.
      The WorkProcessor will report job success status here.
    * `$options`: Options to set, overriding the default options.
      Works the same as a `setOptions()` call right after instantiation.

* <code>public function <b>executeNextJob</b> ($workQueue, int $timeout = WorkServerAdapter::DEFAULT_TIMEOUT) : ?mixed</code>  
    Executes the next job in the Work Queue.  
    If that results in a `\RuntimeException`,
    the method will try to re-queue the job
    and re-throw the exception.  
    If the execution results in any other `\Throwable`,
    no re-queueing will be attempted;
    the job will be buried immediately.  
    Returns the `Job::execute`'s return value on success (which might be `null`).
    Returns `null` if there was no job in the work queue to be executed.  
    Will re-throw any exceptions/throwables from the `Job` class.
    * `$workQueue`: See `WorkServerAdapter::getNextJob()`.
    * `$timeout`: See `WorkServerAdapter::getNextJob()`.

* <code>public function <b>setOption</b> (int $option, $value)</code>  
    Sets one of the configuration options.
    * `$option`: One of the `WP_` constants.
    * `$value`: The option's new value. The required type depends on the option.

* <code>public function <b>setOptions</b> (array $options)</code>  
    Sets one or more of the configuration options.


Option keys:

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
    If this option is `true` (default),
    finished jobs will be deleted.
    Otherwise, its value is taken as a Work Queue name
    where all finished jobs will be moved to.  
    (It's possible to put the origin work queue name here,
     resulting in an infinite loop
     as all jobs in the queue will be executed over and over.
     Probably not what you want.)


Hook methods:

Usually, tasks like logging or stats collection should be done in the custom worker script.
If multiple worker scripts share the same logging/stats code,
it can be put into these hook functions instead
by extending the `WorkProcessor` class.  
All of these hook methods are called by the `executeNextJob()` method.
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
* <code>protected function <b>onJobRequeue</b> (QueueEntry $qe, \Throwable $e, int $delay)</code>  
    This method is called after a job that can be re-tried at least one more time
    has failed (thrown an exception),
    right before `executeNextJob()` re-queues it
    and re-throws the exception.
* <code>protected function <b>onFailedJob</b> (QueueEntry $qe, \Throwable $e)</code>  
    This method is called after a job has permanently failed (thrown an exception and cannot be re-tried),
    right before `executeNextJob()` buries/deletes it
    and re-throws the exception.


## `WorkServerAdapter` interface

(<code>interface mle86\WQ\WorkServerAdapter\\<b>WorkServerAdapter</b></code>)

A Work Server stores jobs inside one or more Work Queues.

A `WorkServerAdapter` implementation
uses a connection handle to an existing Work Server:
for example, the `RedisWorkServer` implementation
takes a `\Redis` instance from the phpredis extension.

A Beanstalkd server or a Redis server might be such a Work Server.
In case of Beanstalkd, Work Queues are Tubes;
in case of Redis, Work Queues are Lists.

* <code>const int <b>DEFAULT_TIMEOUT</b> = 5</code>  
    The default timeout for `getNextQueueEntry()`, in seconds.
* <code>const <b>NOBLOCK</b></code>  
    Causes `getNextQueueEntry()` to return immediately.
* <code>const <b>FOREVER</b></code>  
    Causes `getNextQueueEntry()` to block indefinitely, until a job becomes available.

* <code>public function <b>getNextQueueEntry</b> ($workQueue, int $timeout = DEFAULT_TIMEOUT) : ?QueueEntry</code>  
    This takes the next job from the named work queue(s)
    and returns it.  
    This is probably not the method you want,
    because it will not try to execute the job
    and it won't handle any job exceptions either.
    Use `WorkProcessor::executeNextJob()` instead.
    Returns `null` if no job was available after waiting for `$timeout` seconds.
    * `$workQueue`: The name of the Work Queue to poll (string) or an array of Work Queues to poll.
      In the latter case, the first job in any of these Work Queues will be returned.
    * `$timeout`: How many seconds to wait for a job to arrive, if none is available immediately.
      Set this to `NOBLOCK` if the method should return immediately.
      Set this to `FOREVER` if the call should block until a job becomes available, no matter how long it takes.

* <code>public function <b>storeJob</b> (string $workQueue, Job $job, int $delay = 0)</code>  
    Stores a job in the work queue for later processing.
    * `$workQueue`: The name of the Work Queue to store the job in.
    * `$job`: The job to store.
    * `$delay`:  The job delay in seconds after which it will become available to `getNextQueueEntry()`.
      Set to zero (default) for jobs which should be processed as soon as possible.

* <code>public function <b>buryEntry</b> (QueueEntry $entry)</code>  
    Buries an existing job
    so that it won't be returned by `getNextQueueEntry()` again
    but is still present in the system for manual inspection.  
    This is what happens to failed jobs.

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

* <code>public function <b>deleteEntry</b> (QueueEntry $entry)</code>  
    Permanently deletes a job entry for its work queue.  
    This is what happens to finished jobs.


## Exception classes

* <code>interface mle86\WQ\Exception\\<b>WQException</b></code>  
    All WQ Exceptions implement this empty interface.

* <code>class mle86\WQ\Exception\\<b>OptionValueException</b> extends \InvalidArgumentException</code>  
    Thrown by `WorkProcessor::setOption` and `WorkProcessor::setOptions`
    in case of an invalid option value.

* <code>class mle86\WQ\Exception\\<b>UnserializationException</b> extends \UnexpectedValueException</code>  
    Thrown by `QueueEntry::fromSerializedJob()`
    in case of invalid job data:
    - invalid serialization
    - non-object
    - object, but not a Job implementation

