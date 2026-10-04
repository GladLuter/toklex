<?php
// Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
// Usage: php encode.php <schema.json> <outdir>. Reads out/data/*.json, writes per tool the pretty-JSON
// baseline (.current), safe (.toklex), .dense and .off replies, and schema.txt.
declare(strict_types=1);

require __DIR__ . '/../php/src/Toklex.php';

[, $schema, $out] = $argv + [null, null, null];
$schema && $out || exit("usage: php encode.php <schema.json> <outdir>\n");
$t = Toklex\Toklex::fromFile($schema);
$ins = json_decode(file_get_contents($schema), true)['instructions'] ?? [];
@mkdir($out, 0777, true);
file_put_contents("$out/schema.txt", $t->schemaText());
foreach (glob(__DIR__ . '/out/data/*.json') as $f) {
    $d = json_decode(file_get_contents($f), true);
    $tool = $d['tool'];
    $meta = ['date' => $d['date']];
    file_put_contents("$out/$tool.current.txt", json_encode(
        ['tool' => $tool, 'date' => $d['date'], 'rule' => $ins['rule'] ?? '', 'notice' => $ins['notice'] ?? '', 'results' => $d['rows']],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ));
    foreach (['toklex' => 'safe', 'dense' => 'dense', 'off' => 'off'] as $ext => $mode) {
        file_put_contents("$out/$tool.$ext.txt", $t->encode($tool, $d['rows'], $meta, $mode));
    }
}
