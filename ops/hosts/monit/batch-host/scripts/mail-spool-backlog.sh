#!/bin/sh
# Alert when the mail spool has a SUSTAINED backlog.
#
# Cheap by construction: `ls -U -1` is a bare readdir - no sort, no stat, no
# fnmatch - measured at 79ms against 58k entries, versus 113ms for `find
# -name` and 114ms for `find -type f` (which stats every entry). One run per
# 120s monit cycle is a 0.07% duty cycle, so this can watch a 60k directory
# without being part of the problem it is reporting.
#
# Depth, not age: finding the oldest pending file would mean stat()ing every
# entry, which is exactly the expensive thing to avoid. Depth is the cheap
# proxy, and monit's "for N cycles" supplies the "sustained" part - a digest
# run legitimately queues tens of thousands of messages and drains them, so
# only a backlog that persists across the window is worth waking anyone for.
#
# Exit 0 = fine, 1 = backlog.
SPOOL=${SPOOL_DIR:-/var/www/FreegleDocker/iznik-batch/storage/spool/mail/pending}
THRESHOLD=${1:-20000}

# No directory means the container is down or mid-rebuild; that is not a
# backlog, and other checks cover container health. Don't false-alarm.
[ -d "$SPOOL" ] || exit 0

COUNT=$(ls -U -1 "$SPOOL" 2>/dev/null | wc -l)

if [ "$COUNT" -ge "$THRESHOLD" ]; then
    echo "mail spool backlog: $COUNT pending (threshold $THRESHOLD)"
    exit 1
fi

exit 0
