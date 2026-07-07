#!/usr/bin/env python3
"""Estimate token savings from parser hooks across recent Claude sessions.

Analyses session transcripts to calculate how many tokens would have been
saved if parser scripts had been used for large tool outputs.

Usage:
    python3 scripts/parsers/estimate-savings.py [--days 7]
"""

import json
import os
import glob
import argparse
from collections import defaultdict
from datetime import datetime, timedelta, timezone

TRANSCRIPT_DIR = os.path.expanduser(
    "~/.claude/projects/-home-edward-FreegleDockerWSL"
)
REGISTRY = os.path.join(os.path.dirname(__file__), "registry.json")

# Token estimation: ~4 chars per token for English text, ~1.5 chars per token for base64
CHARS_PER_TOKEN_TEXT = 4
CHARS_PER_TOKEN_BASE64 = 1.5

# Cost per token (Opus input, approximate)
COST_PER_1K_INPUT_TOKENS = 0.015


def load_registry():
    with open(REGISTRY) as f:
        return json.load(f)


def classify_output(tool_name, command, output_size, output_text):
    """Classify a tool output and estimate savings."""
    # Screenshot/image detection
    if "image" in output_text[:200] or "iVBOR" in output_text[:200]:
        return {
            "category": "screenshot",
            "tokens": int(output_size / CHARS_PER_TOKEN_BASE64),
            "savings_pct": 95,  # Use take_snapshot instead
            "suggestion": "Use take_snapshot (DOM) or resize viewport",
        }

    tokens = output_size // CHARS_PER_TOKEN_TEXT

    if tool_name == "Bash":
        if "git log" in command:
            return {"category": "git-log", "tokens": tokens, "savings_pct": 80, "suggestion": "parse-git-log.sh"}
        if "docker logs" in command:
            return {"category": "docker-logs", "tokens": tokens, "savings_pct": 90, "suggestion": "parse-docker-logs.sh"}
        if any(t in command for t in ["phpunit", "go test", "vitest", "playwright"]):
            return {"category": "test-output", "tokens": tokens, "savings_pct": 85, "suggestion": "parse-test-output.sh"}
        if "grep -r" in command or "rg " in command:
            return {"category": "grep-results", "tokens": tokens, "savings_pct": 70, "suggestion": "parse-grep-results.sh"}
        if "circle" in command.lower() or "circleci" in command.lower():
            return {"category": "ci-output", "tokens": tokens, "savings_pct": 80, "suggestion": "pipe through parser or | head"}
        if "curl" in command and "loki" in command.lower() or "3100" in command:
            return {"category": "loki-logs", "tokens": tokens, "savings_pct": 85, "suggestion": "add limit= param"}
        return {"category": "bash-other", "tokens": tokens, "savings_pct": 50, "suggestion": "pipe through head or custom parser"}

    if tool_name == "Read":
        return {"category": "large-file", "tokens": tokens, "savings_pct": 90, "suggestion": "parse-large-file.sh or use limit/offset"}

    if tool_name == "Grep":
        return {"category": "grep-results", "tokens": tokens, "savings_pct": 70, "suggestion": "use head_limit param"}

    if tool_name == "Agent":
        return {"category": "agent-result", "tokens": tokens, "savings_pct": 40, "suggestion": "instruct agent to be more concise"}

    return {"category": "other", "tokens": tokens, "savings_pct": 30, "suggestion": "unknown"}


def analyse_sessions(days):
    cutoff = datetime.now(timezone.utc) - timedelta(days=days)
    files = glob.glob(os.path.join(TRANSCRIPT_DIR, "*.jsonl"))

    categories = defaultdict(lambda: {"count": 0, "total_tokens": 0, "saveable_tokens": 0})
    total_large = 0
    total_tokens_wasted = 0
    total_tokens_saveable = 0

    for filepath in files:
        mtime = datetime.fromtimestamp(os.path.getmtime(filepath), tz=timezone.utc)
        if mtime < cutoff:
            continue

        prev_tool_uses = {}
        with open(filepath) as f:
            for line in f:
                try:
                    d = json.loads(line)
                except (json.JSONDecodeError, UnicodeDecodeError):
                    continue

                msg = d.get("message", {})
                if not isinstance(msg, dict):
                    continue
                content = msg.get("content", [])
                if not isinstance(content, list):
                    continue

                for b in content:
                    if not isinstance(b, dict):
                        continue
                    if b.get("type") == "tool_use":
                        prev_tool_uses[b.get("id", "")] = {
                            "name": b.get("name", "unknown"),
                            "input": b.get("input", {}),
                        }
                    elif b.get("type") == "tool_result":
                        tid = b.get("tool_use_id", "")
                        tool_info = prev_tool_uses.get(tid, {"name": "unknown", "input": {}})
                        c = b.get("content", "")
                        output_text = json.dumps(c) if not isinstance(c, str) else c
                        output_size = len(output_text)

                        if output_size < 10000:  # Only count >10KB
                            continue

                        command = ""
                        inp = tool_info.get("input", {})
                        if isinstance(inp, dict):
                            command = inp.get("command", inp.get("file_path", ""))

                        result = classify_output(tool_info["name"], str(command), output_size, output_text[:500])

                        cat = result["category"]
                        categories[cat]["count"] += 1
                        categories[cat]["total_tokens"] += result["tokens"]
                        categories[cat]["saveable_tokens"] += int(result["tokens"] * result["savings_pct"] / 100)

                        total_large += 1
                        total_tokens_wasted += result["tokens"]
                        total_tokens_saveable += int(result["tokens"] * result["savings_pct"] / 100)

    return categories, total_large, total_tokens_wasted, total_tokens_saveable


def format_tokens(n):
    if n >= 1_000_000:
        return f"{n / 1_000_000:.1f}M"
    if n >= 1_000:
        return f"{n / 1_000:.0f}K"
    return str(n)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--days", type=int, default=14)
    args = parser.parse_args()

    categories, total_large, total_wasted, total_saveable = analyse_sessions(args.days)

    print(f"\n{'=' * 90}")
    print(f"  Token Savings Estimate — last {args.days} days — {total_large} large tool outputs (>10KB)")
    print(f"{'=' * 90}\n")

    print(f"{'Category':<20} {'Count':>6} {'Tokens Used':>12} {'Saveable':>12} {'Savings %':>10} {'Est. Cost Saved':>15}")
    print(f"{'-' * 20} {'-' * 6} {'-' * 12} {'-' * 12} {'-' * 10} {'-' * 15}")

    for cat, s in sorted(categories.items(), key=lambda x: x[1]["saveable_tokens"], reverse=True):
        pct = (s["saveable_tokens"] / max(s["total_tokens"], 1)) * 100
        cost = s["saveable_tokens"] / 1000 * COST_PER_1K_INPUT_TOKENS
        print(
            f"{cat:<20} {s['count']:>6} {format_tokens(s['total_tokens']):>12} "
            f"{format_tokens(s['saveable_tokens']):>12} {pct:>9.0f}% ${cost:>13.2f}"
        )

    total_cost = total_saveable / 1000 * COST_PER_1K_INPUT_TOKENS
    print(f"\n{'TOTAL':<20} {total_large:>6} {format_tokens(total_wasted):>12} {format_tokens(total_saveable):>12} "
          f"{(total_saveable / max(total_wasted, 1) * 100):>9.0f}% ${total_cost:>13.2f}")

    print(f"\n  Note: Token counts are estimates (~4 chars/token text, ~1.5 chars/token base64)")
    print(f"  Cost estimate uses ${COST_PER_1K_INPUT_TOKENS}/1K input tokens (Opus)")

    # Show top recommendations
    print(f"\n  Top recommendations:")
    for cat, s in sorted(categories.items(), key=lambda x: x[1]["saveable_tokens"], reverse=True)[:5]:
        if s["saveable_tokens"] > 1000:
            print(f"    - {cat}: {format_tokens(s['saveable_tokens'])} tokens saveable across {s['count']} calls")

    print()


if __name__ == "__main__":
    main()
