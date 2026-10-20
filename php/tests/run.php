<?php
// Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
// Plain-PHP conformance runner. Run: php php/tests/run.php
declare(strict_types=1);

require __DIR__ . '/../src/Toklex.php';

use Toklex\Toklex;

$fx = __DIR__ . '/../../spec/fixtures';
$n = 0;
$fails = [];

/** Strict equality, except that two numbers are equal when they differ by less than 1e-9 (5 equals 5.0). */
function same(mixed $a, mixed $b): bool
{
    if (is_array($a) && is_array($b)) {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $k => $v) {
            if (!array_key_exists($k, $b) || !same($v, $b[$k])) {
                return false;
            }
        }
        return true;
    }
    return (is_int($a) || is_float($a)) && (is_int($b) || is_float($b)) ? abs($a - $b) < 1e-9 : $a === $b;
}

function check(string $name, mixed $expected, mixed $actual): void
{
    global $n, $fails;
    $n++;
    if (!same($expected, $actual)) {
        $fails[] = "FAIL $name\n  expected: " . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n  actual:   " . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

function throwsInvalid(callable $f): bool
{
    try {
        $f();
    } catch (\InvalidArgumentException) {
        return true;
    }
    return false;
}

// --- Task 2: schema loading, version, schema text, tools, modes, resources ---

foreach (glob("$fx/invalid/*.json") as $f) {
    check('invalid/' . basename($f) . ' is rejected', true, throwsInvalid(fn() => Toklex::fromFile($f)));
}
check('missing file is rejected', true, throwsInvalid(fn() => Toklex::fromFile("$fx/nope.json")));
$junk = tempnam(sys_get_temp_dir(), 'tx');
file_put_contents($junk, 'not json');
check('non-JSON file is rejected', true, throwsInvalid(fn() => Toklex::fromFile($junk)));
unlink($junk);

$tx = Toklex::fromFile("$fx/test.schema.json");
$v = $tx->version();
check('version matches hash.json', json_decode(file_get_contents("$fx/hash.json"), true)['version'], $v);

$crlf = tempnam(sys_get_temp_dir(), 'tx');
file_put_contents($crlf, str_replace("\n", "\r\n", str_replace("\r\n", "\n", file_get_contents("$fx/test.schema.json"))));
check('CRLF copy has the same version', $v, Toklex::fromFile($crlf)->version());
unlink($crlf);

$text = $tx->schemaText();
check('schemaText starts with the version line', true, str_starts_with($text, "Toklex schema tx@{$v}\n"));
$lines = explode("\n", $text);
foreach ([
    'Keys: id=station_id, n=name, l=wind_level, v=rain_m, u=snow_m, t=date, us=user, lg=login, lb=labels',
    'Value codes: l: l=LOW m=MEDIUM h=HIGH; e: nf=No station with that id.',
    'Scaled: v x10000, u x10000 (743 means 0.0743)',
    'Dense tools: station_history',
    'Instructions (they apply to every response marked i:+):',
    'rule: Use only the fields in this reply.',
] as $line) {
    check("schemaText has line: $line", true, in_array($line, $lines, true));
}
check('schemaText has the primer', true, str_contains($text, 'Toklex encodes tool results compactly.'));
check('schemaText primer keeps the pipe escape as six characters', true, str_contains($text, 'Inside it, \u007c stands for |.'));
check('schemaText order', true,
    array_search('Keys: id=station_id, n=name, l=wind_level, v=rain_m, u=snow_m, t=date, us=user, lg=login, lb=labels', $lines, true)
    < array_search('Instructions (they apply to every response marked i:+):', $lines, true));

$contract = "Toklex reply ~tx@{$v}. If this version is not in context, call toklex_schema first. Never guess a code.";
$tools = $tx->withTools([['name' => 'get_station', 'description' => 'Get a station.', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]]]);
check('withTools returns the tool plus the two Toklex tools', ['get_station', 'toklex_schema', 'toklex_calibrate'], array_column($tools, 'name'));
check('first description carries the contract', "Get a station.\n\n" . $contract, $tools[0]['description']);
check('first tool gets toklex_mode', ['type' => 'string', 'enum' => ['off', 'safe', 'dense'], 'description' => 'Reply format: off, safe or dense.'], $tools[0]['inputSchema']['properties']['toklex_mode']);
check('tool json keeps properties an object', true, str_contains(json_encode($tools[0]), '"properties":{"toklex_mode":'));
check('schema tool is read-only', true, $tools[1]['annotations']['readOnlyHint']);
check('schema tool annotations', ['title' => 'Toklex schema', 'readOnlyHint' => true, 'openWorldHint' => false], $tools[1]['annotations']);
check('schema tool title and name', ['toklex_schema', 'Toklex schema', Toklex::SCHEMA_TOOL], [$tools[1]['name'], $tools[1]['title'], 'toklex_schema']);
check('schema tool takes no arguments', '{"type":"object","properties":{}}', json_encode($tools[1]['inputSchema']));
check('schema tool has no toklex_mode', false, str_contains(json_encode($tools[1]), 'toklex_mode'));
check('calibrate tool is read-only with an optional answers object', [true, 'object', []],
    [$tools[2]['annotations']['readOnlyHint'], $tools[2]['inputSchema']['properties']['answers']['type'], $tools[2]['inputSchema']['required'] ?? []]);
check('calibrate tool has no toklex_mode', false, str_contains(json_encode($tools[2]), 'toklex_mode'));
check('tool without schema or description still gets both', true,
    str_contains(json_encode($tx->withTools([['name' => 'x']])[0]), '"toklex_mode"'));

$warnings = [];
$collect = function (string $msg) use (&$warnings) { $warnings[] = $msg; };
$tx->withTools([['name' => 'get_station', 'description' => 'Get a station.']], $collect);
check('short description raises no warning', 0, count($warnings));
$tx->withTools([['name' => 'big', 'description' => str_repeat('x', 2100)]], $collect);
check('2100-char description gives one warning to the callback', [true], array_map(fn($w) => str_contains($w, "tool 'big' is over 2048"), $warnings));
// without a callback a warning goes to error_log, never to stdout, which carries a stdio server's messages, and never through trigger_error
$log = tempnam(sys_get_temp_dir(), 'tx');
$prevLog = ini_set('error_log', $log);
$raised = [];
set_error_handler(function (int $no) use (&$raised) { $raised[] = $no; return true; });
ob_start();
$tx->withTools([['name' => 'big', 'description' => str_repeat('x', 2100)]]);
$stdout = ob_get_clean();
restore_error_handler();
ini_set('error_log', $prevLog);
check('the default warning goes to error_log only', ['', [], true], [$stdout, $raised, str_contains((string)file_get_contents($log), "tool 'big' is over 2048")]);
unlink($log);
$warnings = [];
$os = $tx->withTools([['name' => 'a', 'inputSchema' => ['type' => 'object'], 'outputSchema' => ['type' => 'object']]], $collect);
check('withTools removes outputSchema and says so', [false, 1], [isset($os[0]['outputSchema']), count($warnings)]);
$obj = $tx->withTools([json_decode('{"name":"x","description":"d","inputSchema":{"type":"object","properties":{"q":{"type":"string"}}}}')]);
check('a tool given as stdClass works like the array', json_encode($tx->withTools([json_decode('{"name":"x","description":"d","inputSchema":{"type":"object","properties":{"q":{"type":"string"}}}}', true)])), json_encode($obj));

foreach (json_decode(file_get_contents("$fx/modes/args.json"), true) as $i => $c) {
    $args = $c['args'];
    check("modes/args.json #$i mode", $c['expected_mode'], $tx->mode($c['tool'], $args));
    check("modes/args.json #$i args after", $c['expected_args_after'], $args);
}
$args = ['toklex_mode' => ['dense']];
check('non-string toklex_mode falls back to the default', 'safe', $tx->mode('get_station', $args));
check('non-string toklex_mode is still removed', [], $args);

check('resources lists the two URIs', ['toklex://modes', 'toklex://schema'], array_column($tx->resources(), 'uri'));
check('schema resource equals schemaText', $text, $tx->readResource('toklex://schema'));
$modes = $tx->readResource('toklex://modes');
check('modes resource names the three modes and the tools', true,
    is_string($modes) && str_contains($modes, 'toklex_mode') && str_contains($modes, 'toklex_calibrate')
    && str_contains($modes, 'off') && str_contains($modes, 'safe') && str_contains($modes, 'dense'));
check('modes resource has no em dash or semicolon', false, is_string($modes) && (str_contains($modes, "\u{2014}") || str_contains($modes, ';')));
check('unknown resource is null', null, $tx->readResource('toklex://nope'));
check('model-facing texts hold no CR, whatever the checkout line endings', [false, false, false],
    [str_contains($modes, "\r"), str_contains($tx->calibrate(null), "\r"), str_contains($text, "\r")]);

// --- Task 3: normal and off mode, errors, decode ---

check('modes resource says the two Toklex tools take no toklex_mode', true,
    str_contains($modes, 'The two Toklex tools, toklex_schema and toklex_calibrate, do not take it.'));

check('style of the coded test schema', 'coded', $tx->style());
check('style defaults to plain', ['plain', 'plain'], [Toklex::fromFile("$fx/plain.schema.json")->style(), Toklex::fromFile(__DIR__ . '/../../examples/stations.schema.json')->style()]);

$txs = [];
$caseFiles = glob("$fx/cases/*.json");
$specText = file_get_contents(__DIR__ . '/../../spec/toklex.md');
preg_match('/The folder `cases\/` holds (\d+) files: (\d+) coded cases .*? and (\d+) plain cases .*?The folder `invalid\/` holds (\d+) schemas\./s', $specText, $cnt);
$coded = count(array_filter($caseFiles, fn($f) => (int)basename($f) < 40));
check('the fixture folders hold what section 10 counts', [$cnt[1] ?? 0, $cnt[2] ?? 0, $cnt[3] ?? 0, $cnt[4] ?? 0],
    [(string)count($caseFiles), (string)$coded, (string)(count($caseFiles) - $coded), (string)count(glob("$fx/invalid/*.json"))]);
$readme = file_get_contents(__DIR__ . '/../../README.md');
check('the README counts the same fixtures', true, str_contains($readme, "It has {$cnt[1]} encode and decode cases ({$cnt[2]} coded and {$cnt[3]} plain), {$cnt[4]} schemas"));
foreach ($caseFiles as $f) {
    $name = basename($f, '.json');
    $c = json_decode(file_get_contents($f), true);
    $cx = $txs[$c['schema']] ??= Toklex::fromFile("$fx/{$c['schema']}");
    $cv = $cx->version();
    $exp = str_replace('{v}', $cv, $c['expected']);
    $got = isset($c['error'])
        ? $cx->error($c['error'], $c['mode'] ?? null, $c['instructions'] ?? true)
        : $cx->encode($c['tool'], $c['rows'], $c['meta'], $c['mode'] ?? null, $c['instructions'] ?? true);
    check("$name encode", $exp, $got);
    $d = $cx->decode($exp);
    check("$name decode schema", [json_decode(file_get_contents("$fx/{$c['schema']}"), true)['id'], $cv, $c['decoded_instructions'] ?? true], [$d['schema'], $d['version'], $d['instructions']]);
    if (isset($c['error'])) {
        check("$name decode meta", $c['decoded_meta'], $d['meta']);
        check("$name decode rows", [], $d['rows']);
        continue;
    }
    check("$name decode rows", $c['decoded'] ?? $c['rows'], $d['rows']);
    check("$name decode meta", $c['decoded_meta'] ?? (($c['mode'] ?? '') === 'off' ? $c['meta'] : array_filter($c['meta'], fn($x) => $x !== null)), $d['meta']);
}

$last = fn(array $row) => substr(strrchr($tx->encode('get_station', [$row]), "\n"), 1);
check('a very small and a very large float have no exponent', 'x|0.00000015|100000000000000000000', $last(['k' => 'x', 'a' => 1.5e-7, 'b' => 1e20]));
check('an invalid mode falls back to the tool default', $tx->encode('get_station', [['station_id' => 'a']]), $tx->encode('get_station', [['station_id' => 'a']], [], 'turbo'));

$noIns = tempnam(sys_get_temp_dir(), 'tx');
file_put_contents($noIns, '{"toklex":1,"id":"ni"}');
$ni = Toklex::fromFile($noIns);
unlink($noIns);
check('no instructions: no i:+ in the header, no instructions in off', ['~ni@' . $ni->version() . " a:1\nk\nx", '{"toklex":"off","schema":"ni@' . $ni->version() . '","meta":{},"rows":[]}', false],
    [$ni->encode('get_station', [['k' => 'x']], ['a' => 1]), $ni->encode('get_station', [], [], 'off'), $ni->decode($ni->error('boom'))['instructions']]);

// fix round 1: invalid UTF-8 never fails the encoder (spec 11), nested decode, objects, header whitespace
$bad = "a\xffb";
$hd = "~tx@$v i:+";
$rep = "a\u{FFFD}b";
check('invalid UTF-8 in an unquoted cell becomes U+FFFD', "$hd\nid\n$rep", $tx->encode('get_station', [['station_id' => $bad]]));
check('invalid UTF-8 in a quoted cell becomes U+FFFD', "$hd\nid\n\"$rep|x\"", str_replace('\u007c', '|', $tx->encode('get_station', [['station_id' => "a\xffb|x"]])));
check('invalid UTF-8 in an array string becomes U+FFFD', "$hd\nid|lb\nx|[\"$rep\"]", $tx->encode('get_station', [['station_id' => 'x', 'labels' => [$bad]]]));
check('invalid UTF-8 in a field name becomes U+FFFD', "$hd\na\u{FFFD}b\nx", $tx->encode('get_station', [[$bad => 'x']]));
check('invalid UTF-8 in a header value becomes U+FFFD', "~tx@$v i:+ k:$rep", $tx->encode('get_station', [], ['k' => $bad]));
check('invalid UTF-8 in an off-mode value becomes U+FFFD', true, str_contains($tx->encode('get_station', [['station_id' => $bad]], [], 'off'), "\"$rep\""));
check('invalid UTF-8 in an error message becomes U+FFFD', ["~tx@$v i:+ e:\"$rep\"", true], [$tx->error($bad), str_contains($tx->error($bad, 'off'), "\"$rep\"")]);
check('invalid UTF-8 round-trips as U+FFFD', [['station_id' => $rep]], $tx->decode($tx->encode('get_station', [['station_id' => $bad]]))['rows']);

$nest = [['station_id' => 'a', 'user' => ['login' => 'k']], ['station_id' => 'b', 'user' => null]];
$enc = $tx->encode('get_station', $nest);
check('a null parent cell keeps the nested object', ['login' => 'k'], $tx->decode($enc)['rows'][0]['user']);
check('nested then null parent decodes the nested value', "$hd\nid|us.lg|us\na|k\nb", $enc);
check('a scalar parent and a nested object decode without error', [['user' => 's'], ['user' => ['login' => 'k']]],
    $tx->decode($tx->encode('get_station', [['user' => 's'], ['user' => ['login' => 'k']]]))['rows']);

$obj = json_decode('[{"station_id":"a","user":{"login":"k"}},{"station_id":"b","user":{}}]');
check('a nested object (json_decode without assoc) expands like an array', "$hd\nid|us.lg|us\na|k\nb||[]", $tx->encode('get_station', $obj));
check('an object row encodes like the array row', $tx->encode('get_station', $nest), $tx->encode('get_station', json_decode(json_encode($nest))));

check('a header value with a tab, form feed or vertical tab is quoted and reads back whole', ["x\ty", "p\fq\vz"],
    array_values($tx->decode($tx->encode('get_station', [], ['name' => "x\ty", 'login' => "p\fq\vz"]))['meta']));

$tp = Toklex::fromFile("$fx/plain.schema.json");
check('instructionsText is verbatim', "rule: Use only the fields in this reply.\nnotice: Line one.\nLine two.", $tp->instructionsText());
check('plain meta key error is reserved', [true, true], [throwsInvalid(fn() => $tp->encode('get_station', [], ['error' => 'x'])), throwsInvalid(fn() => $tp->encode('get_station', [], ['error' => 'x'], 'off'))]);
check('plain meta keys i and e are ordinary', true, str_ends_with($tp->encode('get_station', [], ['e' => 'x']), "~tp@{$tp->version()} e:x"));
$dm = $tp->encode('get_station', [], ['x.rain_m' => 0.0743]);
check('a dotted meta key is not labelled scaled when its value is not', ["~tp@{$tp->version()} x.rain_m:0.0743", 0.0743],
    [substr($dm, strrpos($dm, "\n") + 1), $tp->decode($dm)['meta']['x.rain_m']]);
$mm = $tp->encode('get_station', [], ['stats' => ['rain_m' => 0.0743]]);
check('plain: a number under an object member of a meta map is written as it is and reads back whole', ["~tp@{$tp->version()} stats:\"{\\\"rain_m\\\":0.0743}\"", 0.0743],
    [substr($mm, strrpos($mm, "\n") + 1), json_decode($tp->decode($mm)['meta']['stats'], true)['rain_m']]);
check('plain: a decoded object member is not divided', [['station_id' => 'a', 'readings' => [['rain_m' => 0.0743]]]], $tp->decode($tp->encode('get_station', [['station_id' => 'a', 'readings' => [['rain_m' => 0.0743]]]]))['rows']);
check('plain decode of a reply with fewer lines than instruction blocks is refused', [true, true], [throwsInvalid(fn() => $tp->decode('rule: x')), throwsInvalid(fn() => $tp->decode(''))]);
check('a plain loader failure on a v0.1 name hints at style coded', true, (function () use ($fx) {
    try { Toklex::fromFile("$fx/invalid/plain-name-pipe.json"); } catch (\InvalidArgumentException $e) { return str_contains($e->getMessage(), '(add "style": "coded" to keep v0.1 names)') && !str_contains($e->getMessage(), "\n"); }
    return false;
})());
$I = "rule: Use only the fields in this reply.\nnotice: \"Line one.\\nLine two.\"\n";
check('a plain count line without the sentence still decodes', [['station_id' => 'a'], ['station_id' => 'b']],
    $tp->decode($I . "~tp@{$tp->version()}\n[2]\nstation_id:a|b")['rows']);

// --- Task 4: dense mode and encoder hardening ---

$dh = "~tx@$v i:+";
$dense = fn(array $rows) => $tx->encode('station_history', $rows);
$days = fn(array $d) => array_map(fn($x) => ['date' => $x], $d);
check('dates with a step of 7 are a sequence', "$dh\n[3]\nt:2026-07-01+7d", $dense($days(['2026-07-01', '2026-07-08', '2026-07-15'])));
check('a sequence crosses a leap day', "$dh\n[3]\nt:2028-02-28+1d", $dense($days(['2028-02-28', '2028-02-29', '2028-03-01'])));
check('a zero step is a run, not a sequence', "$dh\n[3]\nt:2026-07-01*3", $dense($days(['2026-07-01', '2026-07-01', '2026-07-01'])));
check('a negative step is listed', "$dh\n[3]\nt:2026-07-03|2026-07-02|2026-07-01", $dense($days(['2026-07-03', '2026-07-02', '2026-07-01'])));
check('an impossible date is not a sequence', "$dh\n[3]\nt:2026-02-27|2026-02-28|2026-02-29", $dense($days(['2026-02-27', '2026-02-28', '2026-02-29'])));
check('a null in a date column is not a sequence', "$dh\n[3]\nt:2026-07-01||2026-07-03",
    $dense($days(['2026-07-01', null, '2026-07-03'])));
check('a date sequence reads back as N dates', ['2026-07-01', '2026-07-08', '2026-07-15'], array_column($tx->decode("$dh\n[3]\nt:2026-07-01+7d")['rows'], 'date'));
check('a quoted date sequence text is a literal', [['date' => '2026-07-01+1d']], $tx->decode("$dh\n[1]\nt:\"2026-07-01+1d\"")['rows']);
check('a run of empty cells reads back as nulls', [['date' => '2026-07-01', 'snow_m' => null], ['date' => '2026-07-02', 'snow_m' => null]],
    $tx->decode("$dh\n[2]\nt:2026-07-01|2026-07-02\nu:*2")['rows']);
check('a run of 2 is written with the marker', "$dh\n[2]\nn:x*2", $dense([['name' => 'x'], ['name' => 'x']]));
check('a single cell has no marker', "$dh\n[1]\nn:x", $dense([['name' => 'x']]));
check('a dense reply with no records is the header alone', $dh, $dense([]));
check('records without any field are [N] alone and read back as N empty records', [$dh . "\n[2]", [[], []]],
    [$dense([[], []]), $tx->decode($dense([[], []]))['rows']]);
$nested = [['station_id' => 'a', 'user' => ['login' => 'k']], ['station_id' => 'b', 'user' => ['login' => 'k']]];
check('nested columns work in dense mode', ["$dh\n[2]\nid:a|b\nus.lg:k*2", $nested], [$e = $dense($nested), $tx->decode($e)['rows']]);
$pipe = [['name' => 'a|b'], ['name' => 'a|b']];
check('a pipe in a dense cell is escaped and the run reads back whole', [$dh . "\n[2]\nn:\"a\\u007cb\"*2", $pipe], [$e = $dense($pipe), $tx->decode($e)['rows']]);
$t0 = microtime(true);
$big = array_map(fn($i) => ['name' => 'x', 'wind_level' => 'h'], range(1, 40000));
check('a 40000-record dense reply decodes fast and whole', [40000, true], [count($tx->decode($dense($big))['rows']), microtime(true) - $t0 < 5]);
check('a run longer than the record count is cut at N', [['name' => 'x'], ['name' => 'x']], $tx->decode("$dh\n[2]\nn:x*9")['rows']);

// number output does not depend on php.ini, a non-finite number is null
$one = fn(mixed $x) => $tx->encode('get_station', [['k' => $x]]);
$prev = ini_set('serialize_precision', '17');
$p17 = [$one(0.1), $one([0.1]), $tx->encode('get_station', [['rain_m' => 0.0743]])];
ini_set('serialize_precision', $prev);
check('serialize_precision 17 in php.ini still gives 0.1', ["$hd\nk\n0.1", "$hd\nk\n[0.1]", "$hd\nv\n743"], $p17);
check('serialize_precision is restored after encoding', $prev, ini_get('serialize_precision'));
// the encoder must not need ini_set, a host may disable it
$ns = tempnam(sys_get_temp_dir(), 'tx');
file_put_contents($ns, '<?php require ' . var_export(realpath(__DIR__ . '/../src/Toklex.php'), true) . '; $t = Toklex\Toklex::fromFile(' . var_export(realpath("$fx/test.schema.json"), true) . '); echo $t->encode("get_station", [["k" => 0.1], ["k" => 1.5e-7], ["k" => 1.5e20]]);');
$noIni = shell_exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=ini_set -d serialize_precision=17 ' . escapeshellarg($ns));
unlink($ns);
check('encoding works with ini_set disabled and serialize_precision 17', "$hd\nk\n0.1\n0.00000015\n150000000000000000000", $noIni);
check('INF, -INF and NAN are empty cells', ["$hd\nk\n|", "$hd\nk\n|", "$hd\nk\n|"], [$one(INF), $one(-INF), $one(NAN)]);
check('INF, -INF and NAN are null in an array and in off mode', ["$hd\nk\n[null,null,null]", true],
    [$one([INF, -INF, NAN]), str_contains($tx->encode('get_station', [['k' => INF]], [], 'off'), '{"k":null}')]);
check('a non-finite header value is left out', "~tx@$v i:+", $tx->encode('get_station', [], ['k' => NAN]));

// header meta: arrays are quoted JSON strings, the keys i and e are reserved
check('an array meta value is a quoted JSON string', "~tx@$v i:+ k:\"[1,\\\"a b\\\"]\"", $tx->encode('get_station', [], ['k' => [1, 'a b']]));
check('a map meta value is a quoted JSON string', "~tx@$v i:+ k:\"{\\\"n\\\":\\\"x y\\\"}\"", $tx->encode('get_station', [], ['k' => ['name' => 'x y']]));
foreach (['i', 'e'] as $k) {
    check("meta key $k is reserved", true, throwsInvalid(fn() => $tx->encode('get_station', [], [$k => 'x'])));
}
check('meta keys i and e are rejected in off mode too', [true, true], [throwsInvalid(fn() => $tx->encode('get_station', [], ['i' => 'x'], 'off')), throwsInvalid(fn() => $tx->encode('get_station', [], ['e' => 'x'], 'off'))]);
check('a longer meta key is not reserved', "~tx@$v i:+ ie:x", $tx->encode('get_station', [], ['ie' => 'x']));

// --- Task 4b: calibration and schema hardening ---

$cal = $tx->calibrate(null);
check('calibrate is deterministic', $cal, $tx->calibrate(null));
check('calibrate does not depend on the server schema', $cal, Toklex::fromFile(__DIR__ . '/../../examples/stations.schema.json')->calibrate(null));
check('calibrate output has no em dash and no semicolon', false, str_contains($cal, "\u{2014}") || str_contains($cal, ';'));
check('the dataset has a safe block, a dense block and a legend', true, (bool)preg_match('/^Safe block\n(.*?)\n\nDense block\n(.*?)\n\nQuestions\n/ms', $cal, $blk));
check('the dense block is the real dense grammar with a date sequence and runs', "~cal@0000\n[8]\nn:Anna|Boris|Chen|Dara|Emil|Faye|Gus|Hana\nd:2026-03-02+1d\nt:l*2|m*3|h*2|l\np:612|655|701|688|744|790|823|917", $blk[2] ?? '');
check('the safe block has a header, columns and 8 records', ["~cal@0000", 'n|d|t|p', 'Anna|2026-03-02|l|612', 'Hana|2026-03-09|l|917', 10], [explode("\n", $blk[1])[0], explode("\n", $blk[1])[1], explode("\n", $blk[1])[2], explode("\n", $blk[1])[9], count(explode("\n", $blk[1]))]);
foreach (['Keys: n=name, d=day, t=tier, p=price', 'Value codes: t: l=LOW m=MEDIUM h=HIGH', 'Scaled: p x10000 (743 means 0.0743)'] as $line) {
    check("calibrate legend has line: $line", true, in_array($line, explode("\n", $cal), true));
}
foreach (['s1', 's2', 's3', 'd1', 'd2', 'd3'] as $id) {
    check("calibrate asks $id", true, (bool)preg_match("/^$id \((safe|dense) block\): /m", $cal));
}
check('calibrate tells the agent how to answer', true, str_contains($cal, 'call toklex_calibrate again with {"answers":{"s1":"<answer>"'));

// the key must match what the printed text says, read here by hand and without the library
$sl = array_slice(explode("\n", $blk[1]), 2);
$rec = array_map(fn($l) => explode('|', $l), $sl);
$byName = array_column($rec, null, 0);
$tier = ['l' => 'LOW', 'm' => 'MEDIUM', 'h' => 'HIGH'];
$dl = array_slice(explode("\n", $blk[2]), 2);
$cols = [];
foreach ($dl as $line) {
    [$k, $items] = explode(':', $line, 2);
    $cols[$k] = $items;
}
$tiers = [];
foreach (explode('|', $cols['t']) as $it) {
    [$c, $kk] = explode('*', $it) + [1 => 1];
    array_push($tiers, ...array_fill(0, (int)$kk, $tier[$c]));
}
[$d0] = explode('+', $cols['d']);
$dayOf = fn(int $i) => date('Y-m-d', strtotime("$d0 +$i days"));
$prices = explode('|', $cols['p']);
$right = [
    's1' => $byName['Dara'][1],
    's2' => sprintf('%.4f', $byName['Gus'][3] / 10000),
    's3' => (string)count(array_filter($rec, fn($r) => $tier[$r[2]] === 'LOW')),
    'd1' => $tiers[array_search('2026-03-06', array_map($dayOf, range(0, 7)), true)],
    'd2' => $dayOf(array_search('HIGH', $tiers, true)),
    'd3' => sprintf('%.4f', end($prices) / 10000),
];
check('the hand-read answers grade as dense', '{"recommended":"dense","results":{"s1":true,"s2":true,"s3":true,"d1":true,"d2":true,"d3":true}}', $tx->calibrate($right));
check('the hand-read answers are what the questions ask for', ['s1' => '2026-03-05', 's2' => '0.0823', 's3' => '3', 'd1' => 'MEDIUM', 'd2' => '2026-03-07', 'd3' => '0.0917'], $right);

$grade = fn(array $a) => $tx->calibrate($a);
check('all correct is dense, the reply shape is exact', '{"recommended":"dense","results":{"s1":true,"s2":true,"s3":true,"d1":true,"d2":true,"d3":true}}', $grade($right));
check('only the s answers correct is safe', '{"recommended":"safe","results":{"s1":true,"s2":true,"s3":true,"d1":false,"d2":false,"d3":false}}', $grade(array_slice($right, 0, 3)));
check('one wrong d answer is safe', 'safe', json_decode($grade(['d3' => 'x'] + $right), true)['recommended']);
check('one wrong s answer with all d right is dense', 'dense', json_decode($grade(['s2' => 'x'] + $right), true)['recommended']);
check('one wrong s and one wrong d is off', 'off', json_decode($grade(['s2' => 'x', 'd1' => 'x'] + $right), true)['recommended']);
check('all wrong is off', '{"recommended":"off","results":{"s1":false,"s2":false,"s3":false,"d1":false,"d2":false,"d3":false}}', $grade(['s1' => 'a', 's2' => 'b', 's3' => 'c', 'd1' => 'd', 'd2' => 'e', 'd3' => 'f']));
check('unknown ids are ignored', $grade($right), $grade($right + ['zz' => 'x', 's9' => '1']));
check('numbers and strings compare by decimal text', 'dense', json_decode($grade(['s2' => 0.0823, 's3' => 3, 'd3' => 0.0917] + $right), true)['recommended']);
check('an integral float is its integer text', true, json_decode($grade(['s3' => 3.0] + $right), true)['results']['s3']);
check('answers are trimmed', 'dense', json_decode($grade(['d1' => " MEDIUM\n", 's1' => "\t2026-03-05 "] + $right), true)['recommended']);
check('comparison is case-sensitive', false, json_decode($grade(['d1' => 'medium'] + $right), true)['results']['d1']);
check('a missing answer is wrong', false, json_decode($grade(array_diff_key($right, ['d2' => 1]) + ['s1' => $right['s1']]), true)['results']['d2']);
check('null, bool and array answers are wrong', [false, false, false], array_values(array_intersect_key(json_decode($grade(['s1' => null, 's2' => true, 's3' => [3]] + $right), true)['results'], array_flip(['s1', 's2', 's3']))));
check('the tool arguments object grades like the bare map', $grade($right), $grade(['answers' => $right]));
check('an arguments object with a wrong answer is graded', $grade(['d3' => 'x'] + $right), $grade(['answers' => ['d3' => 'x'] + $right]));
check('an arguments object with no answers returns the dataset', $cal, $tx->calibrate(['answers' => []]));
check('a list as answers counts as absent', $cal, $tx->calibrate(['a', 'b']));
check('an empty answers value counts as absent', $cal, $tx->calibrate([]));
check('answers given as a string, null or a number return the dataset', [$cal, $cal, $cal, $cal],
    [$tx->calibrate(['answers' => 'x']), $tx->calibrate(['answers' => null]), $tx->calibrate(['answers' => 5]), $tx->calibrate(['answers' => true])]);
check('an unrelated argument returns the dataset', [$cal, $cal], [$tx->calibrate(['toklex_mode' => 'safe']), $tx->calibrate(['answers' => ['zz' => 'x']])]);
check('answers given as a JSON string of an object are graded', [$grade($right), $grade(['d3' => 'x'] + $right)],
    [$tx->calibrate(['answers' => json_encode($right)]), $tx->calibrate(['answers' => json_encode(['d3' => 'x'] + $right)])]);
check('a JSON string of a list or of bad JSON returns the dataset', [$cal, $cal], [$tx->calibrate(['answers' => '["a"]']), $tx->calibrate(['answers' => '{"s1":'])]);
check('a stray argument next to answers does not stop the grading', $grade($right), $tx->calibrate(['answers' => $right, 'toklex_mode' => 'safe']));

// schema hardening
check('a key code with a dot is rejected for that reason', true, (function () use ($fx) {
    try { Toklex::fromFile("$fx/invalid/key-code-dot.json"); } catch (\InvalidArgumentException $e) { return str_contains($e->getMessage(), 'key code a.b'); }
    return false;
})());
check('a value code shaped like a date sequence is rejected for that reason', true, (function () use ($fx) {
    try { Toklex::fromFile("$fx/invalid/value-code-date-sequence.json"); } catch (\InvalidArgumentException $e) { return str_contains($e->getMessage(), 'date sequence'); }
    return false;
})());
$mem = memory_get_peak_usage();
check('a forged record count is rejected before anything is allocated', [true, true, true],
    [throwsInvalid(fn() => $tx->decode("$dh\n[4000000]")), throwsInvalid(fn() => $tx->decode("$dh\n[4000000]\nn:x*4000000")), throwsInvalid(fn() => $tx->decode("$dh\n[4000000]\nt:2026-01-01+1d"))]);
check('the forged count costs no memory', true, memory_get_peak_usage() - $mem < 8_000_000);
check('the record limit itself still decodes', 100000, count($tx->decode("$dh\n[100000]\nn:x*100000")['rows']));
check('one record over the limit is rejected', true, throwsInvalid(fn() => $tx->decode("$dh\n[100001]\nn:x")));
$mem = memory_get_peak_usage();
$many = $tx->decode("$dh\n[100000]\nn:" . implode('|', array_fill(0, 3000, 'x*100000')));
check('many max-length runs in one column stop at the record count', [100000, ['name' => 'x'], true], [count($many['rows']), ($many['rows'][99999] ?? []), memory_get_peak_usage() - $mem < 64_000_000]);
unset($many, $big);
check('runs past the record count are cut, not turned into extra records', 3, count($tx->decode("$dh\n[3]\nn:a*2|b*2|c")['rows']));
$mem = memory_get_peak_usage();
$wide = implode("\n", array_map(fn($i) => "c$i:x*100000", range(1, 200)));
check('a short reply that expands past the cell limit is refused before it is built', [true, true],
    [throwsInvalid(fn() => $tx->decode("$dh\n[100000]\n$wide")), memory_get_peak_usage() - $mem < 8_000_000]);

// review 2026-10-19, item 1: the cost of a reply bounds memory and time inside PHP's default memory_limit of 128M (spec 11)
$cost = function (string $reply) use ($fx): array { // [rows or 'refused', MB above the start, seconds], in a fresh process at 128M
    $p = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', __DIR__ . '/cost.php', "$fx/test.schema.json"], [['pipe', 'r'], ['pipe', 'w'], STDERR], $pipes);
    fwrite($pipes[0], $reply);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    proc_close($p);
    return json_decode($out, true) ?? ["no answer: $out", INF, INF];
};
$cols = fn(int $n, int $k, string $cell = 'x') => "$dh\n[$n]\n" . implode("\n", array_map(fn($i) => "c$i:$cell*$n", range(1, $k)));
[$r, $mb] = $cost($cols(100000, 5));
check('a reply at the cost limit decodes inside 80 MB', [100000, true], [$r, $mb < 80]);
check('one more column than the cost limit is refused', 'refused', $cost($cols(100000, 6))[0]);
[$r, $mb] = $cost("$dh\n[100000]\n" . implode('.', array_fill(0, 10, 'a')) . ':x*100000');
check('a nested column costs its depth, so 46 bytes of a.a.a... no longer take 400 MB', ['refused', true], [$r, $mb < 8]);
[$r, $mb] = $cost("$dh\n" . implode('.', array_fill(0, 1000, 'a')) . "\n" . implode("\n", array_fill(0, 2000, '1')));
check('a safe reply with a column path of 1000 names is refused at once', ['refused', true], [$r, $mb < 8]);
check('a column path of 64 names decodes and one of 65 is refused', [1, 'refused'],
    [$cost("$dh\n" . implode('.', array_fill(0, 64, 'a')) . "\n1")[0], $cost("$dh\n" . implode('.', array_fill(0, 65, 'a')) . "\n1")[0]]);
$safe = fn(int $n, int $k) => "$dh\n" . implode('|', array_map(fn($i) => "c$i", range(1, $k))) . "\n" . implode("\n", array_fill(0, $n, implode('|', array_fill(0, $k, '1'))));
check('safe mode has the same cost limit', [100000, 'refused'], [$cost($safe(100000, 5))[0], $cost($safe(100000, 6))[0]]);
[$r, $mb, $s] = $cost("$dh\n[10000]\na:[" . implode(',', range(1, 3000)) . ']*10000');
check('a run of a long JSON array is refused before it is decoded 10000 times', ['refused', true, true], [$r, $mb < 8, $s < 1]);
check('each [ or { in a JSON array cell costs 8', [7000, 'refused'],
    [$cost("$dh\n[7000]\na:" . str_repeat('[', 8) . str_repeat(']', 8) . '*7000')[0], $cost("$dh\n[8000]\na:" . str_repeat('[', 8) . str_repeat(']', 8) . '*8000')[0]]);
[$r, $mb] = $cost("$dh\n[100000]\n" . implode("\n", array_map(fn($i) => "d$i:2026-01-01+1d", range(1, 5))));
check('five date sequences of 100000 dates decode inside 96 MB', [100000, true], [$r, $mb < 96]);
check('a JSON array cell nested 64 levels is read, 65 levels stay text', [true, true],
    [is_array($tx->decode("$dh\nlb\n" . str_repeat('[', 64) . str_repeat(']', 64))['rows'][0]['labels']), is_string($tx->decode("$dh\nlb\n" . str_repeat('[', 65) . str_repeat(']', 65))['rows'][0]['labels'])]);

// review 2026-10-19, item 4: a forged date sequence is refused with InvalidArgumentException, and the calendar holds at year 0000
foreach (['2026-13-45+1d', '2026-02-30+1d', '2026-01-01+99999999999999999999d', '9999-12-30+1d'] as $seq) {
    check("the date sequence $seq is refused as a reply", true, throwsInvalid(fn() => $tx->decode("$dh\n[3]\nt:$seq")));
}
check('a date sequence that ends on 9999-12-31 reads back', ['9999-12-30', '9999-12-31'], array_column($tx->decode("$dh\n[2]\nt:9999-12-30+1d")['rows'], 'date'));
check('a leap day of year 0000 is a date sequence both ways', ["$dh\n[3]\nt:0000-02-27+1d", ['0000-02-27', '0000-02-28', '0000-02-29']],
    [$dense($days(['0000-02-27', '0000-02-28', '0000-02-29'])), array_column($tx->decode("$dh\n[3]\nt:0000-02-27+1d")['rows'], 'date')]);
check('a date sequence crosses from year 0000 into 0001', ['0000-12-31', '0001-01-01', '0001-01-02'], array_column($tx->decode("$dh\n[3]\nt:0000-12-31+1d")['rows'], 'date'));

// review 2026-10-19, item 12: no pattern takes \s or \d from the locale, where a single-byte locale counts NBSP as space
$src = file_get_contents(__DIR__ . '/../src/Toklex.php');
$loose = array_filter(explode("\n", $src), fn($line) => str_contains($line, "'/") && preg_match('/\\\\[sSdDwW]/', $line));
check('every pattern names its characters', [], array_values($loose));
check('a meta key with NBSP reads back whole', ["k\u{a0}x" => 'v'], $tp->decode($tp->encode('get_station', [], ["k\u{a0}x" => 'v']))['meta']);

// values that PHP keeps as objects travel as JSON would carry them (spec 11)
enum TxLevel: string { case High = 'HIGH'; }
enum TxPure { case North; }
final class TxPoint implements JsonSerializable { public function __construct(private int $x) {} public function jsonSerialize(): mixed { return ['x' => $this->x]; } }
final class TxRow { public string $id = 'a'; protected string $hidden = 'p'; private string $secret = 's'; }
$utc = new DateTimeZone('UTC');
check('an enum case is its value or its name', "$hd\nl|n\nh|North", $tx->encode('get_station', [['wind_level' => TxLevel::High, 'name' => TxPure::North]]));
check('a date-time at midnight is the date alone and stays a date sequence', "$dh\n[3]\nt:2026-07-01+1d",
    $tx->encode('station_history', array_map(fn($d) => ['date' => new DateTimeImmutable($d, $utc)], ['2026-07-01', '2026-07-02', '2026-07-03'])));
check('any other date-time is ISO 8601 with the offset, and a fraction only when there is one',
    "$hd\nk\n2026-07-01T10:30:00+02:00\n2026-07-01T10:30:00.250000+00:00",
    $tx->encode('get_station', [['k' => new DateTime('2026-07-01 10:30:00', new DateTimeZone('+02:00'))], ['k' => new DateTimeImmutable('2026-07-01 10:30:00.25', $utc)]]));
check('a JsonSerializable is what it returns, nested and as a record', ["$hd\nid|k.x\na|3", "$hd\nx\n5"],
    [$tx->encode('get_station', [['station_id' => 'a', 'k' => new TxPoint(3)]]), $tx->encode('get_station', [new TxPoint(5)])]);
check('an object keeps only its public properties', "$hd\nid\na", $tx->encode('get_station', [new TxRow()]));

// review 2026-10-19, item 11: no value loses data in silence or makes the encoder fail
final class TxText implements Stringable { public function __toString(): string { return 'as text'; } }
final class TxChain implements JsonSerializable { public function __construct(private int $n) {} public function jsonSerialize(): mixed { return $this->n ? new TxChain($this->n - 1) : 'end'; } }
check('an ArrayObject is its array, a Stringable its text and a closure null', "$hd\nao.k|ao.0|s|f\n1|2|as text",
    $tx->encode('get_station', [['ao' => new ArrayObject(['k' => 1, 2]), 's' => new TxText(), 'f' => fn() => 1]]));
check('a JsonSerializable chain of 8 steps is followed and a longer one is null', ["$hd\nc\nend", "$hd\nc\n|"],
    [$tx->encode('get_station', [['c' => new TxChain(7)]]), $tx->encode('get_station', [['c' => new TxChain(8)]])]);
$loop = new stdClass();
$loop->id = 'a';
$loop->me = $loop;
check('an object that holds itself is null where it repeats, in every mode', ["$hd\nid|me\na", true],
    [$tx->encode('get_station', [$loop]), str_contains($tx->encode('get_station', [$loop], [], 'off'), '"rows":[{"id":"a","me":null}]')]);
$deep = 'x';
for ($i = 0; $i < 70; $i++) {
    $deep = ['k' => $deep];
}
$col = explode("\n", $tx->encode('get_station', [['d' => $deep]]))[1];
check('a map nested past 64 levels stops there, and the reply reads back', [63, true],
    [substr_count($col, '.'), $tx->decode($tx->encode('get_station', [['d' => $deep]]))['rows'] !== []]);
$withNull = $tx->withTools([['name' => 'a', 'outputSchema' => null], ['description' => 'no name']], fn() => null);
check('withTools removes an outputSchema that is null and takes a tool without a name', [false, false], [array_key_exists('outputSchema', $withNull[0]), isset($withNull[1]['outputSchema'])]);
check('objects inside arrays, in the header and in off mode', ["$hd\nlb\n[\"HIGH\",\"2026-07-01\"]", "~tx@$v i:+ l:h", true],
    [$tx->encode('get_station', [['labels' => [TxLevel::High, new DateTimeImmutable('2026-07-01', $utc)]]]), $tx->encode('get_station', [], ['wind_level' => TxLevel::High]),
     str_contains($tx->encode('get_station', [['k' => TxLevel::High, 'p' => new TxPoint(1)]], [], 'off'), '"rows":[{"k":"HIGH","p":{"x":1}}]')]);

// a record that is not a record, a broken off reply, the instruction flag
foreach (['a string' => ['station_id' => 'a'], 'a number' => [1, 2], 'null' => [null], 'an enum' => [TxLevel::High]] as $what => $bad) {
    check("encode refuses $what as a record, in every mode", [true, true], [throwsInvalid(fn() => $tx->encode('get_station', $bad)), throwsInvalid(fn() => $tx->encode('get_station', $bad, [], 'off'))]);
}
check('the record error names the fix', true, (function () use ($tx) {
    try { $tx->encode('get_station', ['station_id' => 'a']); } catch (\InvalidArgumentException $e) { return str_contains($e->getMessage(), 'record 0 is string') && str_contains($e->getMessage(), '[$row]'); }
    return false;
})());
check('decode refuses an off reply that is not JSON or has no schema', [true, true, true],
    [throwsInvalid(fn() => $tx->decode('{not json')), throwsInvalid(fn() => $tx->decode('{"a":1}')), throwsInvalid(fn() => $tx->decode('{"schema":"x"}'))]);
check('the instruction flag leaves the text out of coded off replies and keeps i:+', ["~tx@$v i:+ e:nf", false],
    [$tx->error('nf', null, false), str_contains($tx->error('nf', 'off', false), 'instructions')]);
check('a plain error without instructions starts with the header and reads back', ["~tp@{$tp->version()} error:boom", false, ['error' => 'boom']],
    [$tp->error('boom', null, false), $tp->decode($tp->error('boom', null, false))['instructions'], $tp->decode($tp->error('boom', null, false))['meta']]);
check('an escaped name never splits into two names in any mode', [['a|b' => 'x', 'my key' => 'y'], ['k:x y' => 'z']],
    [$tp->decode($tp->encode('station_history', [['a|b' => 'x', 'my key' => 'y']]))['rows'][0], $tp->decode($tp->encode('get_station', [], ['k:x y' => 'z']))['meta']]);
check('auto with no records is the header alone', $tp->encode('get_station', []), $tp->encode('station_log', []));

$pt = $tp->withTools([['name' => 'get_station', 'description' => 'Get a station.', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]]]);
check('plain withTools adds no tools and no contract', [['get_station'], 'Get a station.'], [array_column($pt, 'name'), $pt[0]['description']]);
check('plain tool gets toklex_mode', $tools[0]['inputSchema']['properties']['toklex_mode'], $pt[0]['inputSchema']['properties']['toklex_mode']);
$w = [];
set_error_handler(function (int $no) use (&$w) { $w[] = $no; return true; });
$tp->withTools([['name' => 'big', 'description' => str_repeat('x', 2100)]]);
restore_error_handler();
check('plain raises no length warning, there is no contract line to cut', [], $w);
check('plain resources', [['toklex://modes'], null], [array_column($tp->resources(), 'uri'), $tp->readResource('toklex://schema')]);
check('plain modes text has no em dash or semicolon', false, str_contains($tp->readResource('toklex://modes'), "\u{2014}") || str_contains($tp->readResource('toklex://modes'), ';'));

// --- spec appendix A: every model-facing text, verbatim ---

$spec = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../../spec/toklex.md'));
preg_match_all('/^```\w*\n(.*?)\n```$/ms', substr($spec, (int)strpos($spec, "\n## Appendix A")), $app);
$app = array_map(fn($b) => str_replace('{v}', $v, $b), $app[1]);
$J = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
check('appendix A has ten blocks', 10, count($app));
check('appendix A.1 is the schema text', $text, $app[0] ?? null);
check('appendix A.2 is the tool list', json_encode($tools, $J), $app[1] ?? null);
check('appendix A.3 is the resource list', json_encode($tx->resources(), $J), $app[2] ?? null);
check('appendix A.3 is the modes text', $modes, $app[3] ?? null);
check('appendix A.4 is the calibration page', $cal, $app[4] ?? null);
check('appendix A.4 is the graded reply', $grade($right), $app[5] ?? null);
$c41 = json_decode(file_get_contents("$fx/cases/41-plain-dense.json"), true);
preg_match('/^\[5\] .*$/m', $tp->encode($c41['tool'], $c41['rows'], $c41['meta']), $cnt);
check('appendix A.5 is the plain tool list', json_encode($pt, $J), $app[6] ?? null);
check('appendix A.5 is the plain resource list', json_encode($tp->resources(), $J), $app[7] ?? null);
check('appendix A.5 is the plain modes text', $tp->readResource('toklex://modes'), $app[8] ?? null);
check('appendix A.5 is the plain dense count line', $cnt[0] ?? null, $app[9] ?? null);
check('section 9 holds the contract line', true, str_contains($spec, "```\n" . str_replace("~tx@$v", '~<id>@<version>', $contract) . "\n```"));

if ($fails) {
    echo implode("\n", $fails), "\n", count($fails), " of $n checks failed\n";
    exit(1);
}
echo "OK $n checks\n";
