# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# Conformance runner for the Python port. Run: python python/tests/run.py
import dataclasses, datetime as dt, decimal, enum, glob, json, math, os, re, sys, tempfile, time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))
from toklex import Toklex, ToklexError  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(HERE))
FX = os.path.join(ROOT, "spec", "fixtures")
n, fails = 0, []


def same(a, b):
    """Strict equality, except that two numbers are equal when they differ by less than 1e-9, and an empty dict equals an empty list as PHP arrays do."""
    if isinstance(a, (dict, list)) and isinstance(b, (dict, list)) and not a and not b:
        return True
    if isinstance(a, dict) and isinstance(b, dict):
        return a.keys() == b.keys() and all(same(a[k], b[k]) for k in a)
    if isinstance(a, list) and isinstance(b, list):
        return len(a) == len(b) and all(same(x, y) for x, y in zip(a, b))
    num = lambda x: isinstance(x, (int, float)) and not isinstance(x, bool)  # noqa: E731
    if num(a) and num(b):
        return abs(a - b) < 1e-9
    return type(a) is type(b) and a == b


def check(name, expected, actual):
    global n
    n += 1
    if not same(expected, actual):
        fails.append(f"FAIL {name}\n  expected: {json.dumps(expected, ensure_ascii=False, default=str)}\n  actual:   {json.dumps(actual, ensure_ascii=False, default=str)}")


def raises(f):
    try:
        f()
    except ToklexError:
        return True
    return False


def load(name):
    return json.load(open(os.path.join(FX, name), encoding="utf-8"))


# schema loading, version, schema text, tools, modes, resources
for f in sorted(glob.glob(f"{FX}/invalid/*.json")):
    check(f"invalid/{os.path.basename(f)} is rejected", True, raises(lambda: Toklex.from_file(f)))
check("missing file is rejected", True, raises(lambda: Toklex.from_file(f"{FX}/nope.json")))
with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False) as junk:
    junk.write("not json")
check("non-JSON file is rejected", True, raises(lambda: Toklex.from_file(junk.name)))
os.unlink(junk.name)

tx = Toklex.from_file(f"{FX}/test.schema.json")
v = tx.version()
check("version matches hash.json", load("hash.json")["version"], v)
raw = open(f"{FX}/test.schema.json", "rb").read().replace(b"\r\n", b"\n").replace(b"\n", b"\r\n")
with tempfile.NamedTemporaryFile("wb", suffix=".json", delete=False) as crlf:
    crlf.write(raw)
check("CRLF copy has the same version", v, Toklex.from_file(crlf.name).version())
os.unlink(crlf.name)

text = tx.schema_text()
contract = f"Toklex reply ~tx@{v}. If this version is not in context, call toklex_schema first. Never guess a code."
tools = tx.with_tools([{"name": "get_station", "description": "Get a station.", "inputSchema": {"type": "object", "properties": {}}}])
check("with_tools returns the tool plus the two Toklex tools", ["get_station", "toklex_schema", "toklex_calibrate"], [t["name"] for t in tools])
check("first description carries the contract", "Get a station.\n\n" + contract, tools[0]["description"])
warnings = []
tx.with_tools([{"name": "big", "description": "x" * 2100}], warnings.append)
check("2100-char description gives one warning to the callback", 1, len(warnings))
warnings.clear()
os_tools = tx.with_tools([{"name": "a", "outputSchema": {"type": "object"}}], warnings.append)
check("with_tools removes outputSchema and says so", [False, 1], ["outputSchema" in os_tools[0], len(warnings)])

for i, c in enumerate(load("modes/args.json")):
    args = dict(c["args"])
    check(f"modes/args.json #{i} mode", c["expected_mode"], tx.mode(c["tool"], args))
    check(f"modes/args.json #{i} args after", c["expected_args_after"], args)
args = {"toklex_mode": ["dense"]}
check("non-string toklex_mode falls back to the default", "safe", tx.mode("get_station", args))
check("non-string toklex_mode is still removed", {}, args)

check("resources lists the two URIs", ["toklex://modes", "toklex://schema"], [r["uri"] for r in tx.resources()])
check("schema resource equals schema_text", text, tx.read_resource("toklex://schema"))
check("unknown resource is None", None, tx.read_resource("toklex://nope"))
modes = tx.read_resource("toklex://modes")

# every case, encode byte for byte and read back
cases = sorted(glob.glob(f"{FX}/cases/*.json"))
schemas = {}
for f in cases:
    name = os.path.basename(f)[:-5]
    c = json.load(open(f, encoding="utf-8"))
    cx = schemas.setdefault(c["schema"], Toklex.from_file(f"{FX}/{c['schema']}"))
    cv = cx.version()
    exp = c["expected"].replace("{v}", cv)
    got = (cx.error(c["error"], c.get("mode"), c.get("instructions", True)) if "error" in c
           else cx.encode(c["tool"], c["rows"], c["meta"], c.get("mode"), c.get("instructions", True)))
    check(f"{name} encode", exp, got)
    d = cx.decode(exp)
    check(f"{name} decode schema", [load(c["schema"])["id"], cv, c.get("decoded_instructions", True)], [d["schema"], d["version"], d["instructions"]])
    if "error" in c:
        check(f"{name} decode meta", c["decoded_meta"], d["meta"])
        check(f"{name} decode rows", [], d["rows"])
        continue
    check(f"{name} decode rows", c.get("decoded", c["rows"]), d["rows"])
    check(f"{name} decode meta", c.get("decoded_meta", c["meta"] if c.get("mode") == "off" else {k: x for k, x in c["meta"].items() if x is not None}), d["meta"])

spec = open(os.path.join(ROOT, "spec", "toklex.md"), encoding="utf-8").read().replace("\r\n", "\n")
cnt = re.search(r"The folder `cases/` holds (\d+) files: (\d+) coded cases .*? and (\d+) plain cases .*?The folder `invalid/` holds (\d+) schemas\.", spec, re.S)
coded = sum(int(os.path.basename(f)[:2]) < 40 for f in cases)
check("the fixture folders hold what section 10 counts", [int(x) for x in cnt.groups()], [len(cases), coded, len(cases) - coded, len(glob.glob(f"{FX}/invalid/*.json"))])

hd = f"~tx@{v} i:+"
dh = hd
tp = Toklex.from_file(f"{FX}/plain.schema.json")
last = lambda row: tx.encode("get_station", [row]).rsplit("\n", 1)[1]  # noqa: E731
check("a very small and a very large float have no exponent", "x|0.00000015|100000000000000000000", last({"k": "x", "a": 1.5e-7, "b": 1e20}))
check("INF, -INF and NAN are empty cells", [f"{hd}\nk\n|"] * 3, [tx.encode("get_station", [{"k": x}]) for x in (math.inf, -math.inf, math.nan)])
check("a non-finite header value is left out", hd, tx.encode("get_station", [], {"k": math.nan}))
check("meta keys i and e are reserved", [True, True], [raises(lambda: tx.encode("get_station", [], {"i": "x"})), raises(lambda: tx.encode("get_station", [], {"e": "x"}, "off"))])
check("a lone surrogate becomes U+FFFD", f"{hd}\nid\na�b", tx.encode("get_station", [{"station_id": "a\ud800b"}]))
check("dates with a step of 7 are a sequence", f"{dh}\n[3]\nt:2026-07-01+7d", tx.encode("station_history", [{"date": d} for d in ("2026-07-01", "2026-07-08", "2026-07-15")]))
check("a sequence crosses a leap day", f"{dh}\n[3]\nt:2028-02-28+1d", tx.encode("station_history", [{"date": d} for d in ("2028-02-28", "2028-02-29", "2028-03-01")]))
check("an impossible date is not a sequence", f"{dh}\n[3]\nt:2026-02-27|2026-02-28|2026-02-29", tx.encode("station_history", [{"date": d} for d in ("2026-02-27", "2026-02-28", "2026-02-29")]))
check("a date sequence reads back as N dates", ["2026-07-01", "2026-07-08", "2026-07-15"], [r["date"] for r in tx.decode(f"{dh}\n[3]\nt:2026-07-01+7d")["rows"]])
check("a run longer than the record count is cut at N", [{"name": "x"}, {"name": "x"}], tx.decode(f"{dh}\n[2]\nn:x*9")["rows"])
check("a forged record count is refused", [True, True], [raises(lambda: tx.decode(f"{dh}\n[100001]\nn:x")), raises(lambda: tx.decode(f"{dh}\n[100000]\n" + "\n".join(f"c{i}:x*100000" for i in range(200))))])


def refused(text):
    try:
        return len(tx.decode(text)["rows"])
    except ToklexError:
        return "refused"


# review 2026-10-19, item 1: the cost of a reply bounds memory and time (spec 11)
cols = lambda n, k, cell="x": f"{dh}\n[{n}]\n" + "\n".join(f"c{i}:{cell}*{n}" for i in range(k))  # noqa: E731
check("a reply at the cost limit decodes and one more column is refused", [100000, "refused"], [refused(cols(100000, 5)), refused(cols(100000, 6))])
check("a nested column costs its depth", "refused", refused(f"{dh}\n[100000]\n" + ".".join(["a"] * 10) + ":x*100000"))
check("a column path of 64 names decodes, 65 or 1000 are refused", [1, "refused", "refused"],
      [refused(f"{dh}\n" + ".".join(["a"] * 64) + "\n1"), refused(f"{dh}\n" + ".".join(["a"] * 65) + "\n1"), refused(f"{dh}\n" + ".".join(["a"] * 1000) + "\n" + "\n".join(["1"] * 2000))])
safe = lambda n, k: f"{dh}\n" + "|".join(f"c{i}" for i in range(k)) + "\n" + "\n".join(["|".join(["1"] * k)] * n)  # noqa: E731
check("safe mode has the same cost limit", [100000, "refused"], [refused(safe(100000, 5)), refused(safe(100000, 6))])
check("safe mode has the same record limit", [100000, "refused", "refused"], [refused(safe(100000, 1)), refused(safe(100001, 1)), refused(f"{dh}\n\n" + "\n" * 100001)])
t0 = time.time()
check("a run of a long JSON array is refused before it is decoded", ["refused", True], [refused(f"{dh}\n[10000]\na:[" + ",".join(map(str, range(3000))) + "]*10000"), time.time() - t0 < 1])
check("each [ or { in a JSON array cell costs 8", [7000, "refused"], [refused(f"{dh}\n[7000]\na:" + "[" * 8 + "]" * 8 + "*7000"), refused(f"{dh}\n[8000]\na:" + "[" * 8 + "]" * 8 + "*8000")])
check("rows of a run never share a list", False, (lambda r: r[0]["labels"] is r[1]["labels"])(tx.decode(f"{dh}\n[2]\nlb:[1]*2")["rows"]))
check("a JSON array cell nested 64 levels is read, 65 levels stay text", [list, str],
      [type(tx.decode(f"{dh}\nlb\n" + "[" * 64 + "]" * 64)["rows"][0]["labels"]), type(tx.decode(f"{dh}\nlb\n" + "[" * 65 + "]" * 65)["rows"][0]["labels"])])

# review 2026-10-19, item 4 and item 3c: forged date sequences are refused, the calendar holds at year 0000
for seq in ("2026-13-45+1d", "2026-02-30+1d", "2026-01-01+99999999999999999999d", "9999-12-30+1d"):
    check(f"the date sequence {seq} is refused as a reply", "refused", refused(f"{dh}\n[3]\nt:{seq}"))
check("a date sequence that ends on 9999-12-31 reads back", ["9999-12-30", "9999-12-31"], [r["date"] for r in tx.decode(f"{dh}\n[2]\nt:9999-12-30+1d")["rows"]])
check("a leap day of year 0000 is a date sequence both ways", [f"{dh}\n[3]\nt:0000-02-27+1d", ["0000-02-27", "0000-02-28", "0000-02-29"]],
      [tx.encode("station_history", [{"date": d} for d in ("0000-02-27", "0000-02-28", "0000-02-29")]), [r["date"] for r in tx.decode(f"{dh}\n[3]\nt:0000-02-27+1d")["rows"]]])
check("a date sequence crosses from year 0000 into 0001", ["0000-12-31", "0001-01-01", "0001-01-02"], [r["date"] for r in tx.decode(f"{dh}\n[3]\nt:0000-12-31+1d")["rows"]])
check("a forged run count of 5000 digits is cut at N", [{"name": "x"}], tx.decode(f"{dh}\n[1]\nn:x*" + "9" * 5000)["rows"])
check("an escaped name never splits into two names in any mode", [{"a|b": "x", "my key": "y"}, {"k:x y": "z"}],
      [tp.decode(tp.encode("station_history", [{"a|b": "x", "my key": "y"}]))["rows"][0], tp.decode(tp.encode("get_station", [], {"k:x y": "z"}))["meta"]])


class Level(enum.Enum):
    HIGH = "HIGH"


@dataclasses.dataclass
class Point:
    x: int
    _hidden: int = 0


check("an enum case is its value", f"{hd}\nl\nh", tx.encode("get_station", [{"wind_level": Level.HIGH}]))
check("a date at midnight is the date alone and stays a date sequence", f"{dh}\n[3]\nt:2026-07-01+1d",
      tx.encode("station_history", [{"date": dt.datetime(2026, 7, d)} for d in (1, 2, 3)]))
check("any other date-time is ISO 8601 with the offset, and a fraction only when there is one",
      f"{hd}\nk\n2026-07-01T10:30:00+02:00\n2026-07-01T10:30:00.250000+00:00",
      tx.encode("get_station", [{"k": dt.datetime(2026, 7, 1, 10, 30, tzinfo=dt.timezone(dt.timedelta(hours=2)))},
                                {"k": dt.datetime(2026, 7, 1, 10, 30, 0, 250000, tzinfo=dt.timezone.utc)}]))
check("a dataclass is the map of its public fields, a Decimal is a number", f"{hd}\nid|k.x|v\na|3|1235",
      tx.encode("get_station", [{"station_id": "a", "k": Point(3, 9), "rain_m": decimal.Decimal("0.12345")}]))

# review 2026-10-19, items 11 and 14: no value loses data in silence or makes the encoder fail, and the ports agree
loop = {"id": "a"}
loop["me"] = loop
check("a dict that holds itself is None where it repeats, in every mode", [f"{hd}\nid|me\na", True],
      [tx.encode("get_station", [loop]), '"rows":[{"id":"a","me":null}]' in tx.encode("get_station", [loop], {}, "off")])
deep = "x"
for _ in range(70):
    deep = {"k": deep}
check("a map nested past 64 levels stops there, and the reply reads back", [63, True],
      [tx.encode("get_station", [{"d": deep}]).split("\n")[1].count("."), tx.decode(tx.encode("get_station", [{"d": deep}]))["rows"] != []])


class Chain:
    def __init__(self, n):
        self.n = n

    def model_dump(self):
        return Chain(self.n - 1) if self.n else "end"


check("a function is None, a chain of 8 conversions is followed and a longer one is None", [f"{hd}\nf|c\n|end", f"{hd}\nc\n|"],
      [tx.encode("get_station", [{"f": len, "c": Chain(7)}]), tx.encode("get_station", [{"c": Chain(8)}])])
check("a dict with the keys 0, 1 in order is a list as in PHP, other dicts stay maps", [f"{hd}\nid|a\nx|[1,2]", f"{hd}\nid|a.1|a.0\nx|1|2"],
      [tx.encode("get_station", [{"station_id": "x", "a": {"0": 1, "1": 2}}]), tx.encode("get_station", [{"station_id": "x", "a": {"1": 1, "0": 2}}])])
check("a JSON object cell with the keys 0, 1 reads back as a list", [[[1, 2]]], [r["labels"] for r in tx.decode(f'{hd}\nlb\n[{{"0":1,"1":2}}]')["rows"]])
check("a naive datetime gets +00:00 as PHP gives every date-time a zone", f"{hd}\nk\n2026-07-01T10:30:00+00:00", tx.encode("get_station", [{"k": dt.datetime(2026, 7, 1, 10, 30)}]))
check("an inputSchema {} stays without a type, as in PHP", {"properties": {"toklex_mode": {"type": "string", "enum": ["off", "safe", "dense"], "description": "Reply format: off, safe or dense."}}},
      tx.with_tools([{"name": "a", "inputSchema": {}}], lambda m: None)[0]["inputSchema"])
check("an outputSchema that is None is removed", False, "outputSchema" in tx.with_tools([{"name": "a", "outputSchema": None}], lambda m: None)[0])
for what, bad in {"a dict": {"station_id": "a"}, "a number": [1], "None": [None]}.items():
    check(f"encode refuses {what} as a list of records, in every mode", [True, True],
          [raises(lambda: tx.encode("get_station", bad if what != "a dict" else ["x"])), raises(lambda: tx.encode("get_station", bad if what != "a dict" else ["x"], {}, "off"))])
check("decode refuses an off reply that is not JSON or has no schema", [True, True], [raises(lambda: tx.decode("{not json")), raises(lambda: tx.decode('{"a":1}'))])
check("a plain error without instructions starts with the header and reads back", [f"~tp@{tp.version()} error:boom", False],
      [tp.error("boom", None, False), tp.decode(tp.error("boom", None, False))["instructions"]])
check("auto with no records is the header alone", tp.encode("get_station", []), tp.encode("station_log", []))

# calibration
cal = tx.calibrate(None)
right = {"s1": "2026-03-05", "s2": "0.0823", "s3": "3", "d1": "MEDIUM", "d2": "2026-03-07", "d3": "0.0917"}
check("all correct is dense, the reply shape is exact", '{"recommended":"dense","results":{"s1":true,"s2":true,"s3":true,"d1":true,"d2":true,"d3":true}}', tx.calibrate(right))
check("only the s answers correct is safe", "safe", json.loads(tx.calibrate({k: right[k] for k in ("s1", "s2", "s3")}))["recommended"])
check("one wrong s and one wrong d is off", "off", json.loads(tx.calibrate(dict(right, s2="x", d1="x")))["recommended"])
check("numbers and strings compare by decimal text", "dense", json.loads(tx.calibrate(dict(right, s2=0.0823, s3=3, d3=0.0917)))["recommended"])
check("answers are trimmed, comparison is case-sensitive", [True, False], [json.loads(tx.calibrate(dict(right, d1=" MEDIUM\n")))["results"]["d1"], json.loads(tx.calibrate(dict(right, d1="medium")))["results"]["d1"]])
check("the arguments object and a JSON string grade like the bare map", [tx.calibrate(right)] * 2, [tx.calibrate({"answers": right}), tx.calibrate({"answers": json.dumps(right)})])
check("malformed answers return the dataset", [cal] * 5, [tx.calibrate({"answers": []}), tx.calibrate(["a"]), tx.calibrate({}), tx.calibrate({"answers": "x"}), tx.calibrate({"toklex_mode": "safe"})])

# spec appendix A: every model-facing text, verbatim
app = re.findall(r"^```\w*\n(.*?)\n```$", spec[spec.index("\n## Appendix A"):], re.S | re.M)
app = [b.replace("{v}", v) for b in app]
pretty = lambda x: json.dumps(x, indent=4, ensure_ascii=False)  # noqa: E731
pt = tp.with_tools([{"name": "get_station", "description": "Get a station.", "inputSchema": {"type": "object", "properties": {}}}])
c41 = load("cases/41-plain-dense.json")
count_line = re.search(r"^\[5\] .*$", tp.encode(c41["tool"], c41["rows"], c41["meta"]), re.M).group(0)
check("appendix A has ten blocks", 10, len(app))
check("appendix A.1 is the schema text", text, app[0])
check("appendix A.2 is the tool list", pretty(tools), app[1])
check("appendix A.3 is the resource list", pretty(tx.resources()), app[2])
check("appendix A.3 is the modes text", modes, app[3])
check("appendix A.4 is the calibration page", cal, app[4])
check("appendix A.4 is the graded reply", tx.calibrate(right), app[5])
check("appendix A.5 is the plain tool list", pretty(pt), app[6])
check("appendix A.5 is the plain resource list", pretty(tp.resources()), app[7])
check("appendix A.5 is the plain modes text", tp.read_resource("toklex://modes"), app[8])
check("appendix A.5 is the plain dense count line", count_line, app[9])

if fails:
    print("\n".join(fails))
    print(f"{len(fails)} of {n} checks failed")
    sys.exit(1)
print(f"OK {n} checks")
