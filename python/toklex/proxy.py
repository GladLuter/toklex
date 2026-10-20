# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# Licensed under Apache-2.0. See LICENSE.
"""Toklex in front of any stdio MCP server, with no change to its code.

    python -m toklex.proxy --schema stations.schema.json -- php server.php

The proxy starts the server and relays every message. On the way it adds toklex_mode to the tool list, removes
toklex_mode from each call and turns a JSON tool result into a Toklex reply. A result is turned when its one JSON text
block, or its structuredContent, is a list of objects, an object with one member that is such a list (the other
members go to the header), or an object of two or more members, which is one record. Any other result, every error
and every other content block pass unchanged. JSON-RPC batches pass unchanged too: MCP dropped them in 2025-06-18.
"""
from __future__ import annotations

import argparse
import json
import shutil
import signal
import subprocess
import sys
import threading
from typing import Any, Optional

from . import CALIBRATE_TOOL, SCHEMA_TOOL, Toklex, ToklexError

GRACE = 5.0  # seconds a server gets to exit after its client has gone


def loads(text: Any) -> Any:
    """JSON, or None for text that is not JSON or is nested too deep for Python to read."""
    try:
        return json.loads(text)
    except (ValueError, RecursionError):
        return None


def rows_of(data: Any) -> Optional[tuple]:
    """(rows, meta) for a result that holds records, else None. One member that wraps a value is not a record."""
    is_rows = lambda x: isinstance(x, list) and x and all(isinstance(r, dict) for r in x)  # noqa: E731
    if is_rows(data):
        return data, {}
    if isinstance(data, dict):
        lists = [k for k, x in data.items() if is_rows(x)]
        if len(lists) == 1:
            return data[lists[0]], {k: x for k, x in data.items() if k != lists[0]}
        if len(data) >= 2 and not lists:
            return [data], {}
    return None


class Proxy:
    def __init__(self, tx: Toklex, cmd: list, once: bool = False, out=None):
        self.tx = tx
        self.once = once
        self.sent = False  # the instruction text went out once in this process
        self.out = out or sys.stdout.buffer
        self.lock = threading.Lock()
        self.pending: dict = {}  # request id -> what to do with the answer
        # Windows finds npx.cmd and the like only through PATHEXT, which shutil.which reads
        self.up = subprocess.Popen([shutil.which(cmd[0]) or cmd[0], *cmd[1:]], stdin=subprocess.PIPE, stdout=subprocess.PIPE)

    def send(self, data: bytes) -> None:
        with self.lock:
            self.out.write(data.rstrip(b"\r\n") + b"\n")
            self.out.flush()

    def write(self, msg: Any) -> None:
        try:
            data = json.dumps(msg, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        except UnicodeEncodeError:  # a lone surrogate, as Node writes when it cuts an emoji in half: send it escaped
            data = json.dumps(msg, separators=(",", ":")).encode("ascii")
        self.send(data)

    def answer(self, mid: Any, result: dict) -> None:
        self.write({"jsonrpc": "2.0", "id": mid, "result": result})

    def text(self, mid: Any, text: str) -> None:
        self.answer(mid, {"content": [{"type": "text", "text": text}]})

    def to_server(self, line: bytes) -> None:
        try:
            self.up.stdin.write(line.rstrip(b"\r\n") + b"\n")
            self.up.stdin.flush()
        except (BrokenPipeError, OSError):
            pass  # the server is gone, and the end of its stdout ends the proxy

    def from_client(self, line: bytes) -> None:
        """One message from the client, answered here or sent on to the server. A message the proxy cannot handle goes on unchanged."""
        try:
            line = self.client_message(line)
        except Exception as e:  # noqa: BLE001 - one bad message must never stop the relay
            print(f"toklex-proxy: passed a client message on unchanged: {e!r}", file=sys.stderr)
        if line is not None:
            self.to_server(line)

    def client_message(self, line: bytes) -> Optional[bytes]:
        msg = loads(line)
        if not (isinstance(msg, dict) and "method" in msg and "id" in msg):
            return line
        key, method, params = json.dumps(msg["id"]), msg["method"], msg.get("params") or {}
        if not isinstance(params, dict):
            return line
        if method == "tools/call":
            name = params.get("name", "")
            args = params.get("arguments")
            if args is not None and not isinstance(args, dict):
                return line  # the server answers a malformed call itself
            args = dict(args or {})
            if self.tx.style() == "coded" and name == SCHEMA_TOOL:
                return self.text(msg["id"], self.tx.schema_text())
            if self.tx.style() == "coded" and name == CALIBRATE_TOOL:
                return self.text(msg["id"], self.tx.calibrate(args))
            mode = self.tx.mode(name, args)
            msg["params"] = dict(params, arguments=args)
            self.pending[key] = ("call", name, mode)
            return json.dumps(msg, separators=(",", ":")).encode("ascii")
        if method == "resources/read" and str(params.get("uri", "")).startswith("toklex://"):
            body = self.tx.read_resource(params["uri"])
            if body is not None:
                return self.answer(msg["id"], {"contents": [{"uri": params["uri"], "mimeType": "text/plain", "text": body}]})
        if method in ("tools/list", "initialize", "resources/list"):
            # a later page of a paginated list has a cursor, and the Toklex entries belong on the first page only
            self.pending[key] = (method, "cursor" not in params)
        return line

    def from_server(self, line: bytes) -> None:
        """One message from the server, turned when it answers a request the proxy watches. A message the proxy cannot handle goes on unchanged."""
        try:
            if self.server_message(line):
                return
        except Exception as e:  # noqa: BLE001 - one bad message must never stop the relay
            print(f"toklex-proxy: passed a server message on unchanged: {e!r}", file=sys.stderr)
        self.send(line)

    def server_message(self, line: bytes) -> bool:
        """True when the message was answered in a new form, False when it should go on as it came."""
        msg = loads(line)
        job = self.pending.pop(json.dumps(msg["id"]), None) if isinstance(msg, dict) and "id" in msg and "method" not in msg else None
        if not job:
            return False
        if job[0] == "resources/list" and "error" in msg and job[1]:
            # a server without resources refuses the method, and the Toklex resources still exist
            self.answer(msg["id"], {"resources": self.tx.resources()})
            return True
        res = msg.get("result")
        if not isinstance(res, dict):
            return False
        if job[0] == "initialize":
            res.setdefault("capabilities", {}).setdefault("resources", {})
            if self.tx.style() == "plain" and self.tx._s.get("instructions_at") == "server" and self.tx.instructions_text():
                res["instructions"] = (res["instructions"] + "\n\n" if res.get("instructions") else "") + self.tx.instructions_text()
        elif job[0] == "tools/list":
            tools = list(res.get("tools") or [])
            res["tools"] = self.tx.with_tools(tools, lambda m: print(m, file=sys.stderr))[:None if job[1] else len(tools)]
        elif job[0] == "resources/list":
            res["resources"] = list(res.get("resources") or []) + (self.tx.resources() if job[1] else [])
        elif job[0] == "call" and not res.get("isError") and not self.turn(res, job[1], job[2]):
            return False
        self.write(msg)
        return True

    def turn(self, res: dict, tool: str, mode: str) -> bool:
        """A tool result with records into a Toklex text block, in place of the JSON text block. Every other block stays."""
        content = res.get("content")
        content = list(content) if isinstance(content, list) else []
        texts = [i for i, b in enumerate(content) if isinstance(b, dict) and b.get("type") == "text"]
        data = res.get("structuredContent")
        if data is not None:
            # the JSON text block that repeats structuredContent is the one the reply replaces
            target = next((i for i in texts if loads(content[i].get("text", "")) == data), None)
        elif len(texts) == 1:
            target = texts[0]
            data = loads(content[target].get("text", ""))
        else:
            return False
        found = rows_of(data)
        if found is None:
            return False
        try:
            text = self.tx.encode(tool, found[0], found[1], mode, not (self.once and self.sent))
        except ToklexError:
            return False
        self.sent = True
        block = {"type": "text", "text": text}
        if target is None:
            content.insert(0, block)
        else:
            content[target] = block
        res["content"] = content
        res.pop("structuredContent", None)
        return True

    def stop(self) -> None:
        """Ends the server: a grace period after its stdin closed, then terminate, then kill."""
        for step in (lambda: None, self.up.terminate, self.up.kill):
            step()
            try:
                self.up.wait(timeout=GRACE)
                return
            except subprocess.TimeoutExpired:
                pass

    def run(self, stdin=None) -> int:
        def pump() -> None:
            try:
                for line in stdin or sys.stdin.buffer:
                    if line.strip():
                        self.from_client(line)
            finally:
                try:
                    self.up.stdin.close()
                except OSError:
                    pass
                self.stop()  # a server that ignores the end of its stdin is ended, so the proxy never waits on it
        threading.Thread(target=pump, daemon=True).start()
        try:
            for line in self.up.stdout:
                if line.strip():
                    self.from_server(line)
            return self.up.wait()
        finally:
            if self.up.poll() is None:  # the proxy is going down, and its server goes with it
                self.up.kill()
                self.up.wait()


def main(argv: Optional[list] = None) -> int:
    ap = argparse.ArgumentParser(prog="python -m toklex.proxy", description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--schema", required=True, help="the Toklex schema file")
    ap.add_argument("--instructions", choices=["always", "once"], default="always",
                    help="once: the instruction text goes with the first reply of this proxy process only (spec 5.1). "
                         "A client that keeps the server running across a new chat sends that chat no rules, "
                         "and a client that compacts its context can lose them")
    ap.add_argument("cmd", nargs=argparse.REMAINDER, help="the server command, after --")
    a = ap.parse_args(argv)
    cmd = a.cmd[1:] if a.cmd[:1] == ["--"] else a.cmd
    if not cmd:
        ap.error("give the server command after --")
    p = Proxy(Toklex.from_file(a.schema), cmd, a.instructions == "once")
    # SIGTERM from the client ends the proxy through its finally block, which ends the server too
    signal.signal(signal.SIGTERM, lambda *_: sys.exit(128 + signal.SIGTERM))
    return p.run()


if __name__ == "__main__":
    sys.exit(main())
