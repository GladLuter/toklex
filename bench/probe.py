# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# Reading probe. build: writes out/probe/{safe,dense,off}.txt and key.json from out/enc and out/data.
# grade: python probe.py grade <safe|dense|off> <answers.json>   (answers.json = a list of {"1": ..., "10": ...} runs,
# or {"<variant>": [runs]}). Prints right/wrong per question per run and the totals.
import json, os, sys

H = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(H, "out")
PROBE = os.path.join(OUT, "probe")
read = lambda *p: open(os.path.join(OUT, *p), encoding="utf-8").read()
rows = lambda tool: json.load(open(os.path.join(OUT, "data", tool + ".json"), encoding="utf-8"))["rows"]
# which encoded files each variant shows, as (tool, extension)
VARIANTS = {"safe": ("toklex", "toklex"), "dense": ("toklex", "dense"), "off": ("off", "off")}
DATE_Q = ["6", "7", "8", "9"]  # dense: rain_m on a date (6, 7), first HIGH date (8), date of minimum (9)


def questions():
    fs, hist = rows("find_stations"), rows("station_history")
    one = lambda v: (lambda s: s if len(s) == 1 else exit(f"ambiguous key: {s}"))(v)
    rain_on = lambda d: next(r["rain_m"] for r in hist if r["date"] == d)
    hail = [r for r in fs if r["hail_m"] is not None]
    return [
        (f"What is the wind level (full word) of the station named '{fs[20]['name']}'?", fs[20]["wind_level"]),
        ("In find_stations, how many stations have wind level HIGH?", sum(r["wind_level"] == "HIGH" for r in fs)),
        (f"What is the snow_m of station id {fs[17]['station_id']}, as a decimal like 0.0123?", fs[17]["snow_m"]),
        ("In find_stations, which station id has the largest hail_m?", one([r["station_id"] for r in hail if r["hail_m"] == max(x["hail_m"] for x in hail)])[0]),
        ("In find_stations, how many stations have snow_m greater than hail_m?", sum(r["snow_m"] is not None and r["snow_m"] > r["hail_m"] for r in fs)),
        ("In station_history, what was rain_m on 2026-08-15, as a decimal like 0.0123?", rain_on("2026-08-15")),
        ("In station_history, what was rain_m on 2026-09-03, as a decimal like 0.0123?", rain_on("2026-09-03")),
        ("In station_history, what is the first date (YYYY-MM-DD) on which the wind level was HIGH?", next(r["date"] for r in hist if r["wind_level"] == "HIGH")),
        ("In station_history, on which date (YYYY-MM-DD) was rain_m lowest?", one([r["date"] for r in hist if r["rain_m"] == min(x["rain_m"] for x in hist)])[0]),
        ("In station_history, on how many days was the wind level LOW?", sum(r["wind_level"] == "LOW" for r in hist)),
    ]


def build():
    os.makedirs(PROBE, exist_ok=True)
    qa = questions()
    for v, (ext_f, ext_h) in VARIANTS.items():
        parts = ["You are reading MCP tool results. Answer ONLY from the data below, by reading it yourself."]
        if v != "off":
            parts += ["LEGEND:", read("enc", "schema.txt")]
        parts += [f"=== find_stations result\n{read('enc', f'find_stations.{ext_f}.txt')}",
                  f"=== station_history result\n{read('enc', f'station_history.{ext_h}.txt')}",
                  "QUESTIONS:\n" + "\n".join(f"{n}. {q}" for n, (q, _) in enumerate(qa, 1))]
        parts.append('Reply with JSON only: {"1": "...", "2": "...", ..., "10": "..."}')
        with open(os.path.join(PROBE, v + ".txt"), "w", encoding="utf-8", newline="\n") as f:
            f.write("\n\n".join(parts) + "\n")
    with open(os.path.join(PROBE, "key.json"), "w", encoding="utf-8") as f:
        json.dump({str(n): a for n, (_, a) in enumerate(qa, 1)}, f, indent=1)


def same(got, want):
    if isinstance(want, (int, float)):
        try: return round(float(str(got).strip()), 4) == round(want, 4)
        except ValueError: return False
    return str(got).replace(" ", "").upper() == str(want).replace(" ", "").upper()


def grade(variant, path):
    key = json.load(open(os.path.join(PROBE, "key.json"), encoding="utf-8"))
    runs = json.load(open(path, encoding="utf-8"))
    if isinstance(runs, dict): runs = runs[variant]
    ok = [[same(r.get(q, ""), key[q]) for q in key] for r in runs]
    for i, o in enumerate(ok, 1):
        print(f"run {i}: {sum(o)}/{len(key)}  " + " ".join(f"{q}{'+' if g else '-'}" for q, g in zip(key, o)))
    per = {q: sum(o[j] for o in ok) for j, q in enumerate(key)}
    print("per question (right of %d): " % len(runs) + " ".join(f"{q}={n}" for q, n in per.items()))
    print(f"TOTAL {variant}: {sum(per.values())}/{len(key) * len(runs)}")
    if variant == "dense":
        print("dense date questions: " + " ".join(f"{q}={per[q]}/{len(runs)}" for q in DATE_Q))


if __name__ == "__main__":
    a = sys.argv[1:]
    grade(a[1], a[2]) if a[:1] == ["grade"] and len(a) == 3 else build() if a[:1] == ["build"] else exit("usage: probe.py build | grade <variant> <answers.json>")
