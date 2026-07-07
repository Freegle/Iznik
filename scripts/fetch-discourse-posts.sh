#!/bin/bash
# Fetch all posts by Edward_Hibbert from Discourse API
# Strategy: Use search API with date ranges to paginate through all posts
# The search API supports "user:X before:date after:date" to window results
#
# Usage: ./scripts/fetch-discourse-posts.sh
# Handles rate limits by backing off on 429 responses

set -euo pipefail

API_KEY="46b3754526cb8b721315ebafe949448d"
API_CLIENT="discourse-mcp"
# Read DISCOURSE_URL from .env if not set in environment.
if [ -z "${DISCOURSE_URL:-}" ]; then
  ENV_FILE="$(dirname "$0")/../.env"
  DISCOURSE_URL=$(grep -E '^DISCOURSE_URL=' "$ENV_FILE" 2>/dev/null | cut -d= -f2-)
fi
BASE_URL="${DISCOURSE_URL:-https://community.ilovefreegle.org}"
USERNAME="Edward_Hibbert"
OUTPUT_DIR="/home/edward/FreegleDockerWSL/discourse-corpus"
OUTPUT_FILE="$OUTPUT_DIR/edward_posts.jsonl"
POST_IDS_FILE="$OUTPUT_DIR/post_ids.txt"
DELAY=3  # seconds between requests (conservative to avoid rate limits)

mkdir -p "$OUTPUT_DIR"

# Helper: curl with rate limit handling
api_call() {
    local url="$1"
    local max_retries=5
    local retry=0
    while [ $retry -lt $max_retries ]; do
        local result
        local http_code
        result=$(curl -s -w "\n%{http_code}" \
            -H "User-Api-Key: $API_KEY" \
            -H "User-Api-Client-Id: $API_CLIENT" \
            "$url" 2>/dev/null)
        http_code=$(echo "$result" | tail -1)
        local body
        body=$(echo "$result" | sed '$d')

        if [ "$http_code" = "200" ]; then
            echo "$body"
            return 0
        elif [ "$http_code" = "429" ]; then
            retry=$((retry + 1))
            local wait=$((DELAY * retry * 2))
            echo "Rate limited, waiting ${wait}s (attempt $retry/$max_retries)..." >&2
            sleep "$wait"
        else
            echo "HTTP $http_code for $url" >&2
            echo "$body"
            return 1
        fi
    done
    echo "Max retries exceeded for $url" >&2
    return 1
}

# Step 1: Collect post IDs using search API with monthly date windows
echo "$(date): Collecting post IDs via search API with date windows..."

if [ -f "$POST_IDS_FILE" ] && [ "$(wc -l < "$POST_IDS_FILE")" -gt 1000 ]; then
    echo "Post IDs file exists with $(wc -l < "$POST_IDS_FILE") IDs, skipping collection"
else
    > "$POST_IDS_FILE"

    # Generate monthly date ranges from account creation (Oct 2018) to now
    start_year=2018
    start_month=10
    end_year=$(date +%Y)
    end_month=$(date +%-m)

    year=$start_year
    month=$start_month

    while true; do
        # Calculate next month
        next_month=$((month + 1))
        next_year=$year
        if [ $next_month -gt 12 ]; then
            next_month=1
            next_year=$((year + 1))
        fi

        after=$(printf "%04d-%02d-01" $year $month)
        before=$(printf "%04d-%02d-01" $next_year $next_month)

        # Search for posts by this user in this date window
        # Discourse search returns up to 50 results per page
        page=1
        window_count=0
        while true; do
            query="user:${USERNAME}+after:${after}+before:${before}+order:latest"
            result=$(api_call "${BASE_URL}/search.json?q=${query}&page=${page}" 2>/dev/null || echo '{}')

            count=$(echo "$result" | python3 -c "
import sys, json
try:
    data = json.load(sys.stdin)
    posts = data.get('posts', [])
    for p in posts:
        print(p.get('id', ''))
    print(f'COUNT:{len(posts)}', file=sys.stderr)
    more = data.get('grouped_search_result', {}).get('more_posts', False)
    print(f'MORE:{more}', file=sys.stderr)
except:
    print('COUNT:0', file=sys.stderr)
    print('MORE:False', file=sys.stderr)
" 2>&1 1>>"$POST_IDS_FILE" | grep -oP 'COUNT:\K\d+' || echo "0")

            more=$(echo "$result" | python3 -c "
import sys, json
try:
    data = json.load(sys.stdin)
    more = data.get('grouped_search_result', {}).get('more_posts', False)
    print(more)
except:
    print('False')
" 2>/dev/null || echo "False")

            window_count=$((window_count + count))

            if [ "$more" != "True" ] || [ "$count" -eq 0 ]; then
                break
            fi

            page=$((page + 1))
            sleep "$DELAY"
        done

        echo "  $after to $before: $window_count posts (page $page)"
        sleep "$DELAY"

        # Check if we've reached the current month
        if [ $year -ge $end_year ] && [ $month -ge $end_month ]; then
            break
        fi

        # Advance to next month
        month=$next_month
        year=$next_year
    done

    # Deduplicate
    sort -un "$POST_IDS_FILE" -o "$POST_IDS_FILE"
    # Remove empty lines
    sed -i '/^$/d' "$POST_IDS_FILE"
    echo "$(date): Collected $(wc -l < "$POST_IDS_FILE") unique post IDs via search"

    # Also add any IDs from user_actions that search might have missed
    echo "$(date): Supplementing with user_actions..."
    offset=0
    while true; do
        result=$(api_call "$BASE_URL/user_actions.json?username=$USERNAME&filter=4,5&offset=$offset&limit=30" 2>/dev/null || echo '{}')

        count=$(echo "$result" | python3 -c "
import sys, json
try:
    data = json.load(sys.stdin)
    actions = data.get('user_actions', [])
    for a in actions:
        pid = a.get('post_id')
        if pid:
            print(pid)
    print(f'COUNT:{len(actions)}', file=sys.stderr)
except:
    print('COUNT:0', file=sys.stderr)
" 2>&1 1>>"$POST_IDS_FILE" | grep -oP 'COUNT:\K\d+' || echo "0")

        if [ "$count" -eq 0 ] || [ "$count" -lt 30 ]; then
            break
        fi

        offset=$((offset + 30))
        sleep "$DELAY"
    done

    sort -un "$POST_IDS_FILE" -o "$POST_IDS_FILE"
    sed -i '/^$/d' "$POST_IDS_FILE"
    echo "$(date): Total unique post IDs after supplementing: $(wc -l < "$POST_IDS_FILE")"
fi

# Step 2: Fetch full content for each post
echo "$(date): Fetching full post content..."

total=$(wc -l < "$POST_IDS_FILE")

# Build set of already-fetched IDs for fast lookup
FETCHED_IDS_FILE="$OUTPUT_DIR/.fetched_ids"
if [ -f "$OUTPUT_FILE" ]; then
    python3 -c "
import json
ids = set()
for line in open('$OUTPUT_DIR/edward_posts.jsonl'):
    try:
        ids.add(str(json.loads(line)['id']))
    except:
        pass
with open('$OUTPUT_DIR/.fetched_ids', 'w') as f:
    f.write('\n'.join(ids))
print(f'Already fetched: {len(ids)}')
"
else
    > "$FETCHED_IDS_FILE"
fi

fetched=0
skipped=0

while IFS= read -r post_id; do
    [ -z "$post_id" ] && continue

    # Skip if already fetched
    if grep -qx "$post_id" "$FETCHED_IDS_FILE" 2>/dev/null; then
        skipped=$((skipped + 1))
        continue
    fi

    result=$(api_call "$BASE_URL/posts/$post_id.json" 2>/dev/null || echo '{}')

    echo "$result" | python3 -c "
import sys, json
try:
    data = json.load(sys.stdin)
    if 'raw' in data:
        out = {
            'id': data.get('id'),
            'topic_id': data.get('topic_id'),
            'topic_slug': data.get('topic_slug'),
            'post_number': data.get('post_number'),
            'reply_to_post_number': data.get('reply_to_post_number'),
            'created_at': data.get('created_at'),
            'raw': data.get('raw'),
            'word_count': len(data.get('raw', '').split()),
            'category_id': data.get('category_id'),
        }
        print(json.dumps(out))
except:
    pass
" >> "$OUTPUT_FILE" 2>/dev/null

    echo "$post_id" >> "$FETCHED_IDS_FILE"
    fetched=$((fetched + 1))

    if [ $((fetched % 50)) -eq 0 ]; then
        echo "$(date): Fetched $fetched / $total (skipped $skipped already done)"
    fi

    sleep "$DELAY"
done < "$POST_IDS_FILE"

echo "$(date): Done! $fetched new posts fetched, saved to $OUTPUT_FILE"
echo "Total lines in output: $(wc -l < "$OUTPUT_FILE")"
echo "Total words: $(python3 -c "
import json
total = 0
for line in open('$OUTPUT_FILE'):
    try:
        total += json.loads(line).get('word_count', 0)
    except:
        pass
print(total)
")"
