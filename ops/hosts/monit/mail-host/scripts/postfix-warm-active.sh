#!/bin/bash
# Is the warm instance's active queue approaching its ceiling?
#
# The warm instance exists so a paced provider cannot pin the PRIMARY's active
# queue and delay healthy domains. Inside the warm instance the same mechanism
# still applies one level down: when its active queue is full the queue manager
# stops importing from `incoming`, and a second paced group then waits behind
# the first. That is much less serious - both providers are ones we are
# deliberately slowing anyway - but it is the same shape, and the reason the
# original incident lasted thirty hours is that nobody could see it.
#
# So this does not try to prevent it. It makes it visible.
#
# monit runs checks with no HOME and a minimal PATH, so use absolute paths and
# test with `env -i`. A check that cannot measure must FAIL, never pass quietly:
# a silent exit 0 is how the mysql_processes check sat inert for years.
set -u

POSTCONF=/usr/sbin/postconf
CONFIG=/etc/postfix-warm
ACTIVE_DIR=/var/spool/postfix-warm/active
WARN_PCT=${WARN_PCT:-80}

[ -x "$POSTCONF" ] || { echo "CRITICAL: $POSTCONF missing"; exit 2; }
[ -d "$ACTIVE_DIR" ] || { echo "CRITICAL: $ACTIVE_DIR missing - is the warm instance installed?"; exit 2; }

limit=$("$POSTCONF" -c "$CONFIG" -h qmgr_message_active_limit 2>/dev/null)
case "$limit" in
  ''|*[!0-9]*) echo "CRITICAL: could not read qmgr_message_active_limit from $CONFIG"; exit 2 ;;
esac
[ "$limit" -gt 0 ] || { echo "CRITICAL: qmgr_message_active_limit is $limit"; exit 2; }

# -maxdepth 1: the active queue is not hashed, and a deep walk would stat tens
# of thousands of files every cycle.
active=$(find "$ACTIVE_DIR" -maxdepth 1 -type f 2>/dev/null | wc -l)
case "$active" in
  ''|*[!0-9]*) echo "CRITICAL: could not count $ACTIVE_DIR"; exit 2 ;;
esac

pct=$(( active * 100 / limit ))

if [ "$pct" -ge "$WARN_PCT" ]; then
  echo "WARNING: warm instance active queue $active/$limit (${pct}%) - a second paced group will start waiting behind the first; raise qmgr_message_active_limit or give the busiest group its own instance"
  exit 1
fi

echo "OK: warm instance active queue $active/$limit (${pct}%)"
exit 0
