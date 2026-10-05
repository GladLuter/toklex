# Toklex protocol

Grammar version 1, draft v0.1.

> Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
>
> Licensed under Apache-2.0. See `LICENSE` and `NOTICE`.

## 1. What Toklex does

Toklex makes the results of MCP tools smaller. It also delivers the server owner's instructions to the model with every result.

The server publishes a schema. The schema holds short key codes, value codes, number scales and blocks of instructions. Every reply starts with a header that names the schema and its version. The model keeps the schema in context. When a header shows a version the model does not have, the model asks for the schema again with the tool `toklex_schema`.

A server can answer in three modes: `off`, `safe` and `dense`. The caller may pick one per call. Section 12 describes them.

Toklex encodes records, which are a list of objects, plus metadata. Metadata values are scalars. A tool that returns free text sends it as usual, without Toklex.

A library that implements this text fails in three cases only. It loads a broken schema (section 3.1). Its encoder gets the metadata key `i` or `e` (section 5). Its reader gets a dense reply that claims more than 100000 records (section 11). Other data does not make it fail. Unknown fields and values go out as they are.

## 2. Conventions

**Fixtures.** Each rule below names the fixtures that pin it. A bare name such as `02-quoting` is a file in `spec/fixtures/cases/`. A name that starts with `invalid/` is a file in `spec/fixtures/invalid/`. Every case uses `spec/fixtures/test.schema.json`. Section 10 describes the fixture formats. Section 11 lists the rules that no fixture pins yet. Section 12 describes the reply modes, section 13 the resources and section 14 calibration. Appendix A gives every text that goes to the model.

**Terms.** A record is one object of the result. A field is a key of a record. A column is one field of the result table. A nested field is a column with a dotted name. A cell is the text of one field of one record. A code is a short name from the schema.

**Text.** A reply is UTF-8 text. Lines are separated by a line feed (U+000A). A reply does not end with a line feed. Every case pins this. A reader splits lines at U+000A only. Other line breaks, such as U+2028, U+0085, a vertical tab or a form feed, can stand raw inside an unquoted cell and belong to it.

**Whitespace** means the six ASCII characters space, tab, line feed, vertical tab, form feed and carriage return (U+0020, U+0009 to U+000D). No other character counts, U+00A0 and U+2028 included. This holds for the code check of section 3.1, for the header quote trigger of section 5 and for the place where a reader ends a header value.

## 3. Schema file

The server owner writes the schema as a JSON file.

```json
{
  "toklex": 1,
  "id": "tx",
  "keys": { "id": "station_id", "n": "name", "l": "wind_level", "v": "rain_m" },
  "values": { "l": { "l": "LOW", "m": "MEDIUM", "h": "HIGH" },
              "e": { "nf": "No station with that id." } },
  "scale": { "v": 10000 },
  "instructions": { "rule": "Use only the fields in this reply." },
  "dense": ["station_history"]
}
```

- `toklex` is the grammar version. This text describes version 1.
- `id` is the schema id. It matches `[a-z][a-z0-9]{0,7}`.
- `keys` maps a code to a full field name.
- `values` maps a field code to a map from a code to a full value. The member `e` is reserved for error messages and does not stand for a field.
- `scale` maps a field code to an integer multiplier. It sets the publication precision of the field, as DECIMAL(10,4) does in a database. A number of that field travels as an integer, `x * multiplier` rounded by section 6.3. A reader divides it back. A string of that field that holds a plain decimal number counts as that number (section 6.3).
- `instructions` holds named blocks of text. All of them apply to a reply whose header carries `i:+`.
- `dense` lists the tools whose default mode is dense (section 7). A tool outside the list defaults to normal mode. The caller can override either default (section 12.2).

`toklex` and `id` are required. The other members may be left out and then count as empty.

### 3.1 Checks at load

A library rejects a file that breaks one of these rules, with an error raised at load time.

- `toklex` equals 1. Fixture: `invalid/grammar-2`.
- `id` exists and matches the pattern. Fixtures: `invalid/no-id`, `invalid/bad-id`.
- A full name occurs once in `keys`. Fixture: `invalid/full-name-twice`.
- A full value occurs once inside one field of `values`. Fixture: `invalid/value-twice`.
- Every multiplier in `scale` is an integer. Fixtures: `invalid/scale-fraction`, `invalid/scale-fraction-large`. The second one has a fraction above 10, so it breaks this rule alone.
- Every multiplier is at least 10. Fixture: `invalid/scale-small`.
- `keys` has no code `i` and no code `e`. The header owns them: `i:` marks instructions and `e:` carries an error. Fixtures: `invalid/reserved-code`, `invalid/reserved-code-e`.
- A code in `keys` or in a field of `values` is a plain token. It is not empty, it holds no `|`, `*`, `:`, whitespace or `"`, it does not start with `[` or `{`, it does not parse as a JSON number and it is not `true`, `false` or `null`. A code that broke this would make a cell or a column line ambiguous, because the encoder writes a code without quotes. Fixtures: `invalid/value-code-star`, `invalid/value-code-number`, `invalid/key-code-space`.
- A code in `keys` holds no `.`. A dot in a column name marks a nested field, so a key code with a dot would read back as two keys. Fixture: `invalid/key-code-dot`.
- A code in a field of `values` does not have the shape of a date sequence, `YYYY-MM-DD+Nd`. In a dense column such a code would read as a run of dates. Fixture: `invalid/value-code-date-sequence`.

## 4. Version

The version is the first 4 hexadecimal characters of the SHA-256 of the schema file, in lower case. The hash runs over the bytes of the file after every CRLF (`\r\n`) is replaced by LF (`\n`).

Fixture: `hash.json` holds the version of `test.schema.json`. A library also hashes a copy of that file with CRLF line endings and gets the same version.

Any change to the file changes the version, formatting included. A needless second fetch of the schema costs a few tokens. A version change that goes unnoticed can leave the model reading codes wrongly.

## 5. Header

The first line of every reply is the header.

```
~<id>@<version>[ i:+][ <key>:<value>]...
```

Elements are separated by one space.

- `~<id>@<version>` names the schema. Every case.
- `i:+` is present when the schema has at least one instruction block. It tells the model that the instructions apply to this reply. Every case, because the test schema has instructions.
- Metadata is written as `<key>:<value>`, in the order the encoder received it. The key is the field code, or the original name when the field is not in `keys`. A key that is not in `keys` follows the limits of section 6.1.
- The value is a cell of that field. Section 6.3 encodes it and section 6.4 quotes it. Two more triggers apply in the header: any whitespace character (space, tab, line feed, vertical tab, form feed or carriage return), and a `"` anywhere in the string. A reader ends a value at the first whitespace, so an unquoted one would be cut there. A quoted value is written as in section 6.4, so a `|` in it is written `\u007c`. Fixtures: `01-basic` (`t:2026-09-30`), `07-empty`, `11-dense`, `20-meta-quoting` (a space, quotes, a pipe, a number-like string, a coded value and a scaled number), `26-meta-whitespace` (a tab, a form feed and a vertical tab).
- A metadata value that is an array or a map is written as a JSON string. The encoder takes the compact JSON text of section 6.3, with keys and values coded, before the step that writes `|` as `\u007c`. Then it quotes that text by section 6.4, so each `|` becomes `\u007c` once. It starts with `[` or `{`, so section 6.4 quotes it, and a space inside it cannot cut the value. A reader returns that text as a string and does not decode it. Fixture: `27-meta-edge` (`labels` with a `|` inside, and `login` with a string that starts with `{`).
- A metadata value that is null is left out of the header. So is a number that is not finite (section 6.3). Fixture: `20-meta-quoting` (`labels`).
- The metadata keys `i` and `e` are reserved like the key codes of section 3.1, because the header owns `i:` and `e:`. An encoder that receives a metadata key `i` or `e`, as a name or as a code, rejects it with an error in every mode, off included.
- An error is `e:<code>` when the message is listed in `values.e`. Otherwise it is `e:` followed by the message as a JSON string, written as in section 6.4 and quoted whatever the message holds. A reply to an error is the header alone. Fixtures: `09-error-code` (the message is given as the code), `09-error-code-from-text` (the message is given as the full text, and the same line comes out), `10-error-text`, `10-error-one-word` (a message without a space is quoted too).

## 6. Normal mode

Normal mode is the default. A reply is the header, a line of columns, and one line per record.

```
~tx@{v} i:+ t:2026-09-30
id|n|l|v
st-1|North Ridge|m|743
st-2|Bay Point|h|1201
```

`{v}` stands for the version. This reply is the case `01-basic`.

### 6.1 Columns

Line 2 lists the columns, separated by `|`. A column is the field code, or the original name when the field is not in `keys`. Fixture: `06-scalars` pins the original names.

A nested object expands into columns. Each column name is the dotted path of codes, recursively, for example `us.lg`. A nested field that is not in `keys` keeps its name, for example `us.type`. Fixture: `05-nested`.

The last code of the path names the field. `values` and `scale` for a nested column are looked up under that last code, as for a top-level field, and section 6.4 compares a string with the value codes of that field. So `us.v` is scaled like `v`, and `us.l` takes the value codes of `l`. Fixture: `15-nested-scaled`.

A field name that is not in `keys` is written as it is, and Toklex does not escape it. A name that equals a code, such as `n`, reads back as the field of that code. A name with a `.` reads back as a nested path. A name with a `|` breaks the line of columns, and in dense mode a name with a `:` breaks the column line. A `*` in a name does no harm. The same holds for the keys of metadata, where a name with a space or a `:` breaks the header. A server whose data can hold such names lists those fields in `keys` under safe codes. Fixture: `16-unmapped-names` (`n` and `a.b`).

Columns are ordered by first appearance across the records, in record order. Fixtures: `01-basic`, `04-collisions` (the columns come out as `id|l|n`).

### 6.2 Records

Each record is one line. Cells are separated by `|`. Trailing empty cells are left out. Fixture: `03-nulls-missing`.

A record whose cells are all empty is written as a single `|`, so its line is never empty. A reader ignores any cell after the last column. Without this rule the last record could leave the reply ending in a line feed, and a reader that strips the end of the text would lose the record. Fixture: `17-empty-record` (one such record in the middle and one at the end).

The first column is the record key. Normal mode writes it in full for every record. Fixtures: all normal-mode cases.

When records have different fields, the table has the union of their columns. A record that lacks a field gets an empty cell in that column, and the cells after it keep their places. Fixtures: `03-nulls-missing`, `04-collisions`, `05-nested`.

A record line is split into cells by splitting at every `|`. A reader needs no quote scanner, because a `|` inside a string or an array is written as `\u007c` (section 6.4).

An empty result (`rows = []`) is the header line alone, with no line of columns. Fixture: `07-empty`.

### 6.3 Cell encoding

- `null` is an empty cell. Fixture: `03-nulls-missing`.
- `true` and `false` are written as they are. Fixture: `06-scalars`.
- A number in a field with a `scale` is written as an integer. Take the shortest decimal text of the number, as the next rule writes it. Multiply that decimal by the multiplier exactly, in decimal arithmetic and not in binary floating point. Round the product to an integer, and round a half away from zero. A result of zero has no minus sign. So 0.00015 with the multiplier 10000 gives 1.5 and becomes `2`, although the binary product of the two is 1.4999999999999998. Fixtures: `01-basic` (0.0743 becomes 743), `03-nulls-missing` (0.05 becomes 500), `08-scale-rounding` (0.123456 becomes 1235, 0.00015 becomes 2, -0.00015 becomes -2, 0.0001499999999999999 becomes 1, -0.00001 becomes 0).
- A string in a field with a `scale` counts as a number when its whole text is a plain decimal: an optional `-`, an integer part with no leading zero unless the part is `0`, and an optional `.` followed by digits. The encoder multiplies the digits of the string as written, by the decimal rule above. It never reads the string into a binary float first, so no digit is lost, a half is judged on the exact decimal, and the result is never infinite. So `"0.092375"` with the multiplier 10000 becomes `924`, and a reader returns the number `0.0924`. `"98765432109876543.21"` becomes `987654321098765432100`, and `"0.00014999999999999999999"` becomes `1`, where the float 0.00015 would give `2`. Database drivers often hand numbers over as strings, and this rule keeps a scaled column made of numbers. A string with an exponent, a `+`, a space, a leading zero or any other text stays a literal and section 6.4 quotes it. Inside an array and in off mode a string stays a string. In a field without a `scale` a string that looks like a number stays a quoted string and keeps its type (section 6.4). Fixtures: `29-scaled-numeric-strings` (`"0.092375"`, `"-0.5"`, `"12"` and `"0"` in a row and `"0.0743"` in the header become numbers, `"98765432109876543.21"` and `"0.00014999999999999999999"` keep every digit, while `"1e3"`, `"007"`, `" 12"`, `"12abc"` and `"NaN"` stay quoted), `30-scaled-numeric-strings-dense`, `31-scaled-numeric-strings-off`.
- Any other number is written in the shortest decimal form that reads back as the same number, without an exponent. A float with an integral value has no fraction part, so `5.0` is `5`. The text depends only on the number. A setting of the language runtime that changes float printing, such as `serialize_precision` in PHP, does not change it. Fixture: `06-scalars` (`0.0000001`, `12345678.9`, `-3.25`, `5`).
- A number that is not finite is null, so it is an empty cell, and `null` inside an array or in off mode. This covers infinity and NaN, and also a finite number that becomes infinite when the scale multiplies it. Fixture: `28-overflow-null`.
- A string that is a full value listed in `values` for this field is written as its code. Fixtures: `01-basic` (`MEDIUM` becomes `m`), `03-nulls-missing`.
- Any other string is written as it is, in UTF-8, unless section 6.4 asks for quotes. A space does not trigger quotes, and neither does a `"` inside the string. Fixtures: `01-basic` (`North Ridge`), `02-quoting` (`O"Neil`), `04-collisions` (`UNKNOWN`, `Côte Zéta Höhe`).
- An array is written as compact JSON without whitespace. Object keys inside it are replaced by key codes, and values are coded with the same schema. Non-ASCII characters and `/` are written as they are, without a `\uXXXX` escape. Every `|` in the JSON text is written `\u007c`. A string inside an array is a JSON string and keeps its JSON quotes. Section 6.4 covers cells only. If such a string equals a value code of its key, the encoder leaves it as it is and a reader turns it into the full value. The text changes in that case, which is a known limit of v0.1. A schema author avoids it by choosing codes that data does not use as text. Fixtures: `05-nested`, `14-array-unicode` (non-ASCII text inside an array), `18-array-code-collision`.

### 6.4 Quoted strings

A string is written as a JSON string in double quotes when any of these holds.

- It is empty. Fixture: `03-nulls-missing` (`a|""`).
- It starts with `"`, `[` or `{`. Fixture: `02-quoting` (`"Quoted" Point` and `[draft]`).
- It contains `|`, `*`, a line feed or a carriage return. Fixture: `02-quoting` (`A|B`, `5*star`, a line feed in `Line1` and `Line2`).
- It equals a value code of this field. Fixture: `04-collisions` (`m` in `wind_level`).
- It parses as a JSON number, by the number grammar of RFC 8259. Fixture: `04-collisions` (`12345`).
- It equals `true`, `false` or `null`. Fixture: `04-collisions` (`true`, `null`).
- It looks like a date sequence, which is an ISO date followed by `+`, one or more digits and `d`. Fixture: `04-collisions` (`2026-07-03+1d`).
- It is in a field with a `scale` and section 6.3 did not read it as a number. So every unquoted cell of a scaled field is a scaled number. Fixture: `29-scaled-numeric-strings` (`"12abc"`).

Inside a quoted string, `|` is written `\u007c`. Fixture: `02-quoting` (`"A\u007cB"`).

The quoted string follows JSON escaping: `"` becomes `\"` and a line feed becomes `\n`. Fixture: `02-quoting`. Non-ASCII characters and `/` are not escaped.

A quoted string is a literal. A reader never replaces it by a value and never scales it. This holds for a cell and for a metadata value. A string inside an array follows section 6.3 instead. Fixtures: `04-collisions` (`"m"` stays `m`, `"12345"` stays a string).

## 7. Dense mode

A tool listed in `dense` answers in dense mode by default. Any other tool answers in normal mode by default. A call can choose another mode (section 12.2). Fixtures: `11-dense`, `12-dense-irregular` and `13-dense-quoting` use the tool `station_history`, and all other cases use `get_station`.

```
~tx@{v} i:+ t:2026-07-05
[5]
t:2026-07-01+1d
l:m*3|h*2
v:743*2|750|800|810
u:*4|993
```

This reply is the case `11-dense`.

- Line 2 is `[N]`, the number of records. It marks the mode. Fixtures: `11-dense`, `12-dense-irregular`, `13-dense-quoting`.
- Each following line is one column, written `<column>:<items>`. Columns follow the rules of section 6.1. Items are separated by `|`.
- An item is a cell, encoded by section 6.3 and 6.4. Every column line covers all N records, empty cells included.
- A run of K equal cells, with K of 2 or more, is written `<cell>*K`. A run of empty cells is written `*K`. A run takes every equal neighbour, so `m*2|m*2` is written `m*4`. Fixture: `11-dense` (`m*3`, `743*2`, `*4`).
- A column of ISO calendar dates (`YYYY-MM-DD`) is written `<first>+<step>d` when it has at least 3 records, every record holds a valid date in that column, and the day step between neighbours is the same positive number. A date such as `2026-02-30` is not valid, and a record with no date in the column stops the sequence. The column line then holds that one item. Fixtures: `11-dense` (`t:2026-07-01+1d`), `12-dense-irregular` (the steps are 1 and 2, so the dates are listed), `13-dense-quoting` (two records, so the dates are listed).
- The run marker is the `*` and the digits at the end of an item. It follows the cell: a quoted string, an array or an unquoted cell. An unquoted cell holds no `*`, because a `*` triggers quotes (section 6.4) and a code cannot hold one (section 3.1). A quoted string ends with `"` and an array ends with `]`, so a `*` inside them is never at the end. Fixtures: `13-dense-quoting` (`n:"a*b"*2`), `19-dense-array-star` (a `*` inside an array, with a run and without).
- The record key stays the first column. A date sequence may stand for it in dense mode. Fixture: `11-dense`.
- An empty result is the header line alone, as in normal mode.

## 8. Reading a reply

A reader reverses the rules above. A reply that starts with `{` is an off reply (section 12.1). Any other reply starts with `~` and its first line is the header.

- Line 1 gives the schema id, the version, the instruction flag and the metadata. Key codes become full field names. A code in `e` becomes the full message. Fixtures: `09-error-code`, `09-error-code-from-text`, `10-error-text` (`decoded_meta`).
- If there is no line 2, the result has no records. Fixture: `07-empty`.
- If line 2 matches `[N]` the reply is dense. Otherwise line 2 lists the columns.
- An unquoted cell is read in this order: empty is null, then a code of the column's field becomes its full value, then a JSON number (divided by the multiplier when the field is scaled), then `true` or `false`, then text that starts with `[` is read as a JSON array and decoded with the schema, which includes turning every string that equals a value code of its key into the full value (section 6.3). Any other text stays a string.
- A cell in quotes is read as a JSON string and stays a literal.
- Dotted columns rebuild nested objects.
- In dense mode `x*K` becomes K copies of x, and `<first>+<step>d` becomes N dates.
- Every column of the table comes back in every record, and a field that a record lacked comes back as null. A case that this changes carries a `decoded` member. Fixtures: `03-nulls-missing`, `04-collisions`, `05-nested` (`user.type` of the second record).
- A scaled number comes back at the published precision, so `1235` is `0.1235`. Fixture: `08-scale-rounding`.

For every other case, reading `expected` returns `rows`. Numbers are compared by value, so `5` equals `5.0`.

## 9. The schema tool and the contract line

A server that speaks Toklex adds a tool named `toklex_schema`. It takes no arguments and only reads. It returns one text block with these parts, in this order. The server also adds the tool `toklex_calibrate` (section 14).

1. The line `Toklex schema <id>@<version>`.
2. A short primer on the grammar of sections 5 to 7. Appendix A.1 gives its text, and that text is normative.
3. The legend: the keys, the value codes, the scales and the dense tools.
4. The instruction blocks, word for word.

A library writes the legend as appendix A.1 describes. For the schema of section 3 it reads like this.

```
Keys: id=station_id, n=name, l=wind_level, v=rain_m
Value codes: l: l=LOW m=MEDIUM h=HIGH; e: nf=No station with that id.
Scaled: v x10000 (743 means 0.0743)
Dense tools: station_history
```

The library appends this contract line to the description of every tool of the server, after two line feeds. It carries the version of the schema. Every tool of the server pays for it in `tools/list`, so it stays short. The modes are described by the `toklex_mode` property, by the tool `toklex_calibrate` and by the resource `toklex://modes`. Appendix A.2 shows the whole tool list.

```
Toklex reply ~<id>@<version>. If this version is not in context, call toklex_schema first. Never guess a code.
```

Some clients cut a tool description at 2048 characters. The contract line comes last, so a library warns the server author when a description is longer than 2048 characters after the line is appended. It counts Unicode code points.

The schema is not placed in the `instructions` of the server handshake, because clients cut it or drop it. A Toklex reply is one MCP `text` content block. A library adds no `structuredContent`, because some clients drop the text block when it is present.

## 10. Conformance fixtures

All fixtures are in `spec/fixtures/`.

- `test.schema.json` is the schema of every case. Its version is in `hash.json`. A change to the schema file changes the version, so `hash.json` must be updated with it.
- `cases/*.json` hold `{"schema", "tool", "mode", "rows", "meta", "expected", "decoded", "decoded_meta"}`. The member `expected` has the placeholder `{v}` where the version stands. The member `decoded` is present only when reading `expected` cannot return `rows` as given. The member `decoded_meta` is present only when reading `expected` cannot return `meta` as given, null values left out. The member `mode` is optional. When it is absent the tool's default mode applies. A library passes a case when `encode(tool, rows, meta, mode)` equals `expected` byte for byte and reading `expected` returns `decoded`, or `rows` when `decoded` is absent, and the metadata that `decoded_meta` or `meta` gives.
- Error cases in `cases/` hold `{"schema", "error", "mode", "expected", "decoded_meta"}`. The member `error` is the code or the full text passed to the error function. The member `mode` is optional as above. The member `decoded_meta` is the metadata that reading `expected` returns. For an off-mode error, reading `expected` returns the message as `e`.
- `modes/args.json` is a list of `{"tool", "args", "expected_mode", "expected_args_after"}`. A library passes an entry when the call arguments `args` for `tool` give the mode `expected_mode` and leave `expected_args_after` as the remaining arguments (section 12.2).
- `invalid/*.json` are schemas that a library must reject at load.

## 11. Rules that no fixture pins in v0.1

These rules are in force. A later version of the fixtures may add cases for them.

- `i:+` is left out of the header when the schema has no instructions.
- A string that is not valid UTF-8 has each bad byte sequence replaced by U+FFFD (the Unicode replacement character) before anything else happens. This holds for cells, metadata, array strings, field names, error messages and off mode, so data never makes the encoder fail (section 1). A fixture file cannot hold such bytes, so a library test pins it.
- An object that a language keeps apart from a list or map, such as a PHP `stdClass`, is read as the map it holds. A non-empty one expands into dotted columns and an empty one is the array `[]`, as an empty map is.
- A reader that meets a column both as a parent and as a nested path, for example `us` and `us.lg`, lets the nested value win. A null parent cell never wipes a nested object, and a null nested cell never replaces a scalar parent.
- JSON escaping of a backslash, a tab and other control characters follows RFC 8259.
- Inside an array, a scale applies to the key a number sits under. Value codes in arrays are pinned by `18-array-code-collision`.
- A result whose records have no field at all has an empty line of columns, and each record is a single `|`. A reader reads an empty line of columns as no columns.
- A date column in dense mode with a zero or negative step is not written as a sequence. A zero step is a run (`2026-07-01*3`).
- A dense reply for records without any field is the header and `[N]`, with no column lines. A reader returns N empty records.
- A reader cuts a run longer than N at N cells, and a column line with fewer cells than N leaves the rest empty. A date sequence is read as literal strings, as a quoted cell is.
- The empty result of a reply in dense mode is the header line alone.
- A reader refuses a dense reply that claims more than 100000 records, with an error. A forged `[N]` or a forged run count then cannot make a reader allocate memory without limit. An encoder does not check this limit.
- Codes are unique inside `keys` and inside each field of `values`. A JSON parser merges repeated member names before a library sees them, so no schema fixture can pin this.
- Members of the schema other than `toklex` and `id` may be left out.
- Sections 13 and 14 have no case fixture. Appendix A gives their texts, and `php/tests/run.php` compares them with the library.

## 12. Reply modes

A reply comes in one of three modes.

| Mode | Reply |
|---|---|
| `off` | Compact JSON with full field names and the full text of the instruction blocks. It has no codes, no scales and no schema legend. |
| `safe` | Normal mode (section 6). |
| `dense` | Dense mode (section 7). |

The instructions travel in every mode. A server owner who writes safety rules in the instruction blocks keeps them when the caller switches Toklex off.

### 12.1 The off reply

An off reply is one JSON object with these members, in this order.

```
{"toklex":"off","schema":"<id>@<version>","instructions":{<name>:<text>,...},"meta":{...},"rows":[...]}
```

- The JSON has no whitespace. Non-ASCII characters, `/` and the line terminators U+2028 and U+2029 are written as they are, without a `\uXXXX` escape.
- A number is written as in section 6.3. A float with an integral value has no fraction part and no number has an exponent, so `5.0` is `5` and `0.0000001` stays `0.0000001`.
- `toklex` is the string `off`. `schema` names the schema and its version, so a reader can tell which instructions it received.
- `instructions` holds every instruction block of the schema, name and text, in schema order. The member is left out when the schema has no blocks.
- `meta` and `rows` carry the metadata and the records as the encoder received them. Names are the full names, values are written as given, and no code or scale is applied. A string that holds a number stays a string, in a scaled field too. A metadata value that is null stays in `meta`. The header of the other modes leaves it out. `meta` is an object and is `{}` when there is no metadata. `rows` is an array and is `[]` when there are no records.
- An error reply has the members `toklex`, `schema`, `instructions` and `error`, in this order, and no `meta` or `rows`. The value of `error` is the full message. A message given as a code from `values.e` is written out in full, as it is in section 5.
- A reader parses the text as JSON. `rows` is the result. An error reply has no `rows`, and its message reads back from `error`.

Fixtures: `21-off-basic` (the rows and metadata of `01-basic`), `22-off-error` (an error given as the code `nf`), `24-off-scalars` (an integral float, a small float, a null metadata value, a non-ASCII name and a `/`), `31-scaled-numeric-strings-off` (number strings in a scaled field).

### 12.2 Choosing the mode of a call

Every tool of the server takes an optional argument `toklex_mode` with the value `off`, `safe` or `dense`. The two Toklex tools (`toklex_schema` and `toklex_calibrate`) do not take it.

- When the argument is absent, the tool's default mode applies. It is dense for a tool listed in `dense` and safe for any other tool.
- When the value is not one of the three, the default mode applies. The comparison is case-sensitive, so `OFF` is not `off`. A value that is null or not a string counts as not one of the three.
- A library removes `toklex_mode` from the arguments before the tool runs, and leaves every other argument as it was.
- An explicit mode beats the default in both directions. A dense tool answers in normal mode with `safe`, and any other tool answers in dense mode with `dense`.

Fixtures: `modes/args.json` pins the choice and the removal of the argument, including `OFF`, a null value and an argument list that holds nothing else. `23-mode-override` pins a dense tool answering in normal mode. `25-get-dense` pins another tool answering in dense mode.

## 13. Resources

A server may publish two MCP resources.

- `toklex://modes` is a text about the three modes, about how a call picks one and about calibration. Appendix A.3 gives it.
- `toklex://schema` is the text that `toklex_schema` returns.

A client does not have to load resources, so the tool `toklex_schema` stays the main channel. The contract line (section 9) points to the tool `toklex_schema` only.

## 14. Calibration

A model can read the dense form well or badly. The tool `toklex_calibrate` lets the server check this and recommend a mode. It takes one optional argument, `answers`, and it only reads.

**Without `answers`** the tool returns a dataset that the library holds. It has these parts.

- A table of 8 records with dates, coded values and scaled numbers.
- A mini legend of its own. The dataset does not use the schema of the server, so a model that reads it shows how it reads the Toklex grammar.
- The same table twice, once in normal mode and once in dense mode.
- Questions `s1` to `s3` about the normal form and `d1` to `d3` about the dense form.

**With `answers`**, an object `{"<question id>": <answer>}`, the server compares each answer with its key. It returns the recommended mode and the result of every question. The mode is chosen by this rule.

1. All `d` questions are right: `dense`.
2. Otherwise all `s` questions are right: `safe`.
3. Otherwise: `off`.

The reply is a JSON object with the members `recommended` (one of `dense`, `safe`, `off`) and `results`, an object that maps every question id of the dataset to `true` or `false`. Two answers are equal when their text forms are equal after trimming leading and trailing whitespace, with a case-sensitive comparison. A JSON number counts as its decimal text, so `993` equals `"993"`. A question with no answer is wrong, and an id that is not a question of the dataset is ignored. A library accepts either the arguments object of the tool call, `{"answers": {...}}`, or the bare answers map, so a server can pass the arguments straight in. An `answers` value that is a string is decoded as JSON first, because some clients send the object as text. Answers that hold no question id count as absent, and the tool returns the dataset. This covers a value that is not an object, a list, a string that does not decode to an object, and arguments without `answers` such as `{"toklex_mode": "safe"}`.

The server keeps no state between the two calls. The dataset and the key are constants of the library. This text gives the shape of the dataset, and the rows are the library's own. The PHP library has this shape.

- The records have a name, a day, a tier and a price. The days are consecutive, so the dense block holds a date sequence. The tiers repeat in runs. The price has the scale 10000.
- The mini schema has the id `cal`, no instructions and no dense tools. Its version is the fixed text `0000`.
- Both blocks come from the library's own encoders, so the test reads the grammar of this text.
- The legend holds the Keys, Value codes and Scaled lines of the mini schema and a few sentences on the two forms. A model needs nothing else to answer.
- `s1` to `s3` ask for a lookup by name, a scaled value and the count of one tier. `d1` to `d3` ask for the tier on a date in the middle of the series, the first day of a tier and the scaled value of the last record. Each question names the format of its answer.
- An `answers` value with no members counts as absent, because a JSON reader cannot tell `{}` from `[]`.

## Appendix A. Model-facing texts

This appendix is normative. It gives every text that a library sends to the model, byte for byte. The reading tests of the bench ran on the primer and on this legend layout, so a port copies them and does not write its own. In a block, `{v}` stands for the version. Lines end with a line feed, and the last line of a block has none. `php/tests/run.php` compares each block with the output of the PHP library.

### A.1 Schema text

This is the reply of `toklex_schema` and the text of `toklex://schema`. Its parts are joined by a line feed.

1. `Toklex schema <id>@<version>`.
2. The primer, one line. It is the same for every schema.
3. `Keys: ` and every `<code>=<full name>` in schema order, joined by `, `.
4. `Value codes: ` and, for each field of `values`, `<field code>: ` and its `<code>=<full value>` pairs joined by a space. The fields are joined by `; `.
5. `Scaled: ` and every `<code> x<multiplier>` joined by `, `, then ` (743 means <x>)`. Here x is 743 divided by the first multiplier, rounded to 12 decimals, with trailing zeros and a trailing point removed.
6. `Dense tools: ` and the tool names joined by `, `.
7. `Instructions (they apply to every response marked i:+):`, then one line `<name>: <text>` per instruction block in schema order.

A part from 3 to 7 is left out when its schema member is empty. This is the text for `test.schema.json`.

```text
Toklex schema tx@{v}
Toklex encodes tool results compactly. Line 1 is the header: ~<schema>@<version>, then i:+ when the instructions below apply, then key:value metadata (e: is an error message). Normal mode: line 2 lists the columns and every following line is one record, cells separated by |. An empty cell is null and trailing empty cells are left out. A cell in double quotes is a literal JSON string, never a code. Inside it, \u007c stands for |. A cell starting with [ is a JSON array that uses the same keys and codes. A dotted column such as us.lg is a nested field. Dense mode: line 2 is [N], the record count, and every following line is key:values for one column in record order, separated by |. x*K means x repeated K times. A value like 2026-07-03+1d means that date for the first record and one more day for each next record. Read codes only from this schema and never guess one. If a response header shows a different version, call toklex_schema again.
Keys: id=station_id, n=name, l=wind_level, v=rain_m, u=snow_m, t=date, us=user, lg=login, lb=labels
Value codes: l: l=LOW m=MEDIUM h=HIGH; e: nf=No station with that id.
Scaled: v x10000, u x10000 (743 means 0.0743)
Dense tools: station_history
Instructions (they apply to every response marked i:+):
rule: Use only the fields in this reply.
```

### A.2 Tool list

The library appends the contract line to the description of every tool of the server, after two line feeds. A tool without a description gets the contract line alone. The library adds the property `toklex_mode` after the tool's own properties, and it gives a tool without an input schema the schema `{"type":"object"}` first. Then it appends `toklex_schema` and `toklex_calibrate`. This is the list for one tool `get_station` with the description `Get a station.` and no properties of its own, as JSON with the member order of the library. A server may write the JSON with other whitespace.

```json
[
    {
        "name": "get_station",
        "description": "Get a station.\n\nToklex reply ~tx@{v}. If this version is not in context, call toklex_schema first. Never guess a code.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "toklex_mode": {
                    "type": "string",
                    "enum": [
                        "off",
                        "safe",
                        "dense"
                    ],
                    "description": "Reply format: off, safe or dense."
                }
            }
        }
    },
    {
        "name": "toklex_schema",
        "title": "Toklex schema",
        "description": "Returns the schema (field names, value codes, number scales and instructions) needed to read the Toklex responses of this server. Call it before reading a response whose ~<id>@<version> you do not have in context.",
        "inputSchema": {
            "type": "object",
            "properties": {}
        },
        "annotations": {
            "title": "Toklex schema",
            "readOnlyHint": true,
            "openWorldHint": false
        }
    },
    {
        "name": "toklex_calibrate",
        "title": "Toklex calibrate",
        "description": "Finds out which Toklex mode you read reliably. Call it without arguments to get a small dataset with questions. Call it again with answers to get the recommended mode.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "answers": {
                    "type": "object",
                    "description": "Your answers, as an object that maps a question id to the answer."
                }
            }
        },
        "annotations": {
            "title": "Toklex calibrate",
            "readOnlyHint": true,
            "openWorldHint": false
        }
    }
]
```

### A.3 Resources

`resources/list` holds these two entries.

```json
[
    {
        "uri": "toklex://modes",
        "name": "Toklex modes",
        "description": "The three reply modes, how to pick one and how to calibrate.",
        "mimeType": "text/plain"
    },
    {
        "uri": "toklex://schema",
        "name": "Toklex schema",
        "description": "The schema text that toklex_schema returns.",
        "mimeType": "text/plain"
    }
]
```

The text of `toklex://modes` is this.

```text
Toklex modes
A server that speaks Toklex can answer a tool call in one of three modes.
off: compact JSON with full field names and the full text of the instructions. No codes and no scales.
safe: the normal table. Line 1 is the header, line 2 lists the columns and each following line is one record. This is the default for every tool that the schema does not list as dense.
dense: line 2 is [N], the record count, and each following line holds one column. This is the default for the tools the schema lists as dense.
Pick a mode for one call with the optional argument toklex_mode on a tool of the server. The two Toklex tools, toklex_schema and toklex_calibrate, do not take it. The values are off, safe and dense. If you leave it out or send another value, the tool's default mode applies. The instructions of the server travel in every mode.
Dense is the shortest form. Some models misread it. To learn which mode you read reliably, call toklex_calibrate without arguments. It returns a small dataset in both forms and six questions. Answer them from the dataset alone, then call toklex_calibrate again with answers, an object that maps each question id to your answer. The reply names the mode to use. Use it with toklex_mode on later calls.
```

### A.4 Calibration

`toklex_calibrate` without answers returns this page.

```text
Toklex calibration
The two blocks below hold the same 8 records, once as a safe table and once as a dense table. The legend covers this test only. Answer the six questions from the text on this page.

Legend
Keys: n=name, d=day, t=tier, p=price
Value codes: t: l=LOW m=MEDIUM h=HIGH
Scaled: p x10000 (743 means 0.0743)

How to read
Safe block: line 1 is the header, line 2 lists the columns and each next line is one record, with cells separated by |.
Dense block: line 2 is [8], the record count, and each next line is key:values, one column for all 8 records in record order.
z*3 means z three times. 2026-07-03+1d means 2026-07-03 for the first record and one more day for each next record.
Read every code through the legend. A scaled number is divided by its scale.

Safe block
~cal@0000
n|d|t|p
Anna|2026-03-02|l|612
Boris|2026-03-03|l|655
Chen|2026-03-04|m|701
Dara|2026-03-05|m|688
Emil|2026-03-06|m|744
Faye|2026-03-07|h|790
Gus|2026-03-08|h|823
Hana|2026-03-09|l|917

Dense block
~cal@0000
[8]
n:Anna|Boris|Chen|Dara|Emil|Faye|Gus|Hana
d:2026-03-02+1d
t:l*2|m*3|h*2|l
p:612|655|701|688|744|790|823|917

Questions
s1 (safe block): On which day is the record named Dara? Write it as YYYY-MM-DD.
s2 (safe block): What is the price of the record named Gus? Write the decimal number with four decimals.
s3 (safe block): How many records have the tier LOW? Write a number.
d1 (dense block): What is the tier of the record dated 2026-03-06? Write the full word.
d2 (dense block): On which day does the first record with the tier HIGH fall? Write it as YYYY-MM-DD.
d3 (dense block): What is the price of the last record? Write the decimal number with four decimals.

Then call toklex_calibrate again with {"answers":{"s1":"<answer>","s2":"<answer>","s3":"<answer>","d1":"<answer>","d2":"<answer>","d3":"<answer>"}}. Write each answer as short text or a number.
```

With all six answers right, the reply is this JSON text.

```json
{"recommended":"dense","results":{"s1":true,"s2":true,"s3":true,"d1":true,"d2":true,"d3":true}}
```
