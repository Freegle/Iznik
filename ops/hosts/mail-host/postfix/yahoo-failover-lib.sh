# Deliveries in the last $1 minutes, by transport, as key=value.
#
# The window matters. Yahoo's throttle admits a BURST and then closes: on
# 2026-08-19 the secondary ip delivered 995 messages in one minute and zero in
# the three that followed. Counted over a whole log tail that reads as
# "delivering fine" for as long as the burst stays in the tail, so a guard
# using the aggregate would never fire. Judge only the recent minutes.
#
# Syslog stamps are "Aug 19 05:25:49" with no year, so rather than parse them
# we generate the minute keys we want and match. That is correct across hour,
# day, month and year boundaries, which string comparison is not.
recent_counts() {
    _mins=${1:-10}
    _keys=$(_i=0; while [ "$_i" -lt "$_mins" ]; do
                date -d "-$_i minutes" '+%b %-d %H:%M'; _i=$((_i + 1)); done | tr '\n' '|')
    # Bounded by BYTES: mail.log is ~2GB and a line-counted tail reads the lot.
    tail -c 20000000 /var/log/mail.log 2>/dev/null | awk -v keys="$_keys" '
        BEGIN { n = split(keys, k, "|"); for (i = 1; i <= n; i++) if (k[i] != "") want[k[i]] = 1 }
        { stamp = $1 " " $2 " " substr($3, 1, 5) }
        !(stamp in want) { next }
        /postfix-yahoo\/smtp/ && /status=sent/     { ys++ }
        /postfix-yahoo\/smtp/ && /status=deferred/ { yd++ }
        /postfix\/smtp\[/     && /status=sent/     { ps++ }
        /postfix\/smtp\[/     && /status=deferred/ { pd++ }
        END { printf "secondary_sent=%d secondary_deferred=%d primary_sent=%d primary_deferred=%d\n",
                     ys+0, yd+0, ps+0, pd+0 }'
}
