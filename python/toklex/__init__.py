# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# Licensed under Apache-2.0. See LICENSE.
"""Toklex for Python: compact MCP tool results. A port of php/src/Toklex.php that passes spec/fixtures."""
from __future__ import annotations

import copy
import dataclasses
import datetime as _dt
import decimal
import enum
import hashlib
import json
import logging
import math
import re
from typing import Any, Callable, Optional

__all__ = ["Toklex", "ToklexError", "SCHEMA_TOOL", "CALIBRATE_TOOL"]
__version__ = "0.3.0"

SCHEMA_TOOL = "toklex_schema"
CALIBRATE_TOOL = "toklex_calibrate"

_MODES = ("off", "safe", "dense")
_NUM = re.compile(r"-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?")
_DEC = re.compile(r"-?(0|[1-9][0-9]*)(\.[0-9]+)?")
_DATE = re.compile(r"[0-9]{4}-[0-9]{2}-[0-9]{2}")
_SEQ = re.compile(r"([0-9]{4})-([0-9]{2})-([0-9]{2})\+([0-9]+)d")
_SEQ_SHAPE = re.compile(r"[0-9]{4}-[0-9]{2}-[0-9]{2}\+[0-9]+d")
_LABEL = re.compile(r"\(÷[0-9]+\)$")
_BAR = "\\u007c"
_DENSE_LINE = 'One line per column: name:v1|v2|… x*K is x repeated K times, start+Nd is a date every N days, an empty cell is null.'
_PRIMER = 'Toklex encodes tool results compactly. Line 1 is the header: ~<schema>@<version>, then i:+ when the instructions below apply, then key:value metadata (e: is an error message). Normal mode: line 2 lists the columns and every following line is one record, cells separated by |. An empty cell is null and trailing empty cells are left out. A cell in double quotes is a literal JSON string, never a code. Inside it, \\u007c stands for |. A cell starting with [ is a JSON array that uses the same keys and codes. A dotted column such as us.lg is a nested field. Dense mode: line 2 is [N], the record count, and every following line is key:values for one column in record order, separated by |. x*K means x repeated K times. A value like 2026-07-03+1d means that date for the first record and one more day for each next record. Read codes only from this schema and never guess one. If a response header shows a different version, call toklex_schema again.'
_MODES_TEXT = (
    'Toklex modes\n'
    'A server that speaks Toklex can answer a tool call in one of three modes.\n'
    'off: compact JSON with full field names and the full text of the instructions. No codes and no scales.\n'
    'safe: the normal table. Line 1 is the header, line 2 lists the columns and each following line is one record. This is the default for every tool that the schema does not list as dense.\n'
    'dense: line 2 is [N], the record count, and each following line holds one column. This is the default for the tools the schema lists as dense.\n'
    "Pick a mode for one call with the optional argument toklex_mode on a tool of the server. The two Toklex tools, toklex_schema and toklex_calibrate, do not take it. The values are off, safe and dense. If you leave it out or send another value, the tool's default mode applies. The instructions of the server travel in every mode.\n"
    'Dense is the shortest form. Some models misread it. To learn which mode you read reliably, call toklex_calibrate without arguments. It returns a small dataset in both forms and six questions. Answer them from the dataset alone, then call toklex_calibrate again with answers, an object that maps each question id to your answer. The reply names the mode to use. Use it with toklex_mode on later calls.'
)
_PLAIN_MODES_TEXT = (
    'Toklex modes\n'
    'A server that speaks Toklex can answer a tool call in one of three modes.\n'
    'off: compact JSON with full field names and numbers as they are.\n'
    'safe: a table. The line that starts with ~ is the header, the next line names the columns and each following line is one record, cells separated by |. A column name that ends in (÷K) holds the value times K, so divide the cell by K. This is the default for every tool that the server does not list as dense.\n'
    'dense: after the header, a line starting with [N] gives the record count and how to read the block. Each following line holds one column. This is the default for the tools the server lists as dense.\n'
    "Pick a mode for one call with the optional argument toklex_mode. The values are off, safe and dense. If you leave it out or send another value, the tool's default mode applies."
)
_MAX_RECORDS = 100000
_MAX_CELLS = 500000  # the cost of a reply in cells (spec 11)
_MAX_DEPTH = 64
_LAST_DAY = 2932896  # 9999-12-31 as days since 1970-01-01
_AUTO_MIN = 10
# characters that would break a column line or a header, written as \uXXXX (spec 6.1)
_NAME_ESC = {"\\": "\\u005c", "|": "\\u007c", ":": "\\u003a", "÷": "\\u00f7",
             "\t": "\\u0009", "\n": "\\u000a", "\v": "\\u000b", "\f": "\\u000c", "\r": "\\u000d"}
_NAME_ESC_HDR = dict(_NAME_ESC, **{" ": "\\u0020"})
_UNNAME = re.compile(r"\\u([0-9a-f]{4})")

# The calibration dataset and its key. They belong to the library and never depend on a server schema.
_CAL_SCHEMA = {"toklex": 1, "id": "cal", "style": "coded", "keys": {"n": "name", "d": "day", "t": "tier", "p": "price"},
               "values": {"t": {"l": "LOW", "m": "MEDIUM", "h": "HIGH"}}, "scale": {"p": 10000}}
_CAL_ROWS = [
    {"name": "Anna", "day": "2026-03-02", "tier": "LOW", "price": 0.0612},
    {"name": "Boris", "day": "2026-03-03", "tier": "LOW", "price": 0.0655},
    {"name": "Chen", "day": "2026-03-04", "tier": "MEDIUM", "price": 0.0701},
    {"name": "Dara", "day": "2026-03-05", "tier": "MEDIUM", "price": 0.0688},
    {"name": "Emil", "day": "2026-03-06", "tier": "MEDIUM", "price": 0.0744},
    {"name": "Faye", "day": "2026-03-07", "tier": "HIGH", "price": 0.0790},
    {"name": "Gus", "day": "2026-03-08", "tier": "HIGH", "price": 0.0823},
    {"name": "Hana", "day": "2026-03-09", "tier": "LOW", "price": 0.0917},
]
_CAL_QUESTIONS = {
    "s1": ("safe", "On which day is the record named Dara? Write it as YYYY-MM-DD.", "2026-03-05"),
    "s2": ("safe", "What is the price of the record named Gus? Write the decimal number with four decimals.", "0.0823"),
    "s3": ("safe", "How many records have the tier LOW? Write a number.", "3"),
    "d1": ("dense", "What is the tier of the record dated 2026-03-06? Write the full word.", "MEDIUM"),
    "d2": ("dense", "On which day does the first record with the tier HIGH fall? Write it as YYYY-MM-DD.", "2026-03-07"),
    "d3": ("dense", "What is the price of the last record? Write the decimal number with four decimals.", "0.0917"),
}
_CAL_HOWTO = (
    'Safe block: line 1 is the header, line 2 lists the columns and each next line is one record, with cells separated by |.\n'
    'Dense block: line 2 is [8], the record count, and each next line is key:values, one column for all 8 records in record order.\n'
    'z*3 means z three times. 2026-07-03+1d means 2026-07-03 for the first record and one more day for each next record.\n'
    'Read every code through the legend. A scaled number is divided by its scale.'
)

_log = logging.getLogger("toklex")


class ToklexError(ValueError):
    """A broken schema, a reserved metadata key, a record that is not a record, or a reply a reader refuses."""


def _no_const(c: str) -> Any:
    raise ValueError(c)


def _big(s: str) -> Any:
    """An integer as PHP reads it: a float when it does not fit in 64 bits. Python also refuses more than 4300 digits."""
    v = int(s) if len(s) <= 25 else None
    return v if v is not None and -2 ** 63 <= v < 2 ** 63 else float(s)


def _jload(t: str) -> Any:
    """Strict JSON as PHP reads it: no NaN or Infinity. Raises ValueError."""
    return json.loads(t, parse_constant=_no_const, parse_int=_big)


def _nest(t: str) -> int:
    """How deep the lists and maps of a JSON text go, outside its strings."""
    if t.count("[") + t.count("{") <= _MAX_DEPTH:
        return t.count("[") + t.count("{")
    depth = top = 0
    in_str = esc = False
    for ch in t:
        if in_str:
            if esc:
                esc = False
            elif ch == "\\":
                esc = True
            elif ch == '"':
                in_str = False
        elif ch == '"':
            in_str = True
        elif ch in "[{":
            depth += 1
            top = max(top, depth)
        elif ch in "]}":
            depth -= 1
    return top


def _listlike(d: dict) -> bool:
    """True for a dict whose keys are 0, 1, ... in order, which PHP keeps as a list."""
    return all(str(k) == str(i) for i, k in enumerate(d))


def _jstr(s: str) -> str:
    return json.dumps(_u8(s), ensure_ascii=False)


def _is_num(x: Any) -> bool:
    return isinstance(x, (int, float, decimal.Decimal)) and not isinstance(x, bool)


def _u8(s: str) -> str:
    """The string with each lone surrogate replaced by U+FFFD, the counterpart of invalid UTF-8 in PHP."""
    if any("\ud800" <= ch <= "\udfff" for ch in s):
        return s.encode("utf-16", "surrogatepass").decode("utf-16", "replace")
    return s


def _iso(d: Any) -> str:
    """The date alone at midnight, so that a date column stays a date sequence. Otherwise the time, a fraction when there
    is one, and the offset, +00:00 for a naive datetime, as PHP gives every date-time a zone."""
    if isinstance(d, _dt.datetime):
        if (d.hour, d.minute, d.second, d.microsecond) == (0, 0, 0, 0):
            return d.date().isoformat()
        return d.isoformat() + ("+00:00" if d.utcoffset() is None else "")
    return d.isoformat()


def _val(v: Any) -> Any:
    """A value as JSON would carry it (spec 11): a date-time as ISO 8601 text, an enum case by its value, a Decimal as a
    number, a tuple as a list, a function as None, a dataclass, a mapping or any other object as the dict of its public
    attributes, and an object with none of these as its text. A chain of more than 8 conversions is None."""
    for _ in range(8):
        if v is None or isinstance(v, (str, bool, int, float, dict, list, decimal.Decimal)):
            return v
        if isinstance(v, (_dt.date, _dt.time)):
            return _iso(v)
        if isinstance(v, enum.Enum):
            v = v.value
        elif isinstance(v, tuple):
            return list(v)
        elif isinstance(v, (bytes, bytearray)):
            return bytes(v).decode("utf-8", "replace")
        elif hasattr(v, "model_dump"):
            v = v.model_dump()
        elif dataclasses.is_dataclass(v) and not isinstance(v, type):
            v = {f.name: getattr(v, f.name) for f in dataclasses.fields(v) if not f.name.startswith("_")}
        elif hasattr(v, "keys") and hasattr(v, "__getitem__"):
            v = {k: v[k] for k in v.keys()}
        elif callable(v):
            return None
        elif hasattr(v, "__dict__"):
            v = {k: x for k, x in vars(v).items() if not str(k).startswith("_")}
        else:
            return str(v)
    return v if v is None or isinstance(v, (str, bool, int, float, dict, list, decimal.Decimal)) else None


def _norm(v: Any, level: int, on: frozenset = frozenset()) -> Any:
    """A value with only JSON types left in it (spec 11). A list or map that sits 64 levels below the record, or
    deeper, is None, and so is a value where it repeats inside itself."""
    if v is None or isinstance(v, (str, bool, int, float)):
        return v
    if id(v) in on:
        return None
    on = on | {id(v)}
    v = _val(v)
    if isinstance(v, dict):
        return None if level >= _MAX_DEPTH else {k: _norm(x, level + 1, on) for k, x in v.items()}
    if isinstance(v, list):
        return None if level >= _MAX_DEPTH else [_norm(x, level + 1, on) for x in v]
    return v


def _num(x: Any, m: Any) -> Optional[str]:
    """A number as its shortest decimal text with no exponent. With a scale, that text times the scale in decimal,
    rounded half away from zero (spec 6.3). None when it is not finite, also after scaling."""
    if isinstance(x, decimal.Decimal):
        if not x.is_finite():
            return None
        t = format(x, "f")
        if "." in t:
            t = t.rstrip("0").rstrip(".")
        t = "0" if t in ("-0", "") else t
        return _scaled(t, _num(m, None)) if m else t
    if isinstance(x, float) and not math.isfinite(x):
        return None
    if m and isinstance(x * m, float) and not math.isfinite(x * m):
        return None
    if m:
        return _scaled(_num(x, None), _num(m, None))
    if isinstance(x, int):
        return str(x)
    if x == math.floor(x) and abs(x) < 1e15:
        return str(int(x))
    # shortest digits that read back as the same float, the same loop as the PHP library
    s = ""
    for p in range(17):
        s = "%.*e" % (p, x)
        if float(s) == x:
            break
    mt = re.fullmatch(r"(-?)([0-9]+)(?:\.([0-9]+))?e([+-]?[0-9]+)", s, re.I)
    if not mt:
        return s
    sign, i, f, e = mt.group(1), mt.group(2), mt.group(3) or "", int(mt.group(4))
    d = i + f
    pt = len(i) + e
    if pt <= 0:
        r = "0." + "0" * -pt + d
    elif pt >= len(d):
        r = d + "0" * (pt - len(d))
    else:
        r = d[:pt] + "." + d[pt:]
    return sign + (r.rstrip("0").rstrip(".") if "." in r else r)


def _scaled(x: str, m: str) -> str:
    """Decimal text x times integer text m, rounded half away from zero, in exact decimal arithmetic."""
    with decimal.localcontext() as ctx:
        ctx.prec = len(x) + len(m) + 4
        q = (decimal.Decimal(x) * decimal.Decimal(m)).quantize(decimal.Decimal(1), rounding=decimal.ROUND_HALF_UP)
    t = str(q)
    return "0" if t == "-0" else t


def _quote(s: str) -> str:
    """A literal JSON string in quotes, with | written as backslash u 0 0 7 c."""
    return _jstr(s).replace("|", _BAR)


def _name(n: str, hdr: bool = False) -> str:
    """A name as a column line writes it (spec 6.1). hdr also escapes a space, which ends a header key."""
    esc = _NAME_ESC_HDR if hdr else _NAME_ESC
    n = "".join(esc.get(ch, ch) for ch in n)
    return "\\u005b" + n[1:] if n.startswith("[") else n


def _unname(n: str) -> str:
    """A written name back to the name. A written name holds no raw backslash, so every \\uXXXX in it is an escape."""
    return _UNNAME.sub(lambda m: chr(int(m.group(1), 16)), n) if "\\u" in n else n


def _bad_code(c: str) -> bool:
    """A code that a cell, a column name or a header value could not carry as plain text."""
    return c == "" or bool(re.search(r'[|*:\s"]', c, re.ASCII)) or c[0] in "[{" or bool(_NUM.fullmatch(c)) or c in ("true", "false", "null")


def _days_from_civil(y: int, m: int, d: int) -> int:
    """Days since 1970-01-01 in the proleptic Gregorian calendar (days_from_civil by H. Hinnant, with floor division).
    A day past the end of a month runs on into the next one."""
    y -= m <= 2
    era = y // 400
    yoe = y - era * 400
    doy = (153 * (m + (-3 if m > 2 else 9)) + 2) // 5 + d - 1
    doe = yoe * 365 + yoe // 4 - yoe // 100 + doy
    return era * 146097 + doe - 719468


def _civil_from_days(z: int) -> str:
    z += 719468
    era = z // 146097
    doe = z - era * 146097
    yoe = (doe - doe // 1460 + doe // 36524 - doe // 146096) // 365
    doy = doe - (365 * yoe + yoe // 4 - yoe // 100)
    mp = (5 * doy + 2) // 153
    d = doy - (153 * mp + 2) // 5 + 1
    m = mp + (3 if mp < 10 else -9)
    return "%04d-%02d-%02d" % (yoe + era * 400 + (m <= 2), m, d)


def _day(x: Any) -> Optional[int]:
    """The day number of a valid ISO date text, else None."""
    if not isinstance(x, str) or not _DATE.fullmatch(x):
        return None
    y, m, d = int(x[:4]), int(x[5:7]), int(x[8:])
    if not 1 <= m <= 12 or d < 1:
        return None
    n = _days_from_civil(y, m, d)
    return n if _civil_from_days(n) == x else None


def _days(v: list) -> Optional[str]:
    """<first>+<step>d when there are 3 or more values, all valid ISO dates, with the same positive day step. Otherwise None."""
    t = []
    for x in v:
        n = _day(x)
        if n is None:
            return None
        t.append(n)
    step = (t[1] if len(t) > 1 else 0) - t[0]
    for i in range(1, len(t)):
        if t[i] - t[i - 1] != step:
            return None
    return f"{v[0]}+{step}d" if len(t) >= 3 and step > 0 else None


def _runs(cells: list) -> str:
    """A dense column as one line of items. A run of equal cells is cell*K."""
    o, i, n = [], 0, len(cells)
    while i < n:
        j = i + 1
        while j < n and cells[j] == cells[i]:
            j += 1
        o.append(cells[i] + ("*" + str(j - i) if j - i > 1 else ""))
        i = j
    return "|".join(o)


def _items(items: str, n: int) -> list:
    """The items of a dense column line as (cell text, K, 0) runs cut at N records, or one (None, first day, step) for a
    date sequence. Raises ToklexError on a date sequence that starts at an invalid date or runs past 9999-12-31."""
    m = _SEQ.fullmatch(items)
    if m:
        first = items[:10]
        d = _day(first)
        if d is None:
            raise ToklexError(f"Toklex: a date sequence starts at the invalid date {first}")
        step = int(m.group(4)) if len(m.group(4)) <= 7 else _LAST_DAY
        if n > 1 and d + (n - 1) * step > _LAST_DAY:
            raise ToklexError("Toklex: a date sequence runs past 9999-12-31")
        return [(None, d, step)]
    o: list = []
    for it in items.split("|"):
        if n <= 0:
            break
        r = re.fullmatch(r"(.*)\*([0-9]+)", it, re.S)
        k = min(int(r.group(2)) if len(r.group(2)) <= 9 else n, n) if r else 1
        o.append((r.group(1) if r else it, k, 0))
        n -= k
    return o


def _extra(t: str) -> int:
    """The cost a JSON array cell adds to its cell, read from its text: 1 per comma and 8 per [ or {."""
    return t.count(",") + 8 * (t.count("[") + t.count("{")) if t.startswith("[") else 0


def _same_list(d: Any) -> Any:
    """A list or the values of a dict, as PHP's in_array sees an array."""
    return list(d.values()) if isinstance(d, dict) else list(d or [])


class Toklex:
    """One schema. Load it with Toklex.from_file()."""

    def __init__(self, s: dict, v: str):
        self._s = s
        self._v = v
        keys = s.get("keys") or {}
        coded = s.get("style", "plain") == "coded"
        self._kf: dict = {f: c for c, f in keys.items()} if coded else {}  # full name -> code, coded only
        self._vf: dict = {f: {full: c for c, full in m.items()} for f, m in (s.get("values") or {}).items()} if coded else {}
        self._dk: dict = dict(keys) if coded else {}  # code -> full name for decode, coded only
        self._dv: dict = dict(s.get("values") or {}) if coded else {}
        # scale by the name the encoder writes: the code when coded, the full name when plain
        self._sc: dict = {(c if coded else keys.get(c, c)): m for c, m in (s.get("scale") or {}).items()}

    @classmethod
    def from_file(cls, path: str) -> "Toklex":
        """Raises ToklexError when the file is unreadable, not JSON or breaks a load rule."""
        try:
            raw = open(path, "rb").read()
        except OSError:
            raise ToklexError(f"Toklex schema: cannot read {path}") from None
        try:
            s = json.loads(raw.decode("utf-8"), parse_constant=lambda c: float(c))
        except ValueError:
            s = None
        if not isinstance(s, dict):
            raise ToklexError(f"Toklex schema: {path} is not a JSON object")
        cls._check(s)
        return cls(cls._norm_schema(s), hashlib.sha256(raw.replace(b"\r\n", b"\n")).hexdigest()[:4])

    @classmethod
    def from_dict(cls, s: dict, version: str) -> "Toklex":
        """A schema already in memory. The version is the caller's, because it is a hash of the file bytes."""
        cls._check(s)
        return cls(cls._norm_schema(s), version)

    @staticmethod
    def _norm_schema(s: dict) -> dict:
        s = dict(s)
        for k in ("keys", "values", "scale", "instructions"):
            if isinstance(s.get(k), list):  # an empty JSON list is an empty object to PHP, a list of instructions has index names
                s[k] = {str(i): x for i, x in enumerate(s[k])}
        for k in ("dense", "auto"):
            if k in s:
                s[k] = _same_list(s[k])
        return s

    def style(self) -> str:
        return self._s.get("style", "plain")

    def version(self) -> str:
        return self._v

    def instructions_text(self) -> str:
        """The instructions as the model reads them: one "name: text" per block, the text verbatim. It goes into the MCP instructions field."""
        return "\n".join(f"{n}: {b}" for n, b in (self._s.get("instructions") or {}).items())

    def schema_text(self) -> str:
        s = self._s
        out = [f"Toklex schema {s['id']}@{self._v}", _PRIMER]
        if s.get("keys"):
            out.append("Keys: " + ", ".join(f"{c}={f}" for c, f in s["keys"].items()))
        if s.get("values"):
            out.append("Value codes: " + "; ".join(f"{f}: " + " ".join(f"{c}={full}" for c, full in m.items()) for f, m in s["values"].items()))
        if s.get("scale"):
            first = next(iter(s["scale"].values()))
            ex = ("%.12f" % (743 / first)).rstrip("0").rstrip(".")
            out.append("Scaled: " + ", ".join(f"{c} x{_num(m, None)}" for c, m in s["scale"].items()) + f" (743 means {ex})")
        if s.get("dense"):
            out.append("Dense tools: " + ", ".join(s["dense"]))
        if s.get("instructions"):
            out.append("Instructions (they apply to every response marked i:+):")
            out.extend(f"{n}: {b}" for n, b in s["instructions"].items())
        return "\n".join(out)

    def with_tools(self, tools: list, warn: Optional[Callable[[str], Any]] = None) -> list:
        """Adds toklex_mode to every tool and removes outputSchema. Coded style also adds the contract line and appends the
        two Toklex tools. warn gets each warning as one line, logging.getLogger("toklex").warning when it is None."""
        warn = warn or _log.warning
        plain = self.style() == "plain"
        contract = f"Toklex reply ~{self._s['id']}@{self._v}. If this version is not in context, call toklex_schema first. Never guess a code."
        out = []
        for t in tools:
            t = dict(_val(t))
            name = "" if t.get("name") is None else t["name"]
            if not plain:
                d = t["description"] + "\n\n" + contract if t.get("description") is not None else contract
                if len(d) > 2048:
                    warn(f"Toklex: description of tool '{name}' is over 2048 characters, a client may cut the contract line")
                t["description"] = d
            # a tool with an outputSchema must return structuredContent, and a Toklex reply is one text block (spec 9)
            if "outputSchema" in t:
                del t["outputSchema"]
                warn(f"Toklex: tool '{name}' loses its outputSchema, a Toklex reply carries no structuredContent")
            inp = dict(_val({"type": "object"} if t.get("inputSchema") is None else t["inputSchema"]))
            props = dict(_val(inp.get("properties") or {}))
            props.setdefault("toklex_mode", {"type": "string", "enum": list(_MODES), "description": "Reply format: off, safe or dense."})
            inp["properties"] = props
            t["inputSchema"] = inp
            out.append(t)
        if plain:
            return out
        ro = lambda title: {"title": title, "readOnlyHint": True, "openWorldHint": False}  # noqa: E731
        out.append({
            "name": SCHEMA_TOOL, "title": "Toklex schema",
            "description": "Returns the schema (field names, value codes, number scales and instructions) needed to read the Toklex responses of this server. Call it before reading a response whose ~<id>@<version> you do not have in context.",
            "inputSchema": {"type": "object", "properties": {}}, "annotations": ro("Toklex schema"),
        })
        out.append({
            "name": CALIBRATE_TOOL, "title": "Toklex calibrate",
            "description": "Finds out which Toklex mode you read reliably. Call it without arguments to get a small dataset with questions. Call it again with answers to get the recommended mode.",
            "inputSchema": {"type": "object", "properties": {"answers": {"type": "object", "description": "Your answers, as an object that maps a question id to the answer."}}},
            "annotations": ro("Toklex calibrate"),
        })
        return out

    def mode(self, tool: str, args: dict) -> str:
        """Mode of one call. Removes toklex_mode from args, so the tool never sees it."""
        m = args.pop("toklex_mode", None)
        return m if isinstance(m, str) and m in _MODES else self._default_mode(tool)

    def encode(self, tool: str, rows: list, meta: Optional[dict] = None, mode: Optional[str] = None, instructions: bool = True) -> str:
        """mode None means the tool's default. instructions False leaves the instruction text out of this reply (spec 5.1).
        Raises ToklexError on a reserved meta key or a record that is not a dict or an object."""
        meta = {k: _norm(v, 1) for k, v in (meta or {}).items()}
        mode = mode if mode in (*_MODES, "auto") else self._default_mode(tool)
        for k in meta:
            c = str(self._kf.get(k, _u8(str(k))))
            if c in (("error",) if self.style() == "plain" else ("i", "e")):
                raise ToklexError(f"Toklex: meta key {c} is reserved")
        rows = [_norm(r, 0) for r in (rows.values() if isinstance(rows, dict) else rows)]
        for i, r in enumerate(rows):
            if isinstance(r, list):
                rows[i] = {str(j): x for j, x in enumerate(r)}
            elif not isinstance(r, dict):
                raise ToklexError(f"Toklex: record {i} is {type(r).__name__}, not a record. Pass a list of records, such as [row] for one record.")
        if mode == "off":
            return self._off({"meta": meta, "rows": rows}, instructions)
        out = [self._header(meta, instructions)]
        if not rows:
            return out[0]
        flat = [self._flat(r) for r in rows]
        cols: dict = {}
        for f in flat:
            for p, (c, _) in f.items():
                cols.setdefault(p, c)
        col = lambda p: [f[p][1] if p in f else None for f in flat]  # noqa: E731
        if mode == "auto":
            # dense pays off on a long series of dates and costs more than safe on a short or wide list (spec 12.2)
            mode = "dense" if len(flat) >= _AUTO_MIN and any(_days(col(p)) is not None for p in cols) else "safe"
        if mode == "dense":
            out.append(f"[{len(flat)}]" + (" " + _DENSE_LINE if self.style() == "plain" else ""))
            for p, c in cols.items():
                v = col(p)
                out.append(self._label(p, c) + ":" + (_days(v) or _runs([self._cell(x, c) for x in v])))
            return "\n".join(out)
        out.append("|".join(self._label(p, c) for p, c in cols.items()))
        for f in flat:
            line = "|".join(self._cell(f[p][1], c) if p in f else "" for p, c in cols.items()).rstrip("|")
            out.append(line or "|")
        return "\n".join(out)

    def error(self, code_or_text: str, mode: Optional[str] = None, instructions: bool = True) -> str:
        """code_or_text is a code from values.e or its full message. An error has no records, so only off differs from a header line."""
        e = (self._s.get("values") or {}).get("e") or {}
        code = code_or_text if code_or_text in e else next((c for c, t in e.items() if t == code_or_text), None)
        text = code_or_text if code is None else e[code]
        if mode == "off":
            return self._off({"error": text}, instructions)
        if self.style() == "plain":
            return self._header({}, instructions) + " error:" + self._cell(text, "error", True)
        return self._header({}, instructions) + " e:" + (_quote(code_or_text) if code is None else code)

    def decode(self, text: str) -> dict:
        """Reads a reply back with full key names and values. Raises ToklexError on a reply it refuses (spec 1)."""
        if text.startswith("{"):
            j = None
            if _nest(text) < 512:  # PHP reads JSON nested at most 511 levels
                try:
                    j = _jload(text)
                except ValueError:
                    pass
            if not (isinstance(j, dict) and isinstance(j.get("schema"), str) and "@" in j["schema"]):
                raise ToklexError("Toklex: the off reply is not a JSON object with a schema")
            sid, ver = j["schema"].split("@", 1)
            meta = {("error" if self.style() == "plain" else "e"): j["error"]} if "error" in j else (j.get("meta") or {})
            return {"schema": sid, "version": ver, "instructions": "instructions" in j, "meta": meta, "rows": j.get("rows") or []}
        plain = self.style() == "plain"
        # instruction lines start with their name, the header with ~, so a reply that left them out starts with the header
        k = len(self._s["instructions"]) if self._in_reply() and not text.startswith("~") else 0
        lines = text.split("\n")[k:]
        strip = (lambda c: _LABEL.sub("", c)) if plain else (lambda c: c)
        h = re.match(r"~([^@ ]+)@([^\t\n\x0b\f\r ]+)", lines[0]) if lines else None
        if not h:
            raise ToklexError("Toklex: the reply has no header line")
        ins = k > 0
        meta: dict = {}
        for m in re.finditer(r' ([^\t\n\x0b\f\r :]+):("(?:[^"\\]|\\.)*"|[^\t\n\x0b\f\r ]*)', lines[0]):
            c, raw = m.group(1), m.group(2)
            if c == "i" and not plain:
                ins = raw == "+"
            else:
                c = _unname(strip(c))
                meta[self._dk.get(c, c)] = self._read(raw, c)
        rows: list = []
        if len(lines) > 1:
            # the work a reply asks for is counted as it is spent, so a short forged reply is refused before it costs much (spec 11)
            left = [_MAX_CELLS]

            def spend(cost: int) -> None:
                left[0] -= cost
                if left[0] < 0:
                    raise ToklexError(f"Toklex: a reply of more than {_MAX_CELLS} cells is refused")
            n = re.fullmatch(r"\[([0-9]+)\]( .*)?", lines[1])
            if n:
                cnt = int(n.group(1)) if len(n.group(1)) <= 9 else _MAX_RECORDS + 1
                if cnt > _MAX_RECORDS:
                    raise ToklexError(f"Toklex: a reply of more than {_MAX_RECORDS} records is refused")
                if cnt * (len(lines) - 2) > _MAX_CELLS:
                    spend(_MAX_CELLS + 1)
                rows = [{} for _ in range(cnt)]
                for line in lines[2:]:
                    name, _, items = line.partition(":")
                    keys, code, cost = self._column(strip(name))
                    spend(cnt * cost)
                    r = 0
                    for t, k, step in _items(items, cnt):
                        if t is None:  # a date sequence from day k
                            for r in range(r, cnt):
                                self._put(rows[r], keys, _civil_from_days(k + r * step))
                            r = cnt
                            break
                        spend(k * _extra(t))
                        v = self._read(t, code)
                        for i in range(k):  # rows never share a list
                            self._put(rows[r + i], keys, copy.deepcopy(v) if i and isinstance(v, list) else v)
                        r += k
                    for r in range(r, cnt):
                        self._put(rows[r], keys, None)
            else:
                cols = [] if lines[1] == "" else [self._column(strip(c)) for c in lines[1].split("|")]
                if len(lines) - 2 > _MAX_RECORDS:
                    raise ToklexError(f"Toklex: a reply of more than {_MAX_RECORDS} records is refused")
                for line in lines[2:]:
                    cells = line.split("|")
                    row: dict = {}
                    for i, (keys, code, cost) in enumerate(cols):
                        cell = cells[i] if i < len(cells) else ""
                        spend(cost + _extra(cell))
                        self._put(row, keys, self._read(cell, code))
                    rows.append(row)
        return {"schema": h.group(1), "version": h.group(2), "instructions": ins, "meta": meta, "rows": rows}

    def _column(self, name: str) -> tuple:
        """A written column name as (the keys of its nested path, the code of its last name, the cost of one of its cells).
        A cell costs 1 and each name before the last adds 8. Raises ToklexError on a path of more than 64 names."""
        if name.count(".") >= _MAX_DEPTH:
            raise ToklexError(f"Toklex: a column path of more than {_MAX_DEPTH} names is refused")
        segs = [_unname(s) for s in name.split(".")]
        return [self._dk.get(s, s) for s in segs], segs[-1], 8 * len(segs) - 7

    @staticmethod
    def _put(row: dict, keys: list, val: Any) -> None:
        """One cell into its nested place. A scalar parent gives way to a nested value, but a null never replaces it,
        and a null parent cell never wipes the object its nested columns built."""
        cont, key = None, None
        for k in keys:
            cur = row if cont is None else cont[key]
            if isinstance(cur, list):  # a list is an array to PHP, so a nested column adds its key to it
                cur = cont[key] = {str(i): x for i, x in enumerate(cur)}
            elif not isinstance(cur, dict):
                if val is None and cur is not None:
                    return
                cur = {}
                cont[key] = cur
            cur.setdefault(k, None)
            cont, key = cur, k
        if val is not None or not isinstance(cont[key], (dict, list)):
            cont[key] = val

    def calibrate(self, args: Any) -> str:
        """Takes the tool arguments {"answers": ...} or the bare answers map. Answers given as a JSON string are decoded.
        Answers that hold no question id count as absent and give the dataset, so a malformed call never grades as off."""
        answers = args.get("answers") if isinstance(args, dict) and args.get("answers") is not None else args
        if isinstance(answers, str):
            try:
                answers = _jload(answers)
            except ValueError:
                answers = None
        cal = Toklex(_CAL_SCHEMA, "0000")
        if not isinstance(answers, dict) or not any(q in answers for q in _CAL_QUESTIONS):
            q = [f"{qid} ({block} block): {text}" for qid, (block, text, _) in _CAL_QUESTIONS.items()]
            return "\n".join(["Toklex calibration",
                "The two blocks below hold the same 8 records, once as a safe table and once as a dense table. The legend covers this test only. Answer the six questions from the text on this page.",
                "", "Legend", "\n".join(cal.schema_text().split("\n")[2:]), "", "How to read", _CAL_HOWTO,
                "", "Safe block", cal.encode("cal", _CAL_ROWS, {}, "safe"), "", "Dense block", cal.encode("cal", _CAL_ROWS, {}, "dense"),
                "", "Questions", *q, "",
                'Then call toklex_calibrate again with {"answers":{"s1":"<answer>","s2":"<answer>","s3":"<answer>","d1":"<answer>","d2":"<answer>","d3":"<answer>"}}. Write each answer as short text or a number.'])

        def norm(x: Any) -> Optional[str]:
            if isinstance(x, str):
                return x.strip(" \t\n\r\0\x0b")
            return _num(x, None) if _is_num(x) else None
        res = {qid: answers.get(qid) is not None and norm(answers[qid]) == key for qid, (_, _, key) in _CAL_QUESTIONS.items()}
        every = lambda p: all(ok for qid, ok in res.items() if qid[0] == p)  # noqa: E731
        return json.dumps({"recommended": "dense" if every("d") else "safe" if every("s") else "off", "results": res}, separators=(",", ":"))

    def resources(self) -> list:
        if self.style() == "plain":
            return [{"uri": "toklex://modes", "name": "Toklex modes", "description": "The three reply modes and how to pick one.", "mimeType": "text/plain"}]
        return [
            {"uri": "toklex://modes", "name": "Toklex modes", "description": "The three reply modes, how to pick one and how to calibrate.", "mimeType": "text/plain"},
            {"uri": "toklex://schema", "name": "Toklex schema", "description": "The schema text that toklex_schema returns.", "mimeType": "text/plain"},
        ]

    def read_resource(self, uri: str) -> Optional[str]:
        plain = self.style() == "plain"
        if uri == "toklex://modes":
            return _PLAIN_MODES_TEXT if plain else _MODES_TEXT
        if uri == "toklex://schema":
            return None if plain else self.schema_text()
        return None

    def _default_mode(self, tool: str) -> str:
        """dense, auto or safe. auto picks dense or safe when the records are known."""
        return "dense" if tool in self._s.get("dense", []) else "auto" if tool in self._s.get("auto", []) else "safe"

    def _in_reply(self) -> bool:
        """True when the instruction lines head the reply: plain style with instructions_at reply."""
        return self.style() == "plain" and self._s.get("instructions_at", "reply") == "reply" and bool(self._s.get("instructions"))

    def _header(self, meta: dict, instructions: bool = True) -> str:
        ins = self._s.get("instructions") or {}
        h = ""
        for n, b in (ins.items() if self._in_reply() and instructions else ()):
            h += f"{n}: " + (_quote(b) if re.search(r"[\r\n]", b) else b) + "\n"
        h += f"~{self._s['id']}@{self._v}" + (" i:+" if ins and self.style() == "coded" else "")
        for k, v in meta.items():
            c = str(self._kf.get(k, _u8(str(k))))
            t = _quote(self._js(v, True, c)) if isinstance(v, (dict, list)) else self._cell(v, c, True)
            if t != "":
                h += " " + self._label(_name(c, True), c) + ":" + t
        return h

    def _label(self, path: str, key: str) -> str:
        """The written path of a column or meta key, plus (÷K) when plain and key is scaled."""
        m = self._sc.get(key) if self.style() == "plain" else None
        return f"{path}(÷{_num(m, None)})" if m else path

    def _off(self, tail: dict, instructions: bool = True) -> str:
        ins = self._s.get("instructions") or {}
        o: dict = {"toklex": "off", "schema": f"{self._s['id']}@{self._v}"}
        if ins and instructions and (self.style() == "coded" or self._in_reply()):
            o["instructions"] = ins
        o.update(tail)
        body = []
        for k, v in o.items():
            if k == "meta":
                t = self._js(v, False, obj=True)
            elif k == "rows":
                t = "[" + ",".join(self._js(r, False, obj=True) for r in v) + "]"
            else:
                t = self._js(v, False, obj=k == "instructions")
            body.append(_jstr(k) + ":" + t)
        return "{" + ",".join(body) + "}"

    def _flat(self, row: dict, path: str = "") -> dict:
        """One record as {dotted column: (code of the last segment, value)}. A non-empty dict expands, anything else is a cell."""
        out: dict = {}
        for k, v in row.items():
            c = str(self._kf.get(k, _u8(str(k))))
            p = _name(c) if path == "" else path + "." + _name(c)
            if isinstance(v, dict) and v and not _listlike(v):
                for q, x in self._flat(v, p).items():  # a nested column keeps the cell it has, as PHP's += does
                    out.setdefault(q, x)
            else:
                out[p] = (c, v)  # two names with one code: the later cell wins and the column keeps its place
        return out

    def _cell(self, v: Any, c: str, hdr: bool = False) -> str:
        """One cell. hdr adds the header's two quote triggers, any whitespace and a double quote."""
        if v is None:
            return ""
        if isinstance(v, bool):
            return "true" if v else "false"
        scaled = c in self._sc
        # a database driver hands numbers over as strings, so a plain decimal in a scaled field is that number, scaled on its digits (spec 6.3)
        if scaled and isinstance(v, str) and _DEC.fullmatch(v):
            return _scaled(v, _num(self._sc[c], None))
        if _is_num(v):
            return _num(v, self._sc.get(c)) or ""
        if isinstance(v, (dict, list)):
            return self._js(v, True, c).replace("|", _BAR)
        v = _u8(str(v))
        if v in self._vf.get(c, {}):
            return str(self._vf[c][v])
        quote = (scaled or v == "" or re.search(r'^["\[{]|[|*\n\r]', v) or v in self._dv.get(c, {})
                 or _NUM.fullmatch(v) or v in ("true", "false", "null") or _SEQ_SHAPE.fullmatch(v)
                 or (hdr and re.search(r'[\t\n\x0b\f\r "]', v)))
        return _quote(v) if quote else v

    def _js(self, x: Any, coded: bool, k: Optional[str] = None, obj: bool = False) -> str:
        """JSON text. coded shortens keys and codes strings and numbers by the key they sit under. Lists pass their key down.
        A dict with the keys 0, 1, ... in order, the empty one too, is a list as that PHP array is, unless obj asks for an object."""
        if isinstance(x, dict) and not obj and _listlike(x):
            x = list(x.values())
        if isinstance(x, list):
            return "[" + ",".join(self._js(e, coded, k) for e in x) + "]"
        if isinstance(x, dict):
            o = []
            for n, e in x.items():
                c = str(self._kf.get(n, n) if coded else n)
                # plain scales no member of an object (spec 6.3)
                o.append(_jstr(c) + ":" + self._js(e, coded, c if self.style() == "coded" else ""))
            return "{" + ",".join(o) + "}"
        if x is None or isinstance(x, bool):
            return json.dumps(x)
        if _is_num(x):
            return _num(x, self._sc.get(k) if coded else None) or "null"
        x = _u8(str(x))
        if coded and x in self._vf.get(k, {}):
            x = self._vf[k][x]
        return _jstr(x)

    def _read(self, t: str, c: str) -> Any:
        """One header value or cell back to a Python value, by the rules of spec section 8."""
        if t == "":
            return None
        if t[0] == '"':
            try:
                j = _jload(t)
                if isinstance(j, str):
                    return j
            except ValueError:
                pass
        if t in self._dv.get(c, {}):
            return self._dv[c][t]
        if _NUM.fullmatch(t):
            return self._dec(_jload(t), c)
        if t in ("true", "false"):
            return t == "true"
        if t[0] == "[" and _nest(t) <= _MAX_DEPTH:  # a deeper JSON array cell stays text, as in PHP
            try:
                j = _jload(t)
                if isinstance(j, list):
                    return self._dec(j, c)
            except ValueError:
                pass
        return t

    def _dec(self, x: Any, k: Optional[str]) -> Any:
        """Full keys, full values and descaled numbers inside decoded JSON."""
        if isinstance(x, dict) and _listlike(x):  # PHP reads {"0":..,"1":..} as a list
            x = list(x.values())
        if isinstance(x, list):
            return [self._dec(e, k) for e in x]
        if isinstance(x, dict):
            return {self._dk.get(str(n), str(n)): self._dec(e, str(n) if self.style() == "coded" else "") for n, e in x.items()}
        if isinstance(x, str):
            return self._dv.get(k, {}).get(x, x)
        m = self._sc.get(k) if _is_num(x) else None
        if not m:
            return x
        # as PHP divides: an int when it divides evenly, otherwise both numbers as floats
        return x // m if isinstance(x, int) and isinstance(m, int) and x % m == 0 else float(x) / m

    @staticmethod
    def _check(s: dict) -> None:
        def fail(why: str) -> None:
            raise ToklexError(f"Toklex schema: {why}")
        hint = ' (add "style": "coded" to keep v0.1 names)'
        t = s.get("toklex")
        if not (type(t) is int and t == 1):
            fail("toklex must be 1")
        if not (isinstance(s.get("id"), str) and re.fullmatch(r"[a-z][a-z0-9]{0,7}", s["id"])):
            fail("id must match [a-z][a-z0-9]{0,7}")
        style = s.get("style", "plain")
        plain = style != "coded"
        if style not in ("plain", "coded"):
            fail("style must be plain or coded")
        if s.get("instructions_at", "reply") not in ("reply", "server"):
            fail("instructions_at must be reply or server")
        if not plain and "instructions_at" in s:
            fail("instructions_at needs style plain")

        def obj(name: str) -> dict:
            v = s.get(name, {})
            if isinstance(v, list) and not v:
                return {}
            if not isinstance(v, dict):
                fail(f"{name} must be an object")
            return v
        keys = obj("keys")
        for c, full in keys.items():
            if not isinstance(full, str):
                fail(f"keys.{c} must be a string")
            if c in ("i", "e"):
                fail(f"key code {c} is reserved")
            if _bad_code(c):
                fail(f"key code {c} is not a plain token")
            if "." in c:
                fail(f"key code {c} holds a dot, which marks a nested column")
            if plain and _bad_code(full):
                fail(f"name {full} of keys.{c} is not a plain token{hint}")
            if plain and "." in full:
                fail(f"name {full} of keys.{c} holds a dot, which marks a nested column{hint}")
            if plain and "(÷" in full:
                fail(f"name {full} of keys.{c} holds (÷, which a reader takes for a scale label{hint}")
        if len(set(keys.values())) != len(keys):
            fail("a full name occurs twice in keys")
        for f, m in obj("values").items():
            if isinstance(m, list) and not m:
                continue
            if not isinstance(m, dict):
                fail(f"values.{f} must be an object")
            for c, full in m.items():
                if not isinstance(full, str):
                    fail(f"values.{f}.{c} must be a string")
                if _bad_code(c):
                    fail(f"value code {c} in values.{f} is not a plain token")
                if _SEQ_SHAPE.fullmatch(c):
                    fail(f"value code {c} in values.{f} has the shape of a date sequence")
            if len(set(m.values())) != len(m):
                fail(f"a full value occurs twice in values.{f}")
        for f, m in obj("scale").items():
            if not ((type(m) is int) or (type(m) is float and math.isfinite(m) and m == math.floor(m))):
                fail(f"scale.{f} must be an integer")
            if m < 10:
                fail(f"scale.{f} must be at least 10")
        ins = s.get("instructions", {})
        if isinstance(ins, list):
            ins = {str(i): x for i, x in enumerate(ins)}
        if not isinstance(ins, dict) or not all(isinstance(b, str) for b in ins.values()):
            fail("instructions must map names to strings")
        for name in ins if plain else ():
            if _bad_code(name):
                fail(f"instruction name {name} is not a plain token{hint}")
            if name.startswith("~"):
                fail(f"instruction name {name} starts with ~, which marks the header")
        for m in ("dense", "auto"):
            v = s.get(m, [])
            if not isinstance(v, (list, dict)) or not all(isinstance(x, str) for x in _same_list(v)):
                fail(f"{m} must be a list of tool names")
        both = [t for t in _same_list(s.get("dense")) if t in _same_list(s.get("auto"))]
        if both:
            fail(f"tool {both[0]} is in both dense and auto")
