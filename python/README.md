# Toklex for Python

A port of the PHP library in this repository. It passes every fixture in [spec/fixtures](https://github.com/GladLuter/toklex/tree/main/spec/fixtures) and gives the texts of appendix A byte for byte. The protocol is [spec/toklex.md](https://github.com/GladLuter/toklex/blob/main/spec/toklex.md). Python 3.9 or newer, no dependencies.

```python
from toklex import Toklex

tx = Toklex.from_file("stations.schema.json")  # raises ToklexError on a bad schema

# tools/list: adds toklex_mode to every tool and removes outputSchema
tools = tx.with_tools(my_tools)

# tools/call
mode = tx.mode(name, args)        # removes toklex_mode from args
rows = run_my_tool(name, args)    # a list of dicts
text = tx.encode(name, rows, {"date": "2026-09-30"}, mode)
```

Return `text` as one MCP `text` content block and add no `structuredContent`.

The methods follow the PHP names in snake case: `error`, `decode`, `version`, `style`, `instructions_text`, `schema_text`, `calibrate`, `resources` and `read_resource`. `encode` and `error` take `instructions=False` to leave the instruction text out of a reply the model has read before (spec 5.1). `with_tools` takes a `warn` callback, and without it warnings go to `logging.getLogger("toklex")`.

Values that JSON has no type for are turned into what JSON would carry:

- A `datetime` or `date` is ISO 8601 text, the date alone at midnight. A `datetime` without a zone gets `+00:00`, as in PHP.
- An `Enum` is its value. A `Decimal` is a number with its exact digits. A tuple is a list.
- A dataclass, a pydantic model or another object is the dict of its public attributes. A function is `None`.
- A dict with the keys `"0"`, `"1"` and so on, in that order, is a list, as PHP keeps it.
- A value that holds itself is `None` where it repeats. A list or dict 64 levels below the record is `None`.

## Proxy

`toklex-proxy` (or `python -m toklex.proxy`) puts Toklex in front of any stdio MCP server without changing its code.

```
toklex-proxy --schema stations.schema.json -- php server.php
```

It relays every message and changes four things. The tool list gets `toklex_mode` on every tool and loses `outputSchema`. A call loses `toklex_mode` before the server sees it. A result whose JSON text block or `structuredContent` holds records gets a Toklex text block in place of that JSON. Its other content blocks stay. With a coded schema the proxy answers `toklex_schema` and `toklex_calibrate` itself, and with any schema it serves `toklex://modes`.

Records are a list of objects, an object with one member that is such a list (the other members go to the header), or an object of two or more members. An object with one member, such as `{"result": "hello"}`, only wraps a value and passes unchanged. Errors, free text, messages the proxy cannot read and JSON-RPC batches pass unchanged too. The main [README](https://github.com/GladLuter/toklex#proxy) has the details.

`--instructions once` sends the instruction text with the first reply of the proxy process only (spec 5.1). It saves tokens on every later reply. A client that keeps the server running across a new chat sends that chat no rules, and a client that compacts its context can lose them. Leave it off when the rules matter for safety.

In an MCP client config, the proxy is the command and the server follows `--`:

```json
{"mcpServers": {"stations": {"command": "toklex-proxy", "args": ["--schema", "/srv/stations.schema.json", "--", "php", "/srv/server.php"]}}}
```

## Tests

```
python python/tests/run.py
python python/tests/test_proxy.py
```

The runner prints `OK <n> checks` and exits with 0, or lists the failures and exits with 1.
