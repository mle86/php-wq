# `AffixAdapter` wrapper class

Declaration: <code>interface mle86\WQ\WorkServerAdapter\\<b>AffixAdapter</b> implements [WorkServerAdapter]</code>  
Source file: [src/WQ/WorkServerAdapter/WorkServerAdapter.php](/src/WQ/WorkServerAdapter/WorkServerAdapter.php)

This adapter serves to manipulate the work queue names
used by another [WorkServerAdapter].

It might be useful if your application prefixes all of its work queues with the app name
or appends the environment name to all work queue names.

In such a case, you can use the `setPrefix`/`setSuffix` methods
to specify the site-wide prefix/suffix.
All other methods of this class are just proxy methods to the "real" instance's methods
which prepend/append something to the work queue name(s).


## Example:

Instead of
<code>$workServer->getNextQueueEntry("myapp-<b>email</b>-PROD")</code>,

you could wrap the existing WorkServerAdapter instance in an AffixAdapter...  
`$workServer = new AffixAdapter($workServer)->withPrefix("myapp-")->withSuffix("-" . ENVIRONMENT);`,

then use it with a simpler call:  
<code>$workServer->getNextQueueEntry("<b>email</b>")</code>.


## Methods:

* <code>public function <b>__construct</b> ([WorkServerAdapter] $server)</code>  
    Instantiates a new AffixAdapter.
    * `$server`: The actual WorkServerAdapter to wrap.

* <code>public function <b>withPrefix</b> (?string $prefix): self</code>  
    Sets the prefix to prepend to all work queue names.  
    (The empty string has the same effect as `null`.)

* <code>public function <b>withSuffix</b> (?string $suffix): self</code>  
    Sets the suffix to append to all work queue names.  
    (The empty string has the same effect as `null`.)


## Proxy methods:

* <code>public function <b>getNextQueueEntry</b> ($workQueue, int $timeout = DEFAULT_TIMEOUT) : ?QueueEntry</code>  
* <code>public function <b>storeJob</b> (string $workQueue, Job $job, int $delay = 0)</code>  
* <code>public function <b>buryEntry</b> (QueueEntry $entry)</code>  
* <code>public function <b>requeueEntry</b> (QueueEntry $entry, int $delay, string $workQueue = null)</code>  
* <code>public function <b>deleteEntry</b> (QueueEntry $entry)</code>  

These proxy methods
for the wrapped instance's methods
modify any work queue names
according to the `withPrefix`/`withSuffix` settings.


[WorkProcessor]: Ref_WorkProcessor_class.md
[WorkServerAdapter]: Ref_WorkServerAdapter_interface.md

