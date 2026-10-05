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
set_error_handler(function (int $no, string $msg) use (&$warnings) { $warnings[] = [$no, $msg]; return true; });
$tx->withTools([['name' => 'get_station', 'description' => 'Get a station.']]);
check('short description raises no warning', 0, count($warnings));
$tx->withTools([['name' => 'big', 'description' => str_repeat('x', 2100)]]);
restore_error_handler();
check('2100-char description raises one E_USER_WARNING', [E_USER_WARNING], array_column($warnings, 0));

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

$caseFiles = glob("$fx/cases/*.json");
check('the case glob finds every fixture', true, count($caseFiles) >= 30);
foreach ($caseFiles as $f) {
    $name = basename($f, '.json');
    $c = json_decode(file_get_contents($f), true);
    $exp = str_replace('{v}', $v, $c['expected']);
    $got = isset($c['error'])
        ? $tx->error($c['error'], $c['mode'] ?? null)
        : $tx->encode($c['tool'], $c['rows'], $c['meta'], $c['mode'] ?? null);
    check("$name encode", $exp, $got);
    $d = $tx->decode($exp);
    check("$name decode schema", ['tx', $v, true], [$d['schema'], $d['version'], $d['instructions']]);
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
check('runs past the record count are cut, not turned into extra records', 3, count($tx->decode("$dh\n[3]\nn:a*2|b*2|c")['rows']));

// --- spec appendix A: every model-facing text, verbatim ---

$spec = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../../spec/toklex.md'));
preg_match_all('/^```\w*\n(.*?)\n```$/ms', substr($spec, (int)strpos($spec, "\n## Appendix A")), $app);
$app = array_map(fn($b) => str_replace('{v}', $v, $b), $app[1]);
$J = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
check('appendix A has six blocks', 6, count($app));
check('appendix A.1 is the schema text', $text, $app[0] ?? null);
check('appendix A.2 is the tool list', json_encode($tools, $J), $app[1] ?? null);
check('appendix A.3 is the resource list', json_encode($tx->resources(), $J), $app[2] ?? null);
check('appendix A.3 is the modes text', $modes, $app[3] ?? null);
check('appendix A.4 is the calibration page', $cal, $app[4] ?? null);
check('appendix A.4 is the graded reply', $grade($right), $app[5] ?? null);
check('section 9 holds the contract line', true, str_contains($spec, "```\n" . str_replace("~tx@$v", '~<id>@<version>', $contract) . "\n```"));

if ($fails) {
    echo implode("\n", $fails), "\n", count($fails), " of $n checks failed\n";
    exit(1);
}
echo "OK $n checks\n";
