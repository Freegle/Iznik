#!/bin/bash
# Pre-tool hook: Suggests piping through parser scripts for known high-output commands.
#
# Checks the command about to run against scripts/parsers/registry.json.
# If a matching parser exists, suggests it. Otherwise checks for common
# unbounded patterns (docker logs, git log, etc.) and suggests limits.
# Does NOT block — just advises. Gives ONE suggestion max to avoid noise.

set -euo pipefail

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$(pwd)}"
REGISTRY="$PROJECT_DIR/scripts/parsers/registry.json"

INPUT=$(cat)

TOOL_NAME=$(echo "$INPUT" | jq -r '.tool_name // "unknown"')
TOOL_INPUT=$(echo "$INPUT" | jq -c '.tool_input // {}')

SUGGESTION=""

case "$TOOL_NAME" in
  Bash)
    COMMAND=$(echo "$TOOL_INPUT" | jq -r '.command // ""')

    # First: check registry for a matching parser (most specific advice)
    if [ -f "$REGISTRY" ] && [ -z "$SUGGESTION" ]; then
      PARSER_COUNT=$(jq '.parsers | length' "$REGISTRY")
      for i in $(seq 0 $((PARSER_COUNT - 1))); do
        P_TOOL=$(jq -r ".parsers[$i].tool" "$REGISTRY")
        P_PATTERN=$(jq -r ".parsers[$i].command_pattern // empty" "$REGISTRY")
        P_SCRIPT=$(jq -r ".parsers[$i].script" "$REGISTRY")
        P_SAVINGS=$(jq -r ".parsers[$i].typical_savings_pct" "$REGISTRY")
        P_DESC=$(jq -r ".parsers[$i].description" "$REGISTRY")

        if [ "$P_TOOL" != "Bash" ]; then continue; fi
        if [ -z "$P_PATTERN" ] || [ "$P_PATTERN" = "null" ]; then continue; fi

        if echo "$COMMAND" | grep -qE "$P_PATTERN" 2>/dev/null; then
          if [ -f "$PROJECT_DIR/$P_SCRIPT" ] && ! echo "$COMMAND" | grep -q "$P_SCRIPT"; then
            SUGGESTION="Pipe through $P_SCRIPT for ~${P_SAVINGS}% token reduction (${P_DESC})"
            break
          fi
        fi
      done
    fi

    # Fallback: generic unbounded-output warnings (only if no parser matched)
    if [ -z "$SUGGESTION" ]; then
      if echo "$COMMAND" | grep -qE '\bdocker logs\b' && ! echo "$COMMAND" | grep -qE '(--tail|--since|\| head|\| tail)'; then
        SUGGESTION="docker logs without --tail can return unbounded output. Add '--tail 100'"
      elif echo "$COMMAND" | grep -qE '\bgit log\b' && ! echo "$COMMAND" | grep -qE '(-[0-9]+|--oneline|-n [0-9]|\| head)'; then
        SUGGESTION="git log without -n limit can return huge output. Add '-n 30 --oneline'"
      elif echo "$COMMAND" | grep -qE '(circle-artifacts\.com|circleci\.com/api)' && ! echo "$COMMAND" | grep -qE '(\| head|\| tail|\| parse)'; then
        SUGGESTION="CircleCI output can be verbose. Pipe through scripts/parsers/parse-ci-output.sh or use '| head -100'"
      elif echo "$COMMAND" | grep -qE 'localhost:3100' && ! echo "$COMMAND" | grep -qE '(limit=|&limit|\| head)'; then
        SUGGESTION="Loki queries can return thousands of log lines. Add 'limit=50' parameter"
      fi
    fi
    ;;

  Read)
    FILE_PATH=$(echo "$TOOL_INPUT" | jq -r '.file_path // ""')
    LIMIT=$(echo "$TOOL_INPUT" | jq -r '.limit // empty')

    if [ -z "$LIMIT" ] && [ -f "$FILE_PATH" ]; then
      FILE_LINES=$(wc -l < "$FILE_PATH" 2>/dev/null || echo 0)
      if [ "$FILE_LINES" -gt 500 ]; then
        SUGGESTION="File has $FILE_LINES lines. Use 'limit' and 'offset' params, or run: scripts/parsers/parse-large-file.sh '$FILE_PATH'"
      fi
    fi
    ;;

  mcp__chrome-devtools__take_screenshot)
    # Image tokens = (width * height) / 750. Format/quality DON'T affect token cost.
    # Full 1920x1080 = ~2765 tokens. Element 400x300 = ~160 tokens.
    HAS_UID=$(echo "$TOOL_INPUT" | jq 'has("uid")')
    HAS_FILEPATH=$(echo "$TOOL_INPUT" | jq -r '.filePath // empty')
    if [ -n "$HAS_FILEPATH" ]; then
      # Saving to file for preprocessing — good pattern, don't nag
      SUGGESTION=""
    elif [ "$HAS_UID" = "true" ]; then
      # Targeting a specific element — good, minimal tokens
      SUGGESTION=""
    else
      SUGGESTION="Full screenshot = ~2765 tokens (re-read every turn). Better alternatives by use case: (1) CHECK TEXT/STRUCTURE: use evaluate_script with scripts/parsers/dom-inspect.js (~300 text tokens, zero image). (2) CHECK VISUAL of specific element: take_snapshot first, then take_screenshot with uid param (~160 tokens). (3) CHECK LAYOUT/POSITIONING: evaluate_script with window.__inspectMode='problems' to detect overlaps/overflow (~200 text tokens). (4) NEED FULL VISUAL: save to filePath, process with scripts/parsers/screenshot-to-layout.sh for posterized thumb+edges (~140 tokens for both). Only take a raw full screenshot as last resort."
    fi
    ;;
esac

if [ -n "$SUGGESTION" ]; then
  CLEAN=$(echo "$SUGGESTION" | sed 's/"/\\"/g')
  cat <<ENDJSON
{
  "hookSpecificOutput": {
    "hookEventName": "PreToolUse",
    "additionalContext": "TOKEN TIP: ${CLEAN}"
  }
}
ENDJSON
fi

exit 0
