#!/usr/bin/env python3
"""Analyze Claude Code session transcripts for token usage breakdown.

Usage:
    python3 scripts/session-usage.py              # Last 7 days
    python3 scripts/session-usage.py --days 30    # Last 30 days
    python3 scripts/session-usage.py --all        # All sessions
"""

import json
import os
import glob
import argparse
from datetime import datetime, timedelta, timezone
from collections import defaultdict

TRANSCRIPT_DIR = os.path.expanduser(
    "~/.claude/projects/-home-edward-FreegleDockerWSL"
)


def parse_session(filepath):
    """Parse a single session transcript and return usage stats."""
    session_id = os.path.basename(filepath).replace(".jsonl", "")
    first_user_msg = None
    model_counts = defaultdict(lambda: {"input": 0, "output": 0, "cache_read": 0, "cache_write": 0})
    timestamps = []
    msg_count = 0
    tool_calls = 0

    with open(filepath) as f:
        for line in f:
            try:
                d = json.loads(line.strip())
            except json.JSONDecodeError:
                continue

            ts = d.get("timestamp")
            if ts:
                timestamps.append(ts)

            if d.get("type") == "user" and first_user_msg is None:
                msg = d.get("message", {})
                if isinstance(msg, dict):
                    content = msg.get("content", "")
                    if isinstance(content, list):
                        # Extract text from content blocks
                        content = " ".join(
                            b.get("text", "") for b in content if isinstance(b, dict)
                        )
                    first_user_msg = content[:120]
                msg_count += 1

            elif d.get("type") == "user":
                msg_count += 1

            elif d.get("type") == "assistant":
                msg = d.get("message", {})
                if isinstance(msg, dict):
                    usage = msg.get("usage", {})
                    model = msg.get("model", "unknown")
                    if usage:
                        model_counts[model]["input"] += usage.get("input_tokens", 0)
                        model_counts[model]["output"] += usage.get("output_tokens", 0)
                        model_counts[model]["cache_read"] += usage.get("cache_read_input_tokens", 0)
                        model_counts[model]["cache_write"] += usage.get("cache_creation_input_tokens", 0)

                    # Count tool uses
                    content = msg.get("content", [])
                    if isinstance(content, list):
                        tool_calls += sum(
                            1 for b in content
                            if isinstance(b, dict) and b.get("type") == "tool_use"
                        )

    if not timestamps:
        return None

    start = min(timestamps)
    end = max(timestamps)

    total_input = sum(m["input"] for m in model_counts.values())
    total_output = sum(m["output"] for m in model_counts.values())
    total_cache_read = sum(m["cache_read"] for m in model_counts.values())
    total_cache_write = sum(m["cache_write"] for m in model_counts.values())

    return {
        "session_id": session_id[:8],
        "start": start,
        "end": end,
        "purpose": first_user_msg or "(no user message)",
        "models": dict(model_counts),
        "total_input": total_input,
        "total_output": total_output,
        "total_cache_read": total_cache_read,
        "total_cache_write": total_cache_write,
        "user_messages": msg_count,
        "tool_calls": tool_calls,
    }


def format_tokens(n):
    if n >= 1_000_000:
        return f"{n / 1_000_000:.1f}M"
    if n >= 1_000:
        return f"{n / 1_000:.0f}K"
    return str(n)


def main():
    parser = argparse.ArgumentParser(description="Analyze Claude Code session usage")
    parser.add_argument("--days", type=int, default=7, help="Look back N days (default 7)")
    parser.add_argument("--all", action="store_true", help="Show all sessions")
    parser.add_argument("--top", type=int, default=20, help="Show top N sessions (default 20)")
    args = parser.parse_args()

    cutoff = None
    if not args.all:
        cutoff = (datetime.now(timezone.utc) - timedelta(days=args.days)).isoformat()

    files = glob.glob(os.path.join(TRANSCRIPT_DIR, "*.jsonl"))
    sessions = []

    for f in files:
        # Quick filter by file modification time
        if not args.all:
            mtime = datetime.fromtimestamp(os.path.getmtime(f), tz=timezone.utc)
            if mtime < datetime.now(timezone.utc) - timedelta(days=args.days):
                continue

        s = parse_session(f)
        if s and (args.all or s["start"] >= cutoff):
            sessions.append(s)

    sessions.sort(key=lambda s: s["start"], reverse=True)

    if not sessions:
        print("No sessions found in the specified time range.")
        return

    # Summary
    grand_input = sum(s["total_input"] for s in sessions)
    grand_output = sum(s["total_output"] for s in sessions)
    grand_cache_read = sum(s["total_cache_read"] for s in sessions)
    grand_cache_write = sum(s["total_cache_write"] for s in sessions)
    grand_tools = sum(s["tool_calls"] for s in sessions)

    period = f"last {args.days} days" if not args.all else "all time"
    print(f"\n{'='*100}")
    print(f"  Claude Code Session Usage — {period} — {len(sessions)} sessions")
    print(f"  Total: input={format_tokens(grand_input)}  output={format_tokens(grand_output)}  "
          f"cache_read={format_tokens(grand_cache_read)}  cache_write={format_tokens(grand_cache_write)}  "
          f"tool_calls={grand_tools}")
    print(f"{'='*100}\n")

    # Per-session table
    print(f"{'Date':<12} {'ID':<10} {'Input':>8} {'Output':>8} {'Cache R':>8} {'Msgs':>5} {'Tools':>6}  Purpose")
    print(f"{'-'*12} {'-'*10} {'-'*8} {'-'*8} {'-'*8} {'-'*5} {'-'*6}  {'-'*40}")

    for s in sessions[: args.top]:
        date = s["start"][:10]
        print(
            f"{date:<12} {s['session_id']:<10} "
            f"{format_tokens(s['total_input']):>8} "
            f"{format_tokens(s['total_output']):>8} "
            f"{format_tokens(s['total_cache_read']):>8} "
            f"{s['user_messages']:>5} "
            f"{s['tool_calls']:>6}  "
            f"{s['purpose'][:60]}"
        )

    if len(sessions) > args.top:
        print(f"\n  ... and {len(sessions) - args.top} more sessions. Use --top N to see more.")

    # Model breakdown
    model_totals = defaultdict(lambda: {"input": 0, "output": 0, "sessions": 0})
    for s in sessions:
        for model, counts in s["models"].items():
            # Shorten model name
            short = model.replace("claude-", "").replace("-20251022", "").replace("-20250514", "")
            model_totals[short]["input"] += counts["input"]
            model_totals[short]["output"] += counts["output"]
            model_totals[short]["sessions"] += 1

    print(f"\n{'Model':<30} {'Input':>10} {'Output':>10} {'Sessions':>10}")
    print(f"{'-'*30} {'-'*10} {'-'*10} {'-'*10}")
    for model, t in sorted(model_totals.items(), key=lambda x: x[1]["input"], reverse=True):
        print(f"{model:<30} {format_tokens(t['input']):>10} {format_tokens(t['output']):>10} {t['sessions']:>10}")

    # Daily breakdown
    daily = defaultdict(lambda: {"input": 0, "output": 0, "sessions": 0, "tools": 0})
    for s in sessions:
        day = s["start"][:10]
        daily[day]["input"] += s["total_input"]
        daily[day]["output"] += s["total_output"]
        daily[day]["sessions"] += 1
        daily[day]["tools"] += s["tool_calls"]

    print(f"\n{'Date':<12} {'Sessions':>10} {'Input':>10} {'Output':>10} {'Tools':>8}")
    print(f"{'-'*12} {'-'*10} {'-'*10} {'-'*10} {'-'*8}")
    for day in sorted(daily.keys(), reverse=True):
        d = daily[day]
        print(f"{day:<12} {d['sessions']:>10} {format_tokens(d['input']):>10} {format_tokens(d['output']):>10} {d['tools']:>8}")

    print()


if __name__ == "__main__":
    main()
