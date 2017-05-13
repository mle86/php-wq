# WQ  (`mle86/wq`)

This package provides an easy way
to put PHP tasks of any kind
into a work queue
such as Beanstalkd or Redis
to execute them at a later time.

This is
**version 0.7**.


# Installation

```
$ composer require mle86/wq
```

It requires PHP 7.1
and has no other dependencies
(apart from PHPUnit for development
 and the PSR-3 interfaces).

You'll also want to install at least one other package
which contains a `WorkServerAdapter` implementation,
such as:

* [mle86/wq-beanstalkd](https://github.com/mle86/php-wq-beanstalkd)
    (Beanstalkd server adapter),
* [mle86/wq-redis](https://github.com/mle86/php-wq-redis)
    (Redis server adapter).


# Basic Concepts

A *Job* is something which should be done exactly once.
Maybe it's sending an e-mail,
maybe it's an external API call like a webhook,
maybe it's some slow clean-up process.
In any case, we're talking about a unit of work
that could be executed right away
but it would be better for the application's performance
to put it in a *Work Queue* instead, so it can be done asynchronously.

A *Work Queue* is a list of jobs that should be executed at some other time.
They are stored in some kind of *Work Server*.
One work server well-known in the PHP world is [Beanstalkd](http://kr.github.io/beanstalkd/).
It can store any number of work queues, although it calls them “tubes”.

Different work queues, or tubes, are commonly used to separate job types.
For example, the same work server might have
one “`mail`” queue for outgoing mails to be sent,
one “`cleanup`” queue for all kinds of clean-up jobs,
and one “`webhook`” queue for outgoing web-hook calls.

This package provides some helpful classes
to set up a simple work queue system.


# Quick Start

This is our Job implementation.
It represents an e-mail that can be sent.

```php
<?php
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
    
    public function send () {
        if (mail($this->recipient, $this->subject, $this->message)) {
            // ok, has been sent!
        } else {
            throw new \RuntimeException ("mail() failed");
        }
    }
}
```


We have some code using that e-mail class.

```php
<?php
use mle86\WQ\WorkServerAdapter\BeanstalkdWorkServer;

$mailJob = new EMail ("test@myproject.xyz", "Hello?", "This is a test mail.");

$workServer = BeanstalkdWorkServer::connect("localhost");
$workServer->storeJob("mail", $mailJob);
```


And finally,
we have our background worker script
which regularly checks the work server
for new e-mail jobs.

```php
<?php
use mle86\WQ\WorkServerAdapter\BeanstalkdWorkServer;
use mle86\WQ\WorkProcessor;

$queue = "mail";
printf("%s worker %d starting.\n", $queue, getmypid());

$processor  = new WorkProcessor (BeanstalkdWorkServer::connect("localhost"));
$fn_handler = function (EMail $mailJob) {
    $mailJob->send();
    // don't catch exceptions here, or the WorkProcessor won't see them.
};

while (true) {
    try {
        $processor->processNextJob($queue, $fn_handler);
    } catch (\Throwable $e) {
        echo $e . "\n";  // TODO: add some real logging here
    }
}
```


# Documentation

1. [Implementing a Job class]
1. [Execute or Queue]
1. [Work the Queue]
1. [Error Handling]
1. [Usage Examples]

Class reference:

* [Job] interface
* [AbstractJob] base class
* [WorkServerAdapter] interface
* [WorkProcessor] class
* [SignalSafeWorkProcessor] class
* [QueueEntry] wrapper class
* [Exceptions](doc/Ref_Exceptions.md)


[Job]: doc/Ref_Job_interface.md
[AbstractJob]: doc/Ref_AbstractJob_base_class.md
[WorkServerAdapter]: doc/Ref_WorkServerAdapter_interface.md
[WorkProcessor]: doc/Ref_WorkProcessor_class.md
[QueueEntry]: doc/Ref_QueueEntry_class.md

[Implementing a Job class]: doc/Implementing_a_Job_class.md
[Execute or Queue]: doc/Execute_or_Queue.md
[Work the Queue]: doc/Work_the_Queue.md
[Error Handling]: doc/Error_Handling.md
[Usage Examples]: doc/Usage_Examples.md
[SignalSafeWorkProcessor]: doc/Ref_SignalSafeWorkProcessor_class.md

