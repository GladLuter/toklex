<?php
// Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
// Licensed under Apache-2.0. See LICENSE.
declare(strict_types=1);

namespace Toklex;

final class Toklex
{
    public const SCHEMA_TOOL = 'toklex_schema';
    public const CALIBRATE_TOOL = 'toklex_calibrate';

    private const MODES = ['off', 'safe', 'dense'];
    private const JF = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE;
    private const NUM = '/^-?(0|[1-9]\d*)(\.\d+)?([eE][+-]?\d+)?$/D';
    private const BAR = '\\' . 'u007c';

    private const PRIMER = <<<'TXT'
Toklex encodes tool results compactly. Line 1 is the header: ~<schema>@<version>, then i:+ when the instructions below apply, then key:value metadata (e: is an error message). Normal mode: line 2 lists the columns and every following line is one record, cells separated by |. An empty cell is null and trailing empty cells are left out. A cell in double quotes is a literal JSON string, never a code. Inside it, \u007c stands for |. A cell starting with [ is a JSON array that uses the same keys and codes. A dotted column such as us.lg is a nested field. Dense mode: line 2 is [N], the record count, and every following line is key:values for one column in record order, separated by |. x*K means x repeated K times. A value like 2026-07-03+1d means that date for the first record and one more day for each next record. Read codes only from this schema and never guess one. If a response header shows a different version, call toklex_schema again.
TXT;

    private const MODES_TEXT = <<<'TXT'
Toklex modes
A server that speaks Toklex can answer a tool call in one of three modes.
off: compact JSON with full field names and the full text of the instructions. No codes and no scales.
safe: the normal table. Line 1 is the header, line 2 lists the columns and each following line is one record. This is the default for every tool that the schema does not list as dense.
dense: line 2 is [N], the record count, and each following line holds one column. This is the default for the tools the schema lists as dense.
Pick a mode for one call with the optional argument toklex_mode on a tool of the server. The two Toklex tools, toklex_schema and toklex_calibrate, do not take it. The values are off, safe and dense. If you leave it out or send another value, the tool's default mode applies. The instructions of the server travel in every mode.
Dense is the shortest form. Some models misread it. To learn which mode you read reliably, call toklex_calibrate without arguments. It returns a small dataset in both forms and six questions. Answer them from the dataset alone, then call toklex_calibrate again with answers, an object that maps each question id to your answer. The reply names the mode to use. Use it with toklex_mode on later calls.
TXT;

    private const MAX_RECORDS = 100000;

    // The calibration dataset and its key. They belong to the library and never depend on a server schema.
    private const CAL_SCHEMA = ['toklex' => 1, 'id' => 'cal', 'keys' => ['n' => 'name', 'd' => 'day', 't' => 'tier', 'p' => 'price'],
        'values' => ['t' => ['l' => 'LOW', 'm' => 'MEDIUM', 'h' => 'HIGH']], 'scale' => ['p' => 10000]];
    private const CAL_ROWS = [
        ['name' => 'Anna', 'day' => '2026-03-02', 'tier' => 'LOW', 'price' => 0.0612],
        ['name' => 'Boris', 'day' => '2026-03-03', 'tier' => 'LOW', 'price' => 0.0655],
        ['name' => 'Chen', 'day' => '2026-03-04', 'tier' => 'MEDIUM', 'price' => 0.0701],
        ['name' => 'Dara', 'day' => '2026-03-05', 'tier' => 'MEDIUM', 'price' => 0.0688],
        ['name' => 'Emil', 'day' => '2026-03-06', 'tier' => 'MEDIUM', 'price' => 0.0744],
        ['name' => 'Faye', 'day' => '2026-03-07', 'tier' => 'HIGH', 'price' => 0.0790],
        ['name' => 'Gus', 'day' => '2026-03-08', 'tier' => 'HIGH', 'price' => 0.0823],
        ['name' => 'Hana', 'day' => '2026-03-09', 'tier' => 'LOW', 'price' => 0.0917],
    ];
    private const CAL_QUESTIONS = [
        's1' => ['safe', 'On which day is the record named Dara? Write it as YYYY-MM-DD.', '2026-03-05'],
        's2' => ['safe', 'What is the price of the record named Gus? Write the decimal number with four decimals.', '0.0823'],
        's3' => ['safe', 'How many records have the tier LOW? Write a number.', '3'],
        'd1' => ['dense', 'What is the tier of the record dated 2026-03-06? Write the full word.', 'MEDIUM'],
        'd2' => ['dense', 'On which day does the first record with the tier HIGH fall? Write it as YYYY-MM-DD.', '2026-03-07'],
        'd3' => ['dense', 'What is the price of the last record? Write the decimal number with four decimals.', '0.0917'],
    ];
    private const CAL_HOWTO = <<<'TXT'
Safe block: line 1 is the header, line 2 lists the columns and each next line is one record, with cells separated by |.
Dense block: line 2 is [8], the record count, and each next line is key:values, one column for all 8 records in record order.
z*3 means z three times. 2026-07-03+1d means 2026-07-03 for the first record and one more day for each next record.
Read every code through the legend. A scaled number is divided by its scale.
TXT;

    private array $kf;
    private array $vf;

    private function __construct(private array $s, private string $v)
    {
        $this->kf = array_flip($s['keys'] ?? []);
        $this->vf = array_map('array_flip', $s['values'] ?? []);
    }

    /** @throws \InvalidArgumentException when the file is unreadable, not JSON or breaks a load rule */
    public static function fromFile(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \InvalidArgumentException("Toklex schema: cannot read $path");
        }
        $s = json_decode($raw, true);
        if (!is_array($s)) {
            throw new \InvalidArgumentException("Toklex schema: $path is not a JSON object");
        }
        self::check($s);
        return new self($s, substr(hash('sha256', str_replace("\r\n", "\n", $raw)), 0, 4));
    }

    public function version(): string
    {
        return $this->v;
    }

    public function schemaText(): string
    {
        $s = $this->s;
        $out = ["Toklex schema {$s['id']}@{$this->v}", self::PRIMER];
        if ($k = $s['keys'] ?? []) {
            $out[] = 'Keys: ' . implode(', ', array_map(fn($c, $f) => "$c=$f", array_keys($k), $k));
        }
        if ($vals = $s['values'] ?? []) {
            $out[] = 'Value codes: ' . implode('; ', array_map(
                fn($f, $m) => "$f: " . implode(' ', array_map(fn($c, $full) => "$c=$full", array_keys($m), $m)),
                array_keys($vals),
                $vals
            ));
        }
        if ($sc = $s['scale'] ?? []) {
            $f = reset($sc);
            $ex = rtrim(rtrim(number_format(743 / $f, 12, '.', ''), '0'), '.');
            $out[] = 'Scaled: ' . implode(', ', array_map(fn($c, $m) => "$c x$m", array_keys($sc), $sc)) . " (743 means $ex)";
        }
        if ($d = $s['dense'] ?? []) {
            $out[] = 'Dense tools: ' . implode(', ', $d);
        }
        if ($ins = $s['instructions'] ?? []) {
            $out[] = 'Instructions (they apply to every response marked i:+):';
            foreach ($ins as $name => $body) {
                $out[] = "$name: $body";
            }
        }
        return implode("\n", $out);
    }

    /** Adds the contract line and toklex_mode to every tool and appends the two Toklex tools. */
    public function withTools(array $tools): array
    {
        $contract = "Returns Toklex ~{$this->s['id']}@{$this->v}. If you do not have this schema version in context, "
            . 'call toklex_schema first. Never guess a code. Optional toklex_mode: off, safe or dense. '
            . 'Call toklex_calibrate once to learn which mode you read reliably.';
        foreach ($tools as &$t) {
            $d = isset($t['description']) ? $t['description'] . "\n\n" . $contract : $contract;
            if (preg_match_all('/./su', $d) > 2048) {
                trigger_error("Toklex: description of tool '{$t['name']}' is over 2048 characters, a client may cut the contract line", E_USER_WARNING);
            }
            $t['description'] = $d;
            $in = $t['inputSchema'] ?? ['type' => 'object'];
            $in['properties'] = (array)($in['properties'] ?? []) + ['toklex_mode' => [
                'type' => 'string',
                'enum' => self::MODES,
                'description' => "Reply mode. Leave it out to use the tool's default.",
            ]];
            $t['inputSchema'] = $in;
        }
        unset($t);
        $ro = fn(string $title) => ['title' => $title, 'readOnlyHint' => true, 'openWorldHint' => false];
        $tools[] = [
            'name' => self::SCHEMA_TOOL,
            'title' => 'Toklex schema',
            'description' => 'Returns the schema (field names, value codes, number scales and instructions) needed to read the Toklex responses of this server. Call it before reading a response whose ~<id>@<version> you do not have in context.',
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            'annotations' => $ro('Toklex schema'),
        ];
        $tools[] = [
            'name' => self::CALIBRATE_TOOL,
            'title' => 'Toklex calibrate',
            'description' => 'Finds out which Toklex mode you read reliably. Call it without arguments to get a small dataset with questions. Call it again with answers to get the recommended mode.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'answers' => ['type' => 'object', 'description' => 'Your answers, as an object that maps a question id to the answer.'],
            ]],
            'annotations' => $ro('Toklex calibrate'),
        ];
        return $tools;
    }

    /** Mode of one call. Removes toklex_mode from $args, so the tool never sees it. */
    public function mode(string $tool, array &$args): string
    {
        $m = $args['toklex_mode'] ?? null;
        unset($args['toklex_mode']);
        if (is_string($m) && in_array($m, self::MODES, true)) {
            return $m;
        }
        return $this->defaultMode($tool);
    }

    /** $mode null means the tool's default. */
    public function encode(string $tool, array $rows, array $meta = [], ?string $mode = null): string
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : $this->defaultMode($tool);
        foreach ($meta as $k => $_) {
            $c = (string)($this->kf[$k] ?? self::u8((string)$k));
            ($c === 'i' || $c === 'e') && throw new \InvalidArgumentException("Toklex: meta key $c is reserved");
        }
        if ($mode === 'off') {
            return $this->off(['meta' => (object)$meta, 'rows' => array_map(fn($r) => (object)$r, array_values($rows))]);
        }
        $out = [$this->header($meta)];
        if (!$rows) {
            return $out[0];
        }
        $flat = array_map(fn($r) => $this->flat($r), array_values($rows));
        $cols = [];
        foreach ($flat as $f) {
            foreach ($f as $p => [$c]) {
                $cols[$p] ??= $c;
            }
        }
        if ($mode === 'dense') {
            $out[] = '[' . count($flat) . ']';
            foreach ($cols as $p => $c) {
                $v = array_map(fn($f) => $f[$p][1] ?? null, $flat);
                $out[] = "$p:" . (self::days($v) ?? self::runs(array_map(fn($x) => $this->cell($x, $c), $v)));
            }
            return implode("\n", $out);
        }
        $out[] = implode('|', array_keys($cols));
        foreach ($flat as $f) {
            $cells = [];
            foreach ($cols as $p => $c) {
                $cells[] = isset($f[$p]) ? $this->cell($f[$p][1], $c) : '';
            }
            $line = rtrim(implode('|', $cells), '|');
            $out[] = $line === '' ? '|' : $line;
        }
        return implode("\n", $out);
    }

    /** $codeOrText is a code from values.e or its full message. An error has no records, so only off differs from a header line. */
    public function error(string $codeOrText, ?string $mode = null): string
    {
        $e = $this->s['values']['e'] ?? [];
        $code = isset($e[$codeOrText]) ? $codeOrText : array_search($codeOrText, $e, true);
        if ($mode === 'off') {
            return $this->off(['error' => $code === false ? $codeOrText : $e[$code]]);
        }
        return $this->header([]) . ' e:' . ($code === false ? self::quote($codeOrText) : $code);
    }

    /** Reads a reply back with full key names and values. */
    public function decode(string $text): array
    {
        if (str_starts_with($text, '{')) {
            $j = json_decode($text, true);
            [$id, $ver] = explode('@', $j['schema'], 2);
            return ['schema' => $id, 'version' => $ver, 'instructions' => isset($j['instructions']),
                'meta' => isset($j['error']) ? ['e' => $j['error']] : $j['meta'], 'rows' => $j['rows'] ?? []];
        }
        $l = explode("\n", $text);
        preg_match('/^~([^@ ]+)@(\S+)/', $l[0], $h);
        preg_match_all('/ ([^\s:]+):("(?:[^"\\\\]|\\\\.)*"|\S*)/', $l[0], $m, PREG_SET_ORDER);
        $ins = false;
        $meta = [];
        foreach ($m as [, $c, $raw]) {
            if ($c === 'i') {
                $ins = $raw === '+';
            } else {
                $meta[$this->s['keys'][$c] ?? $c] = $this->read($raw, $c);
            }
        }
        $rows = [];
        if (isset($l[1])) {
            if (preg_match('/^\[(\d+)\]$/D', $l[1], $n)) {
                $cols = [];
                (int)$n[1] > self::MAX_RECORDS && throw new \InvalidArgumentException('Toklex: a reply of more than ' . self::MAX_RECORDS . ' records is refused');
                $grid = array_fill(0, (int)$n[1], []);
                foreach (array_slice($l, 2) as $i => $line) {
                    [$cols[$i], $items] = explode(':', $line, 2) + [1 => ''];
                    foreach (self::expand($items, (int)$n[1]) as $r => $cell) {
                        $grid[$r][$i] = $cell;
                    }
                }
            } else {
                $cols = $l[1] === '' ? [] : explode('|', $l[1]);
                $grid = array_map(fn($line) => explode('|', $line), array_slice($l, 2));
            }
            foreach ($grid as $cells) {
                $row = [];
                foreach ($cols as $i => $col) {
                    $segs = explode('.', $col);
                    $val = $this->read($cells[$i] ?? '', end($segs));
                    $ref = &$row;
                    foreach ($segs as $sg) {
                        if (!is_array($ref)) {
                            // a scalar parent gives way to a nested value, but a null never replaces it
                            if ($val === null && $ref !== null) {
                                unset($ref);
                                continue 2;
                            }
                            $ref = [];
                        }
                        $ref = &$ref[$this->s['keys'][$sg] ?? $sg];
                    }
                    // a null parent cell never wipes the object its nested columns built
                    if ($val !== null || !is_array($ref)) {
                        $ref = $val;
                    }
                    unset($ref);
                }
                $rows[] = $row;
            }
        }
        return ['schema' => $h[1], 'version' => $h[2], 'instructions' => $ins, 'meta' => $meta, 'rows' => $rows];
    }

    /**
     * Takes the tool arguments {"answers": ...} or the bare answers map. Answers given as a JSON string are decoded.
     * Answers that hold no question id count as absent and give the dataset, so a malformed call never grades as off.
     */
    public function calibrate(?array $args): string
    {
        $answers = $args['answers'] ?? $args;
        $answers = is_string($answers) ? json_decode($answers, true) : $answers;
        $cal = new self(self::CAL_SCHEMA, '0000');
        if (!is_array($answers) || !array_intersect_key($answers, self::CAL_QUESTIONS)) {
            $q = [];
            foreach (self::CAL_QUESTIONS as $id => [$block, $text]) {
                $q[] = "$id ($block block): $text";
            }
            return implode("\n", ["Toklex calibration",
                'The two blocks below hold the same 8 records, once as a safe table and once as a dense table. The legend covers this test only. Answer the six questions from the text on this page.',
                '', 'Legend', implode("\n", array_slice(explode("\n", $cal->schemaText()), 2)), '', 'How to read', self::CAL_HOWTO,
                '', 'Safe block', $cal->encode('cal', self::CAL_ROWS, [], 'safe'), '', 'Dense block', $cal->encode('cal', self::CAL_ROWS, [], 'dense'),
                '', 'Questions', ...$q, '',
                'Then call toklex_calibrate again with {"answers":{"s1":"<answer>","s2":"<answer>","s3":"<answer>","d1":"<answer>","d2":"<answer>","d3":"<answer>"}}. Write each answer as short text or a number.']);
        }
        $norm = fn($x) => is_string($x) ? trim($x) : (is_int($x) || is_float($x) ? self::num($x, null) : null);
        $res = [];
        foreach (self::CAL_QUESTIONS as $id => [, , $key]) {
            $res[$id] = isset($answers[$id]) && $norm($answers[$id]) === $key;
        }
        $all = fn(string $p) => !in_array(false, array_filter($res, fn($k) => $k[0] === $p, ARRAY_FILTER_USE_KEY), true);
        return json_encode(['recommended' => $all('d') ? 'dense' : ($all('s') ? 'safe' : 'off'), 'results' => $res]);
    }

    public function resources(): array
    {
        return [
            ['uri' => 'toklex://modes', 'name' => 'Toklex modes', 'description' => 'The three reply modes, how to pick one and how to calibrate.', 'mimeType' => 'text/plain'],
            ['uri' => 'toklex://schema', 'name' => 'Toklex schema', 'description' => 'The schema text that toklex_schema returns.', 'mimeType' => 'text/plain'],
        ];
    }

    public function readResource(string $uri): ?string
    {
        return match ($uri) {
            'toklex://modes' => self::MODES_TEXT,
            'toklex://schema' => $this->schemaText(),
            default => null,
        };
    }

    private function defaultMode(string $tool): string
    {
        return in_array($tool, $this->s['dense'] ?? [], true) ? 'dense' : 'safe';
    }

    /** A dense column as one line of items. A run of equal cells is cell*K. */
    private static function runs(array $cells): string
    {
        $o = [];
        for ($i = 0, $n = count($cells); $i < $n; $i = $j) {
            for ($j = $i + 1; $j < $n && $cells[$j] === $cells[$i]; $j++);
            $o[] = $cells[$i] . ($j - $i > 1 ? '*' . ($j - $i) : '');
        }
        return implode('|', $o);
    }

    /** <first>+<step>d when there are 3 or more values, all valid ISO dates, with the same positive day step. Otherwise null. */
    private static function days(array $v): ?string
    {
        $t = [];
        foreach ($v as $x) {
            $d = is_string($x) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $x)
                ? \DateTimeImmutable::createFromFormat('!Y-m-d', $x, new \DateTimeZone('UTC')) : false;
            if (!$d || $d->format('Y-m-d') !== $x) {
                return null;
            }
            $t[] = intdiv($d->getTimestamp(), 86400);
        }
        $step = ($t[1] ?? 0) - $t[0];
        foreach ($t as $i => $d) {
            if ($i && $d - $t[$i - 1] !== $step) {
                return null;
            }
        }
        return count($t) >= 3 && $step > 0 ? "{$v[0]}+{$step}d" : null;
    }

    /** The N cell texts of one dense column line. A date sequence gives quoted dates, so they stay literals. */
    private static function expand(string $items, int $n): array
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\+(\d+)d$/D', $items, $m)) {
            $d = new \DateTimeImmutable($m[1], new \DateTimeZone('UTC'));
            return array_map(fn($i) => '"' . $d->modify('+' . $i * (int)$m[2] . ' days')->format('Y-m-d') . '"', array_keys(array_fill(0, $n, 0)));
        }
        $o = [];
        foreach (explode('|', $items) as $it) {
            if (count($o) >= $n) {
                break;
            }
            $r = preg_match('/^(.*)\*(\d+)$/Ds', $it, $m);
            array_push($o, ...array_fill(0, $r ? min((int)$m[2], $n - count($o)) : 1, $r ? $m[1] : $it));
        }
        return $o;
    }

    private function header(array $meta): string
    {
        $h = "~{$this->s['id']}@{$this->v}" . (($this->s['instructions'] ?? []) ? ' i:+' : '');
        foreach ($meta as $k => $v) {
            $c = (string)($this->kf[$k] ?? self::u8((string)$k));
            $t = is_array($v) || is_object($v) ? self::quote($this->js($v, true, $c)) : $this->cell($v, $c, true);
            $t === '' || $h .= " $c:$t";
        }
        return $h;
    }

    private function off(array $tail): string
    {
        $ins = $this->s['instructions'] ?? [];
        return $this->js((object)(['toklex' => 'off', 'schema' => "{$this->s['id']}@{$this->v}"]
            + ($ins ? ['instructions' => (object)$ins] : []) + $tail), false);
    }

    /** One record as [dotted column => [code of the last segment, value]]. A non-empty object or map expands, anything else is a cell. */
    private function flat(array|object $row, string $path = ''): array
    {
        $out = [];
        foreach ((array)$row as $k => $v) {
            $c = (string)($this->kf[$k] ?? self::u8((string)$k));
            $p = $path === '' ? $c : "$path.$c";
            $v = is_object($v) ? (array)$v : $v;
            if (is_array($v) && $v && !array_is_list($v)) {
                $out += $this->flat($v, $p);
            } else {
                $out[$p] = [$c, $v];
            }
        }
        return $out;
    }

    /** One cell. $hdr adds the header's two quote triggers, any whitespace and a double quote. */
    private function cell(mixed $v, string $c, bool $hdr = false): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        $scaled = isset($this->s['scale'][$c]);
        // a database driver hands numbers over as strings, so a plain decimal in a scaled field is that number (spec 6.3)
        if ($scaled && is_string($v) && preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/D', $v)) {
            $v = json_decode($v);
        }
        if (is_int($v) || is_float($v)) {
            return self::num($v, $this->s['scale'][$c] ?? null) ?? '';
        }
        if (is_array($v) || is_object($v)) {
            return str_replace('|', self::BAR, $this->js($v, true, $c));
        }
        $v = self::u8((string)$v);
        if (isset($this->vf[$c][$v])) {
            return (string)$this->vf[$c][$v];
        }
        $quote = $scaled || $v === '' || preg_match('/^["\[{]|[|*\n\r]/', $v) || isset($this->s['values'][$c][$v])
            || preg_match(self::NUM, $v) || in_array($v, ['true', 'false', 'null'], true)
            || preg_match('/^\d{4}-\d{2}-\d{2}\+\d+d$/D', $v) || ($hdr && preg_match('/[\s"]/', $v));
        return $quote ? self::quote($v) : $v;
    }

    /** JSON text. $coded shortens keys and codes strings and numbers by the key they sit under. Lists pass their key down. */
    private function js(mixed $x, bool $coded, ?string $k = null): string
    {
        if (is_array($x) || is_object($x)) {
            if (is_array($x) && array_is_list($x)) {
                return '[' . implode(',', array_map(fn($e) => $this->js($e, $coded, $k), $x)) . ']';
            }
            $o = [];
            foreach ((array)$x as $n => $e) {
                $c = (string)($coded ? ($this->kf[$n] ?? $n) : $n);
                $o[] = json_encode($c, self::JF) . ':' . $this->js($e, $coded, $c);
            }
            return '{' . implode(',', $o) . '}';
        }
        if (is_int($x) || is_float($x)) {
            return self::num($x, $coded ? ($this->s['scale'][$k] ?? null) : null) ?? 'null';
        }
        if (is_string($x) && $coded && isset($this->vf[$k][$x])) {
            $x = $this->vf[$k][$x];
        }
        return json_encode($x, self::JF);
    }

    /** A number as its shortest decimal text with no exponent. With a scale, that text times the scale in decimal, rounded half away from zero (spec 6.3). Null when it is not finite, also after scaling. */
    private static function num(int|float $x, int|float|null $m): ?string
    {
        if (!is_finite((float)$x) || ($m && !is_finite($x * $m))) {
            return null;
        }
        if ($m) {
            return self::scaled(self::num($x, null), self::num($m, null));
        }
        if (is_int($x)) {
            return (string)$x;
        }
        if ($x == floor($x) && abs($x) < 1e15) {
            return (string)(int)$x;
        }
        // shortest digits that read back as the same float, so php.ini serialize_precision never matters
        for ($p = 0; (float)($s = sprintf("%.{$p}e", $x)) !== $x && $p < 16; $p++);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?e([+-]?\d+)$/i', $s, $p)) {
            return $s;
        }
        [, $sign, $i, $f, $e] = $p;
        $d = $i . $f;
        $pt = strlen($i) + (int)$e;
        $r = $pt <= 0 ? '0.' . str_repeat('0', -$pt) . $d
            : ($pt >= strlen($d) ? $d . str_repeat('0', $pt - strlen($d)) : substr($d, 0, $pt) . '.' . substr($d, $pt));
        return $sign . (str_contains($r, '.') ? rtrim(rtrim($r, '0'), '.') : $r);
    }

    /** Decimal text $x times integer text $m, rounded half away from zero, by long multiplication on digits so no float rounding enters. */
    private static function scaled(string $x, string $m): string
    {
        [$i, $f] = explode('.', ltrim($x, '-') . '.');
        $a = $i . $f;
        $n = strlen($a) + strlen($m) + 1;
        $r = array_fill(0, $n, 0);
        for ($p = strlen($a) - 1; $p >= 0; $p--) {
            for ($q = strlen($m) - 1; $q >= 0; $q--) {
                $r[$p + $q + 2] += (int)$a[$p] * (int)$m[$q];
            }
        }
        $f === '' || $r[$n - strlen($f)] += 5; // half of the last kept digit, so cutting the fraction rounds away from zero
        for ($k = $n - 1; $k > 0; $k--) {
            $r[$k - 1] += intdiv($r[$k], 10);
            $r[$k] %= 10;
        }
        $t = ltrim(implode('', array_slice($r, 0, $n - strlen($f))), '0');
        return $t === '' ? '0' : ($x[0] === '-' ? "-$t" : $t);
    }

    /** The string with each invalid UTF-8 sequence replaced by U+FFFD, so that data never makes the encoder fail. */
    private static function u8(string $s): string
    {
        return preg_match('//u', $s) ? $s : json_decode(json_encode($s, JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** A literal JSON string in quotes, with | written as backslash u 0 0 7 c. */
    private static function quote(string $s): string
    {
        return str_replace('|', self::BAR, json_encode($s, self::JF));
    }

    /** One header value or cell back to a PHP value, by the rules of spec section 8. */
    private function read(string $t, string $c): mixed
    {
        if ($t === '') {
            return null;
        }
        if ($t[0] === '"' && is_string($j = json_decode($t))) {
            return $j;
        }
        if (isset($this->s['values'][$c][$t])) {
            return $this->s['values'][$c][$t];
        }
        if (preg_match(self::NUM, $t)) {
            return $this->dec(json_decode($t), $c);
        }
        if ($t === 'true' || $t === 'false') {
            return $t === 'true';
        }
        if ($t[0] === '[' && is_array($j = json_decode($t, true))) {
            return $this->dec($j, $c);
        }
        return $t;
    }

    /** Full keys, full values and descaled numbers inside decoded JSON. */
    private function dec(mixed $x, ?string $k): mixed
    {
        if (is_array($x)) {
            $list = array_is_list($x);
            $o = [];
            foreach ($x as $n => $e) {
                $c = (string)$n;
                $o[$list ? $n : ($this->s['keys'][$c] ?? $c)] = $this->dec($e, $list ? $k : $c);
            }
            return $o;
        }
        if (is_string($x)) {
            return $this->s['values'][$k][$x] ?? $x;
        }
        return (is_int($x) || is_float($x)) && ($m = $this->s['scale'][$k] ?? null) ? $x / $m : $x;
    }

    /** A code that a cell, a column name or a header value could not carry as plain text. */
    private static function badCode(string $c): bool
    {
        return $c === '' || preg_match('/[|*:\s"]|^[\[{]/', $c) || preg_match(self::NUM, $c) || in_array($c, ['true', 'false', 'null'], true);
    }

    private static function check(array $s): void
    {
        $fail = static fn(string $why) => throw new \InvalidArgumentException("Toklex schema: $why");
        ($s['toklex'] ?? null) === 1 || $fail('toklex must be 1');
        (is_string($s['id'] ?? null) && preg_match('/^[a-z][a-z0-9]{0,7}$/D', $s['id'])) || $fail('id must match [a-z][a-z0-9]{0,7}');
        $keys = $s['keys'] ?? [];
        is_array($keys) || $fail('keys must be an object');
        foreach ($keys as $c => $full) {
            is_string($full) || $fail("keys.$c must be a string");
            ((string)$c === 'i' || (string)$c === 'e') && $fail("key code $c is reserved");
            self::badCode((string)$c) && $fail("key code $c is not a plain token");
            str_contains((string)$c, '.') && $fail("key code $c holds a dot, which marks a nested column");
        }
        count(array_unique($keys)) === count($keys) || $fail('a full name occurs twice in keys');
        $values = $s['values'] ?? [];
        is_array($values) || $fail('values must be an object');
        foreach ($values as $f => $map) {
            is_array($map) || $fail("values.$f must be an object");
            foreach ($map as $c => $full) {
                is_string($full) || $fail("values.$f.$c must be a string");
                self::badCode((string)$c) && $fail("value code $c in values.$f is not a plain token");
                preg_match('/^\d{4}-\d{2}-\d{2}\+\d+d$/D', (string)$c) && $fail("value code $c in values.$f has the shape of a date sequence");
            }
            count(array_unique($map)) === count($map) || $fail("a full value occurs twice in values.$f");
        }
        $scale = $s['scale'] ?? [];
        is_array($scale) || $fail('scale must be an object');
        foreach ($scale as $f => $m) {
            (is_int($m) || (is_float($m) && $m === floor($m) && is_finite($m))) || $fail("scale.$f must be an integer");
            $m >= 10 || $fail("scale.$f must be at least 10");
        }
        $ins = $s['instructions'] ?? [];
        (is_array($ins) && $ins === array_filter($ins, 'is_string')) || $fail('instructions must map names to strings');
        $dense = $s['dense'] ?? [];
        (is_array($dense) && $dense === array_filter($dense, 'is_string')) || $fail('dense must be a list of tool names');
    }
}
