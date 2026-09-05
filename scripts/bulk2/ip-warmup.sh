#!/bin/bash
# ip-warmup.sh - warm SEVERAL sending IPs against SEVERAL provider groups, each
# (IP, group) pair independently: route each group's BULK to whichever address
# is currently getting through TO THAT GROUP, and keep every other candidate
# provably alive on a canary trickle of that group's low-volume domains.
#
# One IP carries one reputation PER RECEIVING PROVIDER, so only ONE address
# carries a group's bulk at a time: splitting a day's mail across addresses
# divides the evidence and teaches the provider nothing about either. Each
# (IP, group) pair keeps its own rung, daily cap and cool-off, so an address
# that gets refused by one provider steps down FOR THAT PROVIDER ONLY and hands
# that group over to the next candidate, while carrying on untouched for every
# other group.
#
# Until 2026-09-03 this script managed ONE group at a time, with one rung per
# IP and one bulk slot, and provider-group-discover.sh chose which group that
# was. That coupling is the only reason Virgin Media's policy refusals could
# ever un-route Yahoo (2026-09-02 08:17Z, 33 hours dark for 10,000 members) or
# walk warm1's Yahoo rung from 6 to 0 without a single Yahoo refusal. Now a
# group is a postfix TRANSPORT per candidate - `warm1yahoodnsnet` - so the
# syslog tag IS the (IP, group) pair and every measurement below is per pair
# for free. Nothing about one provider can touch another.
#
# But an address that carries nothing learns nothing. Every candidate that is
# NOT carrying a group's bulk keeps a CANARY for that group: the group's
# low-volume domains (everything absent from /etc/postfix/warmup-bulk-domains)
# are routed to it continuously. That is enough to tell 250 from 4.7.0 without
# meaningfully dividing the reputation signal, and it means a spare is already
# warm on the day the bulk carrier is refused.
#
# Growth is earned and loss is immediate: a rung goes up only after a day that
# used most of its allowance cleanly, but a refusal in the recent window drops
# it on the spot (see the 2026-08-20 notes below). A canary earns rungs on
# ACCEPTANCE rather than volume - it will never have the volume - but only as
# far as CANARY_MAX_RUNG, so an address that has proved no more than "they take
# my twenty an hour" can never be handed a 50,000/day firehose.
#
# DRY=1 runs the whole decision and prints what it would change, touching
# neither postfix nor the map. Use it before believing any edit to this file.
set -u

DRY=${DRY:-0}

LOCK=/var/lock/$(basename "$0").lock
exec 9>"$LOCK" || exit 0
flock -n 9 || exit 0

DIR=/var/lib/ip-warmup
LOG=/var/log/ip-warmup.log
MAILLOG=/var/log/mail.log
MAP=/etc/postfix/warmup_transport
# <domain> <group> - every domain of every provider group in play, written by
# provider-group-discover.sh. A group is a set of recipient domains sharing an
# inbound MX (yahoo.com, yahoo.co.uk, aol.com, sky.com ... are one group,
# yahoodns.net), because that is the unit the receiving side judges us on.
GROUPS_FILE=/etc/postfix/warmup-groups
# The domains that carry a group's volume, and so must always follow that
# group's bulk address rather than a canary. Everything else in the group is
# canary material. One global list, intersected per group.
BULK_DOMAINS=/etc/postfix/warmup-bulk-domains
MAPNEW=$MAP.new

# A dry run that writes state files, appends to the log and drops scratch files
# into /etc/postfix is not a dry run. Point every writable path at a throwaway
# copy of the state instead, so DRY=1 reads the real world and touches nothing
# in it.
if [ "$DRY" = "1" ]; then
  _dry=$(mktemp -d)
  cp -a "$DIR/." "$_dry/" 2>/dev/null
  DIR=$_dry; LOG=$_dry/ip-warmup.log; MAPNEW=$_dry/warmup_transport.new
fi

# Candidates live in a config file, not in this script: adding or retiring a
# sending address should not mean editing code, and the file documents its own
# format. Columns are ip, transport prefix, not-before epoch. The prefix names
# the per-group transports: prefix `warm1` + group `yahoodns.net` ->
# transport `warm1yahoodnsnet`, syslog tag `postfix-warm1yahoodnsnet`.
CANDIDATES_FILE=/etc/postfix/warmup-candidates
CANDIDATES=$(awk '!/^[[:space:]]*(#|$)/ {print $1":"$2":"$3}' "$CANDIDATES_FILE" 2>/dev/null)

LADDER="2000 4000 8000 12000 20000 30000 50000"
DELAY_LADDER="8s 4s 2s 2s 1s 1s 1s"
# Spread a day's allowance over at least six hours, not ten. Ten was harmless
# only while count_since() could not see an hour: with the window fixed, rung 6
# would have capped at 5,000/hour against an observed 4,900/hour and throttled
# the family within minutes of this change going in.
HOURLY_DIVISOR=6
REFUSE_WINDOW_MIN=15
REFUSE_MIN_SAMPLES=20
COOLOFF_MIN=60
# Over a cap we THROTTLE, we do not stop and we never re-route. Both figures are
# a per-DOMAIN delay and the bulk rides on two domains, so the rough hourly rate
# is 7200/delay: 2s is about 3,600/hour (a real slowdown that still clears a
# digest) and 8s about 900/hour (a trickle for a day whose allowance is spent,
# which doubles as the probe that the address is still being accepted).
HOURCAP_DELAY=2s
DAYCAP_DELAY=8s
# A never-used pair is a different animal from a throttled one. The first
# contact with a new address (warm2) was 211 messages in 100 seconds, all refused 421
# TSS04 - what an unknown IP opening at 2/sec looks like, and equally what
# greylisting looks like: deferred on sight, accepted when the same mail is
# presented again shortly after. Both want a trickle and patience, which is the
# opposite of what the warm ladder does.
COLD_CAP=200               # a cold pair's whole first day
COLD_DELAY=60s             # per-domain: a handful an hour, not a burst
COLD_RETRY_MIN=15          # re-present quickly - a greylist clears in minutes
# Canary evidence is thin by design, so it needs a long window and a low bar.
# At ~1% of group volume a candidate sees tens of messages an hour, which
# never reaches REFUSE_MIN_SAMPLES in fifteen minutes - judged on the active
# windows a canary would look permanently healthy no matter what it was told.
CANARY_WINDOW_MIN=180
CANARY_MIN_SAMPLES=15      # refusals needed before a canary counts as refused
CANARY_MIN_SENT=15         # clean deliveries needed before a canary earns a rung
CANARY_PROMOTE_SECS=21600  # at most one canary rung every six hours
CANARY_MAX_RUNG=2          # 8,000/day: warm enough to take over, not a firehose
# Canaries are paced explicitly. set_rate only ever ran for the address carrying
# the bulk, so a newly added candidate inherited default_destination_rate_delay
# (0s) and default_destination_concurrency_limit (20) - i.e. no pacing at all on
# the one kind of address most likely to be under a block. Real canary volume is
# ~20/hour so this rarely binds; it is here for the day a backlog builds behind
# a canary domain and would otherwise arrive as a burst.
CANARY_DELAY=30s
# A transport created this run starts here until the ladder says otherwise.
NEW_TRANSPORT_DELAY=8s
NEW_TRANSPORT_MAXPROC=4

mkdir -p "$DIR"
log() { echo "$(date -u '+%F %H:%M')Z $*" >> "$LOG"; }
today=$(date -u +%F)
now=$(date -u +%s)

maxrung=$(( $(echo "$LADDER" | wc -w) - 1 ))
clamp_rung() { r=$1; [ "$r" -lt 0 ] && r=0; [ "$r" -gt "$maxrung" ] && r=$maxrung; echo "$r"; }
rung_cap()   { n=$1; i=0; for c in $LADDER;       do [ "$i" -eq "$n" ] && { echo "$c"; return; }; i=$((i+1)); done; echo "$c"; }
rung_delay() { n=$1; i=0; for d in $DELAY_LADDER; do [ "$i" -eq "$n" ] && { echo "$d"; return; }; i=$((i+1)); done; echo "$d"; }

# Group name -> the part of a transport name that stands for it. Transport
# names double as main.cf parameter prefixes (<name>_destination_rate_delay),
# and the harvest matches tags with /postfix[-a-z0-9]*\//, so: lowercase
# alphanumerics only. yahoodns.net -> yahoodnsnet.
slug() { echo "$1" | tr 'A-Z' 'a-z' | tr -cd 'a-z0-9'; }

# The groups in play, and their domains.
PGROUPS=$(awk '!/^[[:space:]]*(#|$)/ && NF >= 2 {print $2}' "$GROUPS_FILE" 2>/dev/null | sort -u)
group_domains() { awk -v g="$1" '!/^[[:space:]]*(#|$)/ && $2 == g {print $1}' "$GROUPS_FILE" 2>/dev/null; }

# Every (candidate, group) tag - the harvest and the day roll work over these.
ALLTAGS=""
for _e in $CANDIDATES; do
  _r=${_e#*:}; _t=${_r%%:*}
  for _g in $PGROUPS; do ALLTAGS="$ALLTAGS postfix-${_t}$(slug "$_g")"; done
done

# ---------------------------------------------------------------------------
# Measurement.
#
# Everything below used to be greps over mail.log, and that was the single
# biggest thing standing between this script and being left alone:
#
#  * count_since() took `tail -n 100000`, which on 2026-08-22 covered TWENTY-SIX
#    MINUTES of an 8,000 line/minute log. Every "in the last hour" figure was
#    therefore an undercount of roughly 2.3x, and the hourly cap it fed only
#    ever fired by accident.
#  * sent_today() grepped the whole 8GB log per tag. Measured at 24 SECONDS a
#    call on a box with 2GB of RAM, where the log cannot be cached - with a
#    120s cache and three candidates that is most of every minute spent
#    re-reading the log, and runs long enough to overlap and silently drop.
#
# Both are replaced by one incremental harvest: read only the bytes appended
# since the last run (a couple of MB a minute), fold them into a per-minute
# tally and per-tag daily counters. Windows then come out of a file of a few
# hundred lines, exactly, for nothing. The tag is the (IP, group) transport,
# so nothing here needs to know which domain belongs to which group.
# ---------------------------------------------------------------------------
OFFSET=$DIR/maillog.offset
TALLY=$DIR/tally            # lines: <epoch> <tag> <sent> <deferred>
TALLY_MIN=240
DAYSTAMP=$DIR/day.date

seed_day_counters_from_log() {
  d=$(date -u '+%b %e')
  grep "^$d" "$MAILLOG" 2>/dev/null | awk -v dir="$DIR" '
    /status=sent/ { if (match($0, /postfix[-a-z0-9]*\//)) c[substr($0, RSTART, RLENGTH - 1)]++ }
    END { for (t in c) { f = dir "/" t ".day"; print c[t] > f; close(f) } }'
  for t in $ALLTAGS; do [ -f "$DIR/$t.day" ] || echo 0 > "$DIR/$t.day"; done
}

harvest() {
  size=$(stat -c %s "$MAILLOG" 2>/dev/null || echo 0)
  off=0; [ -f "$OFFSET" ] && off=$(cat "$OFFSET" 2>/dev/null || echo 0)
  # The file shrank, so it was rotated under us and our offset points past the
  # end. Resume from the start of the NEW file rather than re-reading 8GB.
  if [ "$size" -lt "$off" ]; then log "mail.log rotated - harvest resumes from the new file"; off=0; fi
  if [ "$size" -le "$off" ]; then echo "$size" > "$OFFSET"; return; fi
  tail -c +$(( off + 1 )) "$MAILLOG" 2>/dev/null | head -c $(( size - off )) | awk -v now="$now" -v dir="$DIR" '
    function tg() { if (match($0, /postfix[-a-z0-9]*\//)) return substr($0, RSTART, RLENGTH - 1); return "" }
    /status=sent/     { t = tg(); if (t != "") s[t]++ }
    /status=deferred/ { t = tg(); if (t != "") f[t]++ }
    END {
      for (t in s) printf "%d %s %d %d\n", now, t, s[t], (t in f ? f[t] : 0)
      for (t in f) if (!(t in s)) printf "%d %s 0 %d\n", now, t, f[t]
      for (t in s) {
        fn = dir "/" t ".day"; n = 0
        if ((getline line < fn) > 0) n = line + 0
        close(fn)
        print n + s[t] > fn; close(fn)
      }
    }' >> "$TALLY"
  echo "$size" > "$OFFSET"
  awk -v cut=$(( now - TALLY_MIN * 60 )) '$1 >= cut' "$TALLY" > "$TALLY.tmp" 2>/dev/null && mv "$TALLY.tmp" "$TALLY"
}

count_since() {  # $1 minutes, $2 sent|deferred, $3 syslog tag
  [ -f "$TALLY" ] || { echo 0; return; }
  c=3; [ "$2" = "deferred" ] && c=4
  awk -v cut=$(( now - $1 * 60 )) -v t="$3" -v c="$c" '$1 >= cut && $2 == t { n += $c } END { print n + 0 }' "$TALLY"
}

# How much history the tally actually holds. A window we cannot see is not the
# same as a window with nothing in it: "zero refusals" out of no data at all
# must never be read as proof of health, or a restart would hand every address
# a promotion. Promotions are gated on this; the refusal test is not, because
# too little data can only make it fail to fire, which is the safe direction.
tally_span() {
  [ -f "$TALLY" ] || { echo 0; return; }
  o=$(head -1 "$TALLY" 2>/dev/null | awk '{print $1}')
  [ -z "$o" ] && { echo 0; return; }
  echo $(( (now - o) / 60 ))
}

day_sent() { cat "$DIR/$1.day" 2>/dev/null || echo 0; }

# ---------------------------------------------------------------------------
# Postfix plumbing.
# ---------------------------------------------------------------------------
need_reload=0

# Pace a transport. The setting MUST go in main.cf as <transport>_destination_*:
# rate delay and per-destination concurrency are enforced by the QUEUE MANAGER,
# which reads those. Setting smtp_destination_rate_delay with -o in master.cf
# configures the smtp client process instead and does nothing at all - which is
# why the "paced" cold start put 2,810 attempts on a brand-new IP in one minute
# on 2026-08-20, and why none of the earlier rungs ever paced anything either.
set_rate() {  # $1 transport, $2 delay
  have=$(postconf -h "$1_destination_rate_delay" 2>/dev/null)
  [ "$have" = "$2" ] && return 1
  # To stderr: every caller redirects this function's stdout to /dev/null, which
  # silently swallowed the dry run's most important output.
  if [ "$DRY" = "1" ]; then echo "DRY: would set ${1}_destination_rate_delay=$2 (is ${have:-unset})" >&2; return 1; fi
  postconf -e "$1_destination_rate_delay=$2" "$1_destination_concurrency_limit=1" >/dev/null 2>&1
  need_reload=1
  return 0
}

# Seconds behind a postfix time value ("30s", "1m", bare digits, or an unset
# $default_... which reads as zero).
delay_secs() {
  v=${1:-0}; n=$(echo "$v" | tr -dc '0-9'); [ -z "$n" ] && n=0
  case "$v" in *h) n=$(( n * 3600 ));; *m) n=$(( n * 60 ));; esac
  echo "$n"
}

# Slow a transport to at least $2, but NEVER speed it up. Without this the
# canary fights the cold-retry: a blocked address is slowed to COLD_DELAY, cools
# off, re-enters the canary pool, gets re-set to the faster CANARY_DELAY, trips
# again on the very same 180-minute window that has not moved, and is slowed
# again - two postfix reloads every quarter hour for as long as it stays
# blocked, which for the primary has so far been a week.
set_rate_min() {  # $1 transport, $2 minimum delay
  have=$(postconf -h "$1_destination_rate_delay" 2>/dev/null)
  [ "$(delay_secs "$have")" -ge "$(delay_secs "$2")" ] && return 1
  set_rate "$1" "$2"
}

# A transport per (candidate, group), created the first time a pair is seen.
# Its own syslog_name is what makes every measurement above per pair; its own
# bind address is what makes it that candidate. Created slow: the ladder speeds
# it up on evidence, never the other way round.
ensure_transport() {  # $1 transport, $2 ip
  postconf -Mf "$1/unix" 2>/dev/null | grep -q . && return 1
  if [ "$DRY" = "1" ]; then echo "DRY: would create transport $1 bound to $2" >&2; return 1; fi
  postconf -M "$1/unix=$1 unix - - n - $NEW_TRANSPORT_MAXPROC smtp -o syslog_name=postfix-$1 -o smtp_bind_address=$2 -o smtp_connection_reuse_count_limit=10" >/dev/null 2>&1 || {
    log "ERROR: could not create transport $1 for $2"; return 1; }
  postconf -e "$1_destination_rate_delay=$NEW_TRANSPORT_DELAY" "$1_destination_concurrency_limit=1" >/dev/null 2>&1
  need_reload=1
  log "created transport $1 (bind $2, $NEW_TRANSPORT_DELAY)"
  return 0
}

install_map() {  # stdin: desired map content; returns 0 if it changed
  cat > "$MAPNEW"
  if cmp -s "$MAPNEW" "$MAP" 2>/dev/null; then rm -f "$MAPNEW"; return 1; fi
  if [ "$DRY" = "1" ]; then
    echo "DRY: would install map:"; sed 's/^/DRY:   /' "$MAPNEW"; rm -f "$MAPNEW"; return 1
  fi
  mv "$MAPNEW" "$MAP"
  need_reload=1
  return 0
}

# ---------------------------------------------------------------------------
# Daily counters. Rolled here, once, rather than per pair: the counters are
# fed by a single global harvest, so the roll has to be global too.
# ---------------------------------------------------------------------------
if [ ! -f "$DAYSTAMP" ]; then
  # First run, or the state was wiped. We may be starting mid-day, so seed each
  # counter from today's log rather than handing every pair a fresh daily
  # allowance it has already spent. One scan, once - then never again.
  seed_day_counters_from_log
  echo "$today" > "$DAYSTAMP"
  stat -c %s "$MAILLOG" > "$OFFSET" 2>/dev/null || echo 0 > "$OFFSET"
  log "seeded day counters from log: $(for t in $ALLTAGS; do printf '%s=%s ' "$t" "$(day_sent "$t")"; done)"
elif [ "$(cat "$DAYSTAMP" 2>/dev/null)" != "$today" ]; then
  for t in $ALLTAGS; do
    [ -f "$DIR/$t.day" ] && mv "$DIR/$t.day" "$DIR/$t.dayprev"
    echo 0 > "$DIR/$t.day"
  done
  echo "$today" > "$DAYSTAMP"
fi
harvest
span=$(tally_span)

# How much of each group is waiting. Never a brand list: the groups are
# whatever provider-group-discover.sh currently lists, and a hardcoded
# yahoo|aol|sky|... pattern would silently measure the wrong domains the moment
# that changed - counting queue for a provider we are not routing, and missing
# the one we are.
#
# CACHED, because qshape walks every file in the queue - with 23,000 messages
# that took most of a minute, so at a one-minute cadence runs overlapped and
# each new one exited silently on the lock. The figure only gates "is there
# anything to send", where a few minutes of staleness costs nothing. One
# qshape per run serves every group.
QUEUE_CACHE=$DIR/queued.raw
QUEUE_CACHE_SECS=900
refresh_queue_cache() {
  if [ -f "$QUEUE_CACHE" ]; then
    age=$(( now - $(stat -c %Y "$QUEUE_CACHE") ))
    [ "$age" -lt "$QUEUE_CACHE_SECS" ] && return
  fi
  # BOTH queues. Counting only `deferred` measured the family's BACKLOG, not its
  # WORK: once the active candidate is being accepted the mail flows through
  # `active` and the deferred count collapses to ~0 while thousands are still
  # waiting to go. On 2026-08-22 that read as queued=2 against 10,949 active
  # yahoo messages - one tick away from "idle: nothing queued for the family"
  # unrouting the family back onto the primary, which Yahoo was still refusing
  # 4.7.0 at 14:40 that same afternoon.
  raw=$(qshape active deferred 2>/dev/null | awk 'NR > 1 && $1 != "TOTAL" {print $1, $2}')
  # A measurement that did not happen must not read as "nothing to send" - that
  # is the single value that unroutes a group. Keep the last known figures and
  # try again next tick rather than acting on a blank.
  [ -z "$raw" ] && { [ -f "$QUEUE_CACHE" ] || echo "__none__ 1" > "$QUEUE_CACHE"; return; }
  echo "$raw" > "$QUEUE_CACHE"
}
refresh_queue_cache
group_queued() {  # $1 group
  group_domains "$1" | awk 'NR == FNR { d[$1] = 1; next } ($1 in d) { t += $2 } END { print t + 0 }' - "$QUEUE_CACHE"
}

is_bulk_domain() { grep -qxF "$1" "$BULK_DOMAINS" 2>/dev/null; }

# Which transport currently carries a group's bulk. Read from a BULK domain of
# the group, not from the first map line: that is whatever canary happens to
# sort first, which is exactly not the answer to this question.
current_bulk() {  # $1 group, $2 first bulk domain of the group (or any domain)
  awk -v d="$2" '$1 == d { print $2; exit }' "$MAP" 2>/dev/null | tr -d ':'
}

# ---------------------------------------------------------------------------
# Decide, one group at a time. Nothing decided for one group is visible to
# another: state, windows, caps, cool-offs and the bulk/canary roles are all
# per (candidate, group) pair.
# ---------------------------------------------------------------------------
maplines=""
summary=""
for group in $PGROUPS; do
  gs=$(slug "$group")
  gdomains=$(group_domains "$group")
  [ -z "$gdomains" ] && continue

  # Fail CLOSED per group. With no bulk domain in this group every domain
  # reads as canary material and the bulk carrier would be handed nothing
  # whatsoever - the canary would have eaten the group. No bulk list means no
  # canary: the whole group rides the bulk address.
  gbulk=""
  for d in $gdomains; do is_bulk_domain "$d" && gbulk="$gbulk $d"; done
  gbulk=${gbulk# }
  canary_enabled=1; [ -z "$gbulk" ] && canary_enabled=0
  first_bulk=${gbulk%% *}; [ -z "$first_bulk" ] && first_bulk=$(echo "$gdomains" | head -1)

  queued=$(group_queued "$group")
  cur_bulk=$(current_bulk "$group" "$first_bulk")
  best=""; best_rung=-1; best_ip=""; best_cold=0; best_capped=""
  canary_pool=""; report=""

  for entry in $CANDIDATES; do
    ip=${entry%%:*}; rest=${entry#*:}; pre=${rest%%:*}; notbefore=${rest##*:}
    tr="${pre}${gs}"
    tag="postfix-${tr}"
    st="$DIR/$ip.$gs.state"; cf="$DIR/$ip.$gs.cooloff"
    [ -f "$st" ] || echo "date= rung=0 hot=0" > "$st"
    date=; rung=0; hot=0; lastup=0
    # shellcheck disable=SC1090
    . "$st"
    rung=$(clamp_rung "$rung")

    if [ "$notbefore" != "0" ] && [ "$now" -lt "$notbefore" ]; then
      report="$report $ip:held-until-$(date -u -d @"$notbefore" '+%H:%M')Z"
      continue
    fi

    ensure_transport "$tr" "$ip" >/dev/null

    # A pair carrying the bulk has volume, so it is judged on a short window.
    # One on the canary has tens of messages an hour and needs a long one.
    if [ "$tr" = "$cur_bulk" ]; then
      win=$REFUSE_WINDOW_MIN; minsam=$REFUSE_MIN_SAMPLES; role=bulk
    else
      win=$CANARY_WINDOW_MIN; minsam=$CANARY_MIN_SAMPLES; role=canary
    fi

    # day roll: growth is earned
    if [ "${date:-}" != "$today" ]; then
      if [ -n "${date:-}" ]; then
        prev_cap=$(rung_cap "$rung")
        prev_sent=$(cat "$DIR/$tag.dayprev" 2>/dev/null || echo 0)
        if   [ "$hot" -ge 2 ]; then rung=$(clamp_rung $(( rung - 1 ))); v="two hot episodes - down to $rung"
        elif [ "$hot" -ge 1 ]; then v="one hot episode - holding $rung"
        elif [ "$prev_sent" -ge $(( prev_cap * 8 / 10 )) ]; then rung=$(clamp_rung $(( rung + 1 ))); v="used $prev_sent/$prev_cap cleanly - up to $rung"
        else v="only $prev_sent/$prev_cap offered - holding $rung"
        fi
        log "[$group] $ip day rolled: $v"
      fi
      # The baseline carried yesterday's count across a transport rename. Leaving
      # it in place adds yesterday's total to today's, which today pushed warm1 to
      # "6804/2000" - its whole daily allowance consumed before it sent anything,
      # so every send ran on the probe path instead of the cap.
      rm -f "$DIR/$ip.$gs.baseline"
      date=$today; hot=0
    fi

    delivered=$(day_sent "$tag")
    [ -f "$DIR/$ip.$gs.baseline" ] && delivered=$(( delivered + $(cat "$DIR/$ip.$gs.baseline") ))

    # Has this pair EVER delivered? Not "today" - ever. Until it has, it is
    # cold and gets the trickle treatment regardless of its rung.
    #
    # Recorded as a marker file rather than measured: the obvious grep -c over
    # mail.log full-scans the log for every pair on every run, and at one run a
    # minute that made runs overlap and queue behind their own lock, so the
    # warm-up effectively stopped deciding. A cold pair is a once-in-its-life
    # state - the first delivery sets the marker and it is never cold again.
    cold=0
    if [ ! -f "$DIR/$ip.$gs.delivered" ]; then
      if [ "$delivered" -gt 0 ]; then
        touch "$DIR/$ip.$gs.delivered"
      else
        cold=1
      fi
    fi
    cap=$(rung_cap "$rung")
    [ "$cold" = "1" ] && cap=$COLD_CAP
    hour_cap=$(( cap / HOURLY_DIVISOR )); [ "$hour_cap" -lt 50 ] && hour_cap=50
    [ "$cold" = "1" ] && hour_cap=$(( COLD_CAP / 10 ))
    this_hour=$(count_since 60 sent "$tag")
    refused=$(count_since "$win" deferred "$tag")
    recent_sent=$(count_since "$win" sent "$tag")

    # expired cool-off is cleared so a probe can arm
    if [ -f "$cf" ] && [ "$(cat "$cf")" -le "$now" ]; then rm -f "$cf"; fi

    if [ "$refused" -ge "$minsam" ] && [ "$refused" -gt "$recent_sent" ]; then
      if [ "$cold" = "1" ]; then
        # Expected, not disqualifying. Wait out the greylist and present again;
        # do not count a hot episode or ease a rung that is already at the floor.
        if [ ! -f "$cf" ]; then
          echo $(( now + COLD_RETRY_MIN * 60 )) > "$cf"
          set_rate "$tr" "$COLD_DELAY" && log "[$group] $ip cold-start rate -> $COLD_DELAY"
          log "[$group] $ip cold: refused on first contact ($refused deferred, $recent_sent sent) - re-presenting in ${COLD_RETRY_MIN}m"
        fi
        echo "date=$date rung=$rung hot=$hot lastup=${lastup:-0}" > "$st"
        report="$report $ip:cold-retry"
        continue
      fi
      if [ ! -f "$cf" ]; then
        hot=$((hot + 1))
        rung=$(clamp_rung $(( rung - 1 )))
        set_rate "$tr" "$(rung_delay "$rung")" && log "[$group] $ip rate -> $(rung_delay "$rung")"
        echo $(( now + COOLOFF_MIN * 60 )) > "$cf"
        log "[$group] $ip REFUSED as $role ($refused deferred vs $recent_sent sent in ${win}m) - down to rung $rung, cooling off ${COOLOFF_MIN}m"
      fi
      echo "date=$date rung=$rung hot=$hot lastup=${lastup:-0}" > "$st"
      report="$report $ip:refused"
      continue
    fi

    if [ -f "$cf" ]; then
      report="$report $ip:cooling-until-$(date -u -d @"$(cat "$cf")" '+%H:%M')Z"
      echo "date=$date rung=$rung hot=$hot lastup=${lastup:-0}" > "$st"
      continue
    fi

    # Everything from here is a pair healthy enough to be given mail, so it
    # is eligible to carry a canary even if its own cap is spent.
    canary_pool="$canary_pool $tr"

    lastup=${lastup:-0}
    if [ "$role" = bulk ]; then
      # PROMOTE INTRA-DAY. Waiting for the day roll was costing real mail: on
      # 2026-08-21 warm1 was delivering with ZERO deferrals, capped by us at
      # 200/hour, while 22,589 messages queued and ~150 an hour expired unsent. A
      # rung earned by clean delivery should not have to wait for midnight to be
      # spent. Bounded to one promotion every THREE HOURS so it climbs on evidence
      # rather than on impatience - an hour was not long enough to see whether a
      # rung holds up, and the refusal check above still eases it straight down.
      #
      # Deliberately NOT conditioned on recent sends: while the cap is holding us
      # the recent window is empty by construction, so requiring sends as proof of
      # health meant the promotion could never fire - the cap suppressed its own
      # evidence. What matters is that nothing is being REFUSED while a backlog
      # waits.
      if [ "$span" -ge "$win" ] && [ "$refused" -eq 0 ] && [ "$delivered" -gt 0 ] && [ "$queued" -gt 1000 ] &&
         [ "$this_hour" -ge "$hour_cap" ] && [ $(( now - lastup )) -gt 10800 ] && [ "$rung" -lt "$maxrung" ]; then
        rung=$(clamp_rung $(( rung + 1 ))); lastup=$now
        cap=$(rung_cap "$rung"); hour_cap=$(( cap / HOURLY_DIVISOR ))
        set_rate "$tr" "$(rung_delay "$rung")" && log "[$group] $ip rate -> $(rung_delay "$rung")"
        log "[$group] $ip promoted to rung $rung ($recent_sent sent, 0 refused in ${win}m, $queued queued)"
      fi
    else
      # A canary can never satisfy a cap-based promotion: it does not have and
      # will never be given the volume, so it would prove itself accepted and sit
      # on rung 0 for ever. Judge it on ACCEPTANCE instead - clean delivery across
      # a long window - but stop at CANARY_MAX_RUNG. Taking over is what earns the
      # rest, and it takes over on the incumbent's failure, not on its own charm.
      if [ "$span" -ge "$win" ] && [ "$refused" -eq 0 ] && [ "$recent_sent" -ge "$CANARY_MIN_SENT" ] &&
         [ "$rung" -lt "$CANARY_MAX_RUNG" ] && [ $(( now - lastup )) -gt "$CANARY_PROMOTE_SECS" ]; then
        rung=$(clamp_rung $(( rung + 1 ))); lastup=$now
        cap=$(rung_cap "$rung"); hour_cap=$(( cap / HOURLY_DIVISOR ))
        log "[$group] $ip canary earned rung $rung ($recent_sent delivered, 0 refused in ${win}m)"
      fi
    fi

    # Capped is not the same as unhealthy, and the difference is the whole point.
    # Until 2026-08-22 a capped candidate was simply dropped from selection, and
    # with no other candidate ready the family fell through to `route_to ""` -
    # handing every yahoo message to the DEFAULT transport on the primary, the
    # address Yahoo had been refusing 4.7.0 since 08-15. It logged "no candidate
    # can send" 1,630 times and flipped the family onto the blocked primary and
    # back ten times on 08-22 alone. A cap is a reason to SLOW DOWN, never a
    # reason to hand the mail to an address with no evidence behind it.
    capkind=""; state=""
    if [ "$delivered" -ge "$cap" ]; then
      capkind=day; state="day-cap($delivered/$cap)"
    elif [ "$this_hour" -ge "$hour_cap" ]; then
      capkind=hour; state="hour-cap($this_hour/$hour_cap)"
    fi

    # Highest RUNG wins, and only then does uncapped beat capped. Preferring any
    # uncapped address over a capped one regardless of rung looked reasonable and
    # is badly wrong: it would hand the whole group from a proven rung-6 address
    # that had merely spent its allowance to a rung-0 one that has proved nothing,
    # which is the exact move that burned an ip in 2026-08-19. A spent allowance
    # is a reason to trickle, never a reason to change address.
    take=0
    if [ "$rung" -gt "$best_rung" ]; then take=1
    elif [ "$rung" -eq "$best_rung" ] && [ -n "$best_capped" ] && [ -z "$capkind" ]; then take=1
    fi
    if [ "$take" = "1" ]; then
      best=$tr; best_ip=$ip; best_rung=$rung; best_cold=$cold; best_capped=$capkind
    fi
    report="$report $ip:${state:-READY(rung $rung, $delivered/$cap)}"
    echo "date=$date rung=$rung hot=$hot lastup=${lastup:-0}" > "$st"
  done

  # -------------------------------------------------------------------------
  # Choose who carries this group's bulk, then who carries its canaries.
  # -------------------------------------------------------------------------
  throttled=""
  if [ -n "$best" ]; then
    bulk=$best; bulk_ip=$best_ip; bulk_rung=$best_rung; bulk_cold=$best_cold; throttled=$best_capped
  else
    # Nothing is healthy. HOLD what we have rather than unrouting: mail deferred
    # on a cooling address is retried later against whatever the map says then,
    # whereas mail handed to an unproven address is refused now and teaches that
    # address's reputation nothing good.
    bulk=$cur_bulk; bulk_ip="(held)"; bulk_rung=-1; bulk_cold=0
  fi

  if [ -n "$bulk" ]; then
    if [ "$bulk_cold" = "1" ]; then
      set_rate "$bulk" "$COLD_DELAY" >/dev/null && log "[$group] $bulk_ip cold-start rate -> $COLD_DELAY"
    elif [ "$throttled" = "day" ]; then
      set_rate "$bulk" "$DAYCAP_DELAY" >/dev/null && log "[$group] $bulk_ip day allowance spent - trickling at $DAYCAP_DELAY"
    elif [ "$throttled" = "hour" ]; then
      set_rate "$bulk" "$HOURCAP_DELAY" >/dev/null && log "[$group] $bulk_ip over hourly rate - slowed to $HOURCAP_DELAY"
    elif [ "$bulk_rung" -ge 0 ]; then
      set_rate "$bulk" "$(rung_delay "$bulk_rung")" >/dev/null && log "[$group] $bulk_ip rate -> $(rung_delay "$bulk_rung")"
    fi
  fi

  # Canaries go to every healthy pair that is not carrying the bulk, one slice
  # of the group's low-volume domains each, so each keeps earning its own
  # evidence.
  canary_pool=$(echo "$canary_pool" | tr ' ' '\n' | grep -vxF "${bulk:-__none__}" | grep -v '^$' | tr '\n' ' ')
  ncan=$(echo "$canary_pool" | wc -w)
  [ "$canary_enabled" = "0" ] && ncan=0
  for t in $canary_pool; do
    set_rate_min "$t" "$CANARY_DELAY" >/dev/null && log "[$group] canary $t paced at $CANARY_DELAY"
  done

  idx=0
  for d in $gdomains; do
    t=""
    if ! is_bulk_domain "$d" && [ "$ncan" -gt 0 ]; then
      t=$(echo "$canary_pool" | awk -v i=$(( idx % ncan )) '{print $(i + 1)}')
      idx=$((idx + 1))
    fi
    [ -z "$t" ] && t=$bulk
    [ -n "$t" ] && maplines="$maplines$d $t:
"
  done

  summary="$summary[$group] bulk=${bulk_ip:-none}($bulk) canaries=[${canary_pool% }] queued=$queued |$report
"
done

printf '%s' "$maplines" | sort | install_map && log "routing changed: $(printf '%s' "$summary" | awk '{print $1, $2}' | tr '\n' ' ')"

if [ "$need_reload" = "1" ]; then postfix reload >/dev/null 2>&1; fi

printf '%s' "$summary" | while IFS= read -r line; do [ -n "$line" ] && log "$line span=${span}m"; done
