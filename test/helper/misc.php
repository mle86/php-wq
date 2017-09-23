<?php
namespace mle86\WQ\Tests;

function wait_for_subsecond(float $required_remaining_subsecond = 0.3)
{
    $mt        = microtime(true);
    $subsecond = $mt - (int)$mt;
    if ($subsecond > (1 - $required_remaining_subsecond)) {
        usleep(1000 * 1000 * (1.01 - $subsecond));
    }
}

function array_delete_one(array $input, $value_to_delete, bool $strict = true)
{
    foreach ($input as $idx => $value) {
        if ($value === $value_to_delete || (!$strict && $value == $value_to_delete)) {
            unset($input[ $idx ]);
            break;  // only delete one
        }
    }
    return $input;
}

global $_xsj_called;
$_xsj_called = false;

/**
 * @return bool
 *   Returns true once after {@see xsj()} has been called at least once.
 */
function xsj_called(): bool
{
    global $_xsj_called;
    if ($_xsj_called) {
        $_xsj_called = false;
        return true;
    } else {
        return false;
    }
}

/**
 * This function executes any {@see SimpleJob}'s built-in {@see execute()} method,
 * returning its return value.
 * It's only here to shorten our test {@see WorkProcessor::processNextJob()} calls.
 *
 * @param SimpleJob $job
 */
function xsj(SimpleJob $job)
{
    global $_xsj_called;
    $_xsj_called = true;
    $job->execute();
}
