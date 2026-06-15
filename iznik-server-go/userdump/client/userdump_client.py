#!/usr/bin/env python3
"""Reference client for the support user data dump endpoint.

Connects to GET /api/modtools/user/<id>/dump (framed mode), renders a live
progress bar with ETA while the server builds the dump, reassembles the streamed
SQLite database and writes it to disk.

The stream is a sequence of length-prefixed frames. Each frame is a 5-byte
header (1 type byte + uint32 big-endian payload length) followed by the payload:

    type 0x01 progress  -> JSON {phase, section, status, done, total, percent,
                                 elapsed_ms, eta_ms, rows, message}
    type 0x02 data      -> a chunk of the SQLite file
    type 0x03 end       -> JSON {bytes, sha256, warnings}

Usage:
    python3 userdump_client.py --base http://localhost:8192 --jwt "<token>" \
        --user 12345 --out user-12345.sqlite

Then query it:
    sqlite3 user-12345.sqlite "SELECT name, status, rows FROM _sections"
"""
import argparse
import hashlib
import json
import struct
import sys
import urllib.request


def human_ms(ms):
    s = int(ms) // 1000
    return f"{s // 60}m{s % 60:02d}s" if s >= 60 else f"{s}s"


def read_exact(resp, n):
    buf = bytearray()
    while len(buf) < n:
        chunk = resp.read(n - len(buf))
        if not chunk:
            break
        buf.extend(chunk)
    return bytes(buf)


def main():
    ap = argparse.ArgumentParser(description="Stream a support user data dump.")
    ap.add_argument("--base", default="http://localhost:8192", help="API base URL")
    ap.add_argument("--jwt", required=True, help="Support/Admin JWT")
    ap.add_argument("--user", required=True, type=int, help="Target user id")
    ap.add_argument("--out", help="Output .sqlite path (default user-<id>.sqlite)")
    ap.add_argument("--include", default="db,loki,sentry", help="CSV: db,loki,sentry")
    args = ap.parse_args()

    out_path = args.out or f"user-{args.user}.sqlite"
    url = (f"{args.base}/api/modtools/user/{args.user}/dump"
           f"?include={args.include}&jwt={args.jwt}")

    req = urllib.request.Request(url)
    sha = hashlib.sha256()
    written = 0

    with urllib.request.urlopen(req) as resp, open(out_path, "wb") as out:
        if resp.status != 200:
            print(f"HTTP {resp.status}", file=sys.stderr)
            return 1
        while True:
            header = read_exact(resp, 5)
            if len(header) < 5:
                break
            ftype = header[0]
            (length,) = struct.unpack(">I", header[1:5])
            payload = read_exact(resp, length)

            if ftype == 0x01:
                p = json.loads(payload)
                eta = human_ms(p.get("eta_ms", 0))
                bar = int(p.get("percent", 0) // 4)
                sys.stderr.write(
                    f"\r[{'#' * bar:<25}] {p.get('percent', 0):5.1f}%  "
                    f"{p.get('section', ''):<26} ETA {eta:<7}")
                sys.stderr.flush()
            elif ftype == 0x02:
                out.write(payload)
                sha.update(payload)
                written += len(payload)
            elif ftype == 0x03:
                end = json.loads(payload)
                sys.stderr.write("\n")
                if end.get("sha256") and end["sha256"] != sha.hexdigest():
                    print("WARNING: sha256 mismatch — dump may be incomplete",
                          file=sys.stderr)
                for w in end.get("warnings", []):
                    print(f"warning: {w}", file=sys.stderr)
                print(f"Wrote {written} bytes to {out_path}")
                return 0
    print(f"Wrote {written} bytes to {out_path} (stream ended without end frame)",
          file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
