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

(`BeanstalkdWorkServer` is an implementation of the [`WorkServerAdapter`][WorkServerAdapter] interface.
 It is provided in the [mle86/wq-beanstalkd](https://packagist.org/packages/mle86/wq-beanstalkd) package.)

Alright, the job is now in the work queue.
What next?


**See next: [Work the Queue].**


[WorkServerAdapter]: Ref_WorkServerAdapter_interface.md
[Work the Queue]: Work_the_Queue.md

