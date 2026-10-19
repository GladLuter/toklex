# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# The proxy against a fake stdio MCP server. Run: python python/tests/test_proxy.py
import json, os, subprocess, sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
FX = os.path.join(ROOT, "spec", "fixtures")
sys.path.insert(0, os.path.dirname(HERE))
from toklex import Toklex  # noqa: E402


def session(schema, requests, *extra):
    """Sends the requests through the proxy in one session and returns the answers by id."""
    lines = "".join(json.dumps(dict(r, jsonrpc="2.0")) + "\n" for r in requests)
    cp = subprocess.run([sys.executable, "-m", "toklex.proxy", "--schema", os.path.join(FX, schema), *extra, "--", sys.executable, os.path.join(HERE, "fake_server.py")],
                        input=lines, capture_output=True, text=True, encoding="utf-8", cwd=os.path.dirname(HERE), timeout=60)
    assert cp.returncode == 0, cp.stderr
    return {m["id"]: m for m in map(json.loads, cp.stdout.splitlines())}, cp.stderr


def call(i, name, **args):
    return {"id": i, "method": "tools/call", "params": {"name": name, "arguments": args}}


r, err = session("plain.schema.json", [
    {"id": 1, "method": "initialize", "params": {}},
    {"id": 2, "method": "tools/list"},
    call(3, "find_stations"), call(4, "get_station"), call(5, "history"), call(6, "echo", q="a", toklex_mode="off"),
    call(7, "hello"), call(8, "fail"), call(9, "find_stations", toklex_mode="dense"),
    {"id": 10, "method": "resources/list"}, {"id": 11, "method": "resources/read", "params": {"uri": "toklex://modes"}},
    {"id": 12, "method": "ping"},
])
text = lambda i: r[i]["result"]["content"][0]["text"]  # noqa: E731
tools = {t["name"]: t for t in r[2]["result"]["tools"]}
assert all("toklex_mode" in t["inputSchema"]["properties"] for t in tools.values()), tools
assert "outputSchema" not in tools["get_station"] and "loses its outputSchema" in err, err
assert "q" in tools["find_stations"]["inputSchema"]["properties"]
assert r[1]["result"]["capabilities"]["resources"] == {}
tp = Toklex.from_file(os.path.join(FX, "plain.schema.json"))
ins = "rule: Use only the fields in this reply.\nnotice: \"Line one.\\nLine two.\"\n"
assert text(3) == ins + f"~tp@{tp.version()} total:2\nstation_id|name|rain_m(÷10000)\nst-1|North Ridge|743\nst-2|Bay Point|1201", text(3)
assert text(4).endswith("station_id|name|rain_m(÷10000)\nst-1|North Ridge|743") and "structuredContent" not in r[4]["result"], r[4]
assert text(5).startswith(ins + f"~tp@{tp.version()}\ndate|rain_m(÷10000)\n2026-07-01|700\n") and text(5).count("\n") == 3 + 12, text(5)  # safe by default
assert json.loads(text(6))["rows"] == [{"q": "a"}] and json.loads(text(6))["toklex"] == "off", "toklex_mode never reaches the server"
assert text(7) == "Hello, world." and text(8) == "boom" and r[8]["result"]["isError"]
assert "\n[2] One line per column" in text(9)
assert [x["uri"] for x in r[10]["result"]["resources"]] == ["toklex://modes"]
assert r[11]["result"]["contents"][0]["text"].startswith("Toklex modes")
assert r[12]["error"]["code"] == -32601, "other messages pass unchanged"

# once: the instruction text goes with the first reply only
r, _ = session("plain.schema.json", [call(1, "find_stations"), call(2, "find_stations")], "--instructions", "once")
assert text(1).startswith("rule: ") and text(2).startswith("~tp@"), (text(1), text(2))

# coded: the two Toklex tools are answered by the proxy itself
r, _ = session("test.schema.json", [{"id": 1, "method": "tools/list"}, call(2, "toklex_schema"), call(3, "toklex_calibrate"), call(4, "find_stations")])
names = [t["name"] for t in r[1]["result"]["tools"]]
assert names[-2:] == ["toklex_schema", "toklex_calibrate"], names
assert text(2).startswith("Toklex schema tx@") and text(3).startswith("Toklex calibration")
assert text(4).startswith("~tx@") and "\nid|n|v\nst-1|North Ridge|743" in text(4), text(4)
print("ok proxy")
