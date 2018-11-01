# Implementing a Job class

As an example, we'll implement a simple e-mail class.
Each instance represents one e-mail to be sent.
The application can decide whether it should be sent immediately
or if it should be put in a work queue.

We could start by writing a class that implements the [`Job`][Job] interface,
but it has rather a lot of required methods.
It's easier to extend the provided [`AbstractJob`][AbstractJob] class,
which has no required methods:

```php
<?php

use mle86\WQ\Job\AbstractJob;

class EMail extends AbstractJob
{
    protected $recipient;
    protected $subject;
    protected $message;
    
    public function __construct(string $recipient, string $subject, string $message)
    {
        $this->recipient = $recipient;
        $this->subject   = $subject;
        $this->message   = $message;
    }
    
    public function send()
    {
        if (mail($this->recipient, $this->subject, $this->message)) {
            // ok, has been sent!
        } else {
            throw new \RuntimeException ("mail() failed");
        }
    }
}
```

And that's it.
(Obviously, that's an extremely simplified example for the sake of brevity.)

The `AbstractJob` class
already implements
the `Job` interface
and the built-in [`Serializable`](http://php.net/manual/en/class.serializable.php) interface.


**See next: [Execute or Queue].**


[WorkServerAdapter]: Ref_WorkServerAdapter_interface.md
[AbstractJob]: Ref_AbstractJob_base_class.md
[Job]: Ref_Job_interface.md
[Execute or Queue]: Execute_or_Queue.md
