#!/bin/bash
# Post-tool hook: Monitors tool output size and suggests/creates parser scripts.
#
# When a tool returns >WARN_BYTES of output:
#   1. Logs the event to ~/.claude/token-savings.log
#   2. Checks if a parser exists in scripts/parsers/registry.json
#   3. If no parser exists, injects additionalContext telling Claude to create one
#   4. Reports estimated token savings from using existing parsers
#
# For screenshots: detects base64 image data and suggests downscaling.

set -euo pipefail

WARN_BYTES=10000
SUGGEST_BYTES=20000
LOG_FILE="$HOME/.claude/token-savings.log"
PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$(pwd)}"
REGISTRY="$PROJECT_DIR/scripts/parsers/registry.json"

INPUT=$(cat)

TOOL_NAME=$(echo "$INPUT" | jq -r '.tool_name // "unknown"')
TOOL_INPUT=$(echo "$INPUT" | jq -r '.tool_input // {}')
TOOL_OUTPUT=$(echo "$INPUT" | jq -r '.tool_output // empty')

# Measure output size
OUTPUT_SIZE=${#TOOL_OUTPUT}

# Skip small outputs
if [ "$OUTPUT_SIZE" -lt "$WARN_BYTES" ]; then
  exit 0
fi

# Estimate tokens (~4 chars per token)
EST_TOKENS=$((OUTPUT_SIZE / 4))

# Extract command for logging
TIMESTAMP=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
DATE_KEY=$(date -u +"%Y-%m-%d")
COMMAND=""
if [ "$TOOL_NAME" = "Bash" ]; then
  COMMAND=$(echo "$TOOL_INPUT" | jq -r '.command // ""' 2>/dev/null | head -c 100)
elif [ "$TOOL_NAME" = "Read" ]; then
  COMMAND=$(echo "$TOOL_INPUT" | jq -r '.file_path // ""' 2>/dev/null)
fi

# Log structured event (TSV: timestamp, date, tool, size, tokens, saveable_tokens, category, command)
SAVEABLE=0
CATEGORY="unknown"

# Check for screenshot/image data (base64 encoded)
# Image tokens = (width * height) / 750, NOT based on file size.
# A 1920x1080 = 2765 tokens. An 800x600 = 640 tokens. Saving = ~75%.
# We can't know dimensions from the base64, so estimate from typical viewport.
IS_SCREENSHOT=false
if echo "$TOOL_OUTPUT" | grep -q '"type":"image"\|"type": "image"\|data:image/\|iVBOR'; then
  IS_SCREENSHOT=true
  CATEGORY="screenshot"
  # Estimate: typical full viewport ~2765 tokens, resized ~640, saving ~2125
  EST_TOKENS=2765
  SAVEABLE=2125
fi

# Log the event
echo -e "$TIMESTAMP\t$DATE_KEY\t$TOOL_NAME\t$OUTPUT_SIZE\t$EST_TOKENS\t$SAVEABLE\t$CATEGORY\t$COMMAND" >> "$LOG_FILE"

# Update running totals file (atomic append)
TOTALS_FILE="$HOME/.claude/token-savings-totals.tsv"
if [ ! -f "$TOTALS_FILE" ]; then
  echo -e "date\ttokens_used\ttokens_saveable\tcount" > "$TOTALS_FILE"
fi
# Append this event's contribution
echo -e "$DATE_KEY\t$EST_TOKENS\t$SAVEABLE\t1" >> "$TOTALS_FILE"

if [ "$IS_SCREENSHOT" = true ]; then
  cat <<ENDJSON
{
  "hookSpecificOutput": {
    "hookEventName": "PostToolUse",
    "additionalContext": "Screenshot cost ~2765 tokens (full viewport). Next time: take_snapshot first to get DOM + uids, then take_screenshot with uid param to capture only the relevant element (~80-95% fewer tokens)."
  }
}
ENDJSON
  exit 0
fi

# Check if a parser exists for this tool/command combination
SUGGESTION=""
if [ -f "$REGISTRY" ]; then
  PARSER_COUNT=$(jq '.parsers | length' "$REGISTRY")
  for i in $(seq 0 $((PARSER_COUNT - 1))); do
    P_TOOL=$(jq -r ".parsers[$i].tool" "$REGISTRY")
    P_PATTERN=$(jq -r ".parsers[$i].command_pattern // empty" "$REGISTRY")
    P_SCRIPT=$(jq -r ".parsers[$i].script" "$REGISTRY")
    P_SAVINGS=$(jq -r ".parsers[$i].typical_savings_pct" "$REGISTRY")
    P_DESC=$(jq -r ".parsers[$i].description" "$REGISTRY")
    P_MIN=$(jq -r ".parsers[$i].min_output_bytes // 0" "$REGISTRY")

    if [ "$P_TOOL" != "$TOOL_NAME" ]; then
      continue
    fi

    MATCHES=false
    if [ -n "$P_PATTERN" ] && [ "$P_PATTERN" != "null" ]; then
      if echo "$COMMAND" | grep -qE "$P_PATTERN" 2>/dev/null; then
        MATCHES=true
      fi
    elif [ "$P_MIN" -gt 0 ] && [ "$OUTPUT_SIZE" -ge "$P_MIN" ]; then
      MATCHES=true
    fi

    if [ "$MATCHES" = true ] && [ -f "$PROJECT_DIR/$P_SCRIPT" ]; then
      SAVED_TOKENS=$((EST_TOKENS * P_SAVINGS / 100))
      SAVEABLE=$SAVED_TOKENS
      CATEGORY=$(jq -r ".parsers[$i].id" "$REGISTRY")
      # Re-log with correct savings info (overwrite the unknown entry)
      sed -i '$ d' "$LOG_FILE"
      echo -e "$TIMESTAMP\t$DATE_KEY\t$TOOL_NAME\t$OUTPUT_SIZE\t$EST_TOKENS\t$SAVEABLE\t$CATEGORY\t$COMMAND" >> "$LOG_FILE"
      sed -i '$ d' "$TOTALS_FILE"
      echo -e "$DATE_KEY\t$EST_TOKENS\t$SAVEABLE\t1" >> "$TOTALS_FILE"
      SUGGESTION="PARSER AVAILABLE: Pipe through '$P_SCRIPT' to save ~${SAVED_TOKENS} tokens (${P_SAVINGS}% reduction). ${P_DESC}."
      break
    fi
  done
fi

# Only inject context for large outputs
if [ "$OUTPUT_SIZE" -ge "$SUGGEST_BYTES" ]; then
  if [ -n "$SUGGESTION" ]; then
    MSG="TOKEN ALERT: ${TOOL_NAME} returned ${OUTPUT_SIZE}B (~${EST_TOKENS} tokens). ${SUGGESTION} Next time, pipe the command through the parser script."
  else
    MSG="TOKEN ALERT: ${TOOL_NAME} returned ${OUTPUT_SIZE}B (~${EST_TOKENS} tokens). No parser script exists for this pattern. If this output type recurs, consider writing a parser in scripts/parsers/ and adding it to registry.json. Pattern: tool=${TOOL_NAME} cmd=\"${COMMAND}\""
  fi

  cat <<ENDJSON
{
  "hookSpecificOutput": {
    "hookEventName": "PostToolUse",
    "additionalContext": "$MSG"
  }
}
ENDJSON
fi

exit 0
