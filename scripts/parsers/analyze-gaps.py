#!/usr/bin/env python3
"""
Gap Analysis Tool

Reads all annotated JSON files from docs/parity/annotated/ and produces a
deduplicated, table-grouped analysis of NOT_FOUND and FOUND_PARTIAL behaviors.

For each table group it:
  1. Shows every unique V1 write behavior touching that table
  2. Lists which V2 Go files reference the table (if any)
  3. Applies auto-classification rules
  4. Reads/writes verdicts from gap-classifications.json

Verdicts:
  TRUE_GAP      - genuinely missing from V2, needs implementation
  INTENTIONAL   - deliberately absent (feature removed or out of V2 scope)
  BATCH_ONLY    - cron/batch operation, not API behaviour
  DIFFERENT_IMPL - V2 covers this differently (different column, event-driven, etc.)
  DEFERRED      - known gap, accepted for now
  FALSE_POSITIVE - tool limitation producing wrong result

Usage:
  python3 scripts/parsers/analyze-gaps.py [--unclassified-only]
"""

import json
import re
import sys
from pathlib import Path
from collections import defaultdict

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
ANNOTATED_DIR = REPO_ROOT / 'docs' / 'parity' / 'annotated'
CLASSIFICATIONS_FILE = REPO_ROOT / 'scripts' / 'parsers' / 'gap-classifications.json'
GO_ROOT = REPO_ROOT / 'iznik-server-go'
V1_ROOT = REPO_ROOT / 'iznik-server'

UNCLASSIFIED_ONLY = '--unclassified-only' in sys.argv

# ---------------------------------------------------------------------------
# Auto-classification rules applied before manual review
# ---------------------------------------------------------------------------

# Tables known to be batch/newsletter/cron only — not V2 API behaviour
BATCH_TABLES = {
    'newsletters', 'newsletters_articles',
    'returnpath_seedlist',
    'jobs_keywords',
}

# Tables belonging to features explicitly not migrated to V2
INTENTIONAL_TABLES = {
    'polls', 'polls_users',           # SKIP:no-v2 in mapping
    'bulkop',                         # SKIP:no-v2
    'invitation', 'invitations',      # SKIP:no-v2
    'mentions',                       # SKIP:no-v2
    'request',                        # SKIP:no-v2
}

# SQL keywords / patterns that indicate PostgreSQL/PostGIS batch operations
# (should have been filtered by $pgsql variable check, but catch any stragglers)
POSTGIS_PATTERNS = re.compile(
    r'ST_|pg_type|postgis|location_type|locations_tmp|CREATE EXTENSION|CREATE TYPE|CREATE INDEX ON',
    re.IGNORECASE
)


def auto_classify_table(table: str, behaviors: list) -> tuple[str, str] | tuple[None, None]:
    """Return (verdict, reason) or (None, None) if no auto-classification applies."""
    tl = table.lower()

    if table in INTENTIONAL_TABLES:
        return 'INTENTIONAL', 'Endpoint explicitly SKIP:no-v2 in mapping'

    if table in BATCH_TABLES:
        return 'BATCH_ONLY', 'Table used only by batch/newsletter operations, not API'

    # PostGIS straggler (called on $this not $pgsql but still spatial batch)
    if any(POSTGIS_PATTERNS.search(b['description']) for b in behaviors):
        return 'BATCH_ONLY', 'PostGIS/spatial batch operation not part of V2 API'

    return None, None


def auto_classify_column(table: str, column: str) -> tuple[str, str] | tuple[None, None]:
    """Return (verdict, reason) or (None, None) for column-level gaps."""
    # Nothing auto-classifiable at column level without more context
    return None, None


# ---------------------------------------------------------------------------
# Load data
# ---------------------------------------------------------------------------

def load_classifications() -> dict:
    if CLASSIFICATIONS_FILE.exists():
        return json.loads(CLASSIFICATIONS_FILE.read_text())
    return {'tables': {}, 'columns': {}, 'behaviors': {}}


def save_classifications(cls: dict):
    CLASSIFICATIONS_FILE.write_text(json.dumps(cls, indent=2, sort_keys=True))


def load_all_behaviors() -> list[dict]:
    """Load all annotated JSONs, deduplicate by (category, description, file, line)."""
    seen = {}
    for path in sorted(ANNOTATED_DIR.glob('*.json')):
        endpoint = path.stem
        try:
            behaviors = json.loads(path.read_text())
        except Exception as e:
            print(f'WARN: could not load {path}: {e}', file=sys.stderr)
            continue
        for b in behaviors:
            if b['v2_status'] not in ('NOT_FOUND', 'FOUND_PARTIAL'):
                continue
            key = (b['category'], b['description'], b['file'], b['line'])
            if key not in seen:
                seen[key] = {**b, 'endpoints': [endpoint]}
            else:
                seen[key]['endpoints'].append(endpoint)

    return list(seen.values())


def v2_files_for_table(table: str) -> list[str]:
    """Return list of non-test V2 Go file paths that reference the table name."""
    pattern = re.compile(r'\b' + re.escape(table) + r'\b', re.IGNORECASE)
    hits = []
    for f in GO_ROOT.rglob('*.go'):
        if '_test.go' in f.name:
            continue
        try:
            if pattern.search(f.read_text(errors='ignore')):
                hits.append(str(f.relative_to(REPO_ROOT)))
        except Exception:
            pass
    return hits


def v1_context(file_rel: str, line: int, context: int = 3) -> str:
    """Return a few lines of V1 source around file_rel:line."""
    path = V1_ROOT / file_rel
    if not path.exists():
        return f'(source not found: {file_rel})'
    try:
        lines = path.read_text(errors='ignore').splitlines()
        start = max(0, line - context - 1)
        end = min(len(lines), line + context)
        numbered = [f'  {i+1:4d} | {lines[i]}' for i in range(start, end)]
        return '\n'.join(numbered)
    except Exception:
        return '(could not read source)'


# ---------------------------------------------------------------------------
# Main analysis
# ---------------------------------------------------------------------------

def main():
    cls = load_classifications()
    behaviors = load_all_behaviors()

    if not behaviors:
        print('No annotated JSON files found. Run run-parity-check.sh first.')
        sys.exit(1)

    # --- Group SQL behaviors by table ---
    table_groups: dict[str, list[dict]] = defaultdict(list)
    non_sql: list[dict] = []

    for b in behaviors:
        if b['category'] == 'SQL':
            m = re.search(r'\b(?:FROM|INTO|UPDATE|JOIN)\s+`?(\w+)`?',
                          b['description'], re.IGNORECASE)
            table = m.group(1) if m else '__unknown__'
            table_groups[table].append(b)
        else:
            non_sql.append(b)

    # --- Collect column gaps ---
    # For FOUND_PARTIAL: group by (table, column)
    col_gaps: dict[tuple[str, str], list[dict]] = defaultdict(list)
    for b in behaviors:
        if b['v2_status'] == 'FOUND_PARTIAL':
            m = re.search(r'\b(?:FROM|INTO|UPDATE|JOIN)\s+`?(\w+)`?',
                          b['description'], re.IGNORECASE)
            table = m.group(1) if m else '__unknown__'
            for col in b.get('missing_columns', []):
                col_gaps[(table, col)].append(b)

    # --- Build output ---
    report_lines = []
    true_gaps = []
    unclassified_count = 0

    # --- NOT_FOUND table groups ---
    not_found_groups = {t: bs for t, bs in table_groups.items()
                        if any(b['v2_status'] == 'NOT_FOUND' for b in bs)}

    report_lines.append(f'# Gap Analysis\n')
    report_lines.append(f'Unique NOT_FOUND behaviors: {sum(len(v) for v in not_found_groups.values())}')
    report_lines.append(f'Table groups: {len(not_found_groups)}')
    report_lines.append(f'Column gaps (FOUND_PARTIAL): {len(col_gaps)}\n')
    report_lines.append('---\n')

    report_lines.append('## NOT_FOUND: Table Groups\n')

    for table in sorted(not_found_groups.keys()):
        nf_behaviors = [b for b in not_found_groups[table] if b['v2_status'] == 'NOT_FOUND']
        if not nf_behaviors:
            continue

        col_key = f'tables.{table}'
        existing = cls['tables'].get(table)

        # Auto-classify if no existing verdict
        if not existing:
            verdict, reason = auto_classify_table(table, nf_behaviors)
            if verdict:
                cls['tables'][table] = {'verdict': verdict, 'reason': reason, 'auto': True}
                existing = cls['tables'][table]

        v2_files = v2_files_for_table(table) if not existing else None

        verdict_str = f"[{existing['verdict']}] {existing['reason']}" if existing else '[UNCLASSIFIED]'

        if UNCLASSIFIED_ONLY and existing:
            continue

        unclassified_count += (0 if existing else 1)

        report_lines.append(f'### Table: `{table}` — {verdict_str}')
        report_lines.append(f'Unique behaviors: {len(nf_behaviors)} | '
                            f'Endpoints affected: {len(set(e for b in nf_behaviors for e in b["endpoints"]))}')

        if v2_files is not None:
            if v2_files:
                report_lines.append(f'V2 references: {", ".join(Path(f).name for f in v2_files[:5])}')
            else:
                report_lines.append('V2 references: **NONE** — table entirely absent from V2')

        report_lines.append('')
        for b in nf_behaviors[:5]:
            desc = b['description'][:100].replace('\n', ' ')
            report_lines.append(f'  - `{b["file"]}:{b["line"]}` {desc}')
        if len(nf_behaviors) > 5:
            report_lines.append(f'  - ... and {len(nf_behaviors) - 5} more')
        report_lines.append('')

        if existing and existing['verdict'] == 'TRUE_GAP':
            true_gaps.append({'type': 'table', 'table': table,
                              'behavior_count': len(nf_behaviors),
                              'reason': existing['reason']})

    # --- FOUND_PARTIAL: column gaps ---
    report_lines.append('---\n')
    report_lines.append('## FOUND_PARTIAL: Column-Level Gaps\n')

    for (table, col) in sorted(col_gaps.keys()):
        ck = f'{table}.{col}'
        existing_col = cls['columns'].get(ck)

        if not existing_col:
            verdict, reason = auto_classify_column(table, col)
            if verdict:
                cls['columns'][ck] = {'verdict': verdict, 'reason': reason, 'auto': True}
                existing_col = cls['columns'][ck]

        verdict_str = f"[{existing_col['verdict']}] {existing_col['reason']}" if existing_col else '[UNCLASSIFIED]'

        if UNCLASSIFIED_ONLY and existing_col:
            continue

        unclassified_count += (0 if existing_col else 1)

        report_lines.append(f'### Column: `{table}.{col}` — {verdict_str}')
        bs = col_gaps[(table, col)]
        report_lines.append(f'Appears in {len(bs)} unique write behaviors')
        for b in bs[:3]:
            desc = b['description'][:100].replace('\n', ' ')
            report_lines.append(f'  - `{b["file"]}:{b["line"]}` {desc}')
        report_lines.append('')

        if existing_col and existing_col['verdict'] == 'TRUE_GAP':
            true_gaps.append({'type': 'column', 'table': table, 'column': col,
                              'reason': existing_col['reason']})

    # --- Non-SQL behaviors ---
    if non_sql:
        report_lines.append('---\n')
        report_lines.append('## Non-SQL NOT_FOUND Behaviors\n')
        for b in non_sql:
            ck = f'{b["file"]}:{b["line"]}'
            existing_b = cls['behaviors'].get(ck)
            verdict_str = f"[{existing_b['verdict']}]" if existing_b else '[UNCLASSIFIED]'
            report_lines.append(f'- {verdict_str} **{b["category"]}**: {b["description"][:80]} — `{ck}`')

    # --- Summary ---
    report_lines.append('\n---\n')
    report_lines.append('## Summary\n')
    report_lines.append(f'- Unclassified items: **{unclassified_count}**')
    report_lines.append(f'- TRUE_GAP items: **{len(true_gaps)}**')
    if true_gaps:
        report_lines.append('\n### TRUE_GAPs')
        for g in true_gaps:
            if g['type'] == 'table':
                report_lines.append(f'  - Table `{g["table"]}` ({g["behavior_count"]} behaviors): {g["reason"]}')
            else:
                report_lines.append(f'  - Column `{g["table"]}.{g["column"]}`: {g["reason"]}')

    # Save updated classifications
    save_classifications(cls)

    print('\n'.join(report_lines))
    print(f'\nClassifications saved to {CLASSIFICATIONS_FILE}', file=sys.stderr)


if __name__ == '__main__':
    main()
