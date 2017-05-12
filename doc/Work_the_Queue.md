# Work the Queue

We still have to execute the queued jobs somewhere,
but not in the same application script.
We need a process that checks the work queue regularly,
executing any jobs it finds.

We could write a cronjob script
that regularly calls `$workServer->getNextQueueEntry("mail")->getJob()->send()`,
but we'd need to add some custom error handling.
There's a [`WorkProcessor`][WorkProcessor] class that already does all of that:

```php
use mle86\WQ\WorkProcessor;

$processor = new WorkProcessor ($workServer);
$processor->processNextJob("mail", function (EMail $mailJob) {
    $mailJob->send();
});
```

This will fetch the next job from the “`mail`” queue
(waiting up to 5 seconds until a job arrives
 if there's no job currently available),
run the callback function (which will call the job's `send()` method),
and then delete the job from the work queue
(unless it threw an exception).

We could easily wrap that `processNextJob()` call in a `while(true)` loop,
call that script once on system boot,
and be done.


**See next: [Error Handling].**

[Error Handling]: Error_Handling.md
[WorkProcessor]: Ref_WorkProcessor_class.md

