# `Job` Interface

Declaration: <code>interface mle86\WQ\Job\\<b>Job</b></code>  
Source file: [src/WQ/Job/Job.php](/src/WQ/Job/Job.php)

A Job is a representation of some task to do.
It can be stored in a Work Queue with `WorkServerAdapter::storeJob()`.

All Jobs have to be serializable
in order to be stored in a Work Queue.

For your own Job classes,
see the [`AbstractJob`][AbstractJob] base class instead;
it is easier to work with
as it provides default implementations
for the required methods,
including [`__serialize` and `__unserialize`](https://www.php.net/manual/en/language.oop5.magic.php#object.unserialize).

This interface does not specify how a Job should be executed
or how the responsible method(s) should be named,
if they are part of the Job implementation at all.


## Methods

<a name="jobCanRetry"></a>
* <code>public function <b>jobCanRetry</b> (): bool</code>  
    Whether this job can be retried later.
    The [WorkProcessor] helper class will check this if job execution has failed.  
    If it returns true, the job will be stored in the Work Queue again
    to be re-executed after `jobRetryDelay()` seconds;
    if it returns false, the job will be buried for later inspection.

<a name="jobRetryDelay"></a>
* <code>public function <b>jobRetryDelay</b> (): ?int</code>  
    How many seconds the job should be delayed in the Work Queue before being re-tried.
    If `jobCanRetry()` is true,
    this must return a positive integer
    (or zero, if the job should be re-tried as soon as possible).

<a name="jobTryIndex"></a>
* <code>public function <b>jobTryIndex</b> (): int</code>  
    On the first try, this must return `1`,
    on the first retry, this must return `2`,
    and so on.

<a name="jobIsExpired"></a>
* <code>public function <b>jobIsExpired</b> (): bool</code>  
    Return `true` here if the instance should be considered expired.
    The [WorkServerAdapter] implementations will still return expired instances,
    but the [WorkProcessor] class won't process them –
    they will be deleted as soon as they are encountered.
    Always return `false` here if your job class cannot expire.  
    (Also see <code>[JobResult]::EXPIRED</code> which has the same effect as returning `true` here.)

* <code>public function __serialize (): array</code>,  
  <code>public function __unserialize (array $data): void</code>  
    Needed for serializability -- see https://www.php.net/manual/en/language.oop5.magic.php#object.unserialize


[AbstractJob]: Ref_AbstractJob_base_class.md
[WorkProcessor]: Ref_WorkProcessor_class.md
[WorkServerAdapter]: Ref_WorkServerAdapter_interface.md
[JobResult]: Ref_JobResult_class.md
