# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# Offline test for live.py: parses bench/live/sample.jsonl, never calls the claude CLI.
import os, sys
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import live

r = live.parse(os.path.join(HERE, "live", "sample.jsonl"))
assert r["tools"] == ["mcp__srv__toklex_schema", "mcp__srv__get_station"], r["tools"]
assert r["final"] == "Station 7 is North Ridge."
assert r["cost"] == 0.0123 and r["turns"] == 3 and r["session_id"] == "sess-123"
assert r["usage"]["output_tokens"] == 50
assert [n for n, _ in r["results"]] == r["tools"]
assert r["results"][1][1] == "id,name\n7,North Ridge" and r["results"][0][1] == "legend: a=alpha b=beta"

assert live.fmt("Compare {item} and {item2}", "A", "B") == "Compare A and B"
assert live.fmt("Compare {item}", "A", None) == "Compare A"
assert live.fmt('Return {"x": 1} for {item}', "A", "B") == 'Return {"x": 1} for A'

assert live.recommended([("mcp__srv__toklex_calibrate", "{}"), ("mcp__srv__toklex_calibrate", '{"recommended":"dense","results":{}}')]) == "dense"
assert live.recommended([("mcp__srv__get_station", "x")]) is None
print("ok")

# Command flow with subprocess.run replaced by a copy of the sample: the real claude CLI is never started.
import argparse, json, shutil, subprocess, tempfile
calls, inputs = [], []
def fake_run(cmd, stdout, **kw):
    calls.append(cmd)
    inputs.append(kw.get("input"))
    stdout.write(open(os.path.join(HERE, "live", "sample.jsonl"), encoding="utf-8").read())
    return subprocess.CompletedProcess(cmd, 0, "", "")
live.subprocess.run = fake_run
d = tempfile.mkdtemp()
qf = os.path.join(d, "q.json")
json.dump([{"id": "q1", "text": "About {item}", "checks": []}], open(qf, "w"))
a = argparse.Namespace(out=d, server="srv", config=["a=a.json", "b=b.json", "a"], questions=qf, variant="a", runs=2, item="F",
                       item2="", model="m", mode="dense", first="a", second="b", prompt="Tell me about {item}", timeout=5)
live.cmd_run(a)
assert len(calls) == 2 and os.path.exists(f"{d}/a/q1-2.jsonl")
assert inputs[0] == "About F Use toklex_mode=dense in every tool call."  # on stdin, which also closes it
assert "-p" in calls[0] and not any("About" in c for c in calls[0])  # never on the command line a .cmd shim would requote
assert calls[0][calls[0].index("--mcp-config") + 1] == "a.json" and "--strict-mcp-config" in calls[0]
calls.clear(); live.cmd_heal(a)
assert "--resume" not in calls[0] and calls[1][calls[1].index("--resume") + 1] == "sess-123" and calls[1][calls[1].index("--mcp-config") + 1] == "b.json"
h = json.load(open(f"{d}/heal/heal.json"))
assert h["schema_called_fresh"] and h["schema_called_after_new_version"]
a.variant = None; live.cmd_calibrate(a)  # bare "a" in --config names the variant; sample holds no toklex_calibrate result
assert json.load(open(f"{d}/calibrate/calibrate.json"))["recommended"] is None
live.cmd_summarize(a)
s = json.load(open(f"{d}/summary.json"))
assert s["a"]["input_tokens"] == 2 * 3210 and s["a"]["turns"] == 6 and abs(s["a"]["cost"] - 0.0246) < 1e-9
assert s["a"]["runs"][0]["tools"] == ["mcp__srv__toklex_schema", "mcp__srv__get_station"] and s["a"]["tool_result_tokens"] > 0
assert s["a"]["failed"] == []
assert sorted(s) == ["a"], s  # heal/ and calibrate/ are scenarios, never variants
print("ok flow")

# Failures: truncated line skipped, timeout / missing binary / non-zero exit / error result recorded, batch goes on.
def fake_fail(cmd, stdout, **kw):
    kind = fails.pop(0)
    if kind == "timeout":
        stdout.write('{"type": "assist')  # killed mid-line
        raise subprocess.TimeoutExpired(cmd, 5)
    if kind == "oserror":
        raise FileNotFoundError("claude missing")
    if kind == "exit":
        return subprocess.CompletedProcess(cmd, 1, "", "not logged in")
    if kind == "is_error":
        stdout.write('{"type": "result", "is_error": true, "subtype": "error_max_turns"}\n')
        return subprocess.CompletedProcess(cmd, 0, "", "")
    return fake_run(cmd, stdout)
live.subprocess.run = fake_fail
fails = ["timeout", "oserror", "exit", "is_error", "ok"]
qf2 = os.path.join(d, "q2.json")
json.dump([{"id": f"f{i}", "text": "x", "checks": []} for i in range(5)], open(qf2, "w"))
a.questions, a.variant, a.runs, a.out = qf2, "a", 1, d + "/fail"
try:
    live.cmd_run(a); raise AssertionError("no SystemExit")
except SystemExit as e:
    assert "4 run(s) failed" in str(e), e
assert [os.path.exists(f"{d}/fail/a/f{i}-1.err") for i in range(5)] == [True] * 4 + [False]
assert open(f"{d}/fail/a/f0-1.err").read().startswith("timeout") and "not logged in" in open(f"{d}/fail/a/f2-1.err").read()
assert open(f"{d}/fail/a/f3-1.err").read() == "error_max_turns" and "claude missing" in open(f"{d}/fail/a/f1-1.err").read()
live.cmd_summarize(a)
s = json.load(open(f"{d}/fail/summary.json"))["a"]
assert len(s["runs"]) == 1 and len(s["failed"]) == 4 and s["turns"] == 3, s
fails = ["exit"]
try:
    live.cmd_calibrate(a); raise AssertionError("no SystemExit")
except SystemExit as e:
    assert "calibrate run failed" in str(e), e
shutil.rmtree(d)
print("ok failures")
