# `JobContext` DTO Class

Declaration: <code>mle86\Job\\<b>JobContext</b></code>  
Source file: [src/WQ/Job/JobContext.php](/src/WQ/Job/JobContext.php)

Contains metadata about the job currently being processed
and various setters to attach one-off event handlers to the job.

Instances of this class are available in job callbacks
run by [WorkProcessor::processNextJob()][processNextJob]
(their expected signature is `function(Job, JobContext): ?int|void`).

(Only the `WorkProcessor` class should create instances of this class.)


## Context Getters

* <code>public function <b>getQueueEntry</b>(): [QueueEntry]</code>  
    The queue entry DTO containing the current job instance.
* <code>public function <b>getJob</b>(): [Job]</code>  
    The job currently being executed.
* <code>public function <b>getSourceQueue</b>(): string</code>  
    The name of the work queue from which the current job was received.
* <code>public function <b>getWorkProcessor</b>(): [WorkProcessor]</code>  
    The WorkProcessor that created this instance.
* <code>public function <b>getWorkServer</b>(): [WorkServerAdapter]</code>  
    The work server from which the current job was received.

## Callback Setters

Additionally, it's possible to attach one or more callbacks
to the current job context.
One of them will be run when the job callback returns
(or throws an exception).

This may be useful for job handlers
that want to perform cleanup
only in case of a permanently-failed job (`onFailure`)
but not on a temporarily-failed job (`onTemporaryFailure`)

All callbacks are expected to have this signature:
`function(Job, JobContext): void`.

* <code>public function <b>onTemporaryFailure</b>(?callable $callback): void</code>  
    Sets up a callback that will be called once
    if and when the current job is being re-queued
    because it failed and should be re-tried.  
    This happens if [WP_ENABLE_RETRY] is set,
    if [jobCanRetry()][jobCanRetry] is true,
    and if the job handler returned <code>[JobResult]::FAILED</code> or threw a `RuntimeException`.  
    (This callback will be run by the WorkProcessor
     after it calls its internal `onJobRequeue()` hook,
     immediately before calling <code>WorkServerAdapter::requeueEntry()</code>.)

* <code>public function <b>onFailure</b>(?callable $callback): void</code>  
    Sets up a callback that will be called once
    if and when the current job is being buried/deleted
    because it failed and should not (or cannot) be re-tried later.  
    This happens if [WP_ENABLE_RETRY] is not set
     or if [jobCanRetry()][jobCanRetry] returns false
    and if the job handler returned <code>[JobResult]::ABORT</code>
     or threw a non-`RuntimeException` throwable.  
    (This callback will be run by the WorkProcessor
     after it calls its internal `onFailedJob()` hook,
     immediately before calling <code>WorkServerAdapter::buryEntry()</code>/<code>deleteEntry()</code>.)

* <code>public function <b>onSuccess</b>(?callable $callback): void</code>  
    Sets up a callback that will be called once
    if and when the current job is being deleted/movied
    because it succeeded!  
    This happens if the job handler returns <code>[JobResult]::SUCCESS</code>/`null`/void.  
    (This callback will be run by the WorkProcessor
     after it calls its internal `onSuccessfulJob()` hook,
     immediately before calling <code>WorkServerAdapter::deleteEntry()</code>/<code>requeueEntry()</code>.)


[WorkProcessor]: Ref_WorkProcessor_class.md
[WorkServerAdapter]: Ref_WorkServerAdapter_interface.md
[QueueEntry]: Ref_QueueEntry_class.md
[processNextJob]: Ref_WorkProcessor_class.md#processNextJob
[Job]: Ref_Job_interface.md
[JobResult]: Ref_JobResult_class.md
[WP_ENABLE_RETRY]: Ref_WorkProcessor_class.md#WP_ENABLE_RETRY
[jobCanRetry]: Ref_Job_interface.md#jobCanRetry
