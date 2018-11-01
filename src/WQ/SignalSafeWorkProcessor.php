<?php

namespace mle86\WQ;

use mle86\WQ\WorkServerAdapter\WorkServerAdapter;

/**
 * {@inheritdoc}
 *
 * -
 *
 * This class is an extension of the original {@see WorkProcessor}.
 *
 * Key differences:
 * - It has the {@see installSignalHandler()} method.
 * - It has the {@see isAlive()} test method.
 * - It has the {@see lastSignal()} getter method.
 * - The {@see processNextJob()} method always calls {@see pcntl_signal_dispatch} before returning.
 *
 * All instances are *alive*
 * until one of the registered signals is sent to the process.
 * If your worker script has a loop around the {@see processNextJob()} call,
 * check {@see isAlive()} in the loop condition.
 */
class SignalSafeWorkProcessor extends WorkProcessor
{

    private static $alive      = true;
    private static $lastSignal = null;

    public function processNextJob($workQueue, callable $callback, int $timeout = WorkServerAdapter::DEFAULT_TIMEOUT): void
    {
        try {
            parent::processNextJob($workQueue, $callback, $timeout);
        } finally {
            pcntl_signal_dispatch();
        }
    }


    /**
     * Installs a signal handler that will clear the {@see isAlive()} flag.
     *
     * The registered signals will not immediately terminate the program anymore,
     * giving the job handler callback enough time to finish their execution.
     *
     * If {@see isAlive()} is false
     * after {@see processNextJob()} returns,
     * you should {@see exit} the program.
     *
     * @param int[] $signals  An array of signal numbers for which to install the signal handler.
     *                        By default, the signal handler is installed
     *                        for <tt>SIGTERM</tt> and <tt>SIGINT</tt>,
     *                        two signals commonly used to cleanly stop running processes.
     */
    public static function installSignalHandler(array $signals = [\SIGTERM, \SIGINT])
    {
        $lastSignal =& self::$lastSignal;
        $alive      =& self::$alive;

        $fnHandler = function (int $signo) use (&$lastSignal, &$alive) {
            $lastSignal = $signo;
            $alive      = false;
        };

        foreach ($signals as $signo) {
            pcntl_signal($signo, $fnHandler);
        }
    }

    /**
     * @return bool
     *   Returns TRUE as long as no registered signal was received.
     */
    public static function isAlive(): bool
    {
        return self::$alive;
    }

    /**
     * @return int|null
     *   Returns the number of the last signal received,
     *   or NULL if no signal has been received since the signal handler was set up.
     */
    public static function lastSignal(): ?int
    {
        return self::$lastSignal;
    }

}
