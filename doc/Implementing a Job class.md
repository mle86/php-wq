# Implementing a Job class

As an example, we'll implement a simple e-mail class.
Each instance represents one e-mail to be sent.
The application can decide whether it should be sent immediately
or if it should be put in a work queue.

We could start by writing a class that implements the [`Job`](#job-interface) interface,
but it has rather a lot of required methods.
It's easier to extend the provided [`AbstractJob`](#abstractjob-base-class) class,
which has no required methods:

```php
use mle86\WQ\Job\AbstractJob;

class EMail
    extends AbstractJob
{
    protected $recipient;
    protected $subject;
    protected $message;
    
    public function __construct (string $recipient, string $subject, string $message) {
        $this->recipient = $recipient;
        $this->subject   = $subject;
        $this->message   = $message;
    }
    
    public function execute () {
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


## Execute or Queue

Now if our application wants to send an e-mail...

```php
$mailJob = new EMail ("test@myproject.xyz", "Hello?", "This is a test mail.");
```

...then it can either do that right away,
delaying the application's response and requiring exception handling:

```php
$mailJob->send();  // this might throw a RuntimeException!
```

Or it can put the job in a work queue for later execution:

```php
use mle86\WQ\WorkServerAdapter\BeanstalkdWorkServer;

$workServer = BeanstalkdWorkServer::connect("localhost");
$workServer->storeJob("mail", $mailJob);
```

This serializes the `$mailJob`
and puts in into the “`mail`” work queue
of the local Beanstalkd work server.

(`BeanstalkdWorkServer` is an implementation of the [`WorkServerAdapter`](#workserveradapter-interface) interface.
 It is provided in the [mle86/wq-beanstalkd](https://packagist.org/packages/mle86/wq-beanstalkd) package.)

Alright, the job is now in the work queue.
What next?

