# Service configuration by host role

Sanitised copies of the service config that lives on the machines. Read
`README.md` first for scope and restore mechanics. Every address and credential
is a `__PLACEHOLDER__`; fill them from the infrastructure inventory at restore
time. These are `.template` files precisely so nobody copies one into place
without substituting.

## db-node — `mysql/my.cnf.template`

**Read this before editing MySQL config on a db node.** `/etc/mysql/my.cnf` is a
single self-contained file with **no `!includedir`**, so it is the *only* file
that takes effect.

`/etc/mysql/percona-xtradb-cluster.conf.d/*.cnf` also exists on these hosts and
is **NOT read**. Its contents actively contradict what is running — it claims
`wsrep_cluster_address=gcomm://` (empty), `wsrep_cluster_name=pxc-cluster`, and
`wsrep_node_name=pxc-cluster-node-1` on *all three* nodes, while the live values
are a three-node `gcomm://` list, cluster `freegle`, and node names `db-1`/`db-2`
/`db-3`. Editing it does nothing; restoring it would misconfigure the cluster.
The same is true of the `/etc/mysql/my.cnf.{old,bak,cluster,standalone,fallback,
20210729,2023-02-10}` variants — stale, ignore them. Confirm anything you change
with `SHOW VARIABLES` rather than trusting a file on disk.

The template is db1's. **Per-node differences, all deliberate:**

| setting | db1 | db2 | db3 |
|---|---|---|---|
| `server-id` | 22 | 23 | 24 |
| `log-error` | `db1.log` | `db2.log` | `db3.log` |
| `bind-address` | IPv4 only | IPv4 + IPv6 | IPv4 + IPv6 |
| `innodb_buffer_pool_size` | 6G | 6G | **16G** |

Two of those are not just node identity:

- **db3 has a 16G buffer pool against 6G elsewhere.** db3 is the only active
  apiv2 backend in HAProxy (the others are `backup`), so it carries the read
  traffic. Do not "normalise" this.
- **db1 has IPv6 disabled on `bind-address`**, with the in-file note that it was
  "having issues with Google". The commented-out line above it preserves the
  original IPv6 form.

## haproxy — `haproxy/haproxy.cfg.template`

HAProxy 2.4 on the `ha` host, fronting `api.ilovefreegle.org` and others.
Validate and reload with:

```sh
haproxy -c -f /etc/haproxy/haproxy.cfg && systemctl reload haproxy
```

Things in here that have caused confusion before:

- **`timeout server 50000`** (50s). Anything slower 504s, and HAProxy's 504
  carries no CORS headers, so a browser reports it as a *CORS error* — a
  reliable red herring. Per-path overrides work on 2.4, e.g.
  `http-request set-timeout server 180s if { path_beg ... }`.
- **In `api_server_backend`, only db3 is active**; db1/db2 are `backup`. So
  benchmarking apiv2 on db1 measures a server taking no traffic.
- `stats auth` is redacted to `__STATS_USER__:__STATS_PASSWORD__`. The real value
  was stored in plaintext in the config — worth rotating and moving out of the
  file.
- TLS certificates are referenced by path (`/etc/haproxy/*.pem`) and are **not**
  captured here. They must be reissued/restored separately.

## mail-host (bulk2) — `postfix/*.template`

bulk2 runs **postfix**, not exim. `/etc/exim4/` exists and its `mainlog` is stale
from 2023 — ignore it; the live log is `/var/log/mail.log`.

- `mynetworks` contains a private range, redacted to `__IP__/24`.
- `relayhost` is empty because bulk2 IS the smart host - batch relays to it and
  it makes the final delivery to recipient MX. That is why sender reputation on
  its own IP is what matters.

### Adaptive rate shaping (`adaptive-shaper.sh`)

Postfix has adaptive per-destination concurrency, but it keys on
CONNECTION-level failure. A provider throttling us answers 4xx *after* a
successful connection and handshake, so postfix reads the destination as healthy
and keeps ramping toward `default_destination_concurrency_limit` (100 here).
Measured 2026-08-18: ~53 concurrent connections sustained against a 96% deferral
rate from the Yahoo family, whose `4.7.0 [TSSN]` response cites volume.

`adaptive-shaper.sh` (cron, every 10 min) closes that loop: it samples the log,
computes a per-domain deferral rate, and routes throttling domains through the
`shaped` transport. One transport is enough because
`smtp_destination_concurrency_limit` applies PER DESTINATION. It is deliberately
not provider-specific - on first run it caught `sky.com`, `rocketmail.com`,
`ymail.com` and `aol.co.uk` alongside the obvious yahoo/aol, which a hardcoded
rule would have missed.

Three safeguards, each added because testing showed it was needed:

- **Active-queue interlock.** Shaping makes mail wait, and waiting mail occupies
  postfix's active queue. Hitting `qmgr_message_active_limit` loses nothing
  (qmgr just stops importing from `incoming`), but an active queue full of
  shaped mail can head-of-line block destinations that were delivering fine. So
  the shaper reads active depth and relaxes at 35% of the cap, abandons shaping
  entirely at 60%.
- **Breadth-based local-problem bail, not volume-based.** If nearly every domain
  is deferring the fault is ours (IP block, DNS, disk) and shaping is wrong.
  Measuring that by VOLUME was actively harmful: one provider at 46% of traffic
  deferring 100% drags the volume figure over any threshold by itself, so the
  shaper abstained forever and never shaped the domain causing it. It counts
  domains instead, each equally.
- **A generous sample window (60k lines).** A throttling provider inflates its
  own weight, because every retry writes a log line. At 20k lines the Yahoo
  family crowded healthy domains below the attempt threshold so they dropped out
  of the sample - which tripped the bail at a bogus 8/9 domains and showed gmail
  at 51% deferred when the true wider-window figure was 17%. That would have
  shaped Gmail, which was delivering fine.

Concurrency, not rate delay: postfix forces concurrency to 1 whenever
`smtp_destination_rate_delay` is set, so combining them is self-defeating.

**Connection reuse is capped at 10 on this transport.** Yahoo accepts a limited
number of messages per SMTP connection and then closes it *without an error*;
postfix's default `smtp_connection_reuse_count_limit=0` means unlimited reuse,
so a cached connection rides past their ceiling and gets cut off mid
transaction. That is what ~2 million `lost connection with
mx-eu.mail.am0.yahoodns.net while sending RCPT TO` entries in the log are.
Concurrency shaping alone does not address it.

### What shaping cannot fix

Yahoo's own guidance is that TSS0x means the sending PATTERN is unacceptable,
and the remedy is to reduce pressure and fix the underlying cause - not retry
harder. The mechanical checks all pass here: SPF covers the sending IP, rDNS
resolves to `bulk2.ilovefreegle.org`, DKIM signs, DMARC is `p=reject`, and
RFC 8058 one-click List-Unsubscribe is implemented (`MjmlMailable.php`). So the
cause is volume and/or complaint rate.

**Enrolling in the CFL is the outstanding action, and nothing technical blocks
it** (verified 2026-08-18): outbound mail is DKIM-signed `s=z,
d=ilovefreegle.org`, that selector's key resolves, `d=` aligns with the From
domain, and DNS is ours to add the verification TXT to. It is domain-based, so
it survives an IP change. The three steps at
<https://senders.yahooinc.com/complaint-feedback-loop/> need a Yahoo login, so a
person has to do them.

**We cannot currently measure our complaint rate.** Yahoo targets below 0.1%
and restricts above 0.3%, but their old feedback loop was decommissioned at the
end of 2024 and the replacement Complaint Feedback Loop must be re-enrolled
through Sender Hub. It is domain-based and DKIM-verified, so we qualify. Until
that is done we are shaping blind to the metric Yahoo is actually judging.

To disable: remove `/etc/cron.d/postfix-adaptive-shaper`, empty
`/etc/postfix/shaped_destinations`, `postfix reload`.

## Not captured

TLS certificates and keys, DNS zones, firewall rules, package sets, users and
SSH authorised keys, and the real values behind every `__PLACEHOLDER__`. This
records service *configuration*, not the whole machine.
