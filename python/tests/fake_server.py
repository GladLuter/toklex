# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# A tiny stdio MCP server for the proxy test. It answers in plain JSON, as most servers do.
# --resources: the server has two pages of resources. --stubborn: it ignores the end of its stdin and SIGTERM.
import json, signal, sys, time

TOOLS = [
    {"name": "find_stations", "description": "Find stations.", "inputSchema": {"type": "object", "properties": {"q": {"type": "string"}}}},
    {"name": "get_station", "description": "Get a station.", "inputSchema": {"type": "object"}, "outputSchema": {"type": "object"}},
    {"name": "history", "description": "A history.", "inputSchema": {"type": "object"}},
    {"name": "echo", "description": "Echo the arguments.", "inputSchema": {"type": "object"}},
    {"name": "hello", "description": "Free text.", "inputSchema": {"type": "object"}},
    {"name": "fail", "description": "Always fails.", "inputSchema": {"type": "object"}},
    {"name": "cut", "description": "Cut \ud83d text.", "inputSchema": {"type": "object"}},
]
PAGE_TWO = [{"name": "page_two", "description": "A tool on the second page.", "inputSchema": {"type": "object"}}]
ROWS = [{"station_id": "st-1", "name": "North Ridge", "rain_m": 0.0743}, {"station_id": "st-2", "name": "Bay Point", "rain_m": 0.1201}]
IMAGE = {"type": "image", "data": "iVBORw0KGgo=", "mimeType": "image/png"}


def text(x):
    return {"type": "text", "text": x if isinstance(x, str) else json.dumps(x)}


def call(name, args):
    if name == "find_stations":
        return {"content": [text({"total": 2, "items": ROWS})]}
    if name == "get_station":
        return {"content": [text(ROWS[0])], "structuredContent": ROWS[0]}
    if name == "history":
        return {"content": [text([{"date": "2026-07-%02d" % d, "rain_m": 0.07} for d in range(1, 13)])]}
    if name == "echo":
        return {"content": [text(args)]}
    if name == "hello":
        return {"content": [text("Hello, world.")]}
    if name == "cut":  # Node cuts an emoji in half and writes a lone surrogate
        return {"content": [text("hi \ud83d bye")]}
    if name == "cut_rows":
        return {"content": [text([{"station_id": "st-1", "name": "hi \ud83d bye"}])]}
    if name == "deep_text":
        return {"content": [text("[" * 100000 + "]" * 100000)]}
    if name == "mixed":
        return {"content": [text({"items": ROWS}), IMAGE], "structuredContent": {"items": ROWS}}
    if name == "wrapped":
        return {"content": [text({"result": args.get("value")})], "structuredContent": {"result": args.get("value")}}
    return {"content": [text("boom")], "isError": True}


def tools(p):
    return {"tools": PAGE_TWO} if p.get("cursor") == "2" else {"tools": TOOLS, "nextCursor": "2"}


def resources(p):
    if "--resources" not in sys.argv:
        return None
    if p.get("cursor") == "2":
        return {"resources": [{"uri": "file:///b", "name": "b"}]}
    return {"resources": [{"uri": "file:///a", "name": "a"}], "nextCursor": "2"}


if "--stubborn" in sys.argv:
    signal.signal(signal.SIGTERM, signal.SIG_IGN)
    with open(sys.argv[sys.argv.index("--stubborn") + 1], "w") as f:
        f.write(str(__import__("os").getpid()))

for line in sys.stdin:
    m = json.loads(line)
    if "id" not in m:
        continue
    p = m.get("params") or {}
    if not isinstance(p, dict):
        print(json.dumps({"jsonrpc": "2.0", "id": m["id"], "error": {"code": -32602, "message": "Invalid params"}}), flush=True)
        continue
    if m["method"] == "tools/call" and p.get("name") == "deep":  # nested deeper than the proxy can read
        print('{"jsonrpc":"2.0","id":%s,"result":{"content":[],"structuredContent":%s}}' % (json.dumps(m["id"]), "[" * 100000 + "]" * 100000), flush=True)
        continue
    res = {"initialize": lambda p: {"protocolVersion": "2025-06-18", "capabilities": {"tools": {}}, "serverInfo": {"name": "fake", "version": "1"}},
           "tools/list": tools,
           "resources/list": resources,
           "tools/call": lambda p: call(p["name"], p["arguments"] if isinstance(p.get("arguments"), dict) else {})}.get(m["method"], lambda p: None)(p)
    out = {"jsonrpc": "2.0", "id": m["id"], "result": res} if res is not None else \
        {"jsonrpc": "2.0", "id": m["id"], "error": {"code": -32601, "message": "Method not found"}}
    print(json.dumps(out), flush=True)

while "--stubborn" in sys.argv:
    time.sleep(1)
