#!/bin/bash
# Create the primary instance's handover transport into the warm instance.
set -e

# smtp_bind_address MUST be overridden. The primary sets it globally to the
# public sending address; inherited here the kernel would be asked to reach
# 127.0.0.1 from a public source address, which cannot be routed, and every
# throttled message would fail at connect.
#
# TLS off: this is a loopback hop to ourselves, so it buys nothing and costs a
# handshake per connection at tens of thousands of messages a day.
#
# No rate delay - the handover must be fast. The PACING happens in the warm
# instance, against the provider. If we paced here the messages would queue on
# the primary, which is the entire problem this exists to solve.
postconf -M "relaywarm/unix=relaywarm unix - - n - 20 smtp -o syslog_name=postfix-relaywarm -o smtp_bind_address=127.0.0.1 -o smtp_tls_security_level=none -o smtp_connection_cache_on_demand=no -o disable_dns_lookups=yes"

# Concurrency belongs in main.cf: qmgr reads <transport>_destination_*, and
# setting it with -o in master.cf configures the smtp client process instead
# and silently does nothing (the same trap that made earlier "paced" cold
# starts put thousands of attempts on a new IP in one minute).
postconf -e "relaywarm_destination_concurrency_limit=20"

postfix reload >/dev/null 2>&1
sleep 2

echo "=== relaywarm on the primary ==="
postconf -M relaywarm/unix
postconf -h relaywarm_destination_concurrency_limit
