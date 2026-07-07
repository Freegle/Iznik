#!/bin/bash
# SessionStart hook: Shows running token savings summary as proportion of total usage.
# Reads savings from ~/.claude/token-savings-totals.tsv
# Reads actual usage from session transcripts via quick scan.

TOTALS_FILE="$HOME/.claude/token-savings-totals.tsv"
TRANSCRIPT_DIR="$HOME/.claude/projects/-home-edward-FreegleDockerWSL"

if [ ! -f "$TOTALS_FILE" ]; then
  exit 0
fi

# Get actual total token usage from recent transcripts
# Uses input + cache_write + output as the billable total (cache_read is discounted)
ACTUAL_USAGE=$(python3 -c "
import json, os, glob
from datetime import datetime, timedelta, timezone

cutoff = (datetime.now(timezone.utc) - timedelta(days=14)).timestamp()
total_input = 0
total_output = 0
total_cache_write = 0
files = glob.glob('$TRANSCRIPT_DIR/*.jsonl')
for f in files:
    if os.path.getmtime(f) < cutoff:
        continue
    with open(f) as fh:
        for line in fh:
            try:
                d = json.loads(line)
                if d.get('type') != 'assistant': continue
                u = d.get('message', {}).get('usage', {})
                if u:
                    total_input += u.get('input_tokens', 0)
                    total_output += u.get('output_tokens', 0)
                    total_cache_write += u.get('cache_creation_input_tokens', 0)
            except: pass
total = total_input + total_cache_write + total_output
print(f'{total_input}\t{total_output}\t{total}\t{total_cache_write}')
" 2>/dev/null)

ACTUAL_INPUT=$(echo "$ACTUAL_USAGE" | cut -f1)
ACTUAL_OUTPUT=$(echo "$ACTUAL_USAGE" | cut -f2)
ACTUAL_TOTAL=$(echo "$ACTUAL_USAGE" | cut -f3)

# Aggregate savings and show proportion of actual usage
SUMMARY=$(python3 -c "
import sys
actual_total = int('${ACTUAL_TOTAL:-0}' or '0')
totals_file = '$TOTALS_FILE'

from collections import defaultdict
daily = defaultdict(lambda: {'tokens': 0, 'saveable': 0, 'count': 0})
total_tokens = total_saveable = total_count = 0

with open(totals_file) as f:
    next(f)  # skip header
    for line in f:
        parts = line.strip().split('\t')
        if len(parts) < 4: continue
        date, tok, sav, cnt = parts[0], int(parts[1]), int(parts[2]), int(parts[3])
        daily[date]['tokens'] += tok
        daily[date]['saveable'] += sav
        daily[date]['count'] += cnt
        total_tokens += tok
        total_saveable += sav
        total_count += cnt

if total_count == 0:
    sys.exit(0)

def fmt(n):
    if n >= 1_000_000_000: return f'{n/1e9:.1f}B'
    if n >= 1_000_000: return f'{n/1e6:.1f}M'
    if n >= 1_000: return f'{n/1e3:.0f}K'
    return str(n)

pct = f'{total_saveable*100/actual_total:.1f}%' if actual_total > 0 else '?'
cost = total_saveable / 1000 * 0.015
print(f'Token savings: {fmt(total_saveable)} saveable of {fmt(actual_total)} total usage ({pct}) from {total_count} large outputs (~\${cost:.2f})')

dates = sorted(daily.keys())[-3:]
for d in dates:
    s = daily[d]
    print(f'  {d}: {s[\"count\"]} events, {fmt(s[\"saveable\"])} saveable')
" 2>/dev/null)

if [ -n "$SUMMARY" ]; then
  echo "$SUMMARY"
fi

exit 0
