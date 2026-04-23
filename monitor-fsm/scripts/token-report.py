#!/usr/bin/env python3
"""
token-report.py — aggregate Claude Code token usage across session transcripts.

Reads every *.jsonl under ~/.claude/projects/, sums per-turn usage from each
assistant turn's `usage` block, classifies sessions (main FSM, delegate,
interactive, other), and prints cost totals plus a per-session breakdown.

Dollar estimates use current Opus 4.7 list pricing.

Usage:
  token-report.py                   # all sessions, default layout
  token-report.py --since 2026-04-14 --top 20
  token-report.py --jsonl >> ~/.claude/token-report.jsonl   # rolling record
"""

import argparse
import glob
import json
import os
import sys
from collections import defaultdict
from datetime import datetime, timezone

# Opus 4.7 per-token rates ($/token)
RATE_IN = 15.0 / 1e6
RATE_OUT = 75.0 / 1e6
RATE_CACHE_WRITE_5M = 18.75 / 1e6
RATE_CACHE_WRITE_1H = 30.0 / 1e6  # approx 2x 5-min
RATE_CACHE_READ = 1.50 / 1e6

PROJECTS_ROOT = os.path.expanduser("~/.claude/projects")


def classify(project_dir_name: str) -> str:
    n = project_dir_name
    if n.startswith("-tmp-monitor-fsm-delegate-"):
        return "delegate"
    if n.endswith("-monitor-fsm"):
        return "fsm-driver"
    if n.startswith("-home-edward-FreegleDockerWSL"):
        return "interactive-freegle"
    return "interactive-other"


def parse_ts(s):
    if not s:
        return None
    try:
        return datetime.fromisoformat(s.replace("Z", "+00:00"))
    except ValueError:
        return None


def summarise_jsonl(path: str):
    totals = dict(
        input=0, output=0, cache_read=0,
        cache_create_5m=0, cache_create_1h=0,
        turns=0, tools=0,
    )
    first_ts = last_ts = None
    bug_context = None
    with open(path, "r", errors="replace") as fh:
        for line in fh:
            try:
                row = json.loads(line)
            except json.JSONDecodeError:
                continue
            if row.get("type") == "user" and bug_context is None:
                # First user message often contains "bug NNN" or topic hint
                msg = row.get("message", {}).get("content", "")
                if isinstance(msg, str) and len(msg) > 0:
                    bug_context = msg[:120].replace("\n", " ")
            if row.get("type") != "assistant":
                continue
            msg = row.get("message", {})
            u = msg.get("usage", {}) or {}
            totals["input"] += u.get("input_tokens", 0) or 0
            totals["output"] += u.get("output_tokens", 0) or 0
            totals["cache_read"] += u.get("cache_read_input_tokens", 0) or 0
            cc = u.get("cache_creation", {}) or {}
            totals["cache_create_5m"] += cc.get("ephemeral_5m_input_tokens", 0) or 0
            totals["cache_create_1h"] += cc.get("ephemeral_1h_input_tokens", 0) or 0
            if not cc:
                # Fall back to flat cache_creation_input_tokens (treat as 5m)
                totals["cache_create_5m"] += u.get("cache_creation_input_tokens", 0) or 0
            totals["turns"] += 1
            for c in msg.get("content", []) or []:
                if isinstance(c, dict) and c.get("type") == "tool_use":
                    totals["tools"] += 1
            ts = parse_ts(row.get("timestamp"))
            if ts:
                if first_ts is None or ts < first_ts:
                    first_ts = ts
                if last_ts is None or ts > last_ts:
                    last_ts = ts
    return totals, first_ts, last_ts, bug_context


def cost(t):
    return (
        t["input"] * RATE_IN
        + t["output"] * RATE_OUT
        + t["cache_read"] * RATE_CACHE_READ
        + t["cache_create_5m"] * RATE_CACHE_WRITE_5M
        + t["cache_create_1h"] * RATE_CACHE_WRITE_1H
    )


def human(n: int) -> str:
    for unit in ("", "K", "M", "B"):
        if n < 1000:
            return f"{n:.0f}{unit}"
        n /= 1000
    return f"{n:.1f}T"


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--since", help="ISO date — only include sessions whose last activity is on/after this date")
    ap.add_argument("--top", type=int, default=15, help="show N most expensive sessions (default 15)")
    ap.add_argument("--jsonl", action="store_true", help="emit one JSON line per session and exit")
    ap.add_argument("--class", dest="cls", help="only include this classification (delegate|fsm-driver|interactive-freegle|interactive-other)")
    args = ap.parse_args()

    since_dt = None
    if args.since:
        since_dt = datetime.fromisoformat(args.since).replace(tzinfo=timezone.utc)

    sessions = []
    for project_dir in sorted(os.listdir(PROJECTS_ROOT)):
        full = os.path.join(PROJECTS_ROOT, project_dir)
        if not os.path.isdir(full):
            continue
        cls = classify(project_dir)
        if args.cls and cls != args.cls:
            continue
        for path in glob.glob(os.path.join(full, "*.jsonl")):
            totals, first_ts, last_ts, bug = summarise_jsonl(path)
            if totals["turns"] == 0:
                continue
            if since_dt and last_ts and last_ts < since_dt:
                continue
            wall_min = 0.0
            if first_ts and last_ts:
                wall_min = (last_ts - first_ts).total_seconds() / 60
            sessions.append({
                "classification": cls,
                "project_dir": project_dir,
                "session_file": os.path.basename(path),
                "first_ts": first_ts.isoformat() if first_ts else None,
                "last_ts": last_ts.isoformat() if last_ts else None,
                "wall_minutes": round(wall_min, 2),
                "turns": totals["turns"],
                "tool_calls": totals["tools"],
                "input_tokens": totals["input"],
                "output_tokens": totals["output"],
                "cache_read_tokens": totals["cache_read"],
                "cache_create_5m_tokens": totals["cache_create_5m"],
                "cache_create_1h_tokens": totals["cache_create_1h"],
                "cost_usd": round(cost(totals), 4),
                "bug_context": bug,
            })

    if args.jsonl:
        for s in sessions:
            print(json.dumps(s, separators=(",", ":")))
        return

    if not sessions:
        print("No sessions matched.", file=sys.stderr)
        return

    by_class = defaultdict(lambda: dict(count=0, cost=0.0, turns=0, cache_read=0, output=0))
    for s in sessions:
        b = by_class[s["classification"]]
        b["count"] += 1
        b["cost"] += s["cost_usd"]
        b["turns"] += s["turns"]
        b["cache_read"] += s["cache_read_tokens"]
        b["output"] += s["output_tokens"]

    grand = sum(b["cost"] for b in by_class.values())

    print(f"Scanned {len(sessions)} sessions under {PROJECTS_ROOT}")
    if since_dt:
        print(f"Filtered to sessions active since {args.since}")
    print()
    print(f"{'classification':22s} {'count':>6s} {'turns':>8s} {'output':>10s} {'cache_r':>10s} {'cost $':>10s} {'share':>7s}")
    for cls, b in sorted(by_class.items(), key=lambda kv: -kv[1]["cost"]):
        share = (b["cost"] / grand * 100) if grand else 0
        print(f"{cls:22s} {b['count']:6d} {b['turns']:8d} {human(b['output']):>10s} {human(b['cache_read']):>10s} {b['cost']:10.2f} {share:6.1f}%")
    print(f"{'TOTAL':22s} {'':>6s} {'':>8s} {'':>10s} {'':>10s} {grand:10.2f}")
    print()

    sessions.sort(key=lambda s: s["cost_usd"], reverse=True)
    print(f"Top {args.top} sessions by cost:")
    print(f"{'class':13s} {'project/session':60s} {'turns':>6s} {'tools':>6s} {'out':>8s} {'cache_r':>9s} {'min':>6s} {'cost':>8s}")
    for s in sessions[: args.top]:
        tag = s["project_dir"][:35] + "/" + s["session_file"][:24]
        print(
            f"{s['classification']:13s} {tag:60s} "
            f"{s['turns']:6d} {s['tool_calls']:6d} "
            f"{human(s['output_tokens']):>8s} {human(s['cache_read_tokens']):>9s} "
            f"{s['wall_minutes']:6.1f} ${s['cost_usd']:7.2f}"
        )


if __name__ == "__main__":
    main()
