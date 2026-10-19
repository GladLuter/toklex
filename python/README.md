# Toklex for Python

A port of the PHP library in this repository. It passes every fixture in [spec/fixtures](../spec/fixtures) and gives the texts of appendix A byte for byte. The protocol is [spec/toklex.md](../spec/toklex.md). Python 3.9 or newer, no dependencies.

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

Values that JSON has no type for are turned into what JSON would carry: a `datetime` or `date` is ISO 8601 text (the date alone at midnight), an `Enum` is its value, a `Decimal` is a number with its exact digits, a tuple is a list, and a dataclass, a pydantic model or another object is the dict of its public attributes.

## Proxy

`python -m toklex.proxy` puts Toklex in front of any stdio MCP server without changing its code. See the main README.

## Tests

```
python python/tests/run.py
```

The runner prints `OK <n> checks` and exits with 0, or lists the failures and exits with 1.
