# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# Token table per tool: pretty-JSON baseline vs safe, dense and off. Exits 1 if safe saves < 50% in total.
# Usage: python tokens.py [encdir] [--counter o200k|claude] [--model M]   (encdir default out/enc, written by encode.php)
# o200k is OpenAI's tokenizer and runs offline. claude asks the Anthropic count_tokens endpoint, which counts with the
# tokenizer of the model that reads the replies; it needs pip install anthropic and an API key or an `ant auth login`.
import argparse, os, sys

TOOLS = ["get_station", "find_stations", "station_history", "wind_counts", "list_followed"]
EXT = ["current", "toklex", "dense", "off"]


def make_counter(kind="o200k", model="claude-opus-5-5"):
    """A function from text to its token count."""
    if kind == "o200k":
        import tiktoken
        enc = tiktoken.get_encoding("o200k_base")
        return lambda t: len(enc.encode(t))
    from anthropic import Anthropic
    client = Anthropic()
    n = lambda t: client.messages.count_tokens(model=model, messages=[{"role": "user", "content": t}]).input_tokens  # noqa: E731
    frame = n("x") - 1  # the tokens of the message frame around any text, taken off every count
    return lambda t: n(t) - frame


def main(argv=None):
    ap = argparse.ArgumentParser(description="Token table of the encoded bench replies")
    ap.add_argument("encdir", nargs="?", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), "out", "enc"))
    ap.add_argument("--counter", choices=["o200k", "claude"], default="o200k")
    ap.add_argument("--model", default="claude-opus-5-5", help="the model whose tokenizer --counter claude uses")
    a = ap.parse_args(argv)
    count = make_counter(a.counter, a.model)
    tok = lambda f: count(open(os.path.join(a.encdir, f), encoding="utf-8").read())  # noqa: E731
    pct = lambda x, y: f"{x / y - 1:+.0%}"  # noqa: E731
    tot = dict.fromkeys(EXT, 0)
    print(f"counter: {'o200k_base' if a.counter == 'o200k' else a.model + ' (count_tokens)'}")
    print(f"{'tool':16}{'current':>9}{'safe':>9}{'safe%':>7}{'dense':>9}{'dense%':>8}{'off':>9}{'off%':>7}")
    for t in TOOLS:
        n = {e: tok(f"{t}.{e}.txt") for e in EXT}
        for e in EXT:
            tot[e] += n[e]
        print(f"{t:16}{n['current']:>9}{n['toklex']:>9}{pct(n['toklex'], n['current']):>7}{n['dense']:>9}{pct(n['dense'], n['current']):>8}{n['off']:>9}{pct(n['off'], n['current']):>7}")
    print(f"{'TOTAL':16}{tot['current']:>9}{tot['toklex']:>9}{pct(tot['toklex'], tot['current']):>7}{tot['dense']:>9}{pct(tot['dense'], tot['current']):>8}{tot['off']:>9}{pct(tot['off'], tot['current']):>7}")
    print(f"schema.txt (one-time legend): {tok('schema.txt')} tokens")
    return int(tot["toklex"] > tot["current"] / 2)


if __name__ == "__main__":
    sys.exit(main())
