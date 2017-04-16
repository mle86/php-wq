<?php
namespace mle86\WQ\Tests;

function wait_for_subsecond (float $required_remaining_subsecond = 0.3) {
	$mt = microtime(true);
	$subsecond = $mt - intval($mt);
	if ($subsecond > (1 - $required_remaining_subsecond)) {
		usleep(1000 * 1000 * (1.01 - $subsecond));
	}
}

