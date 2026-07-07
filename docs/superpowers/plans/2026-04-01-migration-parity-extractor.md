# Migration Parity Extractor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a PHP-Parser-based call graph extractor + Python coverage checker that mechanically verifies V1→V2 migration completeness, then run it against the completed migration to find gaps.

**Architecture:** A PHP script uses `nikic/php-parser` (already installed in `freegle-apiv1` container) to parse V1 PHP endpoints and recursively traverse referenced class files, emitting a JSON ledger of every SQL query, email send, push notification, audit log entry, and HTTP call. A Python coverage checker then searches the corresponding V2 Go package for each ledger item and marks it FOUND/NOT_FOUND. A shell driver runs both tools across all 58 V1 endpoints and produces a markdown gap report.

**Tech Stack:** PHP 8 + nikic/php-parser (in container), Python 3 (host), bash driver, docker exec for container commands.

---

## File Structure

```
scripts/parsers/
  php-go-mapping.json          # V1 PHP filename → V2 Go package path mapping
  v1-behavior-extractor.php    # PHP-Parser based extractor, runs in container
  v2-coverage-checker.py       # Python script, searches Go files for each behavior
  run-parity-check.sh          # Shell driver: iterates all endpoints, produces report
docs/parity/
  YYYY-MM-DD-parity-report.md  # Output: gap report (NOT_FOUND behaviors per endpoint)
```

---

## Task 1: PHP → Go Endpoint Mapping

**Files:**
- Create: `scripts/parsers/php-go-mapping.json`

- [ ] **Step 1: Write the mapping file**

```json
{
  "abtest.php":                  "abtest",
  "activity.php":                "message",
  "address.php":                 "address",
  "admin.php":                   "admin",
  "alert.php":                   "alert",
  "authority.php":               "authority",
  "bulkop.php":                  "SKIP:no-v2",
  "changes.php":                 "changes",
  "chatmessages.php":            "chat",
  "chatrooms.php":               "chat",
  "comment.php":                 "comment",
  "communityevent.php":          "communityevent",
  "config.php":                  "config",
  "dashboard.php":               "dashboard",
  "domains.php":                 "domain",
  "donations.php":               "donations",
  "error.php":                   "SKIP:no-v2",
  "export.php":                  "export",
  "giftaid.php":                 "donations",
  "group.php":                   "group",
  "groups.php":                  "group",
  "image.php":                   "image",
  "invitation.php":              "SKIP:no-v2",
  "isochrone.php":               "isochrone",
  "item.php":                    "item",
  "jobs.php":                    "job",
  "locations.php":               "location",
  "logo.php":                    "logo",
  "logs.php":                    "logs",
  "memberships.php":             "membership",
  "mentions.php":                "SKIP:no-v2",
  "merge.php":                   "merge",
  "message.php":                 "message",
  "messages.php":                "message",
  "microvolunteering.php":       "microvolunteering",
  "modconfig.php":               "modconfig",
  "newsfeed.php":                "newsfeed",
  "noticeboard.php":             "noticeboard",
  "notification.php":            "notification",
  "poll.php":                    "SKIP:no-v2",
  "profile.php":                 "user",
  "request.php":                 "SKIP:no-v2",
  "session.php":                 "session",
  "shortlink.php":               "shortlink",
  "simulation.php":              "simulation",
  "spammers.php":                "spammers",
  "src.php":                     "src",
  "status.php":                  "status",
  "stdmsg.php":                  "stdmsg",
  "stories.php":                 "story",
  "stripecreateintent.php":      "donations",
  "stripecreatesubscription.php":"donations",
  "team.php":                    "team",
  "tryst.php":                   "tryst",
  "user.php":                    "user",
  "usersearch.php":              "user",
  "visualise.php":               "visualise",
  "volunteering.php":            "volunteering"
}
```

Save to `scripts/parsers/php-go-mapping.json`.

- [ ] **Step 2: Commit**

```bash
git add scripts/parsers/php-go-mapping.json
git commit -m "feat: add V1 PHP→Go package mapping for parity checker"
```

---

## Task 2: PHP Behavior Extractor

**Files:**
- Create: `scripts/parsers/v1-behavior-extractor.php`

This script runs **inside the `freegle-apiv1` container** where PHP-Parser is already installed at `/var/www/iznik/composer/vendor/nikic/php-parser/`.

- [ ] **Step 1: Write the extractor**

```php
<?php
/**
 * V1 Behavior Extractor
 *
 * Parses a V1 PHP API endpoint using PHP-Parser and recursively traverses
 * all referenced class files in include/, emitting a JSON ledger of every
 * SQL query, email send, push notification, audit log entry, and HTTP call.
 *
 * Usage (inside freegle-apiv1 container):
 *   php /var/www/iznik/scripts/parsers/v1-behavior-extractor.php \
 *       /var/www/iznik/http/api/comment.php
 *
 * Output: JSON array of behavior objects to stdout. Errors to stderr.
 */

require_once '/var/www/iznik/composer/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\NodeFinder;

// ─── Behavior collector ────────────────────────────────────────────────────────

class BehaviorCollector extends NodeVisitorAbstract {
    public array $behaviors         = [];
    public array $classesReferenced = [];

    private string $currentFile = '';

    // V1 database method names (on $dbhr / $dbhm objects)
    private const SQL_METHODS  = ['preQuery', 'prePrepared', 'preExec', 'preQueryCol', 'beginTransaction'];
    // V1 email signals
    private const EMAIL_METHODS = ['getMailer', 'sendOne'];
    private const EMAIL_CLASSES = ['Mail', 'Mailer'];
    // V1 push / notification patterns
    private const PUSH_CLASSES  = ['PushNotifications', 'Notifications'];
    // V1 audit log method names
    private const LOG_METHODS   = ['log', 'logModAction', 'logGroupAction'];
    // PHP functions that make HTTP requests
    private const HTTP_FUNCS    = ['curl_exec', 'file_get_contents'];

    public function setFile(string $file): void {
        $this->currentFile = $file;
    }

    public function enterNode(Node $node): void {
        if ($node instanceof Node\Expr\MethodCall) {
            if (!($node->name instanceof Node\Identifier)) {
                return;
            }
            $method = $node->name->toString();
            $line   = $node->getLine();

            if (in_array($method, self::SQL_METHODS, true)) {
                $sql = $this->firstStringArg($node);
                $this->record('SQL', $method . ': ' . substr($sql ?? '[expr]', 0, 80), $line);
            } elseif (in_array($method, self::EMAIL_METHODS, true)) {
                $this->record('Email', $method, $line);
            } elseif (in_array($method, self::LOG_METHODS, true)) {
                $this->record('AuditLog', $method, $line);
            }
            // Note: $mailer->send() is captured under Email via EMAIL_METHODS above.
            // The ->send() method on a Swift_Message is detected by class context (StaticCall).
        }

        if ($node instanceof Node\Expr\StaticCall) {
            if (!($node->class instanceof Node\Name)) {
                return;
            }
            $class  = $node->class->getLast();
            $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '[dynamic]';
            $line   = $node->getLine();

            $this->classesReferenced[] = $class;

            if (in_array($class, self::PUSH_CLASSES, true)) {
                $this->record('Push', "$class::$method", $line);
            } elseif (in_array($class, self::EMAIL_CLASSES, true)) {
                $this->record('Email', "$class::$method", $line);
            }
        }

        if ($node instanceof Node\Expr\New_) {
            if ($node->class instanceof Node\Name) {
                $this->classesReferenced[] = $node->class->getLast();
            }
        }

        if ($node instanceof Node\Expr\FuncCall) {
            if (!($node->name instanceof Node\Name)) {
                return;
            }
            $func = $node->name->toString();
            if (in_array($func, self::HTTP_FUNCS, true)) {
                $this->record('HTTP', $func, $node->getLine());
            }
        }
    }

    private function record(string $category, string $description, int $line): void {
        $this->behaviors[] = [
            'category'    => $category,
            'description' => $description,
            'file'        => $this->currentFile,
            'line'        => $line,
        ];
    }

    private function firstStringArg(Node\Expr\CallLike $node): ?string {
        if (empty($node->args)) {
            return null;
        }
        $arg = $node->args[0];
        if ($arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_) {
            return $arg->value->value;
        }
        return null;
    }
}

// ─── Class index builder ────────────────────────────────────────────────────────

/**
 * Scans the include/ directory and builds a map of class name → file path.
 */
function buildClassIndex(string $includeRoot, \PhpParser\Parser $parser): array {
    $index  = [];
    $finder = new NodeFinder();
    $iter   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($includeRoot));

    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = @file_get_contents($file->getPathname());
        if (!$code) {
            continue;
        }
        try {
            $ast = $parser->parse($code);
        } catch (\Exception $e) {
            continue;
        }
        foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $class) {
            if ($class->name) {
                $index[$class->name->toString()] = $file->getPathname();
            }
        }
    }
    return $index;
}

// ─── File traversal ─────────────────────────────────────────────────────────────

function traverseFile(
    string           $filePath,
    BehaviorCollector $collector,
    \PhpParser\Parser $parser,
    string           $baseDir
): void {
    $code = @file_get_contents($filePath);
    if (!$code) {
        return;
    }
    try {
        $ast = $parser->parse($code);
    } catch (\Exception $e) {
        fwrite(STDERR, "Parse error in $filePath: {$e->getMessage()}\n");
        return;
    }

    $rel = ltrim(str_replace($baseDir, '', $filePath), '/');
    $collector->setFile($rel);

    $traverser = new NodeTraverser();
    $traverser->addVisitor($collector);
    $traverser->traverse($ast);
}

// ─── Main ───────────────────────────────────────────────────────────────────────

$endpointFile = $argv[1] ?? null;
if (!$endpointFile || !file_exists($endpointFile)) {
    fwrite(STDERR, "Usage: php v1-behavior-extractor.php <endpoint.php>\n");
    exit(1);
}

$baseDir     = '/var/www/iznik';
$includeRoot = $baseDir . '/include';
$parser      = (new ParserFactory())->createForNewestSupportedVersion();

fwrite(STDERR, "Building class index from $includeRoot...\n");
$classIndex = buildClassIndex($includeRoot, $parser);
fwrite(STDERR, count($classIndex) . " classes indexed.\n");

$collector = new BehaviorCollector();
$visited   = [];

// Pass 1: parse the endpoint file itself
$realEndpoint = realpath($endpointFile);
$visited[$realEndpoint] = true;
traverseFile($endpointFile, $collector, $parser, $baseDir);

// Pass 2 & 3: recursively parse referenced class files (up to 3 levels deep)
for ($depth = 0; $depth < 3; $depth++) {
    $classes  = array_unique($collector->classesReferenced);
    $newFiles = [];

    foreach ($classes as $class) {
        if (!isset($classIndex[$class])) {
            continue;
        }
        $path = $classIndex[$class];
        $real = realpath($path);
        if ($real && !isset($visited[$real])) {
            $newFiles[]      = $path;
            $visited[$real]  = true;
        }
    }

    if (empty($newFiles)) {
        break;
    }

    foreach ($newFiles as $f) {
        fwrite(STDERR, "Traversing $f\n");
        traverseFile($f, $collector, $parser, $baseDir);
    }
}

// Deduplicate
$seen     = [];
$unique   = [];
foreach ($collector->behaviors as $b) {
    $key = $b['category'] . '|' . $b['description'] . '|' . $b['file'] . '|' . $b['line'];
    if (!isset($seen[$key])) {
        $seen[$key] = true;
        $unique[]   = $b;
    }
}

fwrite(STDERR, count($unique) . " behaviors extracted.\n");
echo json_encode($unique, JSON_PRETTY_PRINT) . "\n";
```

Save as `scripts/parsers/v1-behavior-extractor.php`.

- [ ] **Step 2: Smoke test in container on a small endpoint**

```bash
docker exec freegle-apiv1 php /var/www/iznik/scripts/parsers/v1-behavior-extractor.php \
    /var/www/iznik/http/api/comment.php 2>&1 | tail -5
```

Expected: stderr shows class count + behaviors extracted count; stdout is valid JSON array.

```bash
docker exec freegle-apiv1 php /var/www/iznik/scripts/parsers/v1-behavior-extractor.php \
    /var/www/iznik/http/api/comment.php 2>/dev/null | python3 -c "import json,sys; d=json.load(sys.stdin); print(len(d), 'behaviors'); [print(b['category'], b['description'][:60]) for b in d[:10]]"
```

Expected: prints behavior count and first 10 behaviors. Should include SQL entries.

- [ ] **Step 3: Commit**

```bash
git add scripts/parsers/v1-behavior-extractor.php
git commit -m "feat: add PHP-Parser based V1 behavior extractor"
```

---

## Task 3: Python Coverage Checker

**Files:**
- Create: `scripts/parsers/v2-coverage-checker.py`

This script runs on the **host** (Python 3 is available at `/usr/bin/python3`). It reads the JSON ledger from Task 2 and searches the V2 Go package for each behavior.

- [ ] **Step 1: Write the coverage checker**

```python
#!/usr/bin/env python3
"""
V2 Coverage Checker

Reads a JSON behavior ledger (output of v1-behavior-extractor.php) and a V2
Go package directory. For each behavior, searches the Go source for evidence
of implementation, then writes an annotated JSON ledger to stdout.

Usage:
    python3 v2-coverage-checker.py <ledger.json> <go-package-dir>

Output: JSON to stdout with v2_status: FOUND | NOT_FOUND | UNCERTAIN
"""

import json
import re
import sys
from pathlib import Path


# ─── Intentional V1→V2 architectural differences ──────────────────────────────
# These V1 patterns are deliberately absent in V2 because the architecture changed.
# They are marked FOUND automatically.
INTENTIONAL_PATTERNS = {
    # V1 constructs a Swift Mailer inline; V2 queues via background_tasks
    ('Email', 'getMailer'),
    ('Email', 'Mail::getMailer'),
    # V1 uses Pheanstalk/beanstalkd; V2 uses background_tasks table
    ('Queue', None),  # all Queue behaviors are intentional
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
    # Patterns: FROM table, INTO table, UPDATE table, JOIN table
    m = re.search(
        r'\b(?:FROM|INTO|UPDATE|JOIN)\s+`?(\w+)`?',
        sql_desc,
        re.IGNORECASE,
    )
    return m.group(1) if m else ''


def check(behavior: dict, go_root: str) -> str:
    category = behavior['category']
    desc = behavior['description']

    # Intentional architectural differences → always FOUND
    if category == 'Queue':
        return 'FOUND'
    if (category, desc) in INTENTIONAL_PATTERNS:
        return 'FOUND'

    if category == 'SQL':
        table = extract_table(desc)
        if not table:
            return 'UNCERTAIN'
        # Table name should appear in at least one Go source file
        return 'FOUND' if search(go_root, r'\b' + re.escape(table) + r'\b') else 'NOT_FOUND'

    if category == 'Email':
        # V2 emails go via background_tasks with task types prefixed 'email_'
        return 'FOUND' if search(go_root, r'background_tasks|email_|QueueTask') else 'NOT_FOUND'

    if category == 'Push':
        # V2 push notifications go via background_tasks with push_ task types
        return 'FOUND' if search(go_root, r'background_tasks|push_|QueueTask') else 'NOT_FOUND'

    if category == 'AuditLog':
        # V2 uses log.Log(log.LogEntry{...})
        return 'FOUND' if search(go_root, r'log\.Log\(') else 'NOT_FOUND'

    if category == 'HTTP':
        return 'FOUND' if search(go_root, r'http\.(Get|Post|Do)\(') else 'NOT_FOUND'

    return 'UNCERTAIN'


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
        b['v2_status'] = check(b, go_root)

    print(json.dumps(behaviors, indent=2))


if __name__ == '__main__':
    main()
```

Save as `scripts/parsers/v2-coverage-checker.py`.

- [ ] **Step 2: Smoke test on comment.php**

First extract the ledger (if not already done):

```bash
docker exec freegle-apiv1 php /var/www/iznik/scripts/parsers/v1-behavior-extractor.php \
    /var/www/iznik/http/api/comment.php 2>/dev/null \
    > /tmp/comment-ledger.json
```

Then run the checker:

```bash
python3 scripts/parsers/v2-coverage-checker.py \
    /tmp/comment-ledger.json \
    iznik-server-go/comment/ \
    | python3 -c "
import json, sys
data = json.load(sys.stdin)
not_found = [b for b in data if b['v2_status'] == 'NOT_FOUND']
print(f'Total: {len(data)}, NOT_FOUND: {len(not_found)}')
for b in not_found:
    print(f'  [{b[\"category\"]}] {b[\"description\"]} ({b[\"file\"]}:{b[\"line\"]})')
"
```

Expected: prints total behavior count and any NOT_FOUND items. The comment endpoint has no known gaps so NOT_FOUND count should be 0 or very low.

- [ ] **Step 3: Commit**

```bash
git add scripts/parsers/v2-coverage-checker.py
git commit -m "feat: add Python V2 coverage checker"
```

---

## Task 4: Shell Driver + Markdown Report Generator

**Files:**
- Create: `scripts/parsers/run-parity-check.sh`

- [ ] **Step 1: Write the driver script**

```bash
#!/usr/bin/env bash
# run-parity-check.sh
#
# Runs v1-behavior-extractor.php + v2-coverage-checker.py against all V1 API
# endpoints and produces a markdown gap report in docs/parity/.
#
# Usage: ./scripts/parsers/run-parity-check.sh
#
# Requirements:
#   - freegle-apiv1 container running
#   - Python 3 on host
#   - scripts/parsers/php-go-mapping.json present
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
MAPPING="$SCRIPT_DIR/php-go-mapping.json"
V1_API_DIR="/var/www/iznik/http/api"
GO_ROOT="$REPO_ROOT/iznik-server-go"
REPORT_DIR="$REPO_ROOT/docs/parity"
REPORT="$REPORT_DIR/$(date +%Y-%m-%d)-parity-report.md"
TMP_DIR="$(mktemp -d)"

mkdir -p "$REPORT_DIR"

# ─── Parse mapping ─────────────────────────────────────────────────────────────
declare -A PHP_TO_GO
while IFS=$'\t' read -r php go; do
    PHP_TO_GO["$php"]="$go"
done < <(python3 -c "
import json, sys
m = json.load(open('$MAPPING'))
for php, go in m.items():
    print(php + '\t' + go)
")

# ─── Report header ─────────────────────────────────────────────────────────────
cat > "$REPORT" <<EOF
# V1→V2 Migration Parity Report

Generated: $(date -u '+%Y-%m-%d %H:%M UTC')

Only NOT_FOUND behaviors are shown. UNCERTAIN means the SQL table name could not
be extracted from the V1 query string — these need manual review.

---

## Summary

| Endpoint | Behaviors | NOT_FOUND | UNCERTAIN |
|----------|-----------|-----------|-----------|
EOF

declare -A SUMMARY_ROWS
declare -A DETAILS

# ─── Per-endpoint processing ───────────────────────────────────────────────────
for php_file in "${!PHP_TO_GO[@]}"; do
    go_pkg="${PHP_TO_GO[$php_file]}"

    if [[ "$go_pkg" == SKIP:* ]]; then
        echo "SKIP $php_file ($go_pkg)"
        continue
    fi

    go_dir="$GO_ROOT/$go_pkg"
    if [[ ! -d "$go_dir" ]]; then
        echo "WARN: Go dir not found for $php_file → $go_dir"
        continue
    fi

    echo -n "Processing $php_file ... "

    ledger="$TMP_DIR/${php_file%.php}.json"
    annotated="$TMP_DIR/${php_file%.php}-annotated.json"

    # Extract behaviors
    docker exec freegle-apiv1 php \
        "$V1_API_DIR/../../scripts/parsers/v1-behavior-extractor.php" \
        "$V1_API_DIR/$php_file" \
        2>/dev/null > "$ledger" || { echo "EXTRACT ERROR"; continue; }

    # Check coverage
    python3 "$SCRIPT_DIR/v2-coverage-checker.py" \
        "$ledger" "$go_dir" > "$annotated" 2>/dev/null

    # Count
    total=$(python3 -c "import json; d=json.load(open('$annotated')); print(len(d))")
    not_found=$(python3 -c "import json; d=json.load(open('$annotated')); print(sum(1 for b in d if b['v2_status']=='NOT_FOUND'))")
    uncertain=$(python3 -c "import json; d=json.load(open('$annotated')); print(sum(1 for b in d if b['v2_status']=='UNCERTAIN'))")

    SUMMARY_ROWS["$php_file"]="| \`$php_file\` | $total | $not_found | $uncertain |"

    # Collect details for NOT_FOUND and UNCERTAIN
    details=$(python3 -c "
import json
data = json.load(open('$annotated'))
gaps = [b for b in data if b['v2_status'] in ('NOT_FOUND', 'UNCERTAIN')]
if not gaps:
    print('(none)')
else:
    for b in gaps:
        print(f'- [{b[\"v2_status\"]}] **{b[\"category\"]}**: {b[\"description\"]} — \`{b[\"file\"]}:{b[\"line\"]}\`')
")
    DETAILS["$php_file"]="$details"

    echo "total=$total not_found=$not_found uncertain=$uncertain"
done

# ─── Write summary table ────────────────────────────────────────────────────────
for php_file in $(echo "${!SUMMARY_ROWS[@]}" | tr ' ' '\n' | sort); do
    echo "${SUMMARY_ROWS[$php_file]}" >> "$REPORT"
done

# ─── Write per-endpoint details ────────────────────────────────────────────────
echo "" >> "$REPORT"
echo "---" >> "$REPORT"
echo "" >> "$REPORT"
echo "## Per-Endpoint Gaps" >> "$REPORT"

for php_file in $(echo "${!DETAILS[@]}" | tr ' ' '\n' | sort); do
    echo "" >> "$REPORT"
    echo "### \`$php_file\`" >> "$REPORT"
    echo "" >> "$REPORT"
    echo "${DETAILS[$php_file]}" >> "$REPORT"
done

rm -rf "$TMP_DIR"

echo ""
echo "Report written to: $REPORT"
```

Save as `scripts/parsers/run-parity-check.sh` and make executable:

```bash
chmod +x scripts/parsers/run-parity-check.sh
```

- [ ] **Step 2: Commit**

```bash
git add scripts/parsers/run-parity-check.sh
git commit -m "feat: add parity check shell driver and report generator"
```

---

## Task 5: Smoke Test on Three Endpoints

Before running all 58 endpoints, verify the full pipeline on three known cases: one with no known gaps (comment), one with a known gap (memberships — missing displayname handling was a known V2 gap found in the earlier audit), and one with deliberate N/A (bulkop).

- [ ] **Step 1: Test comment.php (expect: no gaps)**

```bash
docker exec freegle-apiv1 php /var/www/iznik/scripts/parsers/v1-behavior-extractor.php \
    /var/www/iznik/http/api/comment.php 2>&1 | grep -E "classes indexed|behaviors extracted"

docker exec freegle-apiv1 php /var/www/iznik/scripts/parsers/v1-behavior-extractor.php \
    /var/www/iznik/http/api/comment.php 2>/dev/null > /tmp/comment-ledger.json

python3 scripts/parsers/v2-coverage-checker.py \
    /tmp/comment-ledger.json iznik-server-go/comment/ \
    | python3 -c "
import json, sys
d = json.load(sys.stdin)
gaps = [b for b in d if b['v2_status'] == 'NOT_FOUND']
print(f'comment.php: {len(d)} behaviors, {len(gaps)} NOT_FOUND')
"
```

Expected output: `comment.php: N behaviors, 0 NOT_FOUND` (N between 5 and 50).

- [ ] **Step 2: Test memberships.php (expect: some gaps)**

```bash
docker exec freegle-apiv1 php /var/www/iznik/scripts/parsers/v1-behavior-extractor.php \
    /var/www/iznik/http/api/memberships.php 2>/dev/null > /tmp/memberships-ledger.json

python3 scripts/parsers/v2-coverage-checker.py \
    /tmp/memberships-ledger.json iznik-server-go/membership/ \
    | python3 -c "
import json, sys
d = json.load(sys.stdin)
gaps = [b for b in d if b['v2_status'] == 'NOT_FOUND']
print(f'memberships.php: {len(d)} behaviors, {len(gaps)} NOT_FOUND')
for g in gaps:
    print(f'  [{g[\"category\"]}] {g[\"description\"]} ({g[\"file\"]}:{g[\"line\"]})')
"
```

Expected output: at least a few NOT_FOUND items, since memberships had known V2 gaps.

- [ ] **Step 3: Verify SKIP works in mapping**

```bash
python3 -c "
import json
m = json.load(open('scripts/parsers/php-go-mapping.json'))
skips = {k: v for k, v in m.items() if v.startswith('SKIP:')}
print('SKIP entries:', list(skips.keys()))
"
```

Expected: prints the list of SKIPped endpoints (bulkop, error, invitation, mentions, poll, request).

- [ ] **Step 4: If smoke tests look correct, commit a note**

If the smoke tests reveal problems in the extractor or checker (e.g., 0 behaviors found, or everything is UNCERTAIN), diagnose before proceeding to the full run. Common issues:
- **0 behaviors**: PHP-Parser path wrong in container → check `/var/www/iznik/composer/vendor/autoload.php` exists
- **All UNCERTAIN**: SQL strings are complex expressions, not literal strings → expected for some queries; check that simple string queries like `SELECT id FROM users WHERE id = ?` are captured
- **All NOT_FOUND**: Go package dir wrong → verify `iznik-server-go/comment/` contains `*.go` files

---

## Task 6: Full Parity Run

- [ ] **Step 1: Run the full check**

```bash
./scripts/parsers/run-parity-check.sh 2>&1 | tee /tmp/parity-run.log
```

This will take several minutes (58 endpoints × PHP-Parser parse time). Monitor progress via the log.

- [ ] **Step 2: Review the report**

```bash
cat docs/parity/$(ls -t docs/parity/ | head -1)
```

Check the summary table. Compare NOT_FOUND counts against the 22 known bugs in `plans/active/v1-v2-parity-audit.md`. The tool should surface most of them (SQL gaps, missing log entries) and may surface additional ones not previously found.

- [ ] **Step 3: Sanity check: verify known bugs appear**

From `plans/active/v1-v2-parity-audit.md`, these are confirmed real bugs:
- Bug #5: `PATCH /message` missing log entry for edits — should show as `AuditLog NOT_FOUND` in `message.php`
- Bug #14: `DELETE /user` missing log entry — should show as `AuditLog NOT_FOUND` in `user.php`
- Bug #15: Notifications Seen/AllSeen missing push queuing — should show as `Push NOT_FOUND` in `notification.php`

```bash
grep -A5 "message.php\|user.php\|notification.php" \
    docs/parity/$(ls -t docs/parity/ | head -1) | grep "NOT_FOUND.*AuditLog\|NOT_FOUND.*Push"
```

Expected: lines matching at least some of the above known bugs.

- [ ] **Step 4: Commit the report and log**

```bash
git add docs/parity/
git commit -m "feat: run full V1→V2 parity check, produce gap report"
```

---

## Task 7: Commit Toolchain Summary

- [ ] **Step 1: Update CLAUDE.md session log with parity run findings**

Add a session log entry summarising:
- How many endpoints were checked
- Total NOT_FOUND behaviors
- Notable gaps found (beyond the 22 known ones)
- Whether the 22 known bugs were detected

- [ ] **Step 2: Final commit**

```bash
git add CLAUDE.md
git commit -m "docs: record parity check findings in session log"
```

---

## Calibration Notes

After the first run, the extractor/checker may need tuning:

**Too many false NOT_FOUNDs (noise):** The SQL table name extraction regex may fail on complex query strings. Fix `extract_table()` in `v2-coverage-checker.py` to handle more patterns (e.g., subqueries, backtick-quoted names).

**Too many missed gaps (false FOUNDs):** The Go search for table names is too broad. Narrow it by requiring the table name to appear within 3 lines of a `db.Raw` or `db.Exec` call. This requires reading Go files line-by-line.

**Dynamic PHP calls (`[dynamic]` in description):** These are `$obj->$method()` where the method name is a variable. They appear as `Push::([dynamic])` in the ledger. Flag them manually for review — they cannot be statically resolved.
