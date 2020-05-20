# `JobContext` DTO Class

Declaration: <code>mle86\Job\\<b>JobContext</b></code>  
Source file: [src/WQ/Job/JobContext.php](/src/WQ/Job/JobContext.php)

Contains metadata about the job currently being processed
and various setters to attach one-off event handlers to the job.

Instances of this class are available in job callbacks
run by [WorkProcessor::processNextJob()][processNextJob]
(their expected signature is `function(Job, JobContext): ?int|void`).

(Only the `WorkProcessor` class should create instances of this class.)


## Context Getters

* <code>public function <b>getQueueEntry</b>(): [QueueEntry]</code>  
    The queue entry DTO containing the current job instance.
* <code>public function <b>getJob</b>(): [Job]</code>  
    The job currently being executed.
* <code>public function <b>getSourceQueue</b>(): string</code>  
    The name of the work queue from which the current job was received.
* <code>public function <b>getWorkProcessor</b>(): [WorkProcessor]</code>  
    The WorkProcessor that created this instance.
* <code>public function <b>getWorkServer</b>(): [WorkServerAdapter]</code>  
    The work server from which the current job was received.


[WorkProcessor]: Ref_WorkProcessor_class.md
[WorkServerAdapter]: Ref_WorkServerAdapter_interface.md
[QueueEntry]: Ref_QueueEntry_class.md
[processNextJob]: Ref_WorkProcessor_class.md#processNextJob
[Job]: Ref_Job_interface.md
