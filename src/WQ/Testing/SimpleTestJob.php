<?php

namespace mle86\WQ\Testing;

use mle86\WQ\Job\AbstractJob;

/**
 * This is a simple extension of the {@see AbstractJob} base class.
 *
 * Jobs of this type explicitly cannot be re-tried (<tt>{@see MAX_RETRY}=0</tt>)
 * and carry an integer payload ("marker") in the range 1..9999.
 *
 * Actions like construction and execution are logged in the public class property {@see $log}.
 * Apart from that, {@see execute()} does nothing.
 *
 * @internal This is part of the unit tests.
 */
class SimpleTestJob extends AbstractJob
{

    public const MAX_RETRY = 0;  // explicit
    public const EXECUTE_RETURN_VALUE = 12;

    public static $log = [];

    protected $marker = 0;

    public function __construct(int $marker)
    {
        if (!(is_int($marker) && $marker >= 1 && $marker <= 9999)) {
            throw new \InvalidArgumentException('$marker must be int between 1..9999');
        }

        $this->marker = $marker;

        self::$log[] = "CONSTRUCT-{$this->getMarker()}";
    }

    public function getMarker(): int
    {
        return $this->marker;
    }

    public function execute(): int
    {
        self::$log[] = "EXECUTE-{$this->getMarker()}";
        return self::EXECUTE_RETURN_VALUE;
    }

}
