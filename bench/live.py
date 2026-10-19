# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# Generic live-run harness: drives `claude -p` against any MCP server for A/B runs between reply modes,
# schema self-healing checks and the calibration scenario. Knows nothing about a particular site.
# Usage: python live.py {run|heal|calibrate|summarize} --out DIR --server NAME --config V=FILE ... (see --help)
import argparse, glob, json, os, re, shlex, shutil, subprocess, sys


def parse(path):
    """Read a stream-json file -> tools (ordered names), final text, usage, cost, turns, session_id, results [(tool, text)]."""
    r = dict(tools=[], final="", usage={}, cost=0.0, turns=0, session_id="", results=[], ok=False, error="no result line")
    names = {}
    for line in open(path, encoding="utf-8"):
        try:
            e = json.loads(line)
        except ValueError:  # blank or truncated line (killed run)
            continue
        m = e.get("message")  # a string on permission_denied events
        content = m.get("content") if isinstance(m, dict) else None
        for b in content if isinstance(content, list) else []:
            if e["type"] == "assistant" and b.get("type") == "tool_use":
                names[b["id"]] = b["name"]
                r["tools"].append(b["name"])
            elif e["type"] == "user" and b.get("type") == "tool_result":
                c = b.get("content")
                if isinstance(c, list):
                    c = "".join(x.get("text", "") for x in c if isinstance(x, dict))
                r["results"].append((names.get(b.get("tool_use_id"), ""), c or ""))
        if e["type"] == "result":
            r.update(final=e.get("result") or "", usage=e.get("usage") or {}, cost=e.get("total_cost_usd") or 0.0,
                     turns=e.get("num_turns") or 0, ok=not e.get("is_error"),
                     error="" if not e.get("is_error") else str(e.get("subtype") or "is_error"))
        if e.get("session_id"):
            r["session_id"] = e["session_id"]
    return r


def fmt(text, item, item2=None):
    # plain replace, not str.format: question texts may contain other braces
    return text.replace("{item}", item).replace("{item2}", item2 or "")


def recommended(results):
    """Recommended mode from the last toklex_calibrate tool_result, or None."""
    for name, text in reversed(results):
        if name.endswith("toklex_calibrate"):
            m = re.search(r'"recommended"\s*:\s*"(\w+)"', text)
            if m:
                return m.group(1)
    return None


def claude(a, prompt, cfg, path, resume=None):
    # the prompt goes in on stdin: no cmd.exe quoting through a claude.cmd shim, and a closed stdin so -p never waits
    cmd = [shutil.which("claude") or "claude", "-p", "--model", a.model, "--output-format", "stream-json",
           "--verbose", "--mcp-config", cfg, "--strict-mcp-config", "--allowedTools", f"mcp__{a.server}"]
    if resume:
        cmd += ["--resume", resume]
    cmd += shlex.split(a.claude_args)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    err = ""
    with open(path, "w", encoding="utf-8") as f:
        try:
            cp = subprocess.run(cmd, input=prompt, stdout=f, stderr=subprocess.PIPE, text=True, encoding="utf-8", errors="replace", timeout=a.timeout)
            if cp.returncode:
                err = f"exit {cp.returncode}: {(cp.stderr or '').strip()}"
        except subprocess.TimeoutExpired:
            err = f"timeout after {a.timeout}s"
        except OSError as e:
            err = str(e)
    p = parse(path)
    if err:
        p.update(ok=False, error=err)
    if not p["ok"]:  # failed runs stay visible: .err file next to the jsonl, ok/error in the result
        open(path[:-6] + ".err", "w", encoding="utf-8").write(p["error"])
    elif os.path.exists(path[:-6] + ".err"):
        os.remove(path[:-6] + ".err")
    return p


def need(p, what):
    if not p["ok"]:
        raise SystemExit(f"{what} failed: {p['error']}")


def config(a, name):
    return dict(c.split("=", 1) for c in a.config if "=" in c)[name]


def ask(a, text):
    return text + (f" Use toklex_mode={a.mode} in every tool call." if a.mode else "")


def cmd_run(a):
    failed = 0
    for q in json.load(open(a.questions, encoding="utf-8")):
        for n in range(1, a.runs + 1):  # one failed run must not abort the batch: report it, go on
            path = f"{a.out}/{a.variant}/{q['id']}-{n}.jsonl"
            p = claude(a, ask(a, fmt(q["text"], a.item, a.item2)), config(a, a.variant), path)
            if not p["ok"]:
                failed += 1
                print(f"FAILED {path}: {p['error']}")
    if failed:
        raise SystemExit(f"{failed} run(s) failed")


def cmd_heal(a):
    schema = f"mcp__{a.server}__toklex_schema"
    first = claude(a, ask(a, fmt(a.prompt, a.item)), config(a, a.first), f"{a.out}/heal/a-{a.first}.jsonl")
    need(first, "heal first run")
    second = claude(a, ask(a, fmt(a.prompt2, a.item)), config(a, a.second), f"{a.out}/heal/b-{a.second}.jsonl", resume=first["session_id"])
    need(second, "heal second run")
    # None = the run made no tool call besides toklex_schema, so there was nothing to check
    seen = lambda p: (schema in p["tools"]) if any(t != schema for t in p["tools"]) else None
    res = {"first": a.first, "second": a.second, "schema_called_fresh": seen(first),
           "schema_called_after_new_version": seen(second), "tools_a": first["tools"], "tools_b": second["tools"]}
    json.dump(res, open(f"{a.out}/heal/heal.json", "w"), indent=2)
    print(json.dumps(res, indent=2))


def cmd_calibrate(a):
    v = a.variant or next((c for c in a.config if "=" not in c), None)  # `calibrate --config V` names the variant bare
    if not v:
        raise SystemExit("calibrate needs --variant NAME (or a bare NAME in --config)")
    p = claude(a, "Call toklex_calibrate, answer its questions, submit the answers, and tell me the recommended mode.",
               config(a, v), f"{a.out}/calibrate/{v}.jsonl")
    need(p, "calibrate run")
    res = {"config": v, "recommended": recommended(p["results"]), "tools": p["tools"], "final": p["final"]}
    json.dump(res, open(f"{a.out}/calibrate/calibrate.json", "w"), indent=2)
    print(json.dumps(res, indent=2))


def stats(xs):
    """Mean, sample standard deviation and count. A small sample varies a lot, so a table shows the spread too."""
    m = sum(xs) / len(xs) if xs else 0.0
    sd = (sum((x - m) ** 2 for x in xs) / (len(xs) - 1)) ** .5 if len(xs) > 1 else 0.0
    return dict(mean=round(m, 6), stdev=round(sd, 6), n=len(xs))


def cmd_summarize(a):
    import tokens
    count = tokens.make_counter(a.counter, a.count_model)
    out = {}
    keys = ("input_tokens", "output_tokens", "cost", "turns", "tool_result_tokens")
    # heal/ and calibrate/ are scenarios, not variants, so they stay out of the totals
    for d in sorted(x for x in glob.glob(f"{a.out}/*") if os.path.isdir(x) and os.path.basename(x) not in ("heal", "calibrate")):
        s = dict(input_tokens=0, output_tokens=0, cost=0.0, turns=0, tool_result_tokens=0, runs=[], failed=[])
        per = {k: [] for k in keys}
        by_q = {}
        for f in sorted(glob.glob(f"{d}/*.jsonl")):
            p = parse(f)
            run = os.path.basename(f)[:-6]
            if not p["ok"]:  # failed runs are listed, never counted into the totals
                err = f[:-6] + ".err"
                s["failed"].append(dict(run=run, error=open(err, encoding="utf-8").read() if os.path.exists(err) else p["error"]))
                continue
            u = p["usage"]
            one = dict(input_tokens=sum(u.get(k, 0) for k in ("input_tokens", "cache_creation_input_tokens", "cache_read_input_tokens")),
                       output_tokens=u.get("output_tokens", 0), cost=p["cost"], turns=p["turns"],
                       tool_result_tokens=sum(count(t) for _, t in p["results"]))
            for k in keys:
                s[k] += one[k]
                per[k].append(one[k])
                by_q.setdefault(re.sub(r"-\d+$", "", run), {x: [] for x in keys})[k].append(one[k])
            s["runs"].append(dict(run=run, tools=p["tools"], final=p["final"], **one))
        if s["runs"] or s["failed"]:
            s["per_run"] = {k: stats(v) for k, v in per.items()}
            s["by_question"] = {q: {k: stats(v) for k, v in m.items()} for q, m in sorted(by_q.items())}
            out[os.path.basename(d)] = s
    json.dump(out, open(f"{a.out}/summary.json", "w", encoding="utf-8"), indent=2, ensure_ascii=False)
    print(json.dumps({k: {x: v[x] for x in v if x not in ("runs", "by_question")} for k, v in out.items()}, indent=2))


if __name__ == "__main__":
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("cmd", choices=["run", "heal", "calibrate", "summarize"],
                    help="heal and calibrate apply to coded servers (they use toklex_schema and toklex_calibrate); the plain style has neither")
    ap.add_argument("--out", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), "live", "out"), help="default bench/live/out, which git ignores")
    ap.add_argument("--server", help="MCP server name inside the configs")
    ap.add_argument("--config", action="append", default=[], metavar="NAME=FILE", help="variant name -> MCP config (repeatable); calibrate also takes a bare variant name")
    ap.add_argument("--questions", help="JSON list of {id, text, checks}; text may hold {item} and {item2}")
    ap.add_argument("--variant"), ap.add_argument("--runs", type=int, default=1)
    ap.add_argument("--item", default=""), ap.add_argument("--item2", default="")
    ap.add_argument("--model", default="sonnet")
    ap.add_argument("--mode", help="appends 'Use toklex_mode=M in every tool call.' to each question")
    ap.add_argument("--first"), ap.add_argument("--second")
    ap.add_argument("--prompt", default="Tell me about {item} using the available tools.", help="heal question for the first run")
    ap.add_argument("--prompt2", default="Look up {item} again with the tools, the data may have changed.", help="heal question for the resumed second run")
    ap.add_argument("--timeout", type=int, default=600)
    ap.add_argument("--claude-args", default="", help='extra flags for every claude call, one string: --claude-args "--setting-sources project"')
    ap.add_argument("--counter", choices=["o200k", "claude"], default="o200k", help="summarize: how tool results are counted, see tokens.py")
    ap.add_argument("--count-model", default="claude-opus-5-5", help="summarize: the model whose tokenizer --counter claude uses")
    a = ap.parse_args()
    globals()["cmd_" + a.cmd](a)
