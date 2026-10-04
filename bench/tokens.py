# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# o200k token table per tool: pretty-JSON baseline vs safe, dense and off. Exits 1 if safe saves < 50% in total.
# Usage: python tokens.py [encdir]   (default out/enc, written by encode.php)
import os, sys
import tiktoken

D = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(os.path.abspath(__file__)), "out", "enc")
enc = tiktoken.get_encoding("o200k_base")
tok = lambda f: len(enc.encode(open(os.path.join(D, f), encoding="utf-8").read()))
TOOLS = ["get_station", "find_stations", "station_history", "wind_counts", "list_followed"]
EXT = ["current", "toklex", "dense", "off"]
pct = lambda a, b: f"{a / b - 1:+.0%}"

tot = dict.fromkeys(EXT, 0)
print(f"{'tool':16}{'current':>9}{'safe':>9}{'safe%':>7}{'dense':>9}{'dense%':>8}{'off':>9}{'off%':>7}")
for t in TOOLS:
    n = {e: tok(f"{t}.{e}.txt") for e in EXT}
    for e in EXT: tot[e] += n[e]
    print(f"{t:16}{n['current']:>9}{n['toklex']:>9}{pct(n['toklex'], n['current']):>7}{n['dense']:>9}{pct(n['dense'], n['current']):>8}{n['off']:>9}{pct(n['off'], n['current']):>7}")
print(f"{'TOTAL':16}{tot['current']:>9}{tot['toklex']:>9}{pct(tot['toklex'], tot['current']):>7}{tot['dense']:>9}{pct(tot['dense'], tot['current']):>8}{tot['off']:>9}{pct(tot['off'], tot['current']):>7}")
print(f"schema.txt (one-time legend): {tok('schema.txt')} tokens")
sys.exit(tot["toklex"] > tot["current"] / 2)
