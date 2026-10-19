<?php
// Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
// Reads a JSON list of cases on stdin and prints [reply, decoded rows] for each, or ["ERR", ""] when the library refuses.
declare(strict_types=1);

require __DIR__ . '/../../php/src/Toklex.php';

$fx = __DIR__ . '/../../spec/fixtures/';
$tx = [];
$out = [];
foreach (json_decode(stream_get_contents(STDIN), true) as $c) {
    $t = $tx[$c['schema']] ??= Toklex\Toklex::fromFile($fx . $c['schema']);
    try {
        $r = $t->encode($c['tool'], $c['rows'], $c['meta'], $c['mode']);
        $out[] = [$r, $t->decode($r)['rows']];
    } catch (\InvalidArgumentException) {
        $out[] = ['ERR', ''];
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
