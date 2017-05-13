# `SignalSafeWorkProcessor` class

This class is an extension of the original [WorkProcessor].

Key differences:

* It has the `installSignalHandler()` method.
* It has the `isAlive()` test method.
* It has the `lastSignal()` getter method.
* The `processNextJob()` method
  always calls [`pcntl_signal_dispatch`](http://php.net/manual/de/function.pcntl-signal-dispatch.php) before returning.

All instances are *alive*
until one of the registered signals is sent to the process.
If your worker script has a loop around the `processNextJob()` call,
check `isAlive()` in the loop condition.


## Additional methods:

* <code>public static function <b>installSignalHandler</b> (array $signals = [\SIGTERM, \SIGINT])</code>  
Installs a signal handler that will clear the `isAlive()` flag.  
The registered signals will not immediately terminate the program anymore,
giving the job handler callback enough time to finish their execution.
After `processNextJob()` returns,
you should `exit` the program.
    * `$signals`:
      An array of signal numbers for which to install the signal handler.
      By default, the signal handler is installed
      for `SIGTERM` and `SIGINT`,
      two signals commonly used to cleanly stop running processes.
* <code>public static function <b>isAlive</b> () : bool</code>  
  Returns `true` as long as no registered signal was received.
* <code>public static function <b>lastSignal</b> () : ?int</code>  
  Returns the number of the last signal received,
  or `null` if no signal has been received since the signal handler was set up.


[WorkProcessor]: Ref_WorkProcessor_class.md

