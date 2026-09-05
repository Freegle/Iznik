#!/bin/bash
# provider-group-discover.sh - maintain the set of provider GROUPS our primary
# IP is being refused by, and each group's domain membership, for
# /usr/local/sbin/ip-warmup.sh to warm and route PER GROUP.
#
# Nothing here knows what "Yahoo" is. A provider is defined the only way that
# matters for reputation: a set of recipient domains sharing an inbound MX.
# That grouping is why btinternet.com must NOT be lumped in with Yahoo (it is
# Openwave-hosted) while sky.com and aim.com must be (they are not Yahoo brands
# but their MX is *.yahoodns.net).
#
# NO WINNER (2026-09-03). Until today this script chose ONE group for the
# warm-up chain - the one with the most 4.7.x refusals - because ip-warmup.sh
# could only warm one group at a time. That contest is why Virgin Media's
# policy refusals took the chain away from Yahoo on 2026-09-02 08:17Z and left
# 10,000 Yahoo-family members dark for 33 hours: the primary's Yahoo canary had
# made zero attempts inside a sample that covered 102 of the 360 minutes asked
# for, so "0 refusals" read as "accepting", and once Virgin held the seat its
# own refusals kept it there. ip-warmup.sh now runs every (IP, group) pair
# independently, so there is nothing to choose: every group the primary is
# being refused by goes in, and it stays in until the primary is CONFIRMED
# accepting it - more sends than refusals, from the primary, in a window the
# sample actually covers. Nothing about one provider can touch another.
#
# MEMBERSHIP (2026-08-29): a group's domain list is derived from ALL recipient
# domains seen in recent traffic whose MX matches the group - not just the
# domains that happened to be refused this window. Refusal-derived membership
# shrank the family 34->13 across a swap-and-back, leaving the low-volume TLDs
# (yahoo.ca, yahoo.ie, yahoo.co.in ...) permanently unrouted.
set -u
MAILLOG=/var/log/mail.log
OUT=/etc/postfix/warmup-groups           # lines: <domain> <group>
LOG=/var/log/provider-discover.log
CANDIDATES_FILE=/etc/postfix/warmup-candidates
WINDOW_MIN=${1:-180}
MIN_REFUSALS=50                          # 4.7.x refusals from the primary before a group enters play
# Lines, not minutes: 400k covered 102 of 360 minutes at morning volume on
# 2026-09-02. Three million reaches back a day at peak (0.4s to seek) and the
# coverage check below still guards the case where it does not.
SAMPLE=${SAMPLE:-3000000}
# Leaving play needs the primary CONFIRMED accepting the group: at least this
# many sends, and more sends than refusals, inside a fully covered window.
CONFIRM_MIN_SENT=10
# A domain must appear this often in the traffic sample before we spend an MX
# lookup on it - filters the typo-domain long tail (gmali.com, iclood.com ...)
# which mostly has no MX anyway.
TRAFFIC_MIN=5
STATE=/var/lib/provider-discover
CACHE=$STATE/mxcache            # lines: <domain> <group> <epoch-resolved>
CACHE_TTL=$((7*86400))

mkdir -p "$STATE"
touch "$CACHE"

# One run at a time: the hourly cron and a manual (re)seeding run with a big
# SAMPLE must not interleave their cache and OUT writes.
exec 9>"$STATE/lock"
flock -n 9 || { echo "$(date -u '+%F %H:%M')Z another run holds the lock - skipping" >> "$LOG"; exit 0; }

stamp() { date -u '+%F %H:%M'; }

# Reputation boundary = the operator's registrable domain, not an arbitrary
# label count. Three labels split aol.com (mx-aol.mail.gm0.yahoodns.net) from
# yahoo.com (mta*.am0.yahoodns.net) into two "providers" when they are one
# sender reputation - which dropped AOL out of the failover set entirely. Two
# labels is right except where the second-level is a public suffix (co.uk,
# com.au ...), where we need three.
mx_group() {
  echo "$1" | awk -F. '{
    if (NF < 2) { print $0; next }
    sld = $(NF-1)
    if (sld ~ /^(co|com|org|net|ac|gov|edu)$/ && NF >= 3)
      print $(NF-2)"."$(NF-1)"."$NF
    else
      print $(NF-1)"."$NF
  }'
}

# Resolve a recipient domain to its MX group, through the cache. A failed
# lookup falls back to any cached answer regardless of age: DNS being down
# must never shrink a group - a check that did not run is not a result.
dom_group() {
  local dom=$1 now cached cgrp cepoch mx grp
  now=$(date +%s)
  cached=$(awk -v d="$dom" '$1 == d {print $2, $3}' "$CACHE" | tail -1)
  if [ -n "$cached" ]; then
    cgrp=${cached% *}; cepoch=${cached#* }
    if [ $((now - cepoch)) -lt "$CACHE_TTL" ]; then echo "$cgrp"; return; fi
  fi
  mx=$(dig +short mx "$dom" 2>/dev/null | sort -n | head -1 | awk '{print $2}' | sed 's/\.$//')
  if [ -z "$mx" ]; then
    [ -n "$cached" ] && echo "${cached% *}"
    return
  fi
  grp=$(mx_group "$mx")
  { grep -v "^$dom " "$CACHE" 2>/dev/null; echo "$dom $grp $now"; } > "$CACHE.new"
  mv "$CACHE.new" "$CACHE"
  echo "$grp"
}

since=$(date -u -d "$WINDOW_MIN min ago" '+%b %e %H:%M')
tmp=$(mktemp); trap 'rm -f "$tmp" "$tmp".*' EXIT

# The window slice is scanned several times - cut it once. Only the slice is
# written out: the full tail can be hundreds of MB on a 2GB box.
tail -n "$SAMPLE" "$MAILLOG" | awk -v s="$since" '$0 >= s' > "$tmp.win"

# COVERAGE (2026-09-03): SAMPLE is a line count, so at morning volume it can
# reach back far less than WINDOW_MIN (400k lines covered 102 of 360 minutes on
# 2026-09-02 08:17Z). A short slice must never be mistaken for a quiet one - it
# is a check that did not run. Entering play on a short slice is fine (a
# refusal is a refusal); LEAVING play needs the whole window.
first_line=$(tail -n "$SAMPLE" "$MAILLOG" | head -1 | cut -c1-15)
covered=1
if [ -n "$first_line" ] && [ "$first_line" \> "$since" ]; then
  covered=0
  echo "$(stamp)Z sample of $SAMPLE lines reaches only $first_line, not ${WINDOW_MIN}m back to $since - no group leaves play on a short window" >> "$LOG"
fi

# Only the PRIMARY's conversations answer "is the primary being refused". Every
# other candidate has its own transports (prefix from warmup-candidates, e.g.
# postfix-warm1yahoodnsnet); exclude those lines from both counts.
primary_ip=$(postconf -h smtp_bind_address 2>/dev/null)
nonprimary_re=$(awk -v p="$primary_ip" '!/^[[:space:]]*(#|$)/ && $1 != p {printf "%s%s", (n++ ? "|" : ""), "postfix-" $2 "[a-z0-9]*/"}' "$CANDIDATES_FILE" 2>/dev/null)
if [ -n "$nonprimary_re" ]; then grep -vE "$nonprimary_re" "$tmp.win" > "$tmp.prim"; else cp "$tmp.win" "$tmp.prim"; fi

# Recipient domains refused 4.7.x by the primary (reputation/volume refusals).
grep -E "status=deferred.*4\.7\.[0-9]" "$tmp.prim" \
  | grep -oE "to=<[^>]+>" | sed -E 's/.*@//; s/>//' | tr 'A-Z' 'a-z' \
  | sort | uniq -c | sort -rn > "$tmp.ref"
# Recipient domains the primary DELIVERED to.
grep -E "status=sent" "$tmp.prim" \
  | grep -oE "to=<[^>]+>" | sed -E 's/.*@//; s/>//' | tr 'A-Z' 'a-z' \
  | sort | uniq -c | sort -rn > "$tmp.sent"

# Group the refused domains by MX suffix: <group> <domain> <n>.
: > "$tmp.mx"
while read -r n dom; do
  [ -z "${dom:-}" ] && continue
  grp=$(dom_group "$dom")
  [ -z "$grp" ] && continue
  echo "$grp $dom $n" >> "$tmp.mx"
done < "$tmp.ref"

# Groups currently in play.
existing=""
[ -f "$OUT" ] && existing=$(awk '!/^[[:space:]]*(#|$)/ && NF >= 2 {print $2}' "$OUT" | sort -u)

# Groups whose refusals qualify them to enter.
entering=$(awk '{g[$1]+=$3} END {for (k in g) print g[k], k}' "$tmp.mx" | awk -v m="$MIN_REFUSALS" '$1 >= m {print $2}')

# Decide the new set: existing groups stay unless the primary is confirmed
# accepting them; qualifying groups join.
keep=""
for g in $existing; do
  icount=$(awk -v g="$g" '$1 == g {s+=$3} END {print s+0}' "$tmp.mx")
  # Sends from the primary to this group's LISTED domains (the membership we
  # already hold is the cheapest and most honest definition of the group).
  isent=$(awk -v g="$g" '!/^[[:space:]]*(#|$)/ && $2 == g {print $1}' "$OUT" | sort -u \
    | awk 'NR == FNR {fam[$1]=1; next} ($2 in fam) {s+=$1} END {print s+0}' - "$tmp.sent")
  if [ "$covered" = "1" ] && [ "$isent" -ge "$CONFIRM_MIN_SENT" ] && [ "$isent" -gt "$icount" ]; then
    echo "$(stamp)Z $g leaves play: primary confirmed accepting it ($isent sent vs $icount refused in ${WINDOW_MIN}m)" >> "$LOG"
    continue
  fi
  keep="$keep $g"
done
for g in $entering; do
  echo " $keep " | grep -q " $g " && continue
  n=$(awk -v g="$g" '$1 == g {s+=$3} END {print s+0}' "$tmp.mx")
  echo "$(stamp)Z $g enters play: $n 4.7.x refusals from the primary in ${WINDOW_MIN}m" >> "$LOG"
  keep="$keep $g"
done
groups=$(echo "$keep" | tr ' ' '\n' | grep -v '^$' | sort -u)

# Membership: every domain with real traffic whose MX is in the group, plus
# whatever was refused this window, plus what is already listed and still
# resolves into the group. Recent traffic alone must not decide REMOVAL: the
# first ever run dropped aol.com purely because that morning's burst had
# already cleared its backlog.
grep -E "status=(sent|deferred|bounced)" "$tmp.win" \
  | grep -oE "to=<[^>]+>" | sed -E 's/.*@//; s/>//' | tr 'A-Z' 'a-z' \
  | sort | uniq -c | awk -v m="$TRAFFIC_MIN" '$1 >= m {print $2}' > "$tmp.traffic"

: > "$tmp.new"
for g in $groups; do
  fam=$(awk -v g="$g" '$1 == g {print $2}' "$tmp.mx" | sort -u)
  while read -r d; do
    [ -z "${d:-}" ] && continue
    grp=$(dom_group "$d")
    [ "$grp" = "$g" ] && fam=$(printf '%s\n%s' "$fam" "$d")
  done < "$tmp.traffic"
  if [ -f "$OUT" ]; then
    for d in $(awk -v g="$g" '!/^[[:space:]]*(#|$)/ && $2 == g {print $1}' "$OUT"); do
      grp=$(dom_group "$d")
      [ "$grp" = "$g" ] && fam=$(printf '%s\n%s' "$fam" "$d")
    done
  fi
  echo "$fam" | grep -v '^$' | sort -u | awk -v g="$g" '{print $1, g}' >> "$tmp.new"
done

if [ -z "$groups" ]; then
  if [ -n "$existing" ]; then
    echo "$(stamp)Z every group left play - $OUT emptied" >> "$LOG"
  fi
fi
[ -n "$groups" ] && [ ! -s "$tmp.new" ] && { echo "$(stamp)Z derived an EMPTY membership for [$groups] - refusing to write it" >> "$LOG"; exit 0; }

new=$(sort -u "$tmp.new")
if [ -f "$OUT" ] && [ "$(grep -vE '^[[:space:]]*(#|$)' "$OUT" | sort -u)" = "$new" ]; then
  echo "$(stamp)Z unchanged: $(for g in $groups; do printf '%s(%s) ' "$g" "$(awk -v g="$g" '$2 == g' "$tmp.new" | wc -l)"; done)" >> "$LOG"
  exit 0
fi

[ -f "$OUT" ] && cp "$OUT" "$OUT.bak-$(date -u +%Y%m%d-%H%M%S)"
{
  echo "# AUTO-DERIVED by provider-group-discover.sh - do not hand-edit."
  echo "# <domain> <group>: every provider group the primary IP is being refused"
  echo "# by (>= $MIN_REFUSALS 4.7.x refusals in ${WINDOW_MIN}m), with every domain seen in"
  echo "# recent traffic whose MX resolves into that group. A group stays listed"
  echo "# until the primary is confirmed accepting it again. Grouping is by MX,"
  echo "# never by brand name: that is why a same-brand domain can be absent and"
  echo "# an unrelated-looking one present. ip-warmup.sh warms and routes each"
  echo "# group independently."
  echo "# Written $(stamp)Z"
  echo "$new"
} > "$OUT"
echo "$(stamp)Z rewrote $OUT: $(for g in $groups; do printf '%s(%s) ' "$g" "$(awk -v g="$g" '$2 == g' "$tmp.new" | wc -l)"; done)" >> "$LOG"
