## Error handling

So what happens if the execution throws an Exception?


* The <code>[WorkProcessor]::processNextJob()</code> method
  takes a job handler callback
  and runs it inside a try-catch block
  that will catch all exceptions.

* If the handler callback
  throws a `\RuntimeException`
  (or some subclass of that),
  the `WorkProcessor` will attempt to *retry* the job later.
  That means putting it back into the work queue with a delay (*re-queue*).  
  Retrying only works if the Job class is okay with that
  (see <code>[Job]::canRetry()</code>
   and <code>[AbstractJob]::MAX_RETRY</code>).
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
  (This is done in <code>[WorkServerAdapter]::getNextQueueEntry()</code>.
   It will throw an <code>[UnserializationException]</code>.)


**See next: [Usage Examples].**


[WorkServerAdapter]: Ref_WorkServerAdapter_interface.md
[AbstractJob]: Ref_AbstractJob_base_class.md
[Job]: Ref_Job_interface.md
[UnserializationException]: Ref_Exceptions.md
[Usage Examples]: doc/Usage_Examples.md

