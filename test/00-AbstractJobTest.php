<?php
namespace mle86\WQ\Tests;

use mle86\WQ\Job\AbstractJob;

require_once 'helper/SimpleJob.php';

function payload (AbstractJob $j) {
    if ($j instanceof SimpleJob) {
        return $j->getMarker();
    } else {
        throw new \UnexpectedValueException ("cannot get payload from job class " . get_class($j));
    }
}

class AbstractJobTest
    extends \PHPUnit_Framework_TestCase
{

    public function testInstance () {
        $marker = mt_rand(1000, 4000);

        $j = new SimpleJob ($marker);

        $this->assertInstanceOf(AbstractJob::class, $j);
        $this->assertSame($marker, $j->getMarker());

        $this->assertEquals(1, $j->jobTryIndex(),
            "New AbstractJob instance's jobTryIndex() value should be 1!");
        $this->assertFalse($j->jobCanRetry(),
            "AbstractJob::jobCanRetry() returned true for a newly-created MAX_RETRY=0 instance!");

        return $j;
    }

    /**
     * @depends testInstance
     * @param AbstractJob $j
     * @return string
     */
    public function testSerialization (AbstractJob $j) {
        $copy = clone $j;
        $s    = serialize($j);
        $this->assertTrue(is_string($s));

        $this->assertTrue(($copy == $j && $copy->jobTryIndex() == $j->jobTryIndex() && payload($copy) == payload($j)),
            "Serializing an AbstractJob instance changed it!");

        return $s;
    }

    /**
     * @depends testSerialization
     * @depends testInstance
     * @param string $s
     * @param AbstractJob $original
     * @param int $index The unserialization count.
     * @return AbstractJob
     */
    public function testUnserialization (string $s, AbstractJob $original, int $index = 1) {
        $j = unserialize($s);

        $this->assertInstanceOf(AbstractJob::class, $j,
            "After unserialization #{$index}: Unserialized job is not an AbstractJob anymore!");
        $this->assertSame(get_class($original), get_class($j),
            "After unserialization #{$index}: Unserialized job is still an AbstractJob, but is of a different class now!");

        // After the first unserialization, jobTryIndex() should return 1.
        $this->assertEquals($index, $j->jobTryIndex(),
            "After unserialization #{$index}: jobTryIndex() should be {$index}!");
        $this->assertEquals(payload($original), payload($j),
            "After unserialization #{$index}: the job did not have the correct payload anymore!");

        return $j;
    }

    /**
     * @depends testUnserialization
     * @depends testInstance
     * @param AbstractJob $j
     * @param AbstractJob $original
     */
    public function testRepeatedUnserialization (AbstractJob $j, AbstractJob $original) {
        $this->testUnserialization(
            $this->testSerialization($j),
            $original,
            2
        );
    }

}

