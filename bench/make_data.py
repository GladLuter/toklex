# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# Synthetic weather station data for the offline bench. Seed 7.
import datetime as dt, json, math, os, random

random.seed(7)
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "out", "data")
DATE = "2026-09-30"
TERRAINS = ["coast", "mountain", "plain", "valley"]
NETS = ["net_a", "net_b", "net_c"]
RAIN = {"valley": (.005, .02), "plain": (.03, .08), "coast": (.06, .14), "mountain": (.12, .30)}
GET = ["station_id", "name", "terrain", "network", "wind_level", "rain_m", "visibility_km", "snow_m", "hail_m"]
FIND = ["station_id", "name", "wind_level", "snow_m", "hail_m"]


def level(r):  # only the levels the schema has codes for
    return "LOW" if r < .04 else "MEDIUM" if r < .10 else "HIGH"


def split(r):
    lean = .55 + random.random() * 1.3
    d = r / math.sqrt(1 + lean * lean)
    return round(lean * d, 4), round(d, 4)


def station(i):
    c = TERRAINS[i % 4]; r = round(random.uniform(*RAIN[c]), 4); s, h = split(r); gap = (i + 1) % 19 == 0
    return {"station_id": f"st-{i + 1:03d}", "name": f"Station A{i + 1:02d}", "terrain": c, "network": NETS[i % 3],
            "wind_level": level(r), "rain_m": r, "visibility_km": round(random.random() * 100, 1),
            "snow_m": None if gap else s, "hail_m": None if gap else h}


def history(n=90):  # oldest first; snow and hail only on the newest row
    r, rains, end = .1, [], dt.date.fromisoformat(DATE)
    for _ in range(n):
        rains.append(round(r, 4)); r = min(.3, max(.005, r + (random.random() - .5) * .012))
    rains.reverse()
    return [{"date": str(end - dt.timedelta(days=n - 1 - k)), "wind_level": level(x), "rain_m": x,
             "snow_m": (split(x) if k == n - 1 else (None, None))[0],
             "hail_m": (split(x) if k == n - 1 else (None, None))[1]} for k, x in enumerate(rains)]


STATIONS = [station(i) for i in range(40)]
WIND_COUNTS = [{"network": p, "date": DATE, "low": a, "medium": b, "high": c}
               for p, a, b, c in [("net_a", 100, 200, 300), ("net_b", 110, 220, 330), ("net_c", 120, 240, 360)]]
DATA = {
    "get_station": [{k: STATIONS[0][k] for k in GET}],
    "find_stations": [{k: s[k] for k in FIND} for s in STATIONS[:25]],
    "station_history": history(),
    "wind_counts": WIND_COUNTS,
    "list_followed": [],
}

if __name__ == "__main__":
    os.makedirs(OUT, exist_ok=True)
    for tool, rows in DATA.items():
        with open(os.path.join(OUT, tool + ".json"), "w", encoding="utf-8", newline="\n") as f:
            json.dump({"tool": tool, "date": DATE, "rows": rows}, f, indent=1)
