# `AbstractJob` base class

Declaration: <code>abstract class mle86\WQ\Job\\<b>AbstractJob</b> implements Job</code>  
Source file: [src/WQ/Job/AbstractJob.php](src/WQ/Job/AbstractJob.php)

To build a working Job class,
simply extend this class.

* It's your choice
  whether to put your job execution logic in the Job class
  or somewhere else entirely, like the queue worker script.
* If the jobs should be re-tried after an initial failure,
  override the `MAX_RETRY` constant.
* If the retry condition is more complex,
  override the `jobCanRetry()` method instead.
* To change the retry delay interval,
  override the `jobRetryDelay()` method
  (you'll probably want to have an increasing delay,
   so base it on the `jobTryIndex()` counter value).
* If your queued jobs can expire before being executed,
  override `jobIsExpired()` so that it returns `true`
  if the expiry condition is reached. You may need to
  add a job creation timestamp property for that.

It implements the `Job` interface (partially).

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
    Private and/or static properties will never be written to.

* <code>public function <b>jobCanRetry</b> (): bool { … }</code>  
    See `Job::jobCanRetry()`.
    This default implementation
    always returns `true`
    if `jobTryIndex() ≤ MAX_RETRY`.

* <code>public function <b>jobIsExpired</b> () : bool { … }</code>  
    See `Job::jobIsExpired()`.
    This default implementation
    always returns `false`,
    meaning that `AbstractJob` implementations
    never expire by default.

