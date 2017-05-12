# Usage Examples


## Processing jobs

The easiest way to process your jobs
is to use the [WorkProcessor] helper class.
It wraps a [WorkServerAdapter] instance
and takes an optional PSR-3 logger.

Its main method
`processNextJob()`
takes a queue name
and a handler callback.
If there is a job in the queue,
it will be deserialized
and the resulting [Job] instance
will be passed to your handler callback.

If your callback causes a `\RuntimeException`,
the [WorkProcessor] will either re-queue the job for a retry
(assuming its `Job::jobCanRetry()` method agrees)
and bury it;
any other exception causes the job to be buried immediately.
Either way,
any exception from the callback will be passed to the caller.

That means you still need some try-catch block
around your `processNextJob()` call:

```php
try {
    $workProcessor->processNextJob("queue-name", $fn_my_callback);
} catch (\Throwable $t) {
   // Log and continue?
   // Or abort the worker script?
}
```

Another option is to tell the [WorkProcessor]
that you don't want it to re-throw any exceptions.
This might be interesting
if you do all exception handling
in your callback function
or in the `WorkProcessor::onFailedJob()` hook.

```php
$workProcessor->setOption(WorkProcessor::WP_RETHROW_EXCEPTIONS, false);
while (true) {
    $workProcessor->processNextJob("queue-name", $fn_my_callback);
    // this will run forever!
}
```


### Worker Scripts

A common idiom
is a long-running *Worker Script*.

That's a script
which is responsible for one Work Queue
containing one type of Job,
continuously checking the queue
and executing all jobs that arrive.

To stop it,
you'll probably kill it with some signal.
Without a dedicated signal handler,
that signal might interrupt your script
in the middle of a job execution,
resulting in inconsistencies
and in the job still being available in the queue.

Use a signal handler like this
to shut down your worker script cleanly:

```php
$doRun = true;
pcntl_signal(SIGTERM, function() use(&$doRun) { $doRun = false; });
pcntl_signal(SIGINT,  function() use(&$doRun) { $doRun = false; });

while ($doRun) {
    $workProcessor->processNextJob("queue-name", $fn_my_callback);
    pcntl_signal_dispatch();
}

exit();
```

Now if your running worker script
gets a SIGTERM,
it will finish whatever it's currently doing
(e.g. processing some job and then deleting it),
then the signal handler will clear the `$doRun` flag
and your script will terminate.
(It may take a few seconds for your scripts to terminate
 if your [WorkServerAdapter] is currently waiting for a job to arrive –
 by default, it waits for [up to 5 seconds](Ref_WorkServerAdapter_interface.md#DEFAULT_TIMEOUT).)


## Queuing Jobs

On the code side there's really not a lot of possibilities
for queuing jobs:

```php
$workServerAdapter->storeJob("work-queue-name", $job);
```

Because you'll probably need different handler callbacks
for each job class,
it's a good idea
to have one separate Work Queue
for every job class.

(Of course if you have a hierarchy of Job classes
 with a common interface,
 you can put them into the same Work Queue.)


## Going Manual

The [WorkProcessor] class contains no magic –
if you want, you can do everything it does manually
by using [WorkServerAdapter]'s
`getNextQueueEntry()`,
`deleteEntry()`,
`buryEntry()`,
and `requeueEntry()`
methods.

That means this short call...

```php
(new WorkProcessor($workServerAdapter))
    ->processNextJob("queue-name", function(Job $job) {
        $job->execute();
    });
```

...is _roughly_ equivalent to this code block:

```php
if (($queueEntry = $workServerAdapter->getNextQueueEntry("queue-name"))) {
    try {
        $ret = $queueEntry->getJob()->execute();
        $workServerAdapter->deleteJob($queueEntry);
        return $ret;
    } catch (\Throwable $e) {
        $workServerAdapter->buryJob($queueEntry);
        throw $e;
    }
}
```

NB:
This block does _not_ care about expired jobs
and will happily try to execute them as well,
and it does not attempt to re-queue failed jobs.
Including all of those functions in that example
would pretty much turn it into a copy
of the `processNextJob()` method.


[WorkServerAdapter]: Ref_WorkServerAdapter_interface.md
[WorkProcessor]: Ref_WorkProcessor_class.md
[Job]: Ref_Job_interface.md