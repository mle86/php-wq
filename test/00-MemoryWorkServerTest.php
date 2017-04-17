<?php
namespace mle86\WQ\Tests;

use mle86\WQ\WorkServerAdapter\WorkServerAdapter;
use mle86\WQ\WorkServerAdapter\MemoryWorkServer;

require_once 'helper/AbstractWorkServerAdapterTest.php';

class MemoryWorkServerTest
	extends AbstractWorkServerAdapterTest
{

	public function getWorkServerAdapter () : WorkServerAdapter {
		return new MemoryWorkServer ();
	}

}

