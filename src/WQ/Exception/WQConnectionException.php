<?php

namespace mle86\WQ\Exception;

use mle86\WQ\WorkServerAdapter\WorkServerAdapter;

/**
 * Thrown by {@see WorkServerAdapter} implementations
 * if there is a connection problem
 * (e.g. lost connection or unable to connect).
 */
interface WQConnectionException extends WQException
{
}
