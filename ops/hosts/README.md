# Host configuration (outside the containers)

Configuration that lives on the machines themselves rather than in an image or a
container, captured here so a rebuilt server can be brought back to a known
state. Everything under this directory is safe to read: no credentials, no
private IPs, no personal addresses. Where a value is environment-specific it is
a `__PLACEHOLDER__`.

This is a **record, not a deployment mechanism** — nothing applies these files
automatically. Copy them into place and reload the service (see below).

## Why this exists

Container config is in git and rebuilt from the image; host config was not
captured anywhere. On 2026-08-18 two monit changes were made directly on the
batch host — a routing restart-grace fix and a new mail-spool backlog alarm —
and existed on exactly one machine, with nothing to restore them from. Anything
here that is only on one box is one rebuild away from being lost silently,
because monit failing to watch something looks identical to nothing being wrong.

## What is here

See `SERVICES.md` for the non-monit service config (MySQL/Galera, HAProxy,
postfix) and, importantly, which files on those hosts are live versus stale.

```
monit/
  batch-host/          the host running batch-prod, spatial-knn, routing, nginx
    monitrc.settings   the live (non-comment) settings of /etc/monit/monitrc
    conf.d/            service checks -> /etc/monit/conf.d/
    scripts/           health/probe scripts -> /etc/monit/scripts/ (chmod +x)
  db-node/             db1, db2, db3 - byte-identical on all three
    monitrc.settings
    conf.d/
```

`monitrc.settings` holds only the lines that differ from the stock Debian
`monitrc` (the rest of that file is comments). Restore by editing the packaged
file, not by replacing it.

**A monit check is not enough on its own.** If a check names a start program
outside `/etc/monit`, capture that program here too. The photon geocoder, since
retired, was the worked example: its check named `/etc/photon`, which needed
both a launcher and a systemd drop-in on `monit.service`. Restoring the check
without those gave a monit that watched the service, failed to start it, and
retried every 2 minutes forever.

## Restoring onto a rebuilt host

```sh
# 1. files
cp conf.d/*            /etc/monit/conf.d/
cp scripts/*.sh        /etc/monit/scripts/ && chmod +x /etc/monit/scripts/*.sh
#    alerts.conf.template -> /etc/monit/conf.d/alerts.conf, substituting __ALERT_EMAIL__
# 2. apply monitrc.settings to /etc/monit/monitrc, substituting any __PLACEHOLDER__
# 3. validate BEFORE reloading - a syntax error leaves monit not watching anything
monit -t
monit reload
monit summary            # every service should reach OK; "Initializing" for one
                         # cycle after a reload is normal
```

Two monit gotchas worth knowing before you touch this:

- **Backups must not live in `conf.d/`.** monit includes *every* file in that
  directory, so a `spatial.bak` there becomes a duplicate service definition and
  `monit -t` fails with "Service name conflict". Keep them in
  `/etc/monit/backups/` (which is where this host's are).
- **Many rapid `reload`/`unmonitor`/`monitor` calls can wedge the daemon**
  (services stuck "Initializing" across cycles). `systemctl restart monit`
  fixes it and restarts only the daemon, not the monitored processes.

## Cycle length differs by role — check before reading any grace period

| host | `set daemon` | so "for 15 cycles" means |
|---|---|---|
| batch host | 120s | 30 minutes |
| db1/2/3 | 60s | 15 minutes |

Every `if ... for N cycles` in these files is in cycles, not minutes. The same
number means different things on the two roles, which is easy to get wrong when
copying a check from one to the other.

## Deliberately NOT here

- **Secrets, credentials, private IPs, personal addresses.** `__ALERT_EMAIL__`
  and `__NODE_FQDN__` are placeholders; fill them in at restore time.
- **Anything already in git** — container images, `docker-compose*.yml`,
  supervisor config (that is image-baked from `iznik-batch/docker/supervisor.conf`).
- **TLS certificates and private keys, DNS zones, firewall rules, package sets,
  users and SSH keys.** What is captured is service *configuration* only - monit
  (this file) plus MySQL/Galera, HAProxy and postfix (`SERVICES.md`).
  It is a start on capturing host state, not a complete build recipe, and should
  not be read as one.
- **Generated data**, however painful to rebuild: the routing graph, the places
  index, caches. Config only.

See also `docs/ops/monitoring-and-logging.md` for what the monitoring
actually watches and how alerts reach people.
