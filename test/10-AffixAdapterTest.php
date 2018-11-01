<?php

namespace mle86\WQ\Tests;

use mle86\WQ\Job\QueueEntry;
use mle86\WQ\WorkServerAdapter\AffixAdapter;
use mle86\WQ\WorkServerAdapter\MemoryWorkServer;
use mle86\WQ\WorkServerAdapter\WorkServerAdapter;
use PHPUnit\Framework\TestCase;
use PHPUnit_Framework_MockObject_MockObject;

require_once __DIR__ . '/helper/SimpleJob.php';

class AffixAdapterTest extends TestCase
{

    /** @return WorkServerAdapter|PHPUnit_Framework_MockObject_MockObject */
    private function mockws(): WorkServerAdapter
    {
        return $this->createMock(MemoryWorkServer::class);
    }

    public function testBuildInstance(): AffixAdapter
    {
        return new AffixAdapter($this->mockws());
    }

    /**
     * @depends testBuildInstance
     */
    public function testPrefix(): void
    {
        $this->checkAffix([ /* don't call withPrefix() */ ]);
        $this->checkAffix(['prefix' => null]);
        $this->checkAffix(['prefix' => ""]);
        $this->checkAffix(['prefix' => "zzz"]);
        $this->checkAffix(['prefix' => "MYAPPNAME-"]);
    }

    /**
     * @depends testBuildInstance
     */
    public function testSuffix(): void
    {
        $this->checkAffix([ /* don't call withSuffix() */ ]);
        $this->checkAffix(['suffix' => null]);
        $this->checkAffix(['suffix' => ""]);
        $this->checkAffix(['suffix' => "zzz"]);
        $this->checkAffix(['suffix' => "-DEV"]);
    }

    /**
     * @depends testPrefix
     * @depends testSuffix
     */
    public function testBoth(): void
    {
        $this->checkAffix(['prefix' => "MYAPPNAME-", 'suffix' => "-TEST"]);
    }


    private function checkAffix(array $options): void
    {
        $this->checkAffixForSingleGet($options);
        $this->checkAffixForMultiGet($options);
        $this->checkAffixForStoreJob($options);
        $this->checkAffixForRequeueDefault($options);
        $this->checkAffixForRequeueOther($options);
    }

    private function checkAffixForSingleGet(array $options): void
    {
        $ws            = $this->mockws();
        $aa            = $this->prepareAdapter($ws, $options);
        $queue         = "foo";
        $correct_queue = ($options['prefix'] ?? '') . $queue . ($options['suffix'] ?? '');

        $ws->expects($this->once())
            ->method('getNextQueueEntry')
            ->with($this->callback(function($q) use($correct_queue): bool {
                // Should pass the correct queue name to the actual wsa.
                // String or array doesn't matter.
                return ($q === $correct_queue || $q === [$correct_queue]);
            }));

        $aa->getNextQueueEntry($queue);
    }

    private function checkAffixForMultiGet(array $options): void
    {
        $ws         = $this->mockws();
        $aa         = $this->prepareAdapter($ws, $options);
        $q1         = "bar";
        $q2         = "q897676456";
        $correct_q1 = ($options['prefix'] ?? '') . $q1 . ($options['suffix'] ?? '');
        $correct_q2 = ($options['prefix'] ?? '') . $q2 . ($options['suffix'] ?? '');

        $ws->expects($this->once())
            ->method('getNextQueueEntry')
            ->with([$correct_q1, $correct_q2]);
            // Should pass the two correct queue names in the original order to the actual wsa.

        $aa->getNextQueueEntry([$q1, $q2]);
    }

    private function checkAffixForStoreJob(array $options): void
    {
        $ws            = $this->mockws();
        $aa            = $this->prepareAdapter($ws, $options);
        $queue         = "q001923034";
        $correct_queue = ($options['prefix'] ?? '') . $queue . ($options['suffix'] ?? '');

        $ws->expects($this->once())
            ->method('storeJob')
            ->with($correct_queue);

        $aa->storeJob($queue, new SimpleJob(111));
    }

    private function checkAffixForRequeueDefault(array $options): void
    {
        $ws = $this->mockws();
        $aa = $this->prepareAdapter($ws, $options);
        $qe = new QueueEntry(new SimpleJob(222), "q000000000", null);

        $ws->expects($this->once())
            ->method('requeueEntry')
            ->with($qe, 0, null);
            // Null queue name argument should not be changed.

        $aa->requeueEntry($qe, 0, null);
    }

    private function checkAffixForRequeueOther(array $options): void
    {
        $ws            = $this->mockws();
        $aa            = $this->prepareAdapter($ws, $options);
        $queue         = "q004505026";
        $correct_queue = ($options['prefix'] ?? '') . $queue . ($options['suffix'] ?? '');
        $qe            = new QueueEntry(new SimpleJob(333), $correct_queue, null);

        $ws->expects($this->once())
            ->method('requeueEntry')
            ->with($qe, 0, $correct_queue);
            // Explicit requeue target queue should be affixed.

        $aa->requeueEntry($qe, 0, $queue);
    }

    private function prepareAdapter(WorkServerAdapter $server, array $options): AffixAdapter
    {
        $aa = new AffixAdapter($server);

        // The "$aa = $aa->mutator()" style works for real mutator methods
        // as well as for factory methods.

        if (array_key_exists('prefix', $options)) {
            $aa = $aa->withPrefix($options['prefix']);
        }
        if (array_key_exists('suffix', $options)) {
            $aa = $aa->withSuffix($options['suffix']);
        }

        return $aa;
    }

}
