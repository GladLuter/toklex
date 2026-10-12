# Toklex

Toklex shrinks the replies of MCP tools. Most of a JSON reply is keys, quotes and whitespace, and the model pays for every token of it. In the default style, called plain, a reply is a table. A header line, such as `~ws@6425`, names the schema and its version, and the line after it holds the full column names. A scaled column says so in its name, as in `rain_m(÷10000)`. The owner's instructions stand as lines in front of the header. The model needs nothing else to read it.

The first version of Toklex used short codes for names and values. It is now called coded. The model had to fetch a schema through a tool before it could read a reply. That fetch is an extra turn, and every turn sends the whole context again. In a live run on a production server the extra turn cost more than the codes saved (see [Production measurements](#production-measurements)). A plain table keeps most of the saving and has no extra turn. In the offline bench it saves 71% in safe mode where coded saves 76%.

Coded is still there. Put `"style": "coded"` in the schema and you get the v0.1 behaviour, with the `toklex_schema` tool, the contract line and `toklex_calibrate`.

This repository holds the protocol ([spec/toklex.md](spec/toklex.md)), a PHP library, a conformance suite and an offline bench. Status is draft v0.2.

## Example

The schema is [examples/stations.schema.json](examples/stations.schema.json). It has no `style`, so it is plain. The three records below are synthetic. The replies were produced by the PHP library with that schema and are not edited.

Pretty JSON, the usual reply:

```json
[
    {
        "station_id": "st-101",
        "name": "North Ridge",
        "terrain": "mountain",
        "network": "net_a",
        "wind_level": "MEDIUM",
        "rain_m": 0.0743,
        "snow_m": 0.0512,
        "hail_m": 0.0388
    },
    ...two more records like this
]
```

Plain, safe mode:

```
rule: Use only the fields in this reply. Do not compare them with anything else and do not forecast.
notice: Readings come straight from the stations. They are not a forecast.
~ws@6425 date:2026-09-30
station_id|name|terrain|network|wind_level|rain_m(÷10000)|snow_m(÷10000)|hail_m(÷10000)
st-101|North Ridge|mountain|net_a|MEDIUM|743|512|388
st-102|South Ridge|mountain|net_a|MEDIUM|751|498|401
st-103|Bay Point|coast|net_b|HIGH|1534|120|905
```

Plain, dense mode:

```
rule: Use only the fields in this reply. Do not compare them with anything else and do not forecast.
notice: Readings come straight from the stations. They are not a forecast.
~ws@6425 date:2026-09-30
[3] One line per column: name:v1|v2|… x*K is x repeated K times, start+Nd is a date every N days, an empty cell is null.
station_id:st-101|st-102|st-103
name:North Ridge|South Ridge|Bay Point
terrain:mountain*2|coast
network:net_a*2|net_b
wind_level:MEDIUM*2|HIGH
rain_m(÷10000):743|751|1534
snow_m(÷10000):512|498|120
hail_m(÷10000):388|401|905
```

`743` is `0.0743` because the column name says `(÷10000)`. `mountain*2` means two equal cells in a row. The dense block explains itself on the count line. Nothing has to be fetched.

Coded, safe mode, from the same schema with `"style": "coded"` added. The version depends on the exact bytes of the file, so the copy used here reads `f433`:

```
~ws@f433 i:+ t:2026-09-30
id|n|c|p|l|v|u|d
st-101|North Ridge|mt|na|m|743|512|388
st-102|South Ridge|mt|na|m|751|498|401
st-103|Bay Point|co|nb|h|1534|120|905
```

`mt` is `mountain`, `m` is `MEDIUM`, and the schema scales `rain_m` by 10000. The model learns this from the schema text, which it gets from `toklex_schema`. The mark `i:+` says that the instructions in the schema apply.

## Modes

| Mode | What the reply is | Use it when |
|---|---|---|
| `safe` | A table. One line per record. | The default for most tools. |
| `dense` | One line per column, with runs (`MEDIUM*2`) and date sequences (`2026-07-01+1d`). | Long result sets with repeated values and dates. |
| `off` | Compact JSON with full names and the instructions. | The model misreads the table, or you need plain JSON. |

A tool is safe by default. The schema lists the tools that are dense by default in `dense`.

Every tool gets an optional argument `toklex_mode` with the values `off`, `safe` and `dense`. The library removes it before your tool runs.

The style is a separate choice, made in the schema.

| | Plain (default) | Coded |
|---|---|---|
| Field names | Full names. | Short codes. |
| Scaled field | `rain_m(÷10000)`, the cell holds the value times 10000. | The schema holds the scale. |
| Value codes | None. | Listed in the schema. |
| Instructions | Lines in front of the table, or the MCP `instructions` field. | In the schema, marked by `i:+`. |
| Errors | `error:` and the full text. | `e:` and a code. |
| Extra tools | None. | `toklex_schema`, `toklex_calibrate`. |

In coded style the tool `toklex_calibrate` checks which mode a model reads well. Without arguments it returns a small dataset in both forms and six questions. With `answers` it grades them and recommends a mode. The server keeps no state between the two calls. A plain server has no such tool. It serves one resource, `toklex://modes`, a short text about the three modes.

## Numbers

### Synthetic bench

These come from the offline bench in [bench/](bench/). The data is synthetic, generated by `bench/make_data.py` with seed 7 for five tools. Tokens are counted with o200k_base. The baseline is pretty JSON that includes the instruction notice. A plain reply carries the instructions too, so the comparison is fair.

| Mode | Plain | Coded |
|---|---|---|
| safe | -71% | -76% |
| dense | -82% | -88% |
| off | -33% | -33% |

Three readers (two Claude Haiku, one Claude Sonnet) answered ten questions on each plain format. The safe table with its `(÷K)` labels scored 30 of 30, and the dense block with its count-line sentence scored 30 of 30.

For coded there is an older test. Haiku answered ten questions per mode, reading only the text. On this dataset one reader per mode scored 10 of 10 in safe, 10 of 10 in dense with every date right, and 8 of 10 in off. Earlier runs on data with the same shape scored 19 of 20 in safe, 19 of 20 in dense with the dates 8 of 8, and 10 of 10 in off. The misses were counts across many rows, a task small models find hard in any format.

Earlier research measured the tokenizer of Claude itself. A tree layout cost 1.6 times the tokens of a table. Dropping the row key from the table lost accuracy. Safe mode writes the record key in full on every record.

The bench is synthetic and the reading tests used small models. The live run is below.

To run the bench:

```
python bench/make_data.py
php bench/encode.php examples/stations.schema.json bench/out/enc
python bench/tokens.py
```

`bench/tokens.py` needs `pip install -r bench/requirements.txt`. The example schema is plain, so this measures plain. To measure coded, copy the schema, add `"style": "coded"` and encode the copy.

### Production measurements

These come from a production MCP server with seven read-only tools. Every reply carries the owner's rules, about 118 tokens. The data is not synthetic. The model is Claude Sonnet in Claude Code (`claude -p`) and the harness is `bench/live.py`.

Replies from all seven tools, counted with o200k_base, against the server's own JSON replies:

| Style | JSON | off | safe | dense |
|---|---|---|---|---|
| coded (v0.1, measured 2026-10-05) | 7943 | -24% | -71% | -77% |
| plain (v0.2, measured 2026-10-11) | 7934 | -24% | -57% | -61% |

Plain saves less here than in the synthetic bench because every short reply carries the 118 tokens of rules and full names.

The fixed cost per session differs too. Coded added 706 tokens to the tool list, a 571-token schema reply and an extra turn to fetch it. Plain adds 257 tokens to the tool list and nothing else.

Coded sessions, 2026-10-05, 3 questions with 1 run each, against the same server's JSON route:

| | JSON | safe | dense |
|---|---|---|---|
| Cost | base | +17% | +12% |
| Turns over the 3 questions | 13 | 18 | 16 |

Each Toklex session took 1-2 extra turns to fetch the schema. Every turn sends the whole context again, about 58,000 tokens per turn in this setup. That cost more than the compression saved.

Plain sessions, 2026-10-11, 3 questions with 3 runs each, average per question:

| | JSON | plain |
|---|---|---|
| Turns | 4.44 | 4.22 (-5%) |
| Input tokens | 255,480 | 239,874 (-6%) |
| Cost | $0.168 | $0.160 (-5%) |
| Tool-result tokens | 2521 | 1153 (-54%) |

There were no schema calls. Dense in this run has no result, because one question lost two runs to a usage limit.

Reading: no misread numbers in the plain answers. In 2 of 9 answers the model showed the raw form to the user, such as `1032 (÷10000 = 10.32%)`. The value is right and the label leaked into the answer. The advice under `scale` in "Writing a schema" is about this. The follow-up run below had no leak.

The samples are small. The turn count varies within a variant about as much as between variants. Read the plain result as "the per-session penalty is gone" and not as "saves 5%". The context of about 57,000 tokens per turn includes the tester's own plugins. That inflates the absolute numbers and shrinks the relative effect of smaller replies. Comparisons within a run are fair.

After the label leak, the server sent each number a person reads in that person's unit: percent with two decimals, and one field with one decimal. It dropped `scale`. The library did not change. This run is from 2026-10-12.

Replies from all seven tools, o200k_base, against the JSON route (7934 tokens):

| | off | safe | dense |
|---|---|---|---|
| Tokens | 5988 (-25%) | 3649 (-54%) | 3221 (-59%) |

Against the scaled form of the day before (safe 3417, dense 3065), safe is 6.8% longer and dense 5.1% longer.

Sessions with Claude Sonnet in Claude Code, 3 questions with 3 runs each, JSON and plain in one window, average per question:

| | JSON | plain |
|---|---|---|
| Turns | 4.00 | 4.11 (+3%) |
| Input tokens | 230,486 | 234,614 (+2%) |
| of which cache writes | 28,980 | 22,566 (-22%) |
| of which cache reads | 201,499 | 212,041 (+5%) |
| Output tokens | 913 | 921 |
| Cost | $0.165 | $0.142 (-14%) |
| Tool-result tokens | 2719 | 1168 (-57%) |

There were no schema calls. The model showed the raw form to the user in 0 of 9 answers, down from 2 of 9. All values matched the server's own figures.

Cost falls while input does not because almost all input is the repeated context, read from the prompt cache. A shorter tool reply mostly saves cache writes, and a cache write costs 12.5 times a cache read. So the saving shows in cost and in cache writes, and not in total input tokens.

The cache-write drop (6.4 thousand per question) is larger than the reply drop (1.5 thousand), so part of it is noise from which sessions hit a warm cache. Across the two plain runs, cost was 5% and 14% below JSON. The direction held and the size is not settled. Turns and input were 2-3% above JSON in this run and below it in the previous one, which is within the spread.

## What it costs

Toklex adds a fixed cost to a session. The numbers are counted with o200k_base for the example schema and a server with seven tools.

Plain adds the property `toklex_mode` to each tool and nothing else. The seven tools are `get_station`, `find_stations`, `station_history`, `wind_counts`, `list_followed`, `get_network` and `list_terrains`. Their list grows from 230 to 432 tokens. That is 202 in all, about 29 per tool. There is no schema reply and no extra tool.

In the bench a plain safe reply saves 907 tokens per call on average against pretty JSON, and a dense reply saves 1040. A session of average calls pays the cost back on its first call. Small replies save less. `get_station` saves 49 tokens in safe mode, so a session made of such calls needs five of them to break even.

Coded costs more. The table counts the same seven tools with the same descriptions.

| Part | Tokens |
|---|---|
| Contract line and `toklex_mode` on each tool | about 59 per tool, 412 for seven |
| The tools `toklex_schema` and `toklex_calibrate` | 200 |
| The reply of `toklex_schema` | 429 |
| Total for seven tools | 1041 |

The tool list stays in context for the whole session. The schema reply comes once, when the model first calls `toklex_schema`, and it takes an extra turn.

In the bench a coded safe reply saves 967 tokens per call on average, and a dense reply saves 1120. A session of average calls pays the cost back on its second call. `get_station` saves 110 tokens in safe mode, so a session made of such calls needs ten of them to break even. A session with one small call costs more than plain JSON.

## Quick start for PHP

PHP 8.1 or newer. Copy `php/src/Toklex.php` into your project, or install with Composer as `toklex/toklex`.

```php
use Toklex\Toklex;

$tx = Toklex::fromFile(__DIR__ . '/stations.schema.json'); // throws InvalidArgumentException on a bad schema

// tools/list: adds toklex_mode to every tool
$tools = $tx->withTools($myTools);

// tools/call
$mode = $tx->mode($name, $args);   // removes toklex_mode from $args
$rows = runMyTool($name, $args);   // a list of associative arrays
return $tx->encode($name, $rows, ['date' => '2026-09-30'], $mode);
```

Return each reply as one MCP `text` content block and add no `structuredContent`.

Rows from PDO or another database driver can go in as they are. In a scaled field the library reads a number string such as `"0.092375"` as the number. A string with an exponent, such as `"4e-5"`, stays a quoted string and is not scaled. MySQL and MariaDB can send a `DOUBLE` column that way, so cast it to a number in your query code first.

By default the instructions go in front of every reply. If your client passes the MCP `instructions` field to the model, you can set `"instructions_at": "server"` in the schema and send them once, in the `initialize` result:

```php
return [
    'protocolVersion' => $protocolVersion,
    'capabilities' => $capabilities,
    'serverInfo' => $serverInfo,
    'instructions' => $tx->instructionsText(),
];
```

Check this before you rely on it. As of October 2026 claude.ai, Claude Desktop and the API MCP connector do not pass `instructions` to the model. A safety rule placed there never reaches it. Claude Code passes the field but cuts it at 2048 characters, so a long rule would lose its end. With `instructions_at` set to `server` no reply carries the rules, `off` replies included.

Other methods:

- `$tx->error('nf', $mode)` encodes an error. It takes a code from `values.e` or the full message.
- `$tx->decode($reply)` reads a reply back into full names and values. Use it in tests.
- `$tx->version()` returns the schema version.
- `$tx->style()` returns `plain` or `coded`.
- `$tx->resources()` and `$tx->readResource($uri)` serve the optional resources. A plain server has `toklex://modes`. A coded server also has `toklex://schema`.

### Coded style

With `"style": "coded"` the library appends the two Toklex tools to the list and adds a contract line to each description. Your `tools/call` handles them too:

```php
switch ($name) {
    case Toklex::SCHEMA_TOOL:
        return $tx->schemaText();
    case Toklex::CALIBRATE_TOOL:
        return $tx->calibrate($args);
    default:
        // the plain code above
}
```

`withTools` warns when a tool description goes over 2048 characters, because some clients cut it there and the contract line sits at the end.

## Writing a schema

A schema is one JSON file. `toklex` and `id` are required, the rest is optional.

```json
{
  "toklex": 1,
  "id": "ws",
  "values": { "e": { "nf": "No station with that id." } },
  "scale": { "rain_m": 10000 },
  "instructions": { "rule": "Use only the fields in this reply." },
  "dense": ["station_history"]
}
```

- `id` is up to 8 characters, `[a-z][a-z0-9]{0,7}`.
- `style` is `plain` or `coded`. Without it the style is plain. A v0.1 schema has no `style`, so it switches to plain unless you add `"style": "coded"`. The file changes then, and so does its version. A v0.1 schema whose `keys` hold a full name with a space, a `|`, a `:` or a `.`, or whose instruction names are not plain tokens, fails to load under plain. Add `"style": "coded"` and it keeps working.
- `instructions_at` is `reply` or `server`. It applies to plain style only. See the quick start.
- `keys` maps a short code to a field name. In coded style the code is what a reply carries. In plain style a reply carries the name, and `keys` only links a code to `scale`. The codes `i` and `e` are reserved in both styles, and the loader rejects them.
- `values` maps a field code to its value codes. Coded style writes them. Plain style uses only the member `e`, which holds error messages.
- `scale` maps a field code to an integer of at least 10. A number travels as `x * scale`, rounded half away from zero, and the reader divides it back. Pick the precision you publish, as with DECIMAL(10,4). A code that `keys` does not list stands for itself, so the example above can name `rain_m` directly.

  Advice for a number that a person will read: a scaled field saves tokens, but the model may repeat the scale to the person it answers. In the production run, 2 of 9 plain answers showed the raw form, such as `1032 (÷10000 = 10.32%)`. In a later test, Claude Sonnet got the same table in two forms and wrote four answers for a user per form. With `snow_m(÷10000)` cells, all values were right and 3 of 4 answers explained the scaling ("divided by 10,000"). With the values in the user's unit (`snow_mm`, cell `80.1`), all values were right and none mentioned a conversion. The unit form cost 16% more tokens for that table, which had two scaled columns of five. So send such a number in the unit the person sees, rounded to the precision you publish. Put the unit in the field name (`rain_mm`, `share_pct`) and leave the field out of `scale`. Keep `scale` for numbers that the model uses but rarely quotes.
- `instructions` holds named text blocks. In plain style each one is a line in front of the reply, or goes to the `instructions` field. In coded style all of them apply to a reply marked `i:+`.
- `dense` lists the tools that answer in dense mode by default.

The loader rejects codes that would be ambiguous in a cell, such as a code with a space, a `|` or a `*`, or one that looks like a number. A field name that is not in `keys` goes out as it is. In coded style, list any name that holds a `|`, a `:` or a space, so that a code goes out instead. In plain style the name itself is written, and such a name would break the table. A `|` breaks the column line, a `:` breaks a dense line, a space breaks a header key, and a dot reads back as a nested field. A name that holds `(÷` would be read as a scale label. The loader rejects all of these names in `keys`. Do not use them for a field that is not listed either.

The version is the first four hex characters of the SHA-256 of the file, with CRLF turned into LF. Any edit changes it, a space included. In coded style a changed version makes the model fetch the schema again. A needless refetch costs a few tokens. An unnoticed change could make the model read codes wrongly. In plain style the version only names the schema in the header.

## Porting to another language

[spec/toklex.md](spec/toklex.md) is the protocol. Appendix A holds every text the model sees, byte for byte. [spec/fixtures/](spec/fixtures/) is the conformance suite. It has 47 encode and decode cases (33 coded and 14 plain), 22 schemas that must be rejected at load, a mode table and the version hash.

A port passes when it matches every fixture. For the PHP library:

```
php php/tests/run.php
```

The runner prints `OK <n> checks` and exits with 0, or lists the failures and exits with 1. It also compares the library output with Appendix A.

## Licence and citation

Apache-2.0, see [LICENSE](LICENSE). Keep [NOTICE](NOTICE) when you redistribute the code or a port.

If you use Toklex in research or in a product write-up, cite it with [CITATION.cff](CITATION.cff). GitHub shows a "Cite this repository" button from that file.

Author: Yevgenii Slieptsov (GladLuter). Source: https://github.com/GladLuter/toklex
