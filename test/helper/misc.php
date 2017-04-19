<?php
namespace mle86\WQ\Tests;

function wait_for_subsecond (float $required_remaining_subsecond = 0.3) {
	$mt = microtime(true);
	$subsecond = $mt - intval($mt);
	if ($subsecond > (1 - $required_remaining_subsecond)) {
		usleep(1000 * 1000 * (1.01 - $subsecond));
	}
}

function array_delete_one (array $input, $value_to_delete, bool $strict = true) {
	foreach ($input as $idx => $value) {
		if ($value === $value_to_delete || (!$strict && $value == $value_to_delete)) {
			unset($input[$idx]);
			break;  // only delete one
		}
	}
	return $input;
}
