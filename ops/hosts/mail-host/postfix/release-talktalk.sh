#!/bin/bash
# Release a small batch of held TalkTalk/Tiscali messages.
# Run via cron every 30 minutes to drip-feed delivery.
BATCH_SIZE=20

# Find held queue IDs (marked with !) whose recipients are TalkTalk domains.
# Queue format: ID line (with !), optional error line(s), then recipient line(s).
HELD=$(mailq | awk '
/^[0-9A-F]+!/ { qid=$1; gsub(/[^0-9A-F]/,"",qid); curqid=qid }
/@ *(talktalk\.net|tiscali\.co\.uk|onetel\.com|lineone\.net|screaming\.net|homecall\.co\.uk)/ { if(curqid) print curqid; curqid="" }
' | sort -u | head -$BATCH_SIZE)

COUNT=$(echo "$HELD" | grep -c "[0-9A-F]")

if [ "$COUNT" -gt 0 ]; then
    echo "$HELD" | while read id; do postsuper -H "$id" 2>/dev/null; done
    logger -t release-talktalk "Released $COUNT TalkTalk messages"
else
    logger -t release-talktalk "No held TalkTalk messages remaining"
fi
