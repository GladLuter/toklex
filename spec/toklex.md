# Toklex protocol

Grammar version 1, draft v0.2.

> Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
>
> Licensed under Apache-2.0. See `LICENSE` and `NOTICE`.

## 1. What Toklex does

Toklex makes the results of MCP tools smaller. It also delivers the server owner's instructions to the model, with every result by default.

The server owner writes a schema file. A reply comes in one of two styles (section 2.1). In plain style, the default, the reply explains itself: columns carry their full names, a scaled column says by how much to divide, and by default the instructions stand in front of the table. The model needs no schema. In coded style, the style of v0.1, the schema holds short key codes, value codes, number scales and blocks of instructions. Every reply starts with a header that names the schema and its version. The model keeps the schema in context. When a header shows a version the model does not have, the model asks for the schema again with the tool `toklex_schema`.

A server can answer in three modes: `off`, `safe` and `dense`. The caller may pick one per call. Section 12 describes them.

Toklex encodes records, which are a list of objects, plus metadata. Metadata values are scalars. A tool that returns free text sends it as usual, without Toklex.

A library that implements this text fails in four cases only. It loads a broken schema (section 3.1). Its encoder gets a reserved metadata key, `i` or `e` in coded style and `error` in plain style (section 5). Its reader gets a dense reply that claims more than 100000 records (section 11). Its reader gets a reply that has no header line (section 8). Other data does not make it fail. Unknown fields and values go out as they are.

## 2. Conventions

**Fixtures.** Each rule below names the fixtures that pin it. A bare name such as `02-quoting` is a file in `spec/fixtures/cases/`. A name that starts with `invalid/` is a file in `spec/fixtures/invalid/`. The cases `01` to `31` use `spec/fixtures/test.schema.json`, a coded schema. The cases `40` to `53` use plain schemas. Section 10 describes the fixture formats. Section 11 lists the rules that no fixture pins yet. Section 12 describes the reply modes, section 13 the resources and section 14 calibration. Appendix A gives every text that goes to the model.

**Terms.** A record is one object of the result. A field is a key of a record. A column is one field of the result table. A nested field is a column with a dotted name. A cell is the text of one field of one record. A code is a short name from the schema. Coded style writes codes. Plain style writes the full names.

**Text.** A reply is UTF-8 text. Lines are separated by a line feed (U+000A). A reply does not end with a line feed. Every case pins this. A reader splits lines at U+000A only. Other line breaks, such as U+2028, U+0085, a vertical tab or a form feed, can stand raw inside an unquoted cell and belong to it.

**Whitespace** means the six ASCII characters space, tab, line feed, vertical tab, form feed and carriage return (U+0020, U+0009 to U+000D). No other character counts, U+00A0 and U+2028 included. This holds for the code check of section 3.1, for the header quote trigger of section 5 and for the place where a reader ends a header value.

### 2.1 Styles

A schema has a style, `plain` or `coded`. The member `style` of the schema file (section 3) names it. Plain is the default and counts when the member is absent. Coded is the style of v0.1.

Plain exists because the schema fetch of coded style is a cost of its own. The model has to call `toklex_schema` before it can read a reply. That is one more turn, and every turn sends the whole context again. In measurements this cost more than the codes saved. A plain table names its columns in full and says how to scale them. It keeps most of the saving and the extra turn is gone.

| | Plain | Coded |
|---|---|---|
| Field names | Full names. | Key codes. |
| Scaled field | The name is followed by `(÷K)` and the cell holds the value times K. | The schema holds the scale. |
| Value codes | None. | `values` in the schema. |
| Instructions | Lines in front of the header, or the MCP `instructions` field (section 5.1). | In the schema, marked by `i:+` in the header. |
| Error | `error:` and the full text. | `e:` and a code or a quoted text. |
| Schema tool, contract line, calibration, `toklex://schema` | None. | Yes (sections 9, 13 and 14). |

Modes, cell quoting, runs and date sequences work the same way in both styles. Sections 5 to 8 and 12 give the rules once and say where plain differs. A rule that does not say so holds in both styles, except in the sections that open by naming one style (5, 9, 13 and 14).

## 3. Schema file

The server owner writes the schema as a JSON file.

```json
{
  "toklex": 1,
  "id": "tx",
  "style": "coded",
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
- `style` is `plain` or `coded` (section 2.1). When it is absent the style is `plain`. The example above is a coded schema.
- `instructions_at` is `reply` or `server`. It says where the instructions travel in plain style (section 5.1). When it is absent the value is `reply`. Coded style does not take it.
- `keys` maps a code to a full field name. In coded style the code is what a reply carries. In plain style a reply carries the full name, and the code only links the name to `scale`.
- `values` maps a field code to a map from a code to a full value. The member `e` is reserved for error messages and does not stand for a field. In plain style only `e` is used, to turn an error given as a code into its text. The other value codes are checked at load and never written.
- `scale` maps a field code to an integer multiplier. It sets the publication precision of the field, as DECIMAL(10,4) does in a database. A number of that field travels as an integer, `x * multiplier` rounded by section 6.3. A reader divides it back. A string of that field that holds a plain decimal number counts as that number (section 6.3). In plain style the field is the full name that `keys` gives to the code, and a code that `keys` does not list stands for itself.
- `instructions` holds named blocks of text. In coded style all of them apply to a reply whose header carries `i:+`. In plain style they stand in front of the reply or in the MCP `instructions` field (section 5.1).
- `dense` lists the tools whose default mode is dense (section 7). A tool outside the list defaults to normal mode. The caller can override either default (section 12.2).

`toklex` and `id` are required. The other members may be left out and then count as empty.

`spec/fixtures/plain.schema.json` is a plain schema with the same kind of members and no `style`. `spec/fixtures/plain-server.schema.json` is a plain schema with `"instructions_at": "server"`.

### 3.1 Checks at load

A library rejects a file that breaks one of these rules, with an error raised at load time.

- `toklex` equals 1. Fixture: `invalid/grammar-2`.
- `id` exists and matches the pattern. Fixtures: `invalid/no-id`, `invalid/bad-id`.
- `style` is `plain` or `coded`. Fixture: `invalid/style-unknown`.
- `instructions_at` is `reply` or `server`. Fixture: `invalid/instructions-at-unknown`.
- `instructions_at` is not present with the style `coded`. Fixture: `invalid/instructions-at-coded`.
- A full name occurs once in `keys`. Fixture: `invalid/full-name-twice`.
- A full value occurs once inside one field of `values`. Fixture: `invalid/value-twice`.
- Every multiplier in `scale` is an integer. Fixtures: `invalid/scale-fraction`, `invalid/scale-fraction-large`. The second one has a fraction above 10, so it breaks this rule alone.
- Every multiplier is at least 10. Fixture: `invalid/scale-small`.
- `keys` has no code `i` and no code `e`. The header owns them: `i:` marks instructions and `e:` carries an error. The check holds in plain style too, because key codes are still codes in the schema file. Fixtures: `invalid/reserved-code`, `invalid/reserved-code-e`.
- A code in `keys` or in a field of `values` is a plain token. It is not empty, it holds no `|`, `*`, `:`, whitespace or `"`, it does not start with `[` or `{`, it does not parse as a JSON number and it is not `true`, `false` or `null`. A code that broke this would make a cell or a column line ambiguous, because the encoder writes a code without quotes. Fixtures: `invalid/value-code-star`, `invalid/value-code-number`, `invalid/key-code-space`.
- A code in `keys` holds no `.`. A dot in a column name marks a nested field, so a key code with a dot would read back as two keys. Fixture: `invalid/key-code-dot`.
- A code in a field of `values` does not have the shape of a date sequence, `YYYY-MM-DD+Nd`. In a dense column such a code would read as a run of dates. Fixture: `invalid/value-code-date-sequence`.

In plain style the loader also checks the names, because a reply writes them as they are.

- A full name in `keys` is a plain token by the rule for codes, and it holds no `.` and no `(÷`. A `|` in a column name would break the line of columns, a `:` would break a dense column line, a space would break a metadata key in the header, and a `.` would read back as a nested path. A reader takes `(÷` at the end of a name for a scale label (section 8) and would strip it from the name. Fixtures: `invalid/plain-name-pipe`, `invalid/plain-name-dot`, `invalid/plain-name-scale-label`.
- The name of an instruction block is a plain token, so that the line `<name>: <text>` is clear. Fixture: `invalid/plain-instruction-name`.

All the other checks hold in both styles.

A v0.1 schema that has no `style` counts as plain from v0.2 on. If its `keys` hold full names with a space, a `|`, a `:` or a `.`, or its instruction names are not plain tokens, it breaks the plain checks. Adding `"style": "coded"` keeps it working. A library may say so in the message of the error.

## 4. Version

The version is the first 4 hexadecimal characters of the SHA-256 of the schema file, in lower case. The hash runs over the bytes of the file after every CRLF (`\r\n`) is replaced by LF (`\n`).

Fixture: `hash.json` holds the version of `test.schema.json`. A library also hashes a copy of that file with CRLF line endings and gets the same version.

Any change to the file changes the version, formatting included. A needless second fetch of the schema costs a few tokens. A version change that goes unnoticed can leave the model reading codes wrongly. A plain reply has no codes to misread and no schema to fetch. Its header still carries the version, which names the schema file that produced the reply.

## 5. Header

The header names the schema and carries the metadata. In coded style it is the first line of every reply. In plain style it follows the instruction lines (section 5.1). The rules in this section are the coded ones. Section 5.2 gives the plain header.

```
~<id>@<version>[ i:+][ <key>:<value>]...
```

Elements are separated by one space.

- `~<id>@<version>` names the schema. Every case.
- In coded style `i:+` is present when the schema has at least one instruction block. It tells the model that the instructions apply to this reply. Every case, because the test schema has instructions.
- Metadata is written as `<key>:<value>`, in the order the encoder received it. The key is the field code, or the original name when the field is not in `keys`. A key that is not in `keys` follows the limits of section 6.1.
- The value is a cell of that field. Section 6.3 encodes it and section 6.4 quotes it. Two more triggers apply in the header: any whitespace character (space, tab, line feed, vertical tab, form feed or carriage return), and a `"` anywhere in the string. A reader ends a value at the first whitespace, so an unquoted one would be cut there. A quoted value is written as in section 6.4, so a `|` in it is written `\u007c`. Fixtures: `01-basic` (`t:2026-09-30`), `07-empty`, `11-dense`, `20-meta-quoting` (a space, quotes, a pipe, a number-like string, a coded value and a scaled number), `26-meta-whitespace` (a tab, a form feed and a vertical tab).
- A metadata value that is an array or a map is written as a JSON string. The encoder takes the compact JSON text of section 6.3, with keys and values coded, before the step that writes `|` as `\u007c`. Then it quotes that text by section 6.4, so each `|` becomes `\u007c` once. It starts with `[` or `{`, so section 6.4 quotes it, and a space inside it cannot cut the value. A reader returns that text as a string and does not decode it. Fixture: `27-meta-edge` (`labels` with a `|` inside, and `login` with a string that starts with `{`).
- A metadata value that is null is left out of the header. So is a number that is not finite (section 6.3). Fixture: `20-meta-quoting` (`labels`).
- In coded style the metadata keys `i` and `e` are reserved like the key codes of section 3.1, because the header owns `i:` and `e:`. An encoder that receives a metadata key `i` or `e`, as a name or as a code, rejects it with an error in every mode, off included.
- In coded style an error is `e:<code>` when the message is listed in `values.e`. Otherwise it is `e:` followed by the message as a JSON string, written as in section 6.4 and quoted whatever the message holds. A reply to an error is the header alone. Fixtures: `09-error-code` (the message is given as the code), `09-error-code-from-text` (the message is given as the full text, and the same line comes out), `10-error-text`, `10-error-one-word` (a message without a space is quoted too).

### 5.1 Instruction lines (plain)

In plain style the instructions travel in one of two places. The schema member `instructions_at` picks it (section 3).

- `reply`, the default. The reply starts with one line per instruction block, in schema order. A line is `<name>: <text>`. A text that holds a line feed or a carriage return is written as a quoted string by section 6.4, so that the block stays on one line. Any other text is written as it is. The header follows the last instruction line. A schema without blocks has no instruction lines. Fixtures: `40-plain-basic` to `48-plain-meta-scaled` and `51-plain-numeric-strings` all start with the two blocks of `plain.schema.json`, and the second one is quoted.
- `server`. The reply has no instruction lines, and the off reply has no `instructions` member (section 12.1). The library hands the blocks over for the `instructions` field of the MCP handshake: one `<name>: <text>` line per block, the text word for word, joined by a line feed. A text with a line break keeps it there, because nothing is quoted. Fixtures: `49-plain-server`, `50-plain-server-off`.

Use `reply` unless you know that the client passes `instructions` to the model. As of October 2026, clients such as claude.ai, Claude Desktop and the API MCP connector do not pass that field, so a rule placed there never reaches the model. Claude Code passes the field but cuts it at 2048 characters, so a long rule would lose its end. That is why `reply` is the default.

### 5.2 The plain header

```
~<id>@<version>[ <name>:<value>]...
```

- There is no `i:+`. The instructions are not marked. They stand in front of the header or in the handshake.
- A metadata key is the name as the encoder received it, because plain style maps no key. When the name is a scaled field of the schema, the key is written `<name>(÷K)`, K being the multiplier, and the value is the scaled integer of section 6.3. A dotted key such as `x.rain_m` is a name of its own and is not scaled. A value that is a list under a scaled key is scaled, and a number under an object member of a map value is written as it is (section 6.3). Fixture: `48-plain-meta-scaled`.
- The value follows sections 6.3 and 6.4 without value codes, and the header quote triggers of section 5 apply. A null value is left out. Fixtures: `40-plain-basic`, `48-plain-meta-scaled` (`note:"two words"`).
- `error` is the reserved key. An encoder that receives the metadata key `error` rejects it with an error in every mode. The keys `i` and `e` are ordinary names in plain style.
- An error is `error:` and the full message. When the message is given as a code of `values.e`, the text is the message of that code. The text is written like any header value, so it is quoted when section 6.4 or the header triggers ask for it. A message with a space is quoted, and `boom` is not. A reply to an error is the instruction lines and the header alone. A reader returns the text as the metadata `error`. Fixtures: `43-plain-error-code`, `44-plain-error-word`.
- An empty result is the header alone, after the instruction lines. Fixture: `45-plain-empty`.

## 6. Normal mode

Normal mode is the default. A reply is the header, a line of columns, and one line per record. Line numbers in sections 6 to 8 count from the header. In plain style the instruction lines come before line 1.

```
~tx@{v} i:+ t:2026-09-30
id|n|l|v
st-1|North Ridge|m|743
st-2|Bay Point|h|1201
```

`{v}` stands for the version. This reply is the case `01-basic`. The same records in plain style are the case `40-plain-basic`.

```
rule: Use only the fields in this reply.
notice: "Line one.\nLine two."
~tp@{v} date:2026-09-30
station_id|name|wind_level|rain_m(÷10000)
st-1|North Ridge|MEDIUM|743
st-2|Bay Point|HIGH|1201
```

### 6.1 Columns

Line 2 lists the columns, separated by `|`. A column is the field code, or the original name when the field is not in `keys`. Fixture: `06-scalars` pins the original names. In plain style a column is the full name as the record has it, followed by `(÷K)` when the field is scaled, K being its multiplier. Fixture: `40-plain-basic` (`rain_m(÷10000)`).

A nested object expands into columns. Each column name is the dotted path of codes, recursively, for example `us.lg`. A nested field that is not in `keys` keeps its name, for example `us.type`. In plain style the path holds full names, for example `user.login`. Fixtures: `05-nested`, `47-plain-nested-scaled`.

The last code of the path names the field. `values` and `scale` for a nested column are looked up under that last code, as for a top-level field, and section 6.4 compares a string with the value codes of that field. So `us.v` is scaled like `v`, and `us.l` takes the value codes of `l`. In plain style a nested column takes `(÷K)` after the whole path when its last name is scaled. Fixtures: `15-nested-scaled`, `47-plain-nested-scaled` (a scaled array).

A field name that is not in `keys` is written as it is, and Toklex does not escape it. A name that equals a code, such as `n`, reads back as the field of that code. A name with a `.` reads back as a nested path. In plain style every name is written as it is and no name is read as a code. The names in `keys` are checked at load (section 3.1), and the names that data brings are not, so the limits below hold for them. A name with a `|` breaks the line of columns, and in dense mode a name with a `:` breaks the column line. A `*` in a name does no harm. The same holds for the keys of metadata, where a name with a space or a `:` breaks the header. A server whose data can hold such names lists those fields in `keys` under safe codes. Fixture: `16-unmapped-names` (`n` and `a.b`).

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
- In coded style a string that is a full value listed in `values` for this field is written as its code. Fixtures: `01-basic` (`MEDIUM` becomes `m`), `03-nulls-missing`. Plain style has no value codes, so the string is written as it is. Fixture: `40-plain-basic` (`MEDIUM`).
- Any other string is written as it is, in UTF-8, unless section 6.4 asks for quotes. A space does not trigger quotes, and neither does a `"` inside the string. Fixtures: `01-basic` (`North Ridge`), `02-quoting` (`O"Neil`), `04-collisions` (`UNKNOWN`, `Côte Zéta Höhe`).
- An array is written as compact JSON without whitespace. Object keys inside it are replaced by key codes, and values are coded with the same schema. Non-ASCII characters and `/` are written as they are, without a `\uXXXX` escape. Every `|` in the JSON text is written `\u007c`. A string inside an array is a JSON string and keeps its JSON quotes. Section 6.4 covers cells only. If such a string equals a value code of its key, the encoder leaves it as it is and a reader turns it into the full value. The text changes in that case, which is a known limit of v0.1. A schema author avoids it by choosing codes that data does not use as text. Fixtures: `05-nested`, `14-array-unicode` (non-ASCII text inside an array), `18-array-code-collision`. In plain style an array keeps the full names as keys and codes no value, so this limit does not exist. In plain style a number is scaled only where a `(÷K)` label shows it: in the cell of a scaled column, in the value of a scaled metadata key, and in a list directly under such a column or key. A number under an object member inside the JSON is written as it is, whatever the member is called, and a reader does not divide it. Fixtures: `47-plain-nested-scaled` (`[700,801]` under `rain_m`), `52-plain-object-in-array` (`{"rain_m":0.0743}` inside a list). In coded style a number inside an array or an object is scaled when the key it sits under has a scale in the schema.

### 6.4 Quoted strings

A string is written as a JSON string in double quotes when any of these holds.

- It is empty. Fixture: `03-nulls-missing` (`a|""`).
- It starts with `"`, `[` or `{`. Fixture: `02-quoting` (`"Quoted" Point` and `[draft]`).
- It contains `|`, `*`, a line feed or a carriage return. Fixture: `02-quoting` (`A|B`, `5*star`, a line feed in `Line1` and `Line2`).
- In coded style, it equals a value code of this field. Fixture: `04-collisions` (`m` in `wind_level`). In plain style a string that equals a code of the schema is not quoted for that reason, because a plain reader never turns a cell into a value by a code. Fixture: `46-plain-code-string` (`m` and `l`).
- It parses as a JSON number, by the number grammar of RFC 8259. Fixture: `04-collisions` (`12345`).
- It equals `true`, `false` or `null`. Fixture: `04-collisions` (`true`, `null`).
- It looks like a date sequence, which is an ISO date followed by `+`, one or more digits and `d`. Fixture: `04-collisions` (`2026-07-03+1d`).
- It is in a field with a `scale` and section 6.3 did not read it as a number. So an unquoted cell of a scaled field that matches the JSON number grammar is a scaled number. The other unquoted cells are empty, `true`, `false`, an array or, in coded style, a value code. Fixtures: `29-scaled-numeric-strings` (`"12abc"`, `"+1"`, `".5"`, `"5."`, `"1E-2"`), `51-plain-numeric-strings` (a string number in a plain scaled column).

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

This reply is the case `11-dense`. The same records in plain style are the case `41-plain-dense`.

```
rule: Use only the fields in this reply.
notice: "Line one.\nLine two."
~tp@{v} date:2026-07-05
[5] One line per column: name:v1|v2|… x*K is x repeated K times, start+Nd is a date every N days, an empty cell is null.
date:2026-07-01+1d
wind_level:MEDIUM*3|HIGH*2
rain_m(÷10000):743*2|750|800|810
snow_m(÷10000):*4|993
```

- Line 2 is `[N]`, the number of records. It marks the mode. Fixtures: `11-dense`, `12-dense-irregular`, `13-dense-quoting`. In plain style the line is `[N]`, a space and one sentence that tells the model how to read the block, the text of appendix A.5. The sentence is the same for every reply. A reader takes `[N]` as the count and ignores the rest of the line. Fixture: `41-plain-dense`.
- Each following line is one column, written `<column>:<items>`. Columns follow the rules of section 6.1, so a plain scaled column is written `rain_m(÷10000):...`. Items are separated by `|`.
- An item is a cell, encoded by section 6.3 and 6.4. Every column line covers all N records, empty cells included.
- A run of K equal cells, with K of 2 or more, is written `<cell>*K`. A run of empty cells is written `*K`. A run takes every equal neighbour, so `m*2|m*2` is written `m*4`. Fixture: `11-dense` (`m*3`, `743*2`, `*4`).
- A column of ISO calendar dates (`YYYY-MM-DD`) is written `<first>+<step>d` when it has at least 3 records, every record holds a valid date in that column, and the day step between neighbours is the same positive number. A date such as `2026-02-30` is not valid, and a record with no date in the column stops the sequence. The column line then holds that one item. Fixtures: `11-dense` (`t:2026-07-01+1d`), `12-dense-irregular` (the steps are 1 and 2, so the dates are listed), `13-dense-quoting` (two records, so the dates are listed).
- The run marker is the `*` and the digits at the end of an item. It follows the cell: a quoted string, an array or an unquoted cell. An unquoted cell holds no `*`, because a `*` triggers quotes (section 6.4) and a code cannot hold one (section 3.1). A quoted string ends with `"` and an array ends with `]`, so a `*` inside them is never at the end. Fixtures: `13-dense-quoting` (`n:"a*b"*2`), `19-dense-array-star` (a `*` inside an array, with a run and without).
- The record key stays the first column. A date sequence may stand for it in dense mode. Fixture: `11-dense`.
- An empty result is the header line alone, as in normal mode.

## 8. Reading a reply

A reader reverses the rules above. A reply that starts with `{` is an off reply (section 12.1). Any other reply starts with `~` and its first line is the header. A plain reply with `instructions_at` set to `reply` starts with instruction lines instead, one for each block of the schema. The reader skips that many lines and finds the header on the next one. A block is one line, so the count is the number of blocks. Fixture: `40-plain-basic`, where the second block holds a line break and still takes one line.

- Line 1 gives the schema id, the version, the instruction flag and the metadata. In plain style the flag is true when the reply has instruction lines, or when an off reply has the `instructions` member. Key codes become full field names. A code in `e` becomes the full message. Fixtures: `09-error-code`, `09-error-code-from-text`, `10-error-text` (`decoded_meta`). In plain style the names are full already. The reader takes the `(÷K)` off the end of a key and divides the value as for a column (see below). `error` is the full message. Fixtures: `43-plain-error-code`, `44-plain-error-word`, `48-plain-meta-scaled`.
- If the text has no header line, which happens when a plain reply has fewer lines than the schema has instruction blocks, the reader refuses it with an error.
- If there is no line 2, the result has no records. Fixture: `07-empty`.
- If line 2 matches `[N]`, with or without the sentence of a plain count line, the reply is dense. Otherwise line 2 lists the columns.
- An unquoted cell is read in this order: empty is null, then a code of the column's field becomes its full value, then a JSON number (divided by the multiplier when the field is scaled), then `true` or `false`, then text that starts with `[` is read as a JSON array and decoded with the schema, which includes turning every string that equals a value code of its key into the full value (section 6.3). Any other text stays a string. Plain style skips the code steps and has no value codes, so `m` in a cell stays `m`. Fixture: `46-plain-code-string`.
- A cell in quotes is read as a JSON string and stays a literal.
- In plain style a column name loses its `(÷K)` before anything else is done with it. The name that remains tells the nested path and the scale. Fixtures: `47-plain-nested-scaled`, `51-plain-numeric-strings`.
- Dotted columns rebuild nested objects.
- In dense mode `x*K` becomes K copies of x, and `<first>+<step>d` becomes N dates.
- Every column of the table comes back in every record, and a field that a record lacked comes back as null. A case that this changes carries a `decoded` member. Fixtures: `03-nulls-missing`, `04-collisions`, `05-nested` (`user.type` of the second record).
- A scaled number comes back at the published precision, so `1235` is `0.1235`. Fixture: `08-scale-rounding`. In plain style the multiplier is the one the schema holds for the name, and the label `(÷K)` shows the same number. A number under an object member inside a JSON cell is not divided.

For every other case, reading `expected` returns `rows`. Numbers are compared by value, so `5` equals `5.0`.

## 9. The schema tool and the contract line

This section describes the coded style. A plain server adds neither `toklex_schema` nor `toklex_calibrate`, and appends no contract line to its tool descriptions. It raises no length warning, because there is no line to cut. Its `tools/list` holds its own tools with the added property `toklex_mode` (appendix A.5). The text of the schema is not needed, since the reply carries what the model has to know.

A coded server adds a tool named `toklex_schema`. It takes no arguments and only reads. It returns one text block with these parts, in this order. The server also adds the tool `toklex_calibrate` (section 14).

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

The library appends this contract line to the description of every tool of the server, after two line feeds. It carries the version of the schema. Every tool of the server pays for it in `tools/list`, so it stays short. The modes are described by the tool `toklex_calibrate`, by the resource `toklex://modes` and by the schema text. Appendix A.2 shows the whole tool list.

```
Toklex reply ~<id>@<version>. If this version is not in context, call toklex_schema first. Never guess a code.
```

Some clients cut a tool description at 2048 characters. The contract line comes last, so a library warns the server author when a description is longer than 2048 characters after the line is appended. It counts Unicode code points.

The schema is not placed in the `instructions` of the server handshake, because clients cut it or drop it. Plain style puts the instruction blocks there only when the owner sets `instructions_at` to `server` (section 5.1). A Toklex reply is one MCP `text` content block. A library adds no `structuredContent`, because some clients drop the text block when it is present.

## 10. Conformance fixtures

All fixtures are in `spec/fixtures/`.

- `test.schema.json` is a coded schema (`"style": "coded"`). It is the schema of the cases `01` to `31`. Its version is in `hash.json`. A change to the schema file changes the version, so `hash.json` must be updated with it.
- `plain.schema.json` is a plain schema with `instructions_at` absent. It has two instruction blocks, and the second one holds a line break. It is the schema of the cases `40` to `48` and `51` to `53`. `plain-server.schema.json` is a plain schema with `"instructions_at": "server"`. It is the schema of the cases `49` and `50`. `hash.json` holds the version of `test.schema.json` only.
- `cases/*.json` hold `{"schema", "tool", "mode", "rows", "meta", "expected", "decoded", "decoded_meta", "decoded_instructions"}`. The member `expected` has the placeholder `{v}` where the version stands. The member `decoded` is present only when reading `expected` cannot return `rows` as given. The member `decoded_meta` is present only when reading `expected` cannot return `meta` as given, null values left out. The member `decoded_instructions` is present only when reading `expected` returns false for the instruction flag. That happens when the reply carries no instructions, as in the cases `49` and `50`. The member `mode` is optional. When it is absent the tool's default mode applies. A library passes a case when `encode(tool, rows, meta, mode)` equals `expected` byte for byte and reading `expected` returns `decoded`, or `rows` when `decoded` is absent, and the metadata that `decoded_meta` or `meta` gives, and the instruction flag that `decoded_instructions` gives, or true.
- Error cases in `cases/` hold `{"schema", "error", "mode", "expected", "decoded_meta"}`. The member `error` is the code or the full text passed to the error function. The member `mode` is optional as above. The member `decoded_meta` is the metadata that reading `expected` returns. For an off-mode error, reading `expected` returns the message as `e`, or as `error` in plain style.
- `modes/args.json` is a list of `{"tool", "args", "expected_mode", "expected_args_after"}`. A library passes an entry when the call arguments `args` for `tool` give the mode `expected_mode` and leave `expected_args_after` as the remaining arguments (section 12.2).
- `invalid/*.json` are schemas that a library must reject at load.

The folder `cases/` holds 47 files: 33 coded cases (`01` to `31`, where `09` and `10` have two files each) and 14 plain cases (`40` to `53`). The folder `invalid/` holds 22 schemas.

## 11. Rules that no fixture pins

These rules are in force. A later version of the fixtures may add cases for them.

- In coded style `i:+` is left out of the header when the schema has no instructions. In plain style a schema without blocks has no instruction lines.
- In plain style the metadata keys `i` and `e` are ordinary names, and a dotted metadata key such as `x.rain_m` is not scaled, whatever its last name is. `php/tests/run.php` pins both.
- In plain style a reader accepts a count line that is `[N]` alone. `php/tests/run.php` pins it.
- In plain style the text for the MCP `instructions` field is one `<name>: <text>` line per block, the text word for word and not quoted. `php/tests/run.php` pins it.
- A string that is not valid UTF-8 has each bad byte sequence replaced by U+FFFD (the Unicode replacement character) before anything else happens. This holds for cells, metadata, array strings, field names, error messages and off mode, so data never makes the encoder fail (section 1). A fixture file cannot hold such bytes, so a library test pins it.
- An object that a language keeps apart from a list or map, such as a PHP `stdClass`, is read as the map it holds. A non-empty one expands into dotted columns and an empty one is the array `[]`, as an empty map is.
- A reader that meets a column both as a parent and as a nested path, for example `us` and `us.lg`, lets the nested value win. A null parent cell never wipes a nested object, and a null nested cell never replaces a scalar parent.
- JSON escaping of a backslash, a tab and other control characters follows RFC 8259.
- In coded style, inside an array or an object, a scale applies to the key a number sits under. Value codes in arrays are pinned by `18-array-code-collision`. Plain style scales a number only where a label shows it (section 6.3).
- A reader refuses a reply that has no header line, for example a plain reply with fewer lines than the schema has instruction blocks. `php/tests/run.php` pins it.
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

The instructions travel in every mode. A server owner who writes safety rules in the instruction blocks keeps them when the caller switches Toklex off. The one exception is plain style with `instructions_at` set to `server`. Its instructions go with the handshake and none of its replies carries them, off replies included (section 5.1).

### 12.1 The off reply

An off reply is one JSON object with these members, in this order.

```
{"toklex":"off","schema":"<id>@<version>","instructions":{<name>:<text>,...},"meta":{...},"rows":[...]}
```

- The JSON has no whitespace. Non-ASCII characters, `/` and the line terminators U+2028 and U+2029 are written as they are, without a `\uXXXX` escape.
- A number is written as in section 6.3. A float with an integral value has no fraction part and no number has an exponent, so `5.0` is `5` and `0.0000001` stays `0.0000001`.
- `toklex` is the string `off`. `schema` names the schema and its version, so a reader can tell which instructions it received.
- `instructions` holds every instruction block of the schema, name and text, in schema order. The member is left out when the schema has no blocks, and in plain style when `instructions_at` is `server`.
- `meta` and `rows` carry the metadata and the records as the encoder received them. Names are the full names, values are written as given, and no code or scale is applied. A string that holds a number stays a string, in a scaled field too. A metadata value that is null stays in `meta`. The header of the other modes leaves it out. `meta` is an object and is `{}` when there is no metadata. `rows` is an array and is `[]` when there are no records.
- An error reply has the members `toklex`, `schema`, `instructions` and `error`, in this order (`instructions` is left out as above), and no `meta` or `rows`. The value of `error` is the full message. A message given as a code from `values.e` is written out in full, as it is in section 5.
- A reader parses the text as JSON. `rows` is the result. An error reply has no `rows`, and its message reads back from `error`.

Fixtures: `21-off-basic` (the rows and metadata of `01-basic`), `22-off-error` (an error given as the code `nf`), `53-plain-off-error` (the plain form of it), `24-off-scalars` (an integral float, a small float, a null metadata value, a non-ASCII name and a `/`), `31-scaled-numeric-strings-off` (number strings in a scaled field), `42-plain-off` (the plain form of `21-off-basic`, with the instruction blocks in the JSON), `50-plain-server-off` (no `instructions` member).

### 12.2 Choosing the mode of a call

Every tool of the server takes an optional argument `toklex_mode` with the value `off`, `safe` or `dense`. In coded style the two Toklex tools (`toklex_schema` and `toklex_calibrate`) do not take it. Plain style has no such tools.

- When the argument is absent, the tool's default mode applies. It is dense for a tool listed in `dense` and safe for any other tool.
- When the value is not one of the three, the default mode applies. The comparison is case-sensitive, so `OFF` is not `off`. A value that is null or not a string counts as not one of the three.
- A library removes `toklex_mode` from the arguments before the tool runs, and leaves every other argument as it was.
- An explicit mode beats the default in both directions. A dense tool answers in normal mode with `safe`, and any other tool answers in dense mode with `dense`.

Fixtures: `modes/args.json` pins the choice and the removal of the argument, including `OFF`, a null value and an argument list that holds nothing else. `23-mode-override` pins a dense tool answering in normal mode. `25-get-dense` pins another tool answering in dense mode.

## 13. Resources

A coded server may publish two MCP resources.

- `toklex://modes` is a text about the three modes, about how a call picks one and about calibration. Appendix A.3 gives it.
- `toklex://schema` is the text that `toklex_schema` returns.

A client does not have to load resources, so the tool `toklex_schema` stays the main channel. The contract line (section 9) points to the tool `toklex_schema` only.

A plain server may publish one resource, `toklex://modes`. Its text is shorter. It says nothing about calibration or the Toklex tools, and it names the `(÷K)` label of a scaled column. Appendix A.5 gives it. There is no `toklex://schema` in plain style, and reading it returns nothing.

## 14. Calibration

This section describes the coded style. A plain server registers no `toklex_calibrate`, and its `toklex://modes` text does not mention calibration (appendix A.5).

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
- The mini schema has the id `cal`, the style `coded`, no instructions and no dense tools. Its version is the fixed text `0000`.
- Both blocks come from the library's own encoders, so the test reads the grammar of this text.
- The legend holds the Keys, Value codes and Scaled lines of the mini schema and a few sentences on the two forms. A model needs nothing else to answer.
- `s1` to `s3` ask for a lookup by name, a scaled value and the count of one tier. `d1` to `d3` ask for the tier on a date in the middle of the series, the first day of a tier and the scaled value of the last record. Each question names the format of its answer.
- An `answers` value with no members counts as absent, because a JSON reader cannot tell `{}` from `[]`.

## Appendix A. Model-facing texts

This appendix is normative. It gives every text that a library sends to the model, byte for byte. A.1 to A.4 are the texts of the coded style and A.5 those of the plain style. The reading tests of the bench ran on the primer and on this legend layout, so a port copies them and does not write its own. In a block, `{v}` stands for the version. Lines end with a line feed, and the last line of a block has none. `php/tests/run.php` compares each block with the output of the PHP library.

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

### A.5 Plain style

In plain style `tools/list` holds the tools of the server with one added property, `toklex_mode`, and no Toklex tool. Here it is for a server tool named `get_station` that has no properties.

```json
[
    {
        "name": "get_station",
        "description": "Get a station.",
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
    }
]
```

`resources/list` holds this one entry.

```json
[
    {
        "uri": "toklex://modes",
        "name": "Toklex modes",
        "description": "The three reply modes and how to pick one.",
        "mimeType": "text/plain"
    }
]
```

The text of `toklex://modes` is this.

```text
Toklex modes
A server that speaks Toklex can answer a tool call in one of three modes.
off: compact JSON with full field names and numbers as they are.
safe: a table. The line that starts with ~ is the header, the next line names the columns and each following line is one record, cells separated by |. A column name that ends in (÷K) holds the value times K, so divide the cell by K. This is the default for every tool that the server does not list as dense.
dense: after the header, a line starting with [N] gives the record count and how to read the block. Each following line holds one column. This is the default for the tools the server lists as dense.
Pick a mode for one call with the optional argument toklex_mode. The values are off, safe and dense. If you leave it out or send another value, the tool's default mode applies.
```

The line that follows the header of a dense reply is this, here for five records.

```text
[5] One line per column: name:v1|v2|… x*K is x repeated K times, start+Nd is a date every N days, an empty cell is null.
```
