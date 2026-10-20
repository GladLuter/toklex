# Review fixes of 2026-10-19

This file answers the review of branch `claude/great-rubin-80ujoj` (e38ee07..a86e98e). Each item has the commit that fixes it, the test that pins it and what was not done.

The fixes are in these commits:

| Commit | Items |
|---|---|
| 14b9a6d | 1, 3, 4, 5, 11, 12, 14 |
| 93e6e1c | 2 |
| 4509f18 | 7, 8, 9 |
| fc7c7fa | 1 (the memory checks on PHP 8.1, and CI at 128M) |
| ffcdda7 | 10 |
| 506752d | 6, 13, 15, and the README side of 1, 3, 11 and 14 |

All suites pass locally: PHP 490 checks at `memory_limit=128M`, Python 362, TypeScript 364, `tsc --noEmit` clean, the proxy test, both bench tests, and `tests/cross/fuzz.py` with 0 mismatches on seeds 1 to 11.

## Blockers

### 1. Reader limits and memory

Fixed in all three libraries and in spec section 11.

- A reader counts the cost of a reply in cells, in safe and dense mode, and refuses a reply above 500000. A cell costs 1. Each name of a column path before the last adds 8. A JSON array cell adds 1 per comma and 8 per `[` or `{`. The cost is spent while the reply is read, so a refused reply costs no more than the limit.
- A column path has at most 64 names. A dense reply still claims at most 100000 records.
- A run of a JSON array cell is decoded once and copied for each record.
- At the limit the PHP reader takes at most 75 MB, measured with five date sequences of 100000 dates. Five plain columns take 56 MB.
- The 46 bytes `a.a.a.a.a.a.a.a.a.a:x*100000` are refused at about 2 MB. The safe reply with 1000 names in its path is refused at once. `a:[1,...,3000]*10000` is refused before it is decoded.

Tests in `php/tests/run.php`: "a reply at the cost limit decodes inside 80 MB", "one more column than the cost limit is refused", "a nested column costs its depth", "a safe reply with a column path of 1000 names is refused at once", "a column path of 64 names decodes and one of 65 is refused", "safe mode has the same cost limit", "a run of a long JSON array is refused before it is decoded 10000 times", "each [ or { in a JSON array cell costs 8", "five date sequences of 100000 dates decode inside 96 MB". Python and TypeScript have the same cases.

Before the fix the old library under these tests was killed for memory (exit 137).

The first push of these tests failed on PHP 8.1 in CI. They reset the peak with `memory_reset_peak_usage`, which needs PHP 8.2. Commit fc7c7fa moves each measurement into a fresh PHP process (`php/tests/cost.php`) at `memory_limit=128M`, and the CI job runs the suite at 128M too.

### 2. The proxy

Fixed in 93e6e1c. Tests are the blocks 2a, 2b, 2c, 2e and 2f in `python/tests/test_proxy.py`, with new tools and flags in `python/tests/fake_server.py`.

- a. A lone surrogate and JSON nested too deep for Python no longer stop the proxy. A message the proxy cannot read passes unchanged. A message it changes goes out with ASCII escapes when it holds a lone surrogate. Any other error while one message is handled is logged to stderr and the message passes unchanged. Against the old proxy the test fails with UnicodeEncodeError. Note: the encoder itself writes U+FFFD for a lone surrogate, so a converted reply never holds one.
- b. A call whose `arguments` or `params` are not an object goes to the server as it is. Against the old proxy the test hangs.
- c. Only the JSON text block is replaced, and every other content block stays. An object with one member, such as `{"result": "hello"}` or `{"result": [1, 2, 3]}`, passes unchanged. An object of two or more members is still one record. Against the old proxy the image block is lost.
- d. The command is resolved with `shutil.which`. Not tested, because no Windows runner is in CI.
- e. When the client input ends, the server gets 5 seconds, then SIGTERM, then SIGKILL. SIGTERM to the proxy ends the server too. Against the old proxy the first test hangs and the second finds the server alive after the proxy exited.
- f. On a paginated `tools/list` or `resources/list` the Toklex tools and resources go on the first page only. Against the old proxy the test fails.
- g. Batches are documented as not supported, in the docstring and both READMEs. MCP dropped them in its 2025-06-18 revision.
- h. The help of `--instructions once`, the docstring and both READMEs say that it works per proxy process.

### 3. TypeScript port

Fixed in 14b9a6d.

- a. The encoder keeps maps as `Map`, so a record or metadata passed as a `Map` keeps names such as `"1"` in place and gives the bytes of PHP. A plain object still puts such keys first, which JavaScript does before the library sees them. Documented in the README and the TS README. `decode` returns objects, so there such keys come first. For the same reason TS reads a JSON map with the keys `"0"`, `"1"` as a map where PHP and Python read a list. That limit is in spec section 11 too. `tests/cross/encode.ts` now parses its input with an order-keeping parser, and `fuzz.py` has the names `"0"`, `"1"` and `"10"`.
- b. An integer cell outside the safe range reads back as a `bigint`. `fuzz.py` has 2^53+1 and -(2^60).
- c. Dates use `days_from_civil` with floor division in all three libraries. Year 0000 works both ways. `fuzz.py` has series that run up to the leap day of year 0000.
- d. A `Date` outside 0000-9999 keeps its year.

### 4. Forged dates

Fixed in 14b9a6d. A date sequence that starts at an invalid date or runs past 9999-12-31 is refused with InvalidArgumentException in PHP and ToklexError in the ports. `2026-02-30+1d` is refused too. Test: "the date sequence ... is refused as a reply" for `2026-13-45+1d`, `2026-02-30+1d`, `2026-01-01+99999999999999999999d` and `9999-12-30+1d`. Before the fix the first one escaped the PHP runner as DateMalformedStringException.

### 5. `outputSchema: null`

Fixed in 14b9a6d with `array_key_exists`. Python and TypeScript already removed it and have the same test now. Test: "withTools removes an outputSchema that is null".

### 6. The auto wording

Fixed in 506752d. The README now says that the date column must rise by the same number of days, and that a newest-first history stays safe. Spec section 12.2 already said so.

### 7. The counter model

Fixed in 4509f18 by rewording. The default stays `claude-opus-5-5`. The README now says that `--model` names the tokenizer, that it should be the model of the sessions, that the production runs used Claude Sonnet, and that `--count-model` is the option of `live.py summarize`.

### 8. `--counter claude` errors

Fixed in 4509f18. Missing credentials end with one message that names `ANTHROPIC_API_KEY` and `ant auth login`. Refused credentials and a 429 that outlasts 6 SDK retries end with one message each. An empty text counts 0 without a request. Test: `bench/test_tokens.py`, which fails against the old code. With the real SDK 1.15.0 and no credentials the command prints the message and exits with 1.

### 9. `stats()`

Fixed in 4509f18. One run gives a stdev of null, no run a mean of null. `per_run` keeps the mean and the count only, because the runs of a variant ask different questions. stdout now shows `by_question`. Test: `bench/test_live.py`, which fails against the old code.

### 10. Packaging

Fixed in ffcdda7.

- npm and Python: `js/` and `python/` hold copies of LICENSE and NOTICE. `package.json` lists NOTICE. `pyproject.toml` names both in `license-files` and requires setuptools 77. The wheel holds `dist-info/licenses/LICENSE` and `NOTICE` with `License-Expression: Apache-2.0`, and the sdist holds both. The new CI job "Packages carry the licence" compares the copies with the root files and checks the wheel, the sdist and `npm pack`.
- Composer: `phpstan/phpstan` is pinned to 2.3.1, the version CI resolved.
- The README links to `bench/`, `js/` and `python/` are absolute GitHub links. So are the links of the port READMEs to `spec/`.

## Should fix

### 11. PHP encoder gaps

Fixed in 14b9a6d. An ArrayObject is its array, a Stringable its text and a closure null. A JsonSerializable chain longer than 8 is null. A value that holds itself is null where it repeats, and a list or map 64 levels below the record is null. Spec section 11 and the README list these rules. Tests: "an object that holds itself is null where it repeats", the ArrayObject, Stringable, closure and chain cases, and a map nested 70 levels.

### 12. Whitespace in names

The header patterns now name their characters (`[\t\n\x0b\f\r ]`, `[0-9]`) instead of `\s` and `\d`. Test: "every pattern names its characters", a static check of the source.

Not done: the message of e38ee07 still says "other whitespace". The branch is pushed, and changing a message would rewrite its history. The spec and the README say exactly which characters are escaped.

Not reproduced: no Windows-1252 locale was available here, and the glibc ISO-8859-1 locale does not count NBSP as `\s`. So the NBSP case did not fail here. The static test covers it.

### 13. Changes from v0.2

Done. Spec section 15 lists the escaped names with the example `cost\u00f7unit`, the replies without instruction text, the new refusals, the depth and cycle rule, and the API changes. The README intro points to it.

### 14. Differences between the ports

Fixed in 14b9a6d, except one documented limit.

- A map with the keys 0, 1, ... in order is a list in every library, as PHP keeps it. The TypeScript reader cannot see key order after `JSON.parse` and reads such a JSON map as a map. This is in spec section 11 and the TS README.
- `inputSchema: {}` stays without a type in Python.
- A naive datetime gets `+00:00` in Python.
- A JSON cell nested deeper than 64 levels stays text in all three. An off reply nested more than 511 levels is refused.

### 15. Long README sentences

Fixed in 506752d. The auto, encode and proxy paragraphs are short sentences and lists now.

## Not verified here

- Python 3.9 and Node 22.18 exactly did not run locally. Python 3.13 and Node 22 did. CI runs Python 3.9 and 3.13 and Node 22.
- PHP 8.1, 8.2 and 8.4 did not run locally. PHP 8.3 did. CI runs all four.
- The real `count_tokens` endpoint was not called, because this environment has no API key. Only the missing-credentials path ran against the real SDK.
