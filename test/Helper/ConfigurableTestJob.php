<?php

namespace mle86\WQ\Tests\Helper;

use mle86\WQ\Testing\SimpleTestJob;

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
class ConfigurableTestJob extends SimpleTestJob
{

    public static $log = [];
    public static $expired_marker = null;

    protected $marker = 0;
    protected $max_retries;
    protected $retry_delay;
    protected $succeed_on;

    public function __construct(int $marker, int $max_retries = 0, int $succeed_on = 0, int $retry_delay = 1)
    {
        parent::__construct($marker);

        $this->max_retries = $max_retries;
        $this->retry_delay = $retry_delay;
        $this->succeed_on  = $succeed_on;
    }

    public function withMaxRetries(int $maxRetries): self
    {
        $this->max_retries = $maxRetries;
        return $this;
    }

    public function withRetryDelay(int $retryDelay): self
    {
        $this->retry_delay = $retryDelay;
        return $this;
    }

    public function succeedOn(?int $nthTry): self
    {
        $this->succeed_on = $nthTry ?? 0;
        return $this;
    }


    public function jobCanRetry(): bool
    {
        return ($this->jobTryIndex() <= $this->max_retries);
    }

    public function jobRetryDelay(): int
    {
        return $this->retry_delay;
    }

    public function jobIsExpired(): bool
    {
        return ($this->marker === self::$expired_marker);
    }

    public function execute(): int
    {
        if ($this->jobTryIndex() === $this->succeed_on) {
            return parent::execute();
        } else {
            $succeed_on = ($this->succeed_on > 0)
                ? "will succeed on try #{$this->succeed_on}"
                : "will never succeed";

            throw new \RuntimeException("*** failed on try #{$this->jobTryIndex()} ({$succeed_on})");
        }
    }

}

