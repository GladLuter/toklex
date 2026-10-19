# Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
# Licensed under Apache-2.0. See LICENSE.
"""Toklex in front of any stdio MCP server, with no change to its code.

    python -m toklex.proxy --schema stations.schema.json -- php server.php

The proxy starts the server and relays every message. On the way it adds toklex_mode to the tool list, removes
toklex_mode from each call and turns a JSON tool result into a Toklex reply. A result is turned when its one text
block, or its structuredContent, is a list of objects, an object with one member that is such a list (the other
members go to the header), or a single object. Any other result, and every error, passes unchanged.
"""
from __future__ import annotations

import argparse
import json
import subprocess
import sys
import threading
from typing import Any, Optional

from . import CALIBRATE_TOOL, SCHEMA_TOOL, Toklex, ToklexError


def rows_of(data: Any) -> Optional[tuple]:
    """(rows, meta) for a result that holds records, else None."""
    is_rows = lambda x: isinstance(x, list) and all(isinstance(r, dict) for r in x)  # noqa: E731
    if is_rows(data) and data:
        return data, {}
    if isinstance(data, dict):
        lists = [k for k, x in data.items() if is_rows(x)]
        if len(lists) == 1:
            return data[lists[0]], {k: x for k, x in data.items() if k != lists[0]}
        return [data], {}
    return None


class Proxy:
    def __init__(self, tx: Toklex, cmd: list, once: bool = False, out=None):
        self.tx = tx
        self.once = once
        self.sent = False  # the instruction text went out once in this session
        self.out = out or sys.stdout.buffer
        self.lock = threading.Lock()
        self.pending: dict = {}  # request id -> what to do with the answer
        self.up = subprocess.Popen(cmd, stdin=subprocess.PIPE, stdout=subprocess.PIPE)

    def write(self, msg: Any) -> None:
        with self.lock:
            self.out.write(json.dumps(msg, ensure_ascii=False, separators=(",", ":")).encode("utf-8") + b"\n")
            self.out.flush()

    def answer(self, mid: Any, result: dict) -> None:
        self.write({"jsonrpc": "2.0", "id": mid, "result": result})

    def text(self, mid: Any, text: str) -> None:
        self.answer(mid, {"content": [{"type": "text", "text": text}]})

    def from_client(self, line: bytes) -> None:
        """One message from the client. Returns after it is answered here or sent on to the server."""
        try:
            msg = json.loads(line)
        except ValueError:
            msg = None
        if isinstance(msg, dict) and "method" in msg and "id" in msg:
            key, method, params = json.dumps(msg["id"]), msg["method"], msg.get("params") or {}
            if method == "tools/call":
                name = params.get("name", "")
                args = dict(params.get("arguments") or {})
                if self.tx.style() == "coded" and name == SCHEMA_TOOL:
                    return self.text(msg["id"], self.tx.schema_text())
                if self.tx.style() == "coded" and name == CALIBRATE_TOOL:
                    return self.text(msg["id"], self.tx.calibrate(args))
                mode = self.tx.mode(name, args)
                msg["params"] = dict(params, arguments=args)
                line = json.dumps(msg, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
                self.pending[key] = ("call", name, mode)
            elif method == "resources/read" and str(params.get("uri", "")).startswith("toklex://"):
                body = self.tx.read_resource(params["uri"])
                if body is not None:
                    return self.answer(msg["id"], {"contents": [{"uri": params["uri"], "mimeType": "text/plain", "text": body}]})
            elif method in ("tools/list", "initialize", "resources/list"):
                self.pending[key] = (method,)
        self.up.stdin.write(line.rstrip(b"\r\n") + b"\n")
        self.up.stdin.flush()

    def from_server(self, line: bytes) -> None:
        """One message from the server, turned when it answers a request the proxy watches."""
        try:
            msg = json.loads(line)
        except ValueError:
            msg = None
        job = self.pending.pop(json.dumps(msg["id"]), None) if isinstance(msg, dict) and "id" in msg and "method" not in msg else None
        if job and job[0] == "resources/list" and "error" in msg:
            # a server without resources refuses the method, and the Toklex resources still exist
            return self.answer(msg["id"], {"resources": self.tx.resources()})
        res = msg.get("result") if job else None
        if not isinstance(res, dict):
            with self.lock:
                self.out.write(line.rstrip(b"\r\n") + b"\n")
                self.out.flush()
            return
        if job[0] == "initialize":
            res.setdefault("capabilities", {}).setdefault("resources", {})
            if self.tx.style() == "plain" and self.tx._s.get("instructions_at") == "server" and self.tx.instructions_text():
                res["instructions"] = (res["instructions"] + "\n\n" if res.get("instructions") else "") + self.tx.instructions_text()
        elif job[0] == "tools/list":
            res["tools"] = self.tx.with_tools(res.get("tools") or [], lambda m: print(m, file=sys.stderr))
        elif job[0] == "resources/list":
            res["resources"] = list(res.get("resources") or []) + self.tx.resources()
        elif job[0] == "call" and not res.get("isError"):
            self.turn(res, job[1], job[2])
        self.write(msg)

    def turn(self, res: dict, tool: str, mode: str) -> None:
        """A tool result into one Toklex text block, when it holds records."""
        data = res.get("structuredContent")
        content = res.get("content") or []
        if data is None:
            if len(content) != 1 or content[0].get("type") != "text":
                return
            try:
                data = json.loads(content[0].get("text", ""))
            except ValueError:
                return
        found = rows_of(data)
        if found is None:
            return
        try:
            text = self.tx.encode(tool, found[0], found[1], mode, not (self.once and self.sent))
        except ToklexError:
            return
        self.sent = True
        res["content"] = [{"type": "text", "text": text}]
        res.pop("structuredContent", None)

    def run(self, stdin=None) -> int:
        def pump() -> None:
            for line in stdin or sys.stdin.buffer:
                if line.strip():
                    self.from_client(line)
            self.up.stdin.close()
        threading.Thread(target=pump, daemon=True).start()
        for line in self.up.stdout:
            if line.strip():
                self.from_server(line)
        return self.up.wait()


def main(argv: Optional[list] = None) -> int:
    ap = argparse.ArgumentParser(prog="python -m toklex.proxy", description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--schema", required=True, help="the Toklex schema file")
    ap.add_argument("--instructions", choices=["always", "once"], default="always",
                    help="once: the instruction text goes with the first reply of the session only (spec 5.1). "
                         "A client that compacts its context can then lose the rules")
    ap.add_argument("cmd", nargs=argparse.REMAINDER, help="the server command, after --")
    a = ap.parse_args(argv)
    cmd = a.cmd[1:] if a.cmd[:1] == ["--"] else a.cmd
    if not cmd:
        ap.error("give the server command after --")
    return Proxy(Toklex.from_file(a.schema), cmd, a.instructions == "once").run()


if __name__ == "__main__":
    sys.exit(main())
