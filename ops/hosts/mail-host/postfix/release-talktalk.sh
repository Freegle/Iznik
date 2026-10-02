#!/bin/bash
# Release a small batch of held TalkTalk/Tiscali messages.
# Run via cron every 30 minutes to drip-feed delivery.
BATCH_SIZE=20
DOMAINS='talktalk[.]net|tiscali[.]co[.]uk|onetel[.]com|lineone[.]net|screaming[.]net|homecall[.]co[.]uk'

# Every postfix instance, not just the default one. The relay hands paced
# providers to a second instance, and held mail moves with them. Reading only
# the default queue would log "No held TalkTalk messages remaining" for ever
# while messages sat held in the other one - a silent stop, not an error. None
# of these domains is paced today; this is so that changing does not break it.
#
# Queue ids are unique only WITHIN an instance, so postsuper is always told
# which instance the id came from: releasing against the wrong one does nothing,
# or acts on a different message that happens to share the id.
DIRS=$(postmulti -l 2>/dev/null | awk 'NF {print $NF}')
[ -z "$DIRS" ] && DIRS=$(postconf -h config_directory 2>/dev/null || echo /etc/postfix)

TOTAL=0

for D in $DIRS; do
    # Held queue ids (marked with !) whose recipients are TalkTalk domains.
    # Queue format: ID line (with !), optional error line(s), then recipients.
    HELD=$(mailq -C "$D" 2>/dev/null | awk -v re="$DOMAINS" '
        /^[0-9A-F]+!/ { qid=$1; gsub(/[^0-9A-F]/,"",qid); curqid=qid }
        $0 ~ ("@ *(" re ")") { if (curqid) print curqid; curqid="" }
    ' | sort -u | head -$BATCH_SIZE)

    COUNT=$(printf '%s' "$HELD" | grep -c "[0-9A-F]")
    [ "$COUNT" -eq 0 ] && continue

    echo "$HELD" | while read -r id; do
        [ -n "$id" ] && postsuper -c "$D" -H "$id" 2>/dev/null
    done

    logger -t release-talktalk "Released $COUNT TalkTalk messages from $D"
    TOTAL=$(( TOTAL + COUNT ))
done

[ "$TOTAL" -eq 0 ] && logger -t release-talktalk "No held TalkTalk messages remaining"
