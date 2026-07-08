# Batch-prod host reboot runbook (hardware upgrade 2026-07-03)

This host = FreegleDocker / batch-prod. It does NOT run the prod DB or apiv2 (those are on
db1/2/3 + Netlify), so the Freegle site stays up during the reboot. What pauses: batch crons
(digests, mail, ripple), the LOCAL spatial/routing/geocoder, and MJML email rendering.

> **UPDATE 2026-07-08 — edge Stage 1 (adopt tiles + wiki into Compose).** The
> standalone `confident_curran` (tile server), `wiki-media` and `wiki-mysql`
> containers are now Compose services under the `edge` profile
> (`freegledocker-tile-server`, `-wiki-media`, `-wiki-mysql`), so they are
> started/stopped by `freegle-docker.service` like the rest of the stack — no
> longer separate standalone containers. `ors-app` and the stale
> `discourse.ilovefreegle.org` nginx vhost have been dropped (ORS is superseded
> by the native spatial server on db1/2/3; Discourse is hosted externally). The
> host `.env` now carries `COMPOSE_PROFILES=backend,production,mail,edge` and
> `COMPOSE_FILE=docker-compose.yml:docker-compose.override.yml:docker-compose.override.edge.yml`.
> The images tier (`frontend-nginx`/`delivery`/`tusd`) is defined under `edge`
> but held at `replicas:0`, so it does NOT start on boot yet (its migration is a
> later stage). The inventory below reflects this post-Stage-1 state.

## Reboot is graceful — no manual stop needed
`sudo reboot` cleanly stops everything:
- **freegledocker stack** → `freegle-docker.service` (enabled) `ExecStop=docker compose stop`, TimeoutStopSec=120. As of edge Stage 1 this includes the adopted tile + wiki services (see the update note above); there are no non-`freegledocker` standalone app containers left.
- **photon** (native java/ES, monit-managed) → systemd final-kill SIGTERM (90s) → ES clean shutdown.
Everything auto-restarts on boot (systemd unit `compose up -d`, restart policies, monit).

## Everything that SHOULD be running after reboot

### freegledocker compose project (auto-started by freegle-docker.service; COMPOSE_PROFILES=backend,production,mail,edge)
- freegledocker-batch-prod   (the scheduler — prod crons)      restart: unless-stopped
- freegledocker-spatial       (iznik-routing-go, 8196, ~6G graph — rebuilds on start ~7 min) **no** (comes back via compose up; a crashed spatial stays down and batch-prod only gates on it at startup)
- freegledocker-spatial-knn   (iznik-spatial-go, 8194)          **no** (same as spatial)
- freegledocker-mjml          (email render — digests need it)  restart: **no** (comes back via compose up)
- freegledocker-redis                                            **no**
- freegledocker-postfix                                          unless-stopped
- freegledocker-rspamd                                           **no**
- freegledocker-spamassassin                                     **no**
- freegledocker-loki          (logs)                             unless-stopped
- freegledocker-embedding-sidecar (semantic search)             unless-stopped
- freegledocker-ai-support-helper                                **no**
- freegledocker-status        (monitoring UI)                    **no**
- freegledocker-tile-server   (overv tiles; adopted, `edge`)     restart: **no** (comes back via compose up); publishes :8080
- freegledocker-wiki-mysql    (wiki DB; adopted, `edge`)         **no**
- freegledocker-wiki-media    (wiki; adopted, `edge`)            **no**; publishes :8088, depends_on wiki-mysql healthy
(the **no** ones only come back because freegle-docker.service runs `docker compose up -d` — a
bare `docker start` after a crash would NOT restart them. The adopted edge services previously had
`restart: always` as standalone containers; under Compose they rely on the systemd unit like the
rest of the stack. Hardening their restart policy is tracked with the images-tier migration.)
- `edge` images tier (`freegledocker-frontend-nginx`/`-delivery`/`-tusd`) is defined but held at
  `replicas:0` — it does NOT start yet (native nginx still owns :80/:443/:8080).

### Native, under monit
- photon (geocoder, java, port 2322, /etc/photon, 6.3G ES index)
- nginx (fronts tiles.ilovefreegle.org → :8080 and wiki.ilovefreegle.org → :8088; the stale
  discourse vhost has been removed)
- monit itself supervises: photon, spatial-knn, spatial-routing (Program checks), plus
  File/Filesystem checks (spatial-pbf, nginx bins, diskspace). (The `openrouteservice` Remote Host
  check was removed with the ors-app drop — remove it from the monit config as part of the swap.)

## Post-reboot verification checklist
1. `systemctl status freegle-docker` → active (exited), and `docker ps` shows the full stack Up,
   now including `freegledocker-tile-server`, `-wiki-media`, `-wiki-mysql` (and NOT `ors-app`).
2. `sudo monit summary` → all OK (esp. photon, spatial-knn, spatial-routing, docker). No
   `openrouteservice` entry (removed with the ors-app drop).
2a. **tiles**: `curl -s -o /dev/null -w '%{http_code}' -H 'Host: tiles.ilovefreegle.org' http://127.0.0.1:8080/tile/0/0/0.png` → 200, and `curl -sI -H 'Host: tiles.ilovefreegle.org' https://tiles.ilovefreegle.org/tile/0/0/0.png -k | grep -c -i access-control-allow-origin` → exactly 1.
2b. **wiki**: `curl -s -o /dev/null -w '%{http_code}' -H 'Host: wiki.ilovefreegle.org' http://127.0.0.1:8088/` → 200/302 (MediaWiki reached its DB `wiki-mysql`).
3. **photon** (its risk is startup, not shutdown): `curl -s -o /dev/null -w '%{http_code}' 'http://localhost:2322/api?q=london'` → 200. It rebuilds/loads the 6.3G ES index (~1–2 min). If it crash-loops (`Error occurred during initialization of VM`), that was the pre-upgrade heap-under-memory issue — the extra RAM should fix it; if not, `cd /var/www/photon && java -Xmx<N>g -jar photon-0.5.0.jar -listen-ip 127.0.0.1 -cors-any`.
4. **spatial** container rebuilds its 7.3G routing graph on start (~7 min) before healthy — normal.
5. **Daily digest**: the manual catch-up shards I was running die on reboot. The scheduled 4-shard
   daily (`mail:digest:unified --mode=daily --shard=N --shards=4`) resumes in the 07:00–12:00
   London window and drains the ~62k backlog (CRC32(id) sharding; DIGEST_LOAD_CAP bounds memory).
   Check `mysql ... users_digests where mode='daily'` lastsent distribution the next morning.
6. Batch scheduler ticking: `docker exec freegledocker-batch-prod php artisan schedule:list` and
   watch immediate digests spool in laravel logs.

## Optional hardening (not required)
- photon graceful stop is implicit (catch-all SIGTERM). A dedicated `photon.service` with an
  explicit ExecStop would make ordering deterministic — but current 90s SIGTERM is adequate.
- wiki-mysql only gets dockerd's 15s on shutdown; bump `shutdown-timeout` in /etc/docker/daemon.json
  if you ever see wiki DB recovery on boot (non-prod, low priority).
