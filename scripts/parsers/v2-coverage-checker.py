#!/usr/bin/env python3
"""
V2 Coverage Checker

Reads a JSON behavior ledger (output of v1-behavior-extractor.php) and a V2
Go package directory. For each behavior, searches the Go source for evidence
of implementation, then writes an annotated JSON ledger to stdout.

Usage:
    python3 v2-coverage-checker.py <ledger.json> <go-package-dir>

Output: JSON to stdout with v2_status: FOUND | NOT_FOUND | UNCERTAIN | FOUND_PARTIAL
  FOUND_PARTIAL means the table is referenced in V2 but specific columns written
  by V1 are absent from V2 source entirely (possible missing writes).
"""

import json
import re
import sys
from pathlib import Path


# Column names too generic to be meaningful signals on their own.
# These appear in nearly every table and across all Go files.
_GENERIC_COLS = {
    'id', 'userid', 'groupid', 'msgid', 'chatid', 'threadid', 'postid',
    'timestamp', 'created', 'updated', 'modified', 'date', 'time',
    'active', 'deleted', 'hidden', 'type', 'name', 'email', 'role',
    'count', 'value', 'data', 'text', 'status', 'result', 'state',
    'position', 'order', 'key', 'url', 'ip', 'host', 'port', 'path',
    'subject', 'body', 'message', 'content', 'title', 'description',
    'lat', 'lng', 'location', 'address', 'country', 'city',
    'start', 'end', 'from', 'to', 'source', 'target',
    'systemwide', 'heldby', 'pending', 'complete', 'response',
}


def go_files(go_root: str):
    """Yield non-test .go file paths under go_root."""
    for f in Path(go_root).rglob('*.go'):
        if '_test.go' not in f.name:
            yield f


def search(go_root: str, pattern: str, flags: int = re.IGNORECASE) -> bool:
    """Return True if pattern matches in any non-test .go file under go_root."""
    compiled = re.compile(pattern, flags)
    for f in go_files(go_root):
        try:
            if compiled.search(f.read_text(errors='ignore')):
                return True
        except Exception:
            pass
    return False


def extract_table(sql_desc: str) -> str:
    """Extract table name from SQL description string."""
    m = re.search(
        r'\b(?:FROM|INTO|UPDATE|JOIN)\s+`?(\w+)`?',
        sql_desc,
        re.IGNORECASE,
    )
    return m.group(1) if m else ''


def extract_write_columns(sql_desc: str) -> list[str]:
    """
    Extract column names being written in an UPDATE/INSERT/REPLACE statement.
    Returns only non-generic columns worth checking in V2.
    Strips the leading 'preExec: ' / 'preQuery: ' method prefix first.
    """
    sql = re.sub(r'^\w+:\s*', '', sql_desc, count=1)

    # UPDATE table SET col1 = ..., col2 = ... [WHERE ...]
    m = re.match(
        r'UPDATE\s+`?\w+`?\s+SET\s+(.+?)(?:\s+WHERE\b|$)',
        sql, re.IGNORECASE | re.DOTALL
    )
    if m:
        set_clause = m.group(1)
        cols = re.findall(r'`?(\w+)`?\s*=', set_clause)
        return [c for c in cols if c.lower() not in _GENERIC_COLS]

    # INSERT [IGNORE] INTO table (col1, col2) VALUES ...
    # REPLACE INTO table (col1, col2) VALUES ...
    m = re.match(
        r'(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+`?\w+`?\s*\(([^)]+)\)',
        sql, re.IGNORECASE
    )
    if m:
        cols = [c.strip().strip('`') for c in m.group(1).split(',')]
        return [c for c in cols if c and c.lower() not in _GENERIC_COLS]

    return []


def check(behavior: dict, go_root: str) -> tuple[str, list[str]]:
    """
    Returns (v2_status, missing_columns).
    missing_columns is non-empty only when v2_status is FOUND_PARTIAL.
    """
    category = behavior['category']
    desc = behavior['description']

    if category == 'Queue':
        return 'FOUND', []
    # V1 constructs Swift Mailer inline; V2 uses background_tasks queue.
    # These V1 patterns are intentionally absent in V2.
    if category == 'Email' and desc in ('getMailer', 'Mail::getMailer'):
        return 'FOUND', []

    if category == 'SQL':
        table = extract_table(desc)
        if not table:
            return 'UNCERTAIN', []
        if not search(go_root, r'\b' + re.escape(table) + r'\b'):
            return 'NOT_FOUND', []

        # Table is present in V2 — check specific write columns.
        write_cols = extract_write_columns(desc)
        missing = [c for c in write_cols
                   if not search(go_root, r'\b' + re.escape(c) + r'\b')]
        if missing:
            return 'FOUND_PARTIAL', missing
        return 'FOUND', []

    if category == 'Email':
        found = search(go_root, r'background_tasks|email_|QueueTask')
        return ('FOUND' if found else 'NOT_FOUND'), []

    if category == 'Push':
        found = search(go_root, r'background_tasks|push_|QueueTask')
        return ('FOUND' if found else 'NOT_FOUND'), []

    if category == 'AuditLog':
        found = search(go_root, r'INSERT INTO logs|log\.LOG_TYPE_')
        return ('FOUND' if found else 'NOT_FOUND'), []

    if category == 'HTTP':
        found = search(go_root, r'http\.(Get|Post|Do)\(')
        return ('FOUND' if found else 'NOT_FOUND'), []

    return 'UNCERTAIN', []


def main():
    if len(sys.argv) < 3:
        print('Usage: v2-coverage-checker.py <ledger.json> <go-package-dir>', file=sys.stderr)
        sys.exit(1)

    ledger_path = sys.argv[1]
    go_root = sys.argv[2]

    if not Path(ledger_path).exists():
        print(f'Ledger not found: {ledger_path}', file=sys.stderr)
        sys.exit(1)

    if not Path(go_root).is_dir():
        print(f'Go package dir not found: {go_root}', file=sys.stderr)
        sys.exit(1)

    with open(ledger_path) as f:
        behaviors = json.load(f)

    for b in behaviors:
        status, missing_cols = check(b, go_root)
        b['v2_status'] = status
        if missing_cols:
            b['missing_columns'] = missing_cols

    print(json.dumps(behaviors, indent=2))


if __name__ == '__main__':
    main()
