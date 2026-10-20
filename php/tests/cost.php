<?php
// Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
// One decode in a fresh process, for run.php: php cost.php SCHEMA < reply prints [rows or "refused", MB above the start, seconds].
// A fresh process has its own peak memory on every PHP version. memory_reset_peak_usage needs PHP 8.2.
declare(strict_types=1);

require __DIR__ . '/../src/Toklex.php';

$tx = Toklex\Toklex::fromFile($argv[1]);
$reply = (string) stream_get_contents(STDIN);
gc_collect_cycles();
[$m, $t] = [memory_get_usage(), microtime(true)];
try {
    $r = count($tx->decode($reply)['rows']);
} catch (InvalidArgumentException) {
    $r = 'refused';
}
echo json_encode([$r, (memory_get_peak_usage() - $m) / 1048576, microtime(true) - $t]);
