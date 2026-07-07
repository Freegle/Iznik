# Batch-prod host reboot runbook (hardware upgrade 2026-07-03)

This host = FreegleDocker / batch-prod. It does NOT run the prod DB or apiv2 (those are on
db1/2/3 + Netlify), so the Freegle site stays up during the reboot. What pauses: batch crons
(digests, mail, ripple), the LOCAL spatial/routing/geocoder, and MJML email rendering.

## Reboot is graceful — no manual stop needed
`sudo reboot` cleanly stops everything:
- **freegledocker stack** → `freegle-docker.service` (enabled) `ExecStop=docker compose stop`, TimeoutStopSec=120.
- **standalone containers** (ors-app, wiki-media, wiki-mysql, confident_curran) → dockerd shutdown SIGTERM (~15s).
- **photon** (native java/ES, monit-managed) → systemd final-kill SIGTERM (90s) → ES clean shutdown.
Everything auto-restarts on boot (systemd unit `compose up -d`, restart policies, monit).

## Everything that SHOULD be running after reboot

### freegledocker compose project (auto-started by freegle-docker.service; COMPOSE_PROFILES=backend,production,mail)
- freegledocker-batch-prod   (the scheduler — prod crons)      restart: unless-stopped
- freegledocker-spatial       (iznik-routing-go, 8196, ~6G graph — rebuilds on start ~7 min) unless-stopped
- freegledocker-spatial-knn   (iznik-spatial-go, 8194)          unless-stopped
- freegledocker-mjml          (email render — digests need it)  restart: **no** (comes back via compose up)
- freegledocker-redis                                            **no**
- freegledocker-postfix                                          unless-stopped
- freegledocker-rspamd                                           **no**
- freegledocker-spamassassin                                     **no**
- freegledocker-loki          (logs)                             unless-stopped
- freegledocker-embedding-sidecar (semantic search)             unless-stopped
- freegledocker-ai-support-helper                                **no**
- freegledocker-status        (monitoring UI)                    **no**
(the **no** ones only come back because freegle-docker.service runs `docker compose up -d` — a
bare `docker start` after a crash would NOT restart them.)

### Standalone containers (own restart policies)
- ors-app          (OpenRouteService routing)   unless-stopped
- wiki-media       (wiki)                        always
- wiki-mysql       (wiki DB)                     always
- confident_curran (overv/openstreetmap-tile-server) always

### Native, under monit
- photon (geocoder, java, port 2322, /etc/photon, 6.3G ES index)
- nginx
- monit itself supervises: photon, spatial-knn, spatial-routing (Program checks), openrouteservice
  (Remote Host), plus File/Filesystem checks (spatial-pbf, nginx bins, diskspace).

## Post-reboot verification checklist
1. `systemctl status freegle-docker` → active (exited), and `docker ps` shows all ~16 containers Up.
2. `sudo monit summary` → all OK (esp. photon, spatial-knn, spatial-routing, docker).
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
