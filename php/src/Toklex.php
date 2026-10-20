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
    // patterns name their characters, because PCRE takes \s and \d from the locale and a single-byte locale counts NBSP as space
    private const NUM = '/^-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?$/D';
    private const WS = '\t\n\x0b\f\r ';
    private const BAR = '\\' . 'u007c';
    private const DENSE_LINE = 'One line per column: name:v1|v2|… x*K is x repeated K times, start+Nd is a date every N days, an empty cell is null.';

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

    private const PLAIN_MODES_TEXT = <<<'TXT'
Toklex modes
A server that speaks Toklex can answer a tool call in one of three modes.
off: compact JSON with full field names and numbers as they are.
safe: a table. The line that starts with ~ is the header, the next line names the columns and each following line is one record, cells separated by |. A column name that ends in (÷K) holds the value times K, so divide the cell by K. This is the default for every tool that the server does not list as dense.
dense: after the header, a line starting with [N] gives the record count and how to read the block. Each following line holds one column. This is the default for the tools the server lists as dense.
Pick a mode for one call with the optional argument toklex_mode. The values are off, safe and dense. If you leave it out or send another value, the tool's default mode applies.
TXT;

    private const MAX_RECORDS = 100000;
    private const MAX_CELLS = 500000; // the cost of a reply in cells, about 60 MB in PHP at the limit (spec 11)
    private const MAX_DEPTH = 64;
    private const LAST_DAY = 2932896; // 9999-12-31 as days since 1970-01-01
    private const AUTO_MIN = 10;
    // characters that would break a column line or a header, written as \uXXXX (spec 6.1)
    private const NAME_ESC = ['\\' => '\u005c', '|' => '\u007c', ':' => '\u003a', '÷' => '\u00f7',
        "\t" => '\u0009', "\n" => '\u000a', "\v" => '\u000b', "\f" => '\u000c', "\r" => '\u000d'];

    // The calibration dataset and its key. They belong to the library and never depend on a server schema.
    private const CAL_SCHEMA = ['toklex' => 1, 'id' => 'cal', 'style' => 'coded', 'keys' => ['n' => 'name', 'd' => 'day', 't' => 'tier', 'p' => 'price'],
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

    private array $kf = [];  // full name -> code, coded only
    private array $vf = [];  // code -> full value -> value code, coded only
    private array $dk = [];  // code -> full name for decode, coded only
    private array $dv = [];  // code -> value code -> full value for decode, coded only
    private array $sc;       // scale by the name the encoder writes: the code when coded, the full name when plain

    private function __construct(private array $s, private string $v)
    {
        $keys = $s['keys'] ?? [];
        if (($s['style'] ?? 'plain') === 'coded') {
            [$this->kf, $this->vf, $this->dk, $this->dv] = [array_flip($keys), array_map('array_flip', $s['values'] ?? []), $keys, $s['values'] ?? []];
        }
        $this->sc = [];
        foreach ($s['scale'] ?? [] as $c => $m) {
            $this->sc[$this->kf ? $c : ($keys[$c] ?? $c)] = $m;
        }
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

    public function style(): string
    {
        return $this->s['style'] ?? 'plain';
    }

    public function version(): string
    {
        return $this->v;
    }

    /** The instructions as the model reads them: one "name: text" per block, the text verbatim. It goes into the MCP instructions field. */
    public function instructionsText(): string
    {
        $ins = $this->s['instructions'] ?? [];
        return implode("\n", array_map(fn($n, $b) => "$n: $b", array_keys($ins), $ins));
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

    /**
     * Adds toklex_mode to every tool and removes outputSchema. Coded style also adds the contract line and appends the two Toklex tools.
     * $warn gets each warning as one line. Without it the warning goes to error_log: trigger_error would print to stdout in the CLI,
     * which carries the messages of a stdio MCP server, and frameworks turn it into an exception.
     */
    public function withTools(array $tools, ?callable $warn = null): array
    {
        $warn ??= error_log(...);
        $plain = $this->style() === 'plain';
        $contract = "Toklex reply ~{$this->s['id']}@{$this->v}. If this version is not in context, call toklex_schema first. Never guess a code.";
        foreach ($tools as &$t) {
            $t = (array)$t;
            if (!$plain) {
                $d = isset($t['description']) ? $t['description'] . "\n\n" . $contract : $contract;
                if (preg_match_all('/./su', $d) > 2048) {
                    $warn('Toklex: description of tool \'' . ($t['name'] ?? '') . '\' is over 2048 characters, a client may cut the contract line');
                }
                $t['description'] = $d;
            }
            // a tool with an outputSchema must return structuredContent, and a Toklex reply is one text block (spec 9)
            if (array_key_exists('outputSchema', $t)) {
                unset($t['outputSchema']);
                $warn('Toklex: tool \'' . ($t['name'] ?? '') . '\' loses its outputSchema, a Toklex reply carries no structuredContent');
            }
            $in = (array)($t['inputSchema'] ?? ['type' => 'object']);
            $in['properties'] = (array)($in['properties'] ?? []) + ['toklex_mode' => [
                'type' => 'string',
                'enum' => self::MODES,
                'description' => 'Reply format: off, safe or dense.',
            ]];
            $t['inputSchema'] = $in;
        }
        unset($t);
        if ($plain) {
            return $tools;
        }
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

    /**
     * $mode null means the tool's default. $instructions false leaves the instruction text out of this reply, for a server
     * that knows the model has read it earlier in the session (spec 5.1).
     * @throws \InvalidArgumentException on a reserved meta key or a record that is not an array or an object
     */
    public function encode(string $tool, array $rows, array $meta = [], ?string $mode = null, bool $instructions = true): string
    {
        $mode = in_array($mode, [...self::MODES, 'auto'], true) ? $mode : $this->defaultMode($tool);
        foreach ($meta as $k => $_) {
            $c = (string)($this->kf[$k] ?? self::u8((string)$k));
            in_array($c, $this->style() === 'plain' ? ['error'] : ['i', 'e'], true) && throw new \InvalidArgumentException("Toklex: meta key $c is reserved");
        }
        $rows = array_map(fn($r) => self::norm($r, 0), array_values($rows));
        $meta = array_map(fn($v) => self::norm($v, 1), $meta);
        foreach ($rows as $i => $r) {
            is_array($r) || is_object($r) || throw new \InvalidArgumentException("Toklex: record $i is " . get_debug_type($r) . ', not a record. Pass a list of records, such as [$row] for one record.');
        }
        if ($mode === 'off') {
            return $this->off(['meta' => (object)$meta, 'rows' => array_map(fn($r) => (object)$r, $rows)], $instructions);
        }
        $out = [$this->header($meta, $instructions)];
        if (!$rows) {
            return $out[0];
        }
        $flat = array_map(fn($r) => $this->flat($r), $rows);
        $cols = [];
        foreach ($flat as $f) {
            foreach ($f as $p => [$c]) {
                $cols[$p] ??= $c;
            }
        }
        $col = fn(string $p) => array_map(fn($f) => $f[$p][1] ?? null, $flat);
        if ($mode === 'auto') {
            // dense pays off on a long series of dates and costs more than safe on a short or wide list (spec 12.2)
            $seq = count($flat) >= self::AUTO_MIN && array_filter(array_keys($cols), fn($p) => self::days($col((string)$p)) !== null);
            $mode = $seq ? 'dense' : 'safe';
        }
        if ($mode === 'dense') {
            $out[] = '[' . count($flat) . ']' . ($this->style() === 'plain' ? ' ' . self::DENSE_LINE : '');
            foreach ($cols as $p => $c) {
                $v = $col((string)$p);
                $out[] = $this->label((string)$p, $c) . ':' . (self::days($v) ?? self::runs(array_map(fn($x) => $this->cell($x, $c), $v)));
            }
            return implode("\n", $out);
        }
        $out[] = implode('|', array_map(fn($p) => $this->label((string)$p, $cols[$p]), array_keys($cols)));
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
    public function error(string $codeOrText, ?string $mode = null, bool $instructions = true): string
    {
        $e = $this->s['values']['e'] ?? [];
        $code = isset($e[$codeOrText]) ? $codeOrText : array_search($codeOrText, $e, true);
        $text = $code === false ? $codeOrText : $e[$code];
        if ($mode === 'off') {
            return $this->off(['error' => $text], $instructions);
        }
        return $this->style() === 'plain' ? $this->header([], $instructions) . ' error:' . $this->cell($text, 'error', true)
            : $this->header([], $instructions) . ' e:' . ($code === false ? self::quote($codeOrText) : $code);
    }

    /** Reads a reply back with full key names and values. */
    public function decode(string $text): array
    {
        if (str_starts_with($text, '{')) {
            $j = json_decode($text, true);
            (is_array($j) && is_string($j['schema'] ?? null) && str_contains($j['schema'], '@'))
                || throw new \InvalidArgumentException('Toklex: the off reply is not a JSON object with a schema');
            [$id, $ver] = explode('@', $j['schema'], 2);
            return ['schema' => $id, 'version' => $ver, 'instructions' => isset($j['instructions']),
                'meta' => isset($j['error']) ? [$this->style() === 'plain' ? 'error' : 'e' => $j['error']] : (array)($j['meta'] ?? []), 'rows' => (array)($j['rows'] ?? [])];
        }
        $plain = $this->style() === 'plain';
        // instruction lines start with their name, the header with ~, so a reply that left them out starts with the header
        $k = $this->inReply() && !str_starts_with($text, '~') ? count($this->s['instructions']) : 0;
        $l = array_slice(explode("\n", $text), $k);
        $label = fn(string $c) => $plain ? preg_replace('/\(÷[0-9]+\)$/', '', $c) : $c;
        preg_match('/^~([^@ ]+)@([^' . self::WS . ']+)/', $l[0] ?? '', $h) || throw new \InvalidArgumentException('Toklex: the reply has no header line');
        // possessive, because a backtracking match runs out of JIT stack on a value of about 10 KB and then matches nothing
        preg_match_all('/ ([^' . self::WS . ':]+):("(?:[^"\\\\]++|\\\\.)*+"|[^' . self::WS . ']*)/', $l[0], $m, PREG_SET_ORDER);
        $ins = $k > 0;
        $meta = [];
        foreach ($m as [, $c, $raw]) {
            if ($c === 'i' && !$plain) {
                $ins = $raw === '+';
            } else {
                $c = self::unname($label($c));
                $meta[$this->dk[$c] ?? $c] = $this->read($raw, $c);
            }
        }
        $rows = [];
        if (isset($l[1])) {
            // the work a reply asks for is counted as it is spent, so a short forged reply is refused before it costs much (spec 11)
            $left = self::MAX_CELLS;
            $spend = static function (int $n) use (&$left): void {
                ($left -= $n) < 0 && throw new \InvalidArgumentException('Toklex: a reply of more than ' . self::MAX_CELLS . ' cells is refused');
            };
            if (preg_match('/^\[([0-9]+)\]( .*)?$/D', $l[1], $n)) {
                $count = strlen($n[1]) > 9 ? PHP_INT_MAX : (int)$n[1];
                $count > self::MAX_RECORDS && throw new \InvalidArgumentException('Toklex: a reply of more than ' . self::MAX_RECORDS . ' records is refused');
                $count * (count($l) - 2) > self::MAX_CELLS && $spend(PHP_INT_MAX);
                $rows = array_fill(0, $count, []);
                foreach (array_slice($l, 2) as $line) {
                    [$name, $items] = explode(':', $line, 2) + [1 => ''];
                    [$keys, $code, $cost] = $this->column($label($name));
                    $spend($count * $cost);
                    $r = 0;
                    foreach (self::items($items, $count) as [$t, $k, $step]) {
                        if ($t === null) { // a date sequence from day $k
                            for (; $r < $count; $r++) {
                                self::put($rows[$r], $keys, self::date($k + $r * $step));
                            }
                            break;
                        }
                        $spend($k * self::extra($t));
                        $v = $this->read($t, $code);
                        for ($e = $r + $k; $r < $e; $r++) {
                            self::put($rows[$r], $keys, $v);
                        }
                    }
                    for (; $r < $count; $r++) {
                        self::put($rows[$r], $keys, null);
                    }
                }
            } else {
                $cols = $l[1] === '' ? [] : array_map(fn($c) => $this->column($label($c)), explode('|', $l[1]));
                count($l) - 2 > self::MAX_RECORDS && throw new \InvalidArgumentException('Toklex: a reply of more than ' . self::MAX_RECORDS . ' records is refused');
                foreach (array_slice($l, 2) as $line) {
                    $cells = explode('|', $line);
                    $row = [];
                    foreach ($cols as $i => [$keys, $code, $cost]) {
                        $spend($cost + self::extra($cells[$i] ?? ''));
                        self::put($row, $keys, $this->read($cells[$i] ?? '', $code));
                    }
                    $rows[] = $row;
                }
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
        return $this->style() === 'plain' ? [
            ['uri' => 'toklex://modes', 'name' => 'Toklex modes', 'description' => 'The three reply modes and how to pick one.', 'mimeType' => 'text/plain'],
        ] : [
            ['uri' => 'toklex://modes', 'name' => 'Toklex modes', 'description' => 'The three reply modes, how to pick one and how to calibrate.', 'mimeType' => 'text/plain'],
            ['uri' => 'toklex://schema', 'name' => 'Toklex schema', 'description' => 'The schema text that toklex_schema returns.', 'mimeType' => 'text/plain'],
        ];
    }

    public function readResource(string $uri): ?string
    {
        $plain = $this->style() === 'plain';
        return match ($uri) {
            'toklex://modes' => $plain ? self::PLAIN_MODES_TEXT : self::MODES_TEXT,
            'toklex://schema' => $plain ? null : $this->schemaText(),
            default => null,
        };
    }

    /** dense, auto or safe. auto picks dense or safe when the records are known. */
    private function defaultMode(string $tool): string
    {
        return in_array($tool, $this->s['dense'] ?? [], true) ? 'dense' : (in_array($tool, $this->s['auto'] ?? [], true) ? 'auto' : 'safe');
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
            if (($d = self::day($x)) === null) {
                return null;
            }
            $t[] = $d;
        }
        $step = ($t[1] ?? 0) - $t[0];
        foreach ($t as $i => $d) {
            if ($i && $d - $t[$i - 1] !== $step) {
                return null;
            }
        }
        return count($t) >= 3 && $step > 0 ? "{$v[0]}+{$step}d" : null;
    }

    /** The day number of a valid ISO date text such as 2026-07-01, else null. */
    private static function day(mixed $x): ?int
    {
        if (!is_string($x) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $x, $m) || $m[2] < 1 || $m[2] > 12 || $m[3] < 1) {
            return null;
        }
        $n = self::civil((int)$m[1], (int)$m[2], (int)$m[3]);
        return self::date($n) === $x ? $n : null;
    }

    /** Days since 1970-01-01 in the proleptic Gregorian calendar (days_from_civil by H. Hinnant, with floor division). A day past the end of a month runs on into the next one. */
    private static function civil(int $y, int $m, int $d): int
    {
        $y -= $m <= 2 ? 1 : 0;
        $era = (int)floor($y / 400);
        $yoe = $y - $era * 400;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        return $era * 146097 + $doe - 719468;
    }

    /** The ISO date of a day number, the inverse of civil(). */
    private static function date(int $z): string
    {
        $z += 719468;
        $era = (int)floor($z / 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $m = $mp + ($mp < 10 ? 3 : -9);
        $d = $doy - intdiv(153 * $mp + 2, 5) + 1;
        // concatenation, because a sprintf result keeps its spare buffer and a date sequence makes many of them
        return str_pad((string)($yoe + $era * 400 + ($m <= 2 ? 1 : 0)), 4, '0', STR_PAD_LEFT) . ($m < 10 ? '-0' : '-') . $m . ($d < 10 ? '-0' : '-') . $d;
    }

    /**
     * The items of a dense column line as [cell text, K] runs cut at N records, or one [null, first day, step] for a date sequence.
     * @throws \InvalidArgumentException on a date sequence that starts at an invalid date or runs past 9999-12-31
     */
    private static function items(string $items, int $n): array
    {
        if (preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2})\+([0-9]+)d$/D', $items, $m)) {
            ($d = self::day($m[1])) === null && throw new \InvalidArgumentException("Toklex: a date sequence starts at the invalid date {$m[1]}");
            $step = strlen($m[2]) > 7 ? self::LAST_DAY : (int)$m[2];
            $n > 1 && $d + ($n - 1) * $step > self::LAST_DAY && throw new \InvalidArgumentException('Toklex: a date sequence runs past 9999-12-31');
            return [[null, $d, $step]];
        }
        $o = [];
        foreach (explode('|', $items) as $it) {
            if ($n <= 0) {
                break;
            }
            $r = preg_match('/^(.*)\*([0-9]+)$/Ds', $it, $m);
            $k = $r ? min(strlen($m[2]) > 9 ? PHP_INT_MAX : (int)$m[2], $n) : 1;
            $o[] = [$r ? $m[1] : $it, $k, 0];
            $n -= $k;
        }
        return $o;
    }

    /** The cost a JSON array cell adds to its cell, read from its text: 1 per comma and 8 per [ or {, as a nested list or map costs about 8 cells. */
    private static function extra(string $t): int
    {
        return $t !== '' && $t[0] === '[' ? substr_count($t, ',') + 8 * (substr_count($t, '[') + substr_count($t, '{')) : 0;
    }

    /**
     * A written column name as [the keys of its nested path, the code of its last name, the cost of one of its cells].
     * A cell costs 1 and each name before the last adds 8, the cost of the nested map it builds.
     * @throws \InvalidArgumentException when the path has more than MAX_DEPTH names
     */
    private function column(string $name): array
    {
        substr_count($name, '.') >= self::MAX_DEPTH && throw new \InvalidArgumentException('Toklex: a column path of more than ' . self::MAX_DEPTH . ' names is refused');
        $segs = array_map(self::unname(...), explode('.', $name));
        return [array_map(fn($s) => $this->dk[$s] ?? $s, $segs), end($segs), 8 * count($segs) - 7];
    }

    /** One cell into its nested place. A scalar parent gives way to a nested value, but a null never replaces it, and a null parent cell never wipes the object its nested columns built. */
    private static function put(array &$row, array $keys, mixed $val): void
    {
        $ref = &$row;
        foreach ($keys as $k) {
            if (!is_array($ref)) {
                if ($val === null && $ref !== null) {
                    return;
                }
                $ref = [];
            }
            $ref = &$ref[$k];
        }
        if ($val !== null || !is_array($ref)) {
            $ref = $val;
        }
    }

    private function header(array $meta, bool $instructions = true): string
    {
        $ins = $this->s['instructions'] ?? [];
        $h = '';
        foreach ($this->inReply() && $instructions ? $ins : [] as $n => $b) {
            $h .= "$n: " . (preg_match('/[\r\n]/', $b) ? self::quote($b) : $b) . "\n";
        }
        $h .= "~{$this->s['id']}@{$this->v}" . ($ins && $this->style() === 'coded' ? ' i:+' : '');
        foreach ($meta as $k => $v) {
            $c = (string)($this->kf[$k] ?? self::u8((string)$k));
            $t = is_array($v) || is_object($v) ? self::quote($this->js($v, true, $c)) : $this->cell($v, $c, true);
            $t === '' || $h .= ' ' . $this->label(self::name($c, true), $c) . ":$t";
        }
        return $h;
    }

    /** True when the instruction lines head the reply: plain style with instructions_at reply. */
    private function inReply(): bool
    {
        return $this->style() === 'plain' && ($this->s['instructions_at'] ?? 'reply') === 'reply' && ($this->s['instructions'] ?? []);
    }

    /** The written path of a column or meta key, plus (÷K) when plain and $key is scaled. $key is the code of the last segment of a column path, or the whole key of a meta entry, as cell() reads it. */
    private function label(string $path, string $key): string
    {
        $m = $this->style() === 'plain' ? $this->sc[$key] ?? null : null;
        return $m ? "$path(÷" . self::num($m, null) . ')' : $path;
    }

    /** A name as a column line writes it: each character that would break a line or a label as \uXXXX, and a leading [ too. $hdr also escapes a space, which ends a header key (spec 6.1). */
    private static function name(string $n, bool $hdr = false): string
    {
        $n = strtr($n, $hdr ? self::NAME_ESC + [' ' => '\u0020'] : self::NAME_ESC);
        return str_starts_with($n, '[') ? '\u005b' . substr($n, 1) : $n;
    }

    /** A written name back to the name. A written name holds no raw backslash, so every \uXXXX in it is an escape. */
    private static function unname(string $n): string
    {
        return str_contains($n, '\u') ? preg_replace_callback('/\\\\u([0-9a-f]{4})/', fn($m) => json_decode('"\u' . $m[1] . '"') ?? $m[0], $n) : $n;
    }

    private function off(array $tail, bool $instructions = true): string
    {
        $ins = $this->s['instructions'] ?? [];
        return $this->js((object)(['toklex' => 'off', 'schema' => "{$this->s['id']}@{$this->v}"]
            + ($ins && $instructions && ($this->style() === 'coded' || $this->inReply()) ? ['instructions' => (object)$ins] : []) + $tail), false);
    }

    /** One record as [dotted column => [code of the last segment, value]]. A non-empty object or map expands, anything else is a cell. */
    private function flat(array|object $row, string $path = ''): array
    {
        $out = [];
        foreach ((array)$row as $k => $v) {
            $c = (string)($this->kf[$k] ?? self::u8((string)$k));
            $p = $path === '' ? self::name($c) : "$path." . self::name($c);
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
        $scaled = isset($this->sc[$c]);
        // a database driver hands numbers over as strings, so a plain decimal in a scaled field is that number, scaled on its digits with no float step (spec 6.3)
        if ($scaled && is_string($v) && preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/D', $v)) {
            return self::scaled($v, self::num($this->sc[$c], null));
        }
        if (is_int($v) || is_float($v)) {
            return self::num($v, $this->sc[$c] ?? null) ?? '';
        }
        if (is_array($v) || is_object($v)) {
            return str_replace('|', self::BAR, $this->js($v, true, $c));
        }
        $v = self::u8((string)$v);
        if (isset($this->vf[$c][$v])) {
            return (string)$this->vf[$c][$v];
        }
        $quote = $scaled || $v === '' || preg_match('/^["\[{]|[|*\n\r]/', $v) || isset($this->dv[$c][$v])
            || preg_match(self::NUM, $v) || in_array($v, ['true', 'false', 'null'], true)
            || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}\+[0-9]+d$/D', $v) || ($hdr && preg_match('/[' . self::WS . '"]/', $v));
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
                $o[] = json_encode($c, self::JF) . ':' . $this->js($e, $coded, $this->style() === 'coded' ? $c : ''); // plain scales no member of an object (spec 6.3)
            }
            return '{' . implode(',', $o) . '}';
        }
        if (is_int($x) || is_float($x)) {
            return self::num($x, $coded ? ($this->sc[$k] ?? null) : null) ?? 'null';
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
        if (!preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?e([+-]?[0-9]+)$/i', $s, $p)) {
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

    /**
     * A value with only scalars, arrays and stdClass left in it (spec 11). A list or map that sits MAX_DEPTH levels
     * below the record, or deeper, is null, and so is an object where it repeats inside itself.
     */
    private static function norm(mixed $v, int $level, array $on = []): mixed
    {
        if (is_object($v)) {
            $id = spl_object_id($v);
            if (isset($on[$id])) {
                return null;
            }
            $on[$id] = true;
            $v = self::val($v);
        }
        if (!is_array($v) && !is_object($v)) {
            return $v === null || is_scalar($v) ? $v : null; // a resource
        }
        if ($level >= self::MAX_DEPTH) {
            return null;
        }
        $o = [];
        foreach ((array)$v as $k => $x) {
            $o[$k] = self::norm($x, $level + 1, $on);
        }
        return is_object($v) ? (object)$o : $o;
    }

    /**
     * An object as JSON would carry it: a date-time as ISO 8601 text, an enum case by its value or name, a JsonSerializable
     * by what it returns, an ArrayObject by its array, a closure as null, any other Stringable as its text and any other
     * object as a stdClass of its public properties. A chain of more than 8 JsonSerializable steps is null.
     */
    private static function val(object $v): mixed
    {
        for ($i = 0; $i < 8 && is_object($v) && !$v instanceof \stdClass; $i++) {
            $v = match (true) {
                $v instanceof \DateTimeInterface => self::iso($v),
                $v instanceof \BackedEnum => $v->value,
                $v instanceof \UnitEnum => $v->name,
                $v instanceof \JsonSerializable => $v->jsonSerialize(),
                $v instanceof \ArrayObject, $v instanceof \ArrayIterator => $v->getArrayCopy(),
                $v instanceof \Closure => null,
                $v instanceof \Stringable => (string)$v,
                default => (object)get_object_vars($v),
            };
        }
        return is_object($v) && !$v instanceof \stdClass ? null : $v;
    }

    /** The date alone at midnight, so that a date column stays a date sequence. Otherwise the time, a fraction when there is one, and the offset. */
    private static function iso(\DateTimeInterface $d): string
    {
        if ($d->format('His.u') === '000000.000000') {
            return $d->format('Y-m-d');
        }
        return $d->format('Y-m-d\TH:i:s' . ($d->format('u') === '000000' ? '' : '.u') . 'P');
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
        if (isset($this->dv[$c][$t])) {
            return $this->dv[$c][$t];
        }
        if (preg_match(self::NUM, $t)) {
            return $this->dec(json_decode($t), $c);
        }
        if ($t === 'true' || $t === 'false') {
            return $t === 'true';
        }
        if ($t[0] === '[' && is_array($j = json_decode($t, true, self::MAX_DEPTH + 1))) {
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
                $o[$list ? $n : ($this->dk[$c] ?? $c)] = $this->dec($e, $list ? $k : ($this->style() === 'coded' ? $c : ''));
            }
            return $o;
        }
        if (is_string($x)) {
            return $this->dv[$k][$x] ?? $x;
        }
        return (is_int($x) || is_float($x)) && ($m = $this->sc[$k] ?? null) ? $x / $m : $x;
    }

    /** A code that a cell, a column name or a header value could not carry as plain text. */
    private static function badCode(string $c): bool
    {
        return $c === '' || preg_match('/[|*:"' . self::WS . ']|^[\[{]/', $c) || preg_match(self::NUM, $c) || in_array($c, ['true', 'false', 'null'], true);
    }

    private static function check(array $s): void
    {
        $fail = static fn(string $why) => throw new \InvalidArgumentException("Toklex schema: $why");
        $hint = ' (add "style": "coded" to keep v0.1 names)';
        ($s['toklex'] ?? null) === 1 || $fail('toklex must be 1');
        (is_string($s['id'] ?? null) && preg_match('/^[a-z][a-z0-9]{0,7}$/D', $s['id'])) || $fail('id must match [a-z][a-z0-9]{0,7}');
        $plain = ($s['style'] ?? 'plain') !== 'coded';
        in_array($s['style'] ?? 'plain', ['plain', 'coded'], true) || $fail('style must be plain or coded');
        in_array($s['instructions_at'] ?? 'reply', ['reply', 'server'], true) || $fail('instructions_at must be reply or server');
        ($plain || !isset($s['instructions_at'])) || $fail('instructions_at needs style plain');
        $keys = $s['keys'] ?? [];
        is_array($keys) || $fail('keys must be an object');
        foreach ($keys as $c => $full) {
            is_string($full) || $fail("keys.$c must be a string");
            ((string)$c === 'i' || (string)$c === 'e') && $fail("key code $c is reserved");
            self::badCode((string)$c) && $fail("key code $c is not a plain token");
            str_contains((string)$c, '.') && $fail("key code $c holds a dot, which marks a nested column");
            $plain && self::badCode($full) && $fail("name $full of keys.$c is not a plain token$hint");
            $plain && str_contains($full, '.') && $fail("name $full of keys.$c holds a dot, which marks a nested column$hint");
            $plain && str_contains($full, '(÷') && $fail("name $full of keys.$c holds (÷, which a reader takes for a scale label$hint");
        }
        count(array_unique($keys)) === count($keys) || $fail('a full name occurs twice in keys');
        $values = $s['values'] ?? [];
        is_array($values) || $fail('values must be an object');
        foreach ($values as $f => $map) {
            is_array($map) || $fail("values.$f must be an object");
            foreach ($map as $c => $full) {
                is_string($full) || $fail("values.$f.$c must be a string");
                self::badCode((string)$c) && $fail("value code $c in values.$f is not a plain token");
                preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}\+[0-9]+d$/D', (string)$c) && $fail("value code $c in values.$f has the shape of a date sequence");
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
        foreach ($plain ? array_keys($ins) : [] as $name) {
            self::badCode((string)$name) && $fail("instruction name $name is not a plain token$hint");
            str_starts_with((string)$name, '~') && $fail("instruction name $name starts with ~, which marks the header");
        }
        foreach (['dense', 'auto'] as $m) {
            (is_array($s[$m] ?? []) && ($s[$m] ?? []) === array_filter($s[$m] ?? [], 'is_string')) || $fail("$m must be a list of tool names");
        }
        ($both = array_intersect($s['dense'] ?? [], $s['auto'] ?? [])) && $fail('tool ' . reset($both) . ' is in both dense and auto');
    }
}
