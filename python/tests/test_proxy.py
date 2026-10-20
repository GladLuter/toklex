# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# The proxy against a fake stdio MCP server. Run: python python/tests/test_proxy.py
import io, json, os, signal, subprocess, sys, tempfile, time

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
FX = os.path.join(ROOT, "spec", "fixtures")
sys.path.insert(0, os.path.dirname(HERE))
from toklex import Toklex  # noqa: E402


SERVER = [sys.executable, os.path.join(HERE, "fake_server.py")]
UNREAD = []  # answers too deep for json.loads, as raw lines


def session(schema, requests, *extra, server=()):
    """Sends the requests through the proxy in one session and returns the answers by id."""
    lines = "".join(json.dumps(dict(r, jsonrpc="2.0")) + "\n" for r in requests)
    cp = subprocess.run([sys.executable, "-m", "toklex.proxy", "--schema", os.path.join(FX, schema), *extra, "--", *SERVER, *server],
                        input=lines, capture_output=True, text=True, encoding="utf-8", cwd=os.path.dirname(HERE), timeout=60)
    assert cp.returncode == 0, cp.stderr
    out = {}
    for line in cp.stdout.splitlines():
        try:
            m = json.loads(line)
            out[m["id"]] = m
        except RecursionError:
            UNREAD.append(line)
    return out, cp.stderr


def call(i, name, **args):
    return {"id": i, "method": "tools/call", "params": {"name": name, "arguments": args}}


r, err = session("plain.schema.json", [
    {"id": 1, "method": "initialize", "params": {}},
    {"id": 2, "method": "tools/list"},
    call(3, "find_stations"), call(4, "get_station"), call(5, "history"), call(6, "echo", q="a", n=1, toklex_mode="off"),
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
assert json.loads(text(6))["rows"] == [{"q": "a", "n": 1}] and json.loads(text(6))["toklex"] == "off", "toklex_mode never reaches the server"
assert text(7) == "Hello, world." and text(8) == "boom" and r[8]["result"]["isError"]
assert "\n[2] One line per column" in text(9)
assert [x["uri"] for x in r[10]["result"]["resources"]] == ["toklex://modes"]
assert r[11]["result"]["contents"][0]["text"].startswith("Toklex modes")
assert r[12]["error"]["code"] == -32601, "other messages pass unchanged"

# once: the instruction text goes with the first reply only
r, _ = session("plain.schema.json", [call(1, "find_stations"), call(2, "find_stations")], "--instructions", "once")
assert text(1).startswith("rule: ") and text(2).startswith("~tp@"), (text(1), text(2))

# instructions_at server: the rules go with the handshake and no reply carries them
r, _ = session("plain-server.schema.json", [{"id": 1, "method": "initialize", "params": {}}, call(2, "find_stations")])
assert r[1]["result"]["instructions"] == "rule: Use only the fields in this reply." and text(2).startswith("~ts@"), r

# coded: the two Toklex tools are answered by the proxy itself
r, _ = session("test.schema.json", [{"id": 1, "method": "tools/list"}, call(2, "toklex_schema"), call(3, "toklex_calibrate"), call(4, "find_stations")])
names = [t["name"] for t in r[1]["result"]["tools"]]
assert names[-2:] == ["toklex_schema", "toklex_calibrate"], names
assert text(2).startswith("Toklex schema tx@") and text(3).startswith("Toklex calibration")
assert text(4).startswith("~tx@") and "\nid|n|v\nst-1|North Ridge|743" in text(4), text(4)

# 2a: a lone surrogate, as Node writes when it cuts an emoji, and JSON nested deeper than Python reads, never stop the proxy
r, err = session("plain.schema.json", [call(1, "cut"), call(2, "cut_rows"), call(3, "echo", q="\ud83d"), call(4, "deep_text"), call(5, "deep"),
                                       call(6, "find_stations"), {"id": 7, "method": "tools/list"}])
assert text(1) == "hi \ud83d bye", r[1]
assert text(2).endswith("station_id|name\nst-1|hi \ufffd bye"), "the encoder writes U+FFFD for a lone surrogate"
assert json.loads(text(3)) == {"q": "\ud83d"}, text(3)
assert text(4) == "[" * 100000 + "]" * 100000, "free text that is too deep to read passes unchanged"
assert 5 not in r and len(UNREAD) == 1 and UNREAD[0].startswith('{"jsonrpc":"2.0","id":5,'), "an unreadable message passes unchanged"
assert text(6).endswith("st-2|Bay Point|1201"), "the proxy still works after them"
assert r[7]["result"]["tools"][-1]["description"] == "Cut \ud83d text.", "a lone surrogate in a message the proxy changes goes out escaped"

# 2b: a call whose arguments are not an object goes to the server as it is, and the proxy goes on
r, err = session("plain.schema.json", [{"id": 1, "method": "tools/call", "params": {"name": "echo", "arguments": [1]}},
                                       {"id": 2, "method": "tools/call", "params": [1]}, call(3, "find_stations")])
assert text(1) == "{}" and r[2]["error"]["code"] == -32602 and text(3).endswith("st-2|Bay Point|1201"), r

# 2c: only the JSON text block is replaced, every other block stays, and a wrapped value is no record
r, _ = session("plain.schema.json", [call(1, "mixed"), call(2, "wrapped", value="hello"), call(3, "wrapped", value=[1, 2, 3]),
                                     call(4, "wrapped", value={"a": 1})])
content = r[1]["result"]["content"]
assert len(content) == 2 and content[0]["text"].endswith("st-2|Bay Point|1201") and content[1]["type"] == "image", content
assert "structuredContent" not in r[1]["result"]
for i, value in ((2, "hello"), (3, [1, 2, 3]), (4, {"a": 1})):
    assert json.loads(text(i)) == {"result": value} and r[i]["result"]["structuredContent"] == {"result": value}, r[i]

# 2f: on a paginated list the Toklex tools and resources go on the first page only
r, _ = session("test.schema.json", [{"id": 1, "method": "tools/list"}, {"id": 2, "method": "tools/list", "params": {"cursor": "2"}},
                                    {"id": 3, "method": "resources/list"}, {"id": 4, "method": "resources/list", "params": {"cursor": "2"}}],
               server=["--resources"])
names = [t["name"] for t in r[1]["result"]["tools"]]
assert names[-2:] == ["toklex_schema", "toklex_calibrate"] and r[1]["result"]["nextCursor"] == "2", names
page = r[2]["result"]["tools"]
assert [t["name"] for t in page] == ["page_two"] and "toklex_mode" in page[0]["inputSchema"]["properties"], page
assert [x["uri"] for x in r[3]["result"]["resources"]] == ["file:///a", "toklex://modes", "toklex://schema"], r[3]
assert [x["uri"] for x in r[4]["result"]["resources"]] == ["file:///b"], r[4]
r, _ = session("test.schema.json", [{"id": 1, "method": "resources/list", "params": {"cursor": "x"}}])
assert r[1]["error"]["code"] == -32601, "a server without resources refuses a later page itself"

# 2e: a server that ignores the end of its stdin and SIGTERM is killed, so the proxy never waits on it
from toklex import proxy  # noqa: E402

proxy.GRACE = 0.3
with tempfile.TemporaryDirectory() as tmp:
    pidfile = os.path.join(tmp, "pid")
    p = proxy.Proxy(Toklex.from_file(os.path.join(FX, "plain.schema.json")), [*SERVER, "--stubborn", pidfile], out=io.BytesIO())
    start = time.time()
    p.run(stdin=[(json.dumps(call(1, "find_stations")) + "\n").encode()])
    assert time.time() - start < 10 and p.up.poll() is not None, "the stubborn server is ended"
    assert p.out.getvalue().count(b"\n") == 1 and b"st-2|Bay Point|1201" in p.out.getvalue(), p.out.getvalue()

# 2e: a proxy that is told to stop ends its server too
if os.name == "posix":
    with tempfile.TemporaryDirectory() as tmp:
        pidfile = os.path.join(tmp, "pid")
        pp = subprocess.Popen([sys.executable, "-m", "toklex.proxy", "--schema", os.path.join(FX, "plain.schema.json"), "--", *SERVER, "--stubborn", pidfile],
                              stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, cwd=os.path.dirname(HERE))
        for _ in range(100):
            if os.path.exists(pidfile) and open(pidfile).read():
                break
            time.sleep(0.1)
        child = int(open(pidfile).read())
        pp.send_signal(signal.SIGTERM)
        pp.wait(timeout=10)
        pp.stdin.close()
        try:
            os.kill(child, 0)
            os.kill(child, signal.SIGKILL)
            raise AssertionError("the server outlived its proxy")
        except ProcessLookupError:
            pass
print("ok proxy")
