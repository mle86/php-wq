# `JobResult` Enum Class

Declaration: <code>mle86\Job\\<b>JobResult</b></code>  
Source file: [src/WQ/Job/JobResult.php](/src/WQ/Job/JobResult.php)

This class exists only to hold the job callback return status constants.

- `SUCCESS`
- `FAILED`
- (`ABORT`)
- (`EXPIRED`)

The [WorkProcessor] class
assumes that the job handler function
returns one of these constants
 (or `null`/no value,
 in which case `DEFAULT` will be used).

If you don't use the WorkProcessor class,
you won't need this class either.
If you do use the WorkProcessor class
but your job handlers always either succeed or throw an exception,
you won't need this class either –
it's useful only if you want additional control over the re-try mechanism without throwing exceptions.


## Constants

* <code>const int <b>SUCCESS</b></code>  
    This status indicates that the job has been processed correctly
    and that it should now be deleted from the work queue.  
    (It triggers the behavior set through the WorkProcessor's [WP_DELETE][WP_DELETE] option.)
    
* <code>const int <b>FAILED</b></code>  
    This status indicates that the job has failed.  
    If the job can be re-tried (according to its [jobCanRetry()][jobCanRetry] result),
    (according to its [jobCanRetry()][jobCanRetry] result
     and the [WorkProcessor::WP_ENABLE_BURY][WP_ENABLE_BURY] option),
    it will be re-queued for later re-execution.
    If not, it will be *buried*.  
    (That behavior may be changed through the WorkProcessor's [WP_ENABLE_RETRY][WP_ENABLE_RETRY] and
    [WP_ENABLE_BURY][WP_ENABLE_BURY] options.)  
    (The same thing happens if the job handler callback throws some `RuntimeException`.)

* <code>const int <b>ABORT</b></code>  
    This status indicates that the job has failed
    and that it should _not_ be re-tried,
    regardless of its [jobCanRetry()][jobCanRetry] result
    and the [WP_ENABLE_RETRY][WP_ENABLE_RETRY] setting.
    The job will immediately be buried/deleted
    (according to the [WP_ENABLE_BURY][WP_ENABLE_BURY] setting).  
    (The same thing happens if the job handler callback throws some non-`RuntimeException` exception.)

* <code>const int <b>EXPIRED</b></code>  
    This status indicates that the job is actually expired
    and that it should be deleted
    (or whatever other action is indicated by the [WP_EXPIRED][WP_EXPIRED] setting).  
    Usually, an expired job's [jobIsExpired()][jobIsExpired] method should return `true` right away
    which is the preferred way to indicate job expiration.
    But there might be situations in which the newly-unserialized [Job]
    cannot determine its own expiration without additional dependencies.
    The job handler callback however may be able to
    and that's why this constant exists.

* <code>const int <b>DEFAULT</b> = SUCCESS</code>  
    If the handler function returns `null` or no value at all,
    the WorkProcessor will use the default behavior
    set by this constant.


[WorkProcessor]: Ref_WorkProcessor_class.md
[WP_DELETE]: Ref_WorkProcessor_class.md#WP_DELETE
[WP_ENABLE_RETRY]: Ref_WorkProcessor_class.md#WP_ENABLE_RETRY
[WP_ENABLE_BURY]: Ref_WorkProcessor_class.md#WP_ENABLE_BURY
[WP_EXPIRED]: Ref_WorkProcessor_class.md#WP_EXPIRED
[Job]: Ref_Job_interface.md
[jobCanRetry]: Ref_Job_interface.md#jobCanRetry
[jobIsExpired]: Ref_Job_interface.md#jobIsExpired
