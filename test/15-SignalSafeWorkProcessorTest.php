<?php

namespace mle86\WQ\Tests;

use mle86\WQ\WorkProcessor;
use mle86\WQ\WorkServerAdapter\MemoryWorkServer;
use mle86\WQ\WorkServerAdapter\WorkServerAdapter;
use mle86\WQ\SignalSafeWorkProcessor;
use PHPUnit\Framework\TestCase;

function sswp(): SignalSafeWorkProcessor
{
    $wsa = new MemoryWorkServer();
    $wp  = new SignalSafeWorkProcessor($wsa);
    return $wp;
}

class NoSignalHandlerException extends \RuntimeException
{
}

class SignalSafeWorkProcessorTest extends TestCase
{

    public static function registeredSignals(): array { return [
        [\SIGTERM],
        [\SIGINT],
    ]; }

    protected static function signame(int $signo): string
    {
        return (([
            \SIGTERM => "SIGTERM",
            \SIGINT  => "SIGINT",
            \SIGUSR1 => "SIGUSR1",
            \SIGUSR2 => "SIGUSR2",
            \SIGHUP  => "SIGHUP",
        ])[$signo]) ?? "SIGNAL {$signo}";
    }


    protected static function installFallbackSignalHandlers(): void
    {
        foreach (self::registeredSignals() as $signo) {
            $signo   = $signo[0];
            $signame = self::signame($signo);
            pcntl_signal($signo, function() use($signame) {
                throw new NoSignalHandlerException("SSWP did not install {$signame} a handler!");
            });
        }
    }

    protected static function restoreOriginalSignalHandlers(): void
    {
        foreach (self::registeredSignals() as $signo) {
            $signo = $signo[0];
            pcntl_signal($signo, \SIG_DFL);
        }
    }


    public static function setUpBeforeClass()
    {
        self::installFallbackSignalHandlers();
    }

    public static function tearDownAfterClass()
    {
        self::restoreOriginalSignalHandlers();
    }


    public function testInstance(): WorkProcessor
    {
        return sswp();
    }

    /**
     * Creating a new instance should not yet set up any signal handlers.
     *
     * @depends      testInstance
     * @dataProvider registeredSignals
     */
    public function testUninitializedInstance(int $signo)
    {
        $wp = sswp();

        $signame = self::signame($signo);
        $e = null;
        try {
            posix_kill(getmypid(), $signo);
            pcntl_signal_dispatch();
        } catch (NoSignalHandlerException $e) {
            // ok, continue
        }

        $this->assertInstanceOf(NoSignalHandlerException::class, $e,
            "The SignalSafeWorkProcessor constructor has set up a {$signame} handler by itself!");

        unset($wp);
    }

    /**
     * @depends testInstance
     * @depends testUninitializedInstance
     */
    public function testInitializedInstance()
    {
        $wp = sswp();
        $this->assertTrue($wp->isAlive(),
            "New SignalSafeWorkProcessor's isAlive() test was false!");

        $wp->installSignalHandler();
        $this->assertTrue($wp->isAlive(),
            "New SignalSafeWorkProcessor's isAlive() test was false right after calling installSignalHandler()!");

        return $wp;
    }

    /**
     * @dataProvider registeredSignals
     * @depends      testInitializedInstance
     */
    public function testIPC(int $signo, SignalSafeWorkProcessor $wp)
    {
        $signame = self::signame($signo);

        posix_kill(getmypid(), $signo);

        $fn_job = function() {
            throw new \RuntimeException("Empty queue, but the job handler got called anyway!");
        };
        $wp->processNextJob("test-queue", $fn_job, WorkServerAdapter::NOBLOCK);  // this should call pcntl_signal_dispatch.

        $this->assertFalse($wp->isAlive(),
            "After receiving a {$signame}, isAlive() is still true!");
        $this->assertEquals($signo, $wp->lastSignal(),
            "After receiving a {$signame} and correctly clearing isAlive(), lastSignal() did not return the correct signal number!");
    }

}
