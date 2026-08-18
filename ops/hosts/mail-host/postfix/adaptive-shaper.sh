#!/bin/sh
# Adaptive outbound rate shaping for postfix, by destination domain.
#
# WHY: a remote MTA that is throttling us answers 4xx AFTER a successful
# connection and handshake. Postfix's own adaptive concurrency keys on
# connection-level failure, so it reads that destination as healthy and keeps
# ramping toward default_destination_concurrency_limit. Observed on bulk2
# 2026-08-18: ~53 concurrent connections sustained against a 96% deferral rate
# from Yahoo/AOL, whose 4.7.0 [TSSN] response literally cites volume.
#
# WHAT: sample the recent log, compute a per-domain deferral rate, and route
# domains that are throttling us through a single shaped transport. One
# transport suffices because smtp_destination_concurrency_limit applies PER
# DESTINATION, so every domain routed there gets its own limit.
#
# Deliberately NOT domain-specific: whichever provider starts throttling gets
# shaped, and is released again when it recovers.
#
# Usage:
#   adaptive-shaper.sh --dry-run    decide and print, change nothing (default)
#   adaptive-shaper.sh --apply      write the map, tune postfix, reload if changed
set -eu

MAILLOG=${MAILLOG:-/var/log/mail.log}
MAP=${MAP:-/etc/postfix/shaped_destinations}
STATE=${STATE:-/var/lib/postfix-shaper/state}
# Recent log tail to judge on. Must be GENEROUS, because a throttling provider
# inflates its own weight: every retry writes a log line, so a domain deferring
# 100% produces far more lines per real message than one delivering cleanly.
# Measured 2026-08-18: at 20000 lines the Yahoo family crowded healthy domains
# (outlook, googlemail, appleid) below MIN_ATTEMPTS so they vanished from the
# sample entirely - which both tripped the local-problem bail at a bogus 8/9
# domains AND showed gmail at 51% deferred when the true figure over a wider
# window was 17%. That would have shaped Gmail, which was delivering fine.
SAMPLE_LINES=${SAMPLE_LINES:-60000}

# A domain is shaped above HIGH and released below LOW. The gap is hysteresis:
# without it a domain sitting near the threshold flaps in and out of the map and
# postfix gets reloaded every run.
HIGH_PCT=${HIGH_PCT:-40}
LOW_PCT=${LOW_PCT:-10}
MIN_ATTEMPTS=${MIN_ATTEMPTS:-50}      # ignore domains too small to judge

# Concurrency/delay applied to shaped destinations, moved between these bounds
# according to how bad things are. Never 0: that would stop delivery entirely.
CONC_MIN=${CONC_MIN:-2}
CONC_MAX=${CONC_MAX:-12}
DELAY_MAX=${DELAY_MAX:-3}

# If nearly EVERY destination is deferring, the problem is local (our IP, DNS,
# disk, a block) and shaping individual domains is the wrong response - it would
# just slow down mail that was never the issue. Bail out and say so.
GLOBAL_BAIL_PCT=${GLOBAL_BAIL_PCT:-80}

# ACTIVE-QUEUE INTERLOCK. Shaping deliberately makes mail wait, and waiting mail
# occupies postfix's active queue. Hitting qmgr_message_active_limit does not
# lose mail - qmgr just stops importing from incoming - but an active queue full
# of shaped mail can head-of-line block UNSHAPED destinations that were
# delivering perfectly well. So the amount of shaping is bounded by how much
# room is left, rather than by observing it and hoping.
#   below RELAX  : shape normally
#   above RELAX  : loosen concurrency, keep the map
#   above ABANDON: drop all shaping this run and say why - protecting everyone
#                  else matters more than being polite to one provider
ACTIVE_RELAX_PCT=${ACTIVE_RELAX_PCT:-35}
ACTIVE_ABANDON_PCT=${ACTIVE_ABANDON_PCT:-60}

MODE=dry-run
[ "${1:-}" = "--apply" ] && MODE=apply

[ -r "$MAILLOG" ] || { echo "shaper: cannot read $MAILLOG" >&2; exit 1; }

# --- interlock: how full is the active queue? --------------------------------
active_now=$(find /var/spool/postfix/active -type f 2>/dev/null | wc -l)
active_cap=$(postconf -h qmgr_message_active_limit 2>/dev/null || echo 20000)
[ "$active_cap" -gt 0 ] 2>/dev/null || active_cap=20000
active_pct=$(( active_now * 100 / active_cap ))
echo "shaper: active queue ${active_now}/${active_cap} (${active_pct}%)"

if [ "$active_pct" -ge "$ACTIVE_ABANDON_PCT" ]; then
  echo "shaper: ABANDONING shaping - active queue ${active_pct}% >= ${ACTIVE_ABANDON_PCT}%."
  echo "shaper: shaped mail is at risk of head-of-line blocking healthy destinations."
  if [ "$MODE" = "apply" ]; then
    : > "$MAP"
    : > "$STATE" 2>/dev/null || true
    postconf -e "shaped_destination_concurrency_limit=$CONC_MAX" "shaped_destination_rate_delay=0s"
    postfix reload && echo "shaper: all shaping removed"
  else
    echo "shaper: DRY RUN - would remove all shaping"
  fi
  exit 0
fi

# --- sample: per-domain sent/deferred over the recent tail --------------------
stats=$(tail -n "$SAMPLE_LINES" "$MAILLOG" 2>/dev/null | awk '
  /status=(sent|deferred)/ {
    dom = ""
    if (match($0, /to=<[^>]*@[^>]*>/)) {
      addr = substr($0, RSTART+4, RLENGTH-5)
      n = split(addr, p, "@"); dom = tolower(p[n])
    }
    if (dom == "") next
    total[dom]++
    if ($0 ~ /status=deferred/) def[dom]++
  }
  END { for (d in total) printf "%s %d %d\n", d, total[d], def[d]+0 }
')

[ -n "$stats" ] || { echo "shaper: no deliveries in sample; nothing to do"; exit 0; }

all_total=$(echo "$stats" | awk '{t+=$2} END{print t+0}')
all_def=$(echo "$stats" | awk '{d+=$3} END{print d+0}')
all_pct=$(( all_total > 0 ? all_def * 100 / all_total : 0 ))

# "Is this a LOCAL problem?" is a question about BREADTH ACROSS DOMAINS, not
# about volume. Measuring it by volume is wrong and was actively harmful: one
# provider that is 46% of traffic and deferring 100% drags the volume-weighted
# figure past any sane threshold on its own, so the shaper would abstain forever
# and never shape the very domain causing it. Count domains instead, each one
# equally, and only conclude "local" when nearly ALL of them are unhappy -
# including the ones that would otherwise be delivering fine.
doms_total=$(echo "$stats" | awk -v minn="$MIN_ATTEMPTS" '$2>=minn' | wc -l)
doms_bad=$(echo "$stats" | awk -v minn="$MIN_ATTEMPTS" -v hi="$HIGH_PCT" '$2>=minn && ($3*100/$2)>=hi' | wc -l)
doms_pct=$(( doms_total > 0 ? doms_bad * 100 / doms_total : 0 ))

if [ "$doms_total" -ge 3 ] && [ "$doms_pct" -ge "$GLOBAL_BAIL_PCT" ]; then
  echo "shaper: ABSTAINING - ${doms_bad}/${doms_total} domains deferring (${doms_pct}% >= ${GLOBAL_BAIL_PCT}%)."
  echo "shaper: nearly every destination unhappy means a LOCAL problem (IP block, DNS, disk),"
  echo "shaper: not per-provider throttling - shaping would slow mail that was never the issue."
  exit 0
fi

# --- decide: which domains to shape, carrying hysteresis from last run --------
prev=""
[ -f "$STATE" ] && prev=$(cat "$STATE" 2>/dev/null || true)

shaped=$(echo "$stats" | awk -v hi="$HIGH_PCT" -v lo="$LOW_PCT" -v minn="$MIN_ATTEMPTS" -v prev="$prev" '
  BEGIN { n=split(prev, a, /[ \n]+/); for (i=1;i<=n;i++) if (a[i]!="") was[a[i]]=1 }
  {
    dom=$1; tot=$2; def=$3
    if (tot < minn) next
    pct = def * 100 / tot
    if (was[dom]) { if (pct > lo) print dom }      # stay shaped until clearly better
    else          { if (pct >= hi) print dom }     # only shape when clearly bad
  }' | sort -u)

# --- how hard to shape: worst offender drives the setting --------------------
# CONCURRENCY, not rate delay. Two reasons:
#  1. Simultaneous connections are what a throttling MTA objects to - Yahoo's
#     TSSN is a volume/reputation signal, and cutting concurrency is the
#     documented response. A rate delay throttles total throughput instead,
#     which is a blunter instrument.
#  2. Postfix forces concurrency to 1 whenever smtp_destination_rate_delay is
#     set, so setting BOTH is self-defeating: the delay wins. An earlier version
#     of this script paired concurrency=2 with a 3s delay, which would have
#     capped a domain at ~1200 msg/hour when yahoo.co.uk alone needs ~2500 -
#     a permanent, self-inflicted backlog.
# Delay stays available but is only reached if concurrency alone is not enough,
# and is never combined with a meaningful concurrency value.
worst=$(echo "$stats" | awk -v minn="$MIN_ATTEMPTS" '$2>=minn {p=$3*100/$2; if(p>m) m=p} END{printf "%d", m+0}')
if   [ "$worst" -ge 90 ]; then conc=$CONC_MIN;              delay=0
elif [ "$worst" -ge 70 ]; then conc=$(( CONC_MIN + 1 ));    delay=0
elif [ "$worst" -ge 50 ]; then conc=$(( CONC_MIN + 3 ));    delay=0
else                           conc=$CONC_MAX;              delay=0
fi
# Interlock, gentler tier: give shaped destinations more room so the active
# queue drains, rather than waiting for the abandon threshold.
if [ "$active_pct" -ge "$ACTIVE_RELAX_PCT" ]; then
  conc=$(( conc * 3 ))
  echo "shaper: RELAXING - active queue ${active_pct}% >= ${ACTIVE_RELAX_PCT}%, widening concurrency"
fi
[ "$conc" -gt "$CONC_MAX" ] && conc=$CONC_MAX
[ "$conc" -lt "$CONC_MIN" ] && conc=$CONC_MIN

n_shaped=$(echo "$shaped" | grep -c . || true)
echo "shaper: sample=${all_total} deliveries, ${all_pct}% deferred by volume, ${doms_bad}/${doms_total} domains bad, worst ${worst}%"
echo "shaper: shaping ${n_shaped} domain(s) at concurrency=${conc} rate_delay=${delay}s"
echo "$stats" | awk -v minn="$MIN_ATTEMPTS" '$2>=minn {printf "  %-28s %5d attempts %3d%% deferred\n", $1, $2, $3*100/$2}' | sort -k4 -rn | head -8

if [ "$MODE" = "dry-run" ]; then
  echo "shaper: DRY RUN - no changes made. Would write:"
  echo "$shaped" | sed 's/^/    /' | head -12
  exit 0
fi

# --- apply -------------------------------------------------------------------
new=$(echo "$shaped" | awk 'NF {printf "%s shaped:\n", $1}')
old=$(cat "$MAP" 2>/dev/null || true)
changed=0
[ "$new" != "$old" ] && changed=1

printf '%s\n' "$new" > "$MAP"
mkdir -p "$(dirname "$STATE")"
printf '%s\n' "$shaped" > "$STATE"

cur_c=$(postconf -h shaped_destination_concurrency_limit 2>/dev/null || echo "")
cur_d=$(postconf -h shaped_destination_rate_delay 2>/dev/null || echo "")
if [ "$cur_c" != "$conc" ] || [ "$cur_d" != "${delay}s" ]; then
  postconf -e "shaped_destination_concurrency_limit=$conc" "shaped_destination_rate_delay=${delay}s"
  changed=1
fi

if [ "$changed" = "1" ]; then
  postfix reload && echo "shaper: applied and reloaded"
else
  echo "shaper: no change since last run; no reload"
fi
