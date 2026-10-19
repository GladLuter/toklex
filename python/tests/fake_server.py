# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# A tiny stdio MCP server for the proxy test. It answers in plain JSON, as most servers do.
import json, sys

TOOLS = [
    {"name": "find_stations", "description": "Find stations.", "inputSchema": {"type": "object", "properties": {"q": {"type": "string"}}}},
    {"name": "get_station", "description": "Get a station.", "inputSchema": {"type": "object"}, "outputSchema": {"type": "object"}},
    {"name": "history", "description": "A history.", "inputSchema": {"type": "object"}},
    {"name": "echo", "description": "Echo the arguments.", "inputSchema": {"type": "object"}},
    {"name": "hello", "description": "Free text.", "inputSchema": {"type": "object"}},
    {"name": "fail", "description": "Always fails.", "inputSchema": {"type": "object"}},
]
ROWS = [{"station_id": "st-1", "name": "North Ridge", "rain_m": 0.0743}, {"station_id": "st-2", "name": "Bay Point", "rain_m": 0.1201}]


def call(name, args):
    if name == "find_stations":
        return {"content": [{"type": "text", "text": json.dumps({"total": 2, "items": ROWS})}]}
    if name == "get_station":
        return {"content": [{"type": "text", "text": json.dumps(ROWS[0])}], "structuredContent": ROWS[0]}
    if name == "history":
        return {"content": [{"type": "text", "text": json.dumps([{"date": "2026-07-%02d" % d, "rain_m": 0.07} for d in range(1, 13)])}]}
    if name == "echo":
        return {"content": [{"type": "text", "text": json.dumps(args)}]}
    if name == "hello":
        return {"content": [{"type": "text", "text": "Hello, world."}]}
    return {"content": [{"type": "text", "text": "boom"}], "isError": True}


for line in sys.stdin:
    m = json.loads(line)
    if "id" not in m:
        continue
    res = {"initialize": lambda p: {"protocolVersion": "2025-06-18", "capabilities": {"tools": {}}, "serverInfo": {"name": "fake", "version": "1"}},
           "tools/list": lambda p: {"tools": TOOLS},
           "tools/call": lambda p: call(p["name"], p.get("arguments") or {})}.get(m["method"])
    out = {"jsonrpc": "2.0", "id": m["id"], "result": res(m.get("params") or {})} if res else \
        {"jsonrpc": "2.0", "id": m["id"], "error": {"code": -32601, "message": "Method not found"}}
    print(json.dumps(out), flush=True)
