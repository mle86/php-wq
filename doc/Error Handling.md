## Error handling

So what happens if the execution throws an Exception?


* The `WorkProcessor::processNextJob()` method
  takes a job handler callback
  and runs it inside a try-catch block
  that will catch all exceptions.
* If the handler callback
  throws a `\RuntimeException`
  (or some subclass of that),
  the `WorkProcessor` will attempt to *retry* the job later.
  That means putting it back into the work queue with a delay (*re-queue*).  
  Retrying only works if the Job class is okay with that
  (see `Job::canRetry()`
   and `AbstractJob::MAX_RETRY`).
* If the callback
  throws any other exception,
  the job will be *buried* immediately.
* Regardless of exception type,
  `processNextJob()`
  will re-throw all caught exceptions
  after processing them.
* If the unserialization of a job in the work queue fails for any reason,
  the job will also be *buried* immediately,
  because retrying would not change anything.  
  (This is done in `WorkServerAdapter::getNextQueueEntry()`.
   It will throw an `UnserializationException`.)

