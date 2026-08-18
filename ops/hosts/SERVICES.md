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
- `relayhost` is empty: bulk2 delivers direct to recipient MX, which is why
  sender reputation on its own IP matters so much. As of 2026-08-18 Yahoo/AOL
  were deferring ~96% of mail to them with `4.7.0 [TSSN]` against that IP, and
  they are ~46% of volume.

## Not captured

TLS certificates and keys, DNS zones, firewall rules, package sets, users and
SSH authorised keys, and the real values behind every `__PLACEHOLDER__`. This
records service *configuration*, not the whole machine.
