# Toklex

Toklex shrinks the replies of MCP tools. Most of a JSON reply is keys, quotes and whitespace, and the model pays for every token of it. In the default style, called plain, a reply is a table. A header line, such as `~ws@6425`, names the schema and its version, and the line after it holds the full column names. A scaled column says so in its name, as in `rain_m(÷10000)`. The owner's instructions stand as lines in front of the header. The model needs nothing else to read it.

The first version of Toklex used short codes for names and values. It is now called coded. The model had to fetch a schema through a tool before it could read a reply. That fetch is an extra turn, and every turn sends the whole context again. In a live run on a production server the extra turn cost more than the codes saved (see [Production measurements](#production-measurements)). A plain table keeps most of the saving and has no extra turn. In the offline bench it saves 71% in safe mode where coded saves 76%.

Coded is still there. Put `"style": "coded"` in the schema and you get the v0.1 behaviour, with the `toklex_schema` tool, the contract line and `toklex_calibrate`.

This repository holds the protocol ([spec/toklex.md](spec/toklex.md)), a PHP library, ports to Python and TypeScript, a proxy that puts Toklex in front of any stdio MCP server, a conformance suite and an offline bench. Status is draft v0.3. Section 15 of the spec lists what changed from v0.2.

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
| `dense` | One line per column, with runs (`MEDIUM*2`) and date sequences (`2026-07-01+1d`). | A long series of one record over dates, such as a history. |
| `off` | Compact JSON with full names and the instructions. | The model misreads the table, or you need plain JSON. |

A tool is safe by default. The schema lists the tools that are dense by default in `dense`. It lists the tools that pick a mode per reply in `auto`. An auto tool answers dense when the reply has at least 10 records and a date column that rises by the same number of days from each record to the next. Otherwise it answers safe. A history with the newest date first has falling dates, so it stays safe. This rule follows the production run of 2026-10-19 below. Dense was the cheapest form of a 30-day history there, and it cost more than safe on a short list.

Dense makes the reply shorter, but the model has to put the rows back together from the columns, and that costs output tokens. Output costs more than input. On a short list with many columns dense can cost more than safe. Measure dense by what a session costs. The production run of 2026-10-19 below shows both cases.

Every tool gets an optional argument `toklex_mode` with the values `off`, `safe` and `dense`. The library removes it before your tool runs. A caller cannot ask for `auto`. It is the server's choice.

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

These come from the offline bench in [bench/](https://github.com/GladLuter/toklex/tree/main/bench). The data is synthetic, generated by `bench/make_data.py` with seed 7 for five tools. Tokens are counted with o200k_base. The baseline is pretty JSON that includes the instruction notice. A plain reply carries the instructions too, so the comparison is fair.

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

The numbers above count with o200k_base, which is OpenAI's tokenizer and runs offline. `python bench/tokens.py --counter claude` counts with the Anthropic `count_tokens` endpoint instead. It uses the tokenizer of the model named by `--model`, `claude-opus-5-5` by default. Pass the model that reads the replies in your sessions. The production runs below used Claude Sonnet. The counter needs `pip install anthropic` and an API key in `ANTHROPIC_API_KEY`, or a login with `ant auth login`. An empty text counts 0 without a request.

`bench/live.py summarize` takes the same `--counter`, and there `--count-model` names the model. Per question it reports the mean, the standard deviation and the number of runs, because three runs per question vary about as much as the variants differ. One run has no spread, so its standard deviation is `null`. For a whole variant it reports the mean and the number of runs only, because those runs ask different questions. The summary goes to stdout and to `summary.json`. The single runs are in `summary.json` only.

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

There were no schema calls. Dense in this run has no result, because one question lost two runs to a usage limit. The run of 2026-10-19 below has it.

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

The run of 2026-10-19 measured dense, with the same server, library and questions. Each question ran 3 times per variant. In the dense variant every question asked for `toklex_mode=dense` in every call (`--mode dense`), so dense was used on every tool, the list included. The sessions ran with `--claude-args "--setting-sources project"`, which leaves out the tester's plugins and hooks. The context per turn fell to about 45,000 tokens, so the absolute numbers here are lower than above and cannot be compared with them.

The server allows 120 requests an hour, so the run went in three phases an hour apart. The first session of each phase wrote a cold cache and cost about twice as much as the others. Plain and dense each had one such session, JSON had none. The table gives the cost twice: over all sessions, and over the warm ones only.

| | JSON | plain safe | plain dense |
|---|---|---|---|
| Turns | 4.56 | 4.89 (+7%) | 4.67 (+2%) |
| Cost, all sessions | $0.091 | $0.103 (+13%) | $0.109 (+20%) |
| Cost, warm cache | $0.091 | $0.086 (-5%) | $0.092 (+1%) |
| Tool-result tokens | 2890 | 1304 (-55%) | 1150 (-60%) |
| Output tokens | 939 | 953 | 1717 (+83%) |

Output tokens per session, by question:

| | JSON | plain safe | plain dense |
|---|---|---|---|
| A list of 13 records, five columns | 884 | 892 | 2297 |
| A 30-day history of one record | 1055 | 1068 | 1697 |
| A comparison of two records | 879 | 899 | 1158 |

The answers to the user were the same length in all variants. The extra output in dense is the model's own work: it rebuilds 13 rows from five column lines and expands runs like `MEDIUM*2`. On the history the extra output is smaller, and the question took one turn fewer in dense (4.00 against 4.67 in safe), so dense was the cheapest variant there. On the list dense cost more than safe. Three sessions per question is a small sample, so read this as a direction.

Reading: all values in all answers matched the server. Dense notation (`[N]`, `x*K`, `start+Nd`) never reached an answer. One plain answer showed the schema version to the user.

Plain on a warm cache cost 5% less than JSON. Across the three plain runs that is 5%, 14% and 5%. Plain took more turns than JSON on the comparison question (7.00 against 5.67).

The same run tested coded once more. The description of `toklex_schema` said that the schema was already loaded, with the schema text after it. The model stopped calling `toklex_schema`: 0 of 9 sessions, down from 6 of 9. But Claude Code loads an MCP tool's description only when the model fetches the tool with `ToolSearch`, and the model fetched `toklex_schema` that way in 9 of 9 sessions. The extra turn changed form and stayed. Coded took 5.33 turns per question with the new description, 5.67 without it, and plain took 4.89. A client that puts all tool descriptions in context from the first turn should lose the extra turn. This harness cannot measure such a client.

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

Return each reply as one MCP `text` content block and add no `structuredContent`. `withTools` removes `outputSchema` from your tools for that reason, because MCP requires `structuredContent` from a tool that declares one. It accepts tools given as arrays or as `stdClass`. A warning goes to the callback you pass as the second argument, or to `error_log` without one. It never goes to stdout, which carries the messages of a stdio server.

`encode` takes a list of records. One record goes in as `[$row]`. A record that is not an array or an object makes it throw `InvalidArgumentException` with that advice.

Values that PHP keeps as objects go out as JSON would carry them:

- A `DateTimeInterface` is ISO 8601 text. At midnight it is the date alone.
- A backed enum is its value, and a pure enum is its name.
- A `JsonSerializable` is what it returns. After 8 such steps the value is null.
- An `ArrayObject` is its array. A `Stringable` is its text. A closure is null.
- Any other object is the map of its public properties.

A value that holds itself is null where it repeats. A list or map 64 levels below the record is null. So such data never makes `encode` fail.

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

The rules cost tokens on every reply, 118 per reply on the production server below. A server that keeps state per MCP session can send them once: pass `false` as the fifth argument of `encode` (or the third of `error`) for every reply after the first one of the session. The reply then starts with the header. This has a risk. A client that compacts or trims its context can drop the first reply, and the rules with it. Leave the rules in when they matter for safety, and measure before you switch.

Other methods:

- `$tx->error('nf', $mode)` encodes an error. It takes a code from `values.e` or the full message.
- `$tx->decode($reply)` reads a reply back into full names and values. Use it in tests. It refuses a reply that claims more than 100000 records, costs more than 500000 cells or has a column path of more than 64 names. Section 11 of the spec says how the cost is counted. At the limit the PHP reader takes about 80 MB, so a forged reply stays inside the default `memory_limit` of 128 MB. It also refuses a date sequence that starts at an invalid date or runs past 9999-12-31.
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

## Python and TypeScript

[python/](https://github.com/GladLuter/toklex/tree/main/python) and [js/](https://github.com/GladLuter/toklex/tree/main/js) are ports of the PHP library with the same rules, texts and method names (snake case in Python). Each passes the whole conformance suite. `tests/cross/fuzz.py` sends the same random records to all three libraries and checks that the replies are equal byte for byte and read back to the same rows. CI runs it on every pull request.

```python
from toklex import Toklex
tx = Toklex.from_file("stations.schema.json")
text = tx.encode(name, rows, {"date": "2026-09-30"}, tx.mode(name, args))
```

```ts
import { Toklex } from 'toklex';
const tx = Toklex.fromFile('stations.schema.json');
const text = tx.encode(name, rows, { date: '2026-09-30' }, tx.mode(name, args));
```

The Python port needs Python 3.9 or newer, the TypeScript port Node 22.18 or newer. Neither has runtime dependencies. Each folder has a README with the details for its language.

Two limits come from JavaScript. An object puts keys that look like array indexes, such as `"0"`, before its other keys. Pass a record as a `Map` when such names must keep their place, and the reply matches PHP byte for byte. `decode` returns plain objects, so there such keys come first. An integer above 2^53 has lost digits in a JavaScript number. Pass it as a `bigint`. `decode` returns a `bigint` for an integer cell outside the safe range.

## Proxy

`toklex-proxy`, from the Python package, puts Toklex in front of any stdio MCP server without a change to its code:

```
toklex-proxy --schema stations.schema.json -- php server.php
```

The proxy starts the server and relays every message. It changes four things.

- The tool list gets `toklex_mode` on every tool and loses `outputSchema`. With a coded schema the two Toklex tools go on the first page of the list.
- A call loses `toklex_mode` before the server sees it.
- A result that holds records gets a Toklex text block in place of its JSON text block. Its other content blocks stay, and its `structuredContent` goes.
- With a coded schema the proxy answers `toklex_schema` and `toklex_calibrate` itself.

A result holds records when its JSON text block, or its `structuredContent`, is one of these:

- a list of objects,
- an object with one member that is such a list, where the other members go to the header,
- an object of two or more members without such a list, which is one record.

An object with one member, such as `{"result": "hello"}`, only wraps a value and passes unchanged. Errors, free text and every other result pass unchanged too. So does a message the proxy cannot read, such as JSON nested too deep for Python. JSON-RPC batches are not supported. They pass unchanged, so a call in a batch keeps `toklex_mode` and its result stays JSON. MCP dropped batches in its 2025-06-18 revision.

`--instructions once` sends the rules with the first reply of the proxy process only, with the risk described above. The proxy cannot see a conversation. A client that keeps the server running across a new chat sends that chat no rules at all.

When the client closes the input of the proxy, the server gets 5 seconds to exit. Then the proxy sends it SIGTERM, and SIGKILL 5 seconds later. A proxy that gets SIGTERM ends its server too.

## Writing a schema

A schema is one JSON file. `toklex` and `id` are required, the rest is optional.

```json
{
  "toklex": 1,
  "id": "ws",
  "values": { "e": { "nf": "No station with that id." } },
  "scale": { "rain_m": 10000 },
  "instructions": { "rule": "Use only the fields in this reply." },
  "dense": ["station_history"],
  "auto": ["station_log"]
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
- `auto` lists the tools that pick dense or safe for each reply, as described under Modes. A tool is in `dense` or in `auto`, not in both.

The loader rejects codes that would be ambiguous in a cell, such as a code with a space, a `|` or a `*`, or one that looks like a number. In plain style it also rejects names in `keys` that hold a `|`, a `:`, a space, a dot or `(÷`, and instruction names that start with `~`.

A field name that is not in `keys` goes out as it is, apart from the characters that would break a line. The encoder writes a backslash, `|`, `:`, `÷`, a line break or a tab in a name as `\u` and four hex digits, such as `\u007c`, and a reader turns them back. A space stays as it is in a column name and is written `\u0020` in a header key. A dot still reads back as a nested field. Names from your own code rarely need this. It protects the table from names that come with the data.

The version is the first four hex characters of the SHA-256 of the file, with CRLF turned into LF. Any edit changes it, a space included. In coded style a changed version makes the model fetch the schema again. A needless refetch costs a few tokens. An unnoticed change could make the model read codes wrongly. In plain style the version only names the schema in the header.

## Porting to another language

[spec/toklex.md](spec/toklex.md) is the protocol. Appendix A holds every text the model sees, byte for byte. [spec/fixtures/](spec/fixtures/) is the conformance suite. It has 55 encode and decode cases (34 coded and 21 plain), 24 schemas that must be rejected at load, a mode table and the version hash.

A port passes when it matches every fixture. The Python and TypeScript ports in this repository are examples. For the three libraries:

```
php php/tests/run.php
python python/tests/run.py
node js/test/run.ts
```

Each runner prints `OK <n> checks` and exits with 0, or lists the failures and exits with 1. It also compares the library output with Appendix A. `python tests/cross/fuzz.py` then compares the three libraries on random data. A new port can join it with a small encode script like `tests/cross/encode.php`.

## Licence and citation

Apache-2.0, see [LICENSE](LICENSE). Keep [NOTICE](NOTICE) when you redistribute the code or a port.

If you use Toklex in research or in a product write-up, cite it with [CITATION.cff](CITATION.cff). GitHub shows a "Cite this repository" button from that file.

Author: Yevgenii Slieptsov (GladLuter). Source: https://github.com/GladLuter/toklex
