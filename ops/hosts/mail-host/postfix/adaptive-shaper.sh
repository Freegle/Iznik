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
# State lines are "domain shapedflag votes". If the format ever changes again,
# seed it from the live map rather than letting a stale format read as
# "everything unshaped" - that would drop every active suppression for a scan:
#   cut -d' ' -f1 /etc/postfix/shaped_destinations | awk 'NF {print $1, 1, 0}' > STATE
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
# 70, not 40. A throttling provider is unmistakable - the Yahoo family sits at
# 99-100% deferred. A busy-but-healthy one is not: gmail measured ~39% deferred
# on 2026-08-18 while simultaneously accounting for 133 of the last 300
# SUCCESSFUL deliveries, more than any other domain. A 40% threshold shaped it,
# throttling the largest healthy destination we have. Partial deferral is normal
# for a big provider under load; near-total deferral is a throttle. 70 separates
# them with margin.
HIGH_PCT=${HIGH_PCT:-70}
LOW_PCT=${LOW_PCT:-30}
MIN_ATTEMPTS=${MIN_ATTEMPTS:-50}      # ignore domains too small to judge
# Successful deliveries in the window above which a domain is never shaped, and
# is released if it was. Immune to the retry-inflation feedback above.
MIN_SENT_RELEASE=${MIN_SENT_RELEASE:-20}
# ...but a trickle is not acceptance. A provider throttling us can still let a
# handful through: on 2026-08-22 yahoo.co.uk logged 4,834 attempts at 96%
# deferred - about 190 delivered - and 190 clears any absolute floor, so the
# release rule below read it as healthy and let it go. It was shaped and
# released 305 times, reloading postfix each time, while the queue held ~6,000
# of its messages and the oldest reached the five-day lifetime and bounced.
#
# So deliveries release a domain only when it is NOT deferring catastrophically.
# The bar is set high on purpose, well above any load-related deferral: gmail
# measured 39-48% deferred while healthy (and 52% of its attempts delivered),
# where the throttled Yahoo family sits at 96-100%. Nothing legitimate lives up
# there, so this cannot re-create the "once shaped, always shaped" trap that
# releasing on the deferral rate alone caused - a recovering domain crosses back
# under 90% long before it looks healthy by any other measure.
CATASTROPHIC_PCT=${CATASTROPHIC_PCT:-90}
# Deferred count in the sample below which a delivering domain is fully
# released. Above it the domain keeps a (widening) concurrency cap so the
# backlog drains at a rate the provider has shown it will accept, instead of
# being fired at them all at once the moment they relent.
MAX_DEFERRED_RELEASE=${MAX_DEFERRED_RELEASE:-100}

# Consecutive scans a domain must agree before its state changes. Single-scan
# decisions flap: gmail measured sent=34, then 20, then 5 across three
# consecutive minutes on 2026-08-18 as full-mailbox retries batched up, and any
# fixed threshold sitting in that spread toggles with whichever minute the
# sample happens to cover. Shaping and releasing our largest healthy destination
# every few minutes is worse than either state. The deferral scanner damps the
# same way with release_clear_scans.
AGREE_SCANS=${AGREE_SCANS:-2}

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
# Loosened from 35/60 after watching it on 2026-08-18. Active-queue depth is
# driven mostly by qmgr retrying the DEFERRED BACKLOG (93k messages, backoff
# capped at maximal_backoff_time=4000s), not by our shaping - so 35% of cap is
# ordinary postfix behaviour with a large backlog, and the interlock was firing
# on it every run. Verified no harm at that depth: unshaped destinations
# (hotmail, outlook, trashnothing) kept delivering throughout. The interlock is
# a genuine safety net - when it did fire it drained active from 15887 to 778 -
# so it stays, just at levels that mean something.
ACTIVE_RELAX_PCT=${ACTIVE_RELAX_PCT:-50}
ACTIVE_ABANDON_PCT=${ACTIVE_ABANDON_PCT:-75}

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

    # PER-MAILBOX failures are not provider throttling and must not be counted
    # as either. A 452 4.2.2 out-of-storage is one full mailbox; it says nothing
    # about whether the provider will accept our mail, and it retries for days,
    # so counting it inflates a healthy domain deferral ratio without limit.
    # Measured 2026-08-18: gmail 497 x 452-4.2.2 (all mailbox-full) against
    # yahoo 7701 x 421 4.7.0 (all provider throttle) - the codes separate them
    # cleanly. Counting both is what repeatedly made gmail, our largest
    # DELIVERING destination, look throttled and get shaped.
    # NB: no apostrophes in here - this whole block sits inside awk '...' and a
    # single quote silently terminates the shell quoting.
    if ($0 ~ /4\.2\.2|452[- ]|over quota|out of storage|[Mm]ailbox full|quota exceeded/) next

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
# "Is the fault OURS?" reduces to one question: is ANYTHING getting through?
#
# Counting how many domains look bad does not survive contact with reality. The
# ratio moves when the threshold moves and when healthy low-volume domains drop
# below MIN_ATTEMPTS, so it reported 8/9 at one setting and tripped again at
# another - twice making the shaper abstain at exactly the moment it was needed.
# Volume was worse still: one provider at 46% of traffic dominated it outright.
#
# Deliveries are unambiguous. If mail is landing anywhere, our IP, DNS and disk
# are fine and the problem belongs to specific providers - which is precisely
# what this script exists to handle. Only when NOTHING is being accepted
# anywhere is the fault plausibly ours, and then shaping is the wrong tool.
doms_total=$(echo "$stats" | awk -v minn="$MIN_ATTEMPTS" '$2>=minn' | wc -l)
sent_total=$(( all_total - all_def ))

# IDLE is not BROKEN. Overnight the site generates no mail, so "few deliveries"
# means there was nothing to deliver - not that we cannot. Judging that as a
# local fault stopped the shaper evaluating at all overnight, which also stopped
# it RELEASING a domain that recovered while we slept. A verdict needs a
# denominator: how much did we actually attempt?
attempts_excl_shaped=$(echo "$stats" | awk -v minn="$MIN_ATTEMPTS" '{t+=$2} END{print t+0}')

if [ "$attempts_excl_shaped" -lt 200 ]; then
  echo "shaper: idle - only ${attempts_excl_shaped} delivery attempts in the sample; nothing to judge."
  echo "shaper: leaving the current shaping unchanged."
  exit 0
fi

if [ "$sent_total" -lt "$MIN_SENT_RELEASE" ]; then
  echo "shaper: ABSTAINING - ${attempts_excl_shaped} attempts but only ${sent_total} deliveries."
  echo "shaper: trying hard and landing nothing ANYWHERE means a LOCAL problem (IP block, DNS,"
  echo "shaper: disk) - not per-provider throttling, and shaping would slow mail that was never"
  echo "shaper: the issue."
  exit 0
fi

# --- decide: which domains to shape, carrying hysteresis from last run --------
prev=""
[ -f "$STATE" ] && prev=$(cat "$STATE" 2>/dev/null || true)

# RELEASE ON DELIVERIES, NOT ON THE DEFERRAL RATE. Shaping inflates a domain's
# own deferral rate: shaped mail queues, gets retried, and every retry logs
# another "deferred" line. Observed 2026-08-18 - gmail read 39% when it was
# shaped and 48% once shaped, so a release rule comparing that rate to a
# threshold can never fire. Once shaped, always shaped.
#
# Successful deliveries cannot be inflated that way. A domain that is actually
# accepting mail should never stay throttled, whatever its deferral ratio looks
# like: yahoo.co.uk delivered 0 of 5571 attempts, gmail delivered ~253 of 487.
# That distinction is the whole decision.
shaped=$(echo "$stats" | awk -v hi="$HIGH_PCT" -v minn="$MIN_ATTEMPTS" -v minsent="$MIN_SENT_RELEASE" -v catpct="$CATASTROPHIC_PCT" -v agree="$AGREE_SCANS" -v prev="$prev" '
  BEGIN {
    # state lines are "domain shapedflag votes"
    n=split(prev, L, /\n/)
    for (i=1;i<=n;i++) { if (L[i]=="") continue; split(L[i], f, " "); was[f[1]]=f[2]+0; votes[f[1]]=f[3]+0 }
  }
  {
    dom=$1; tot=$2; def=$3
    if (tot < minn) next
    sent = tot - def
    pct  = def * 100 / tot
    # What THIS scan thinks, before any damping. Delivering means healthy,
    # whatever the ratio says - deliveries are the one signal shaping cannot
    # inflate.
    # +0 forces numeric: a domain absent from the state file yields an unset
    # value, and comparing "" against a number in awk is ambiguous - it also
    # printed a blank flag column, which only re-parsed correctly by luck of
    # whitespace splitting.
    prevstate = was[dom] + 0
    # Delivering means healthy - unless it is deferring nearly everything, in
    # which case the deliveries are a trickle past a throttle rather than a
    # provider accepting our mail.
    delivering = (sent >= minsent && pct < catpct)
    want = delivering ? 0 : ((prevstate ? (pct >= hi - 20) : (pct >= hi)) ? 1 : 0)

    # Only change state once `agree` consecutive scans want the same thing.
    if (want == prevstate) { v = 0 }
    else                   { v = votes[dom] + 0 + 1 }

    state = prevstate
    if (v >= agree) { state = want; v = 0 }

    print dom, state, v
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

n_shaped=$(echo "$shaped" | awk '$2 == 1' | grep -c . || true)
echo "shaper: sample=${all_total} attempts, ${sent_total} delivered, ${all_pct}% deferred, ${doms_total} domains, worst ${worst}%"
echo "shaper: shaping ${n_shaped} domain(s) at concurrency=${conc} rate_delay=${delay}s"
echo "$stats" | awk -v minn="$MIN_ATTEMPTS" '$2>=minn {printf "  %-28s %5d attempts %3d%% deferred\n", $1, $2, $3*100/$2}' | sort -k4 -rn | head -8

if [ "$MODE" = "dry-run" ]; then
  echo "shaper: DRY RUN - no changes made. Would write:"
  echo "$shaped" | awk '$2 == 1 {print "    " $1}' | head -12
  echo "$shaped" | awk '$3 > 0 {printf "    (%s pending change, %s/%s scans agree)\n", $1, $3, "'"$AGREE_SCANS"'"}' | head -6
  exit 0
fi

# --- apply -------------------------------------------------------------------
new=$(echo "$shaped" | awk '$2 == 1 {printf "%s shaped:\n", $1}')
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
