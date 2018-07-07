# `BlackHoleWorkServer` dummy class

Declaration: <code>interface mle86\WQ\WorkServerAdapter\\<b>BlackHoleWorkServer</b> implements [WorkServerAdapter]</code>  
Source file: [src/WQ/WorkServerAdapter/BlackHoleWorkServer.php](/src/WQ/WorkServerAdapter/WorkServerAdapter.php)

This Work Server Adapter
does not connect to anything.

The `getNextQueueEntry` method always returns null.
The `storeJob`, `buryEntry`, `requeueEntry`, `deleteEntry` methods do nothing at all.


[WorkServerAdapter]: Ref_WorkServerAdapter_interface.md
