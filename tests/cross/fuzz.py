# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# Differential test of the three libraries: random records through PHP, Python and TypeScript must give the same
# reply byte for byte and the same rows when read back. Usage: python tests/cross/fuzz.py [seed] [cases]
import json, os, random, subprocess, sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, os.path.join(ROOT, "python"))
from toklex import Toklex, ToklexError  # noqa: E402

ATOMS = ["", "a", "m", "MEDIUM", "HIGH", "x y", "a|b", "5*star", '"q', "[x]", "{", "true", "null", "12", "0.5", "1e3", "007",
         "Line1\nLine2", "tab\tx", "Côte", "a/b", "2026-07-03+1d", "2026-07-01", "\\", "u\\u007c", "ü÷", " lead", "-0", "0", " "]
NAMES = ["station_id", "name", "wind_level", "rain_m", "snow_m", "date", "user", "login", "labels", "a.b", "k", "x y", "a|b",
         "c:d", "[1]", "z(÷5)", "n", "e", "i", "t\tx", "back\\slash"]
NUMS = [0, 1, -1, 5.0, 0.0743, 0.00015, -0.00015, 1.5e-7, 1e20, 12345678.9, 0.1, 2 ** 40, -3.25, 0.0001499999999999999, 1e15, 123456789012.5]


def same(a, b):
    if isinstance(a, (dict, list)) and isinstance(b, (dict, list)) and not a and not b:
        return True
    if isinstance(a, dict) and isinstance(b, dict):
        return a.keys() == b.keys() and all(same(a[k], b[k]) for k in a)
    if isinstance(a, list) and isinstance(b, list):
        return len(a) == len(b) and all(same(x, y) for x, y in zip(a, b))
    num = lambda x: isinstance(x, (int, float)) and not isinstance(x, bool)  # noqa: E731
    return abs(a - b) < 1e-9 if num(a) and num(b) else type(a) is type(b) and a == b


def val(rnd, d=0):
    r = rnd.random()
    if r < .15: return None  # noqa: E701
    if r < .25: return rnd.choice([True, False])  # noqa: E701
    if r < .5: return rnd.choice(NUMS)  # noqa: E701
    if r < .8: return rnd.choice(ATOMS)  # noqa: E701
    if r < .9 and d < 2: return [val(rnd, d + 1) for _ in range(rnd.randint(0, 3))]  # noqa: E701
    if d < 2: return {rnd.choice(NAMES): val(rnd, d + 1) for _ in range(rnd.randint(0, 3))}  # noqa: E701
    return "z"


def cases(seed, count):
    rnd = random.Random(seed)
    out = []
    for _ in range(count):
        rows = [{rnd.choice(NAMES): val(rnd) for _ in range(rnd.randint(0, 5))} for _ in range(rnd.randint(0, 6))]
        if rnd.random() < .3:  # a series, so dense and auto meet date sequences
            rows = [{"date": "2026-07-%02d" % (i + 1), "rain_m": rnd.choice([0.07, 0.08]), "wind_level": rnd.choice(["HIGH", "LOW"])} for i in range(rnd.randint(1, 12))]
        meta = {rnd.choice(NAMES + ["my key"]): rnd.choice(NUMS + ATOMS) for _ in range(rnd.randint(0, 3))}
        out.append({"schema": rnd.choice(["test.schema.json", "plain.schema.json", "plain-server.schema.json"]),
                    "tool": rnd.choice(["get_station", "station_history", "station_log"]),
                    "rows": rows, "meta": {k: x for k, x in meta.items() if k not in ("error", "i", "e")},
                    "mode": rnd.choice([None, "safe", "dense", "off", "auto"])})
    return out


def python(cs):
    tx, out = {}, []
    for c in cs:
        t = tx.setdefault(c["schema"], Toklex.from_file(os.path.join(ROOT, "spec", "fixtures", c["schema"])))
        try:
            r = t.encode(c["tool"], c["rows"], c["meta"], c["mode"])
            out.append([r, t.decode(r)["rows"]])
        except ToklexError:
            out.append(["ERR", ""])
    return out


def run(cmd, cs):
    return json.loads(subprocess.run(cmd, input=json.dumps(cs), capture_output=True, text=True, encoding="utf-8", check=True).stdout)


if __name__ == "__main__":
    seed = int(sys.argv[1]) if len(sys.argv) > 1 else 1
    cs = cases(seed, int(sys.argv[2]) if len(sys.argv) > 2 else 1000)
    got = {"php": run(["php", os.path.join(HERE, "encode.php")], cs), "python": python(cs), "ts": run(["node", os.path.join(HERE, "encode.ts")], cs)}
    bad = 0
    for i, c in enumerate(cs):
        ref = got["php"][i]
        for lang in ("python", "ts"):
            r = got[lang][i]
            if r[0] != ref[0] or not same(ref[1], r[1]):
                bad += 1
                if bad <= 3:
                    print(f"MISMATCH {lang} case {i}: {json.dumps(c, ensure_ascii=False)[:300]}\n  php: {json.dumps(ref, ensure_ascii=False)[:300]}\n  {lang}: {json.dumps(r, ensure_ascii=False)[:300]}")
    print(f"seed {seed}: {len(cs)} cases, {bad} mismatches")
    sys.exit(1 if bad else 0)
