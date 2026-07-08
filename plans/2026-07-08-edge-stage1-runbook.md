# Edge Stage 1 — adopt tiles + wiki into Compose, drop ors-app + discourse vhost

Runbook for the host swap that the repo changes on branch
`infra/edge-stage1-adopt-tile-wiki` enable. This is plan §2 step 2 of
`plans/2026-06-25-frontend-server-migration.md`.

**Nothing here changes prod until a human runs it.** Every step is individually
reversible; rollback = restart the old container. Do it one service at a time.

## What changes
- `confident_curran` (standalone overv tile server, `:8080`) → Compose service
  `freegledocker-tile-server` on the SAME `osm-data`/`osm-tiles` volumes,
  republishing `:8080`. Native nginx `maps` vhost (tiles.ilovefreegle.org →
  127.0.0.1:8080) is untouched.
- `wiki-media` + `wiki-mysql` (standalone) → Compose services
  `freegledocker-wiki-media`/`-wiki-mysql` on the SAME `/var/wiki` binds,
  republishing `:8088`. Native nginx wiki vhost untouched.
- `ors-app` → dropped (superseded by native spatial on db1/2/3).
- stale `discourse.ilovefreegle.org` nginx vhost → removed (DNS points at the
  external SaaS `178.156.182.236`, not this host).

Zero data movement: the tile volumes and `/var/wiki` binds are reused in place.

## Preconditions (verify, read-only)
```bash
cd /var/www/FreegleDocker
git log --oneline -1                       # branch infra/edge-stage1-adopt-tile-wiki (or merged to master) is checked out
docker volume ls | grep -E 'osm-data|osm-tiles'   # both present
ls -d /var/wiki/{db,html,sharewiki,sharemysql}    # all present
docker image inspect wiki-mysql:v02 >/dev/null && echo wiki-mysql:v02 present
docker inspect confident_curran wiki-media wiki-mysql ors-app --format '{{.Name}} {{.State.Status}}'  # running
```

## Step A — host .env (enable the edge profile + override)
The edit that activates everything. Additive: adds the `edge` profile and the
edge override to the existing batch-prod config.
```bash
# BEFORE (current):
#   COMPOSE_PROFILES=backend,production,mail
#   (no COMPOSE_FILE line → auto-loads docker-compose.yml + docker-compose.override.yml)
# AFTER:
#   COMPOSE_PROFILES=backend,production,mail,edge
#   COMPOSE_FILE=docker-compose.yml:docker-compose.override.yml:docker-compose.override.edge.yml
```
Sanity-check the render BEFORE touching any container (no-op, read-only):
```bash
docker compose config --services | sort            # tile-server, wiki-media, wiki-mysql now present
docker compose config | grep -A3 'frontend-nginx:' | grep replicas   # images tier = replicas:0
```
Rollback: revert the two `.env` lines.

## Step B — swap tiles (downtime = container start, ~tens of seconds)
Pre-create the new container first so only the stop→start gap is downtime; it
does NOT bind `:8080` until started, so it can be created while the old one runs.
```bash
docker compose create tile-server                  # create, not start (no port bind yet)
docker update --restart=no confident_curran        # stop it fighting for :8080 on daemon restart; keep it for rollback
docker stop confident_curran                        # frees :8080
docker compose up -d tile-server                    # binds :8080, adopts osm-data/osm-tiles
# verify:
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: tiles.ilovefreegle.org' http://127.0.0.1:8080/tile/0/0/0.png   # 200
curl -sI -H 'Host: tiles.ilovefreegle.org' http://127.0.0.1:8080/tile/0/0/0.png | grep -ci access-control-allow  # (0 from container; native nginx adds the 1)
docker logs --tail=30 freegledocker-tile-server | grep -i 'replication\|renderd'   # replication resumed
```
Rollback: `docker compose stop tile-server && docker update --restart=always confident_curran && docker start confident_curran`.

## Step C — swap wiki (downtime = container start)
```bash
docker compose create wiki-mysql wiki-media
docker update --restart=no wiki-media wiki-mysql
docker stop wiki-media wiki-mysql                   # frees :8088 and the /var/wiki/db lock
docker compose up -d wiki-media                     # brings up wiki-mysql (dep) then wiki-media
# verify:
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: wiki.ilovefreegle.org' http://127.0.0.1:8088/   # 200/302
docker exec freegledocker-wiki-media sh -c 'php maintenance/version.php 2>/dev/null || true'        # DB reachable via wiki-mysql
```
Rollback: `docker compose stop wiki-media wiki-mysql && docker update --restart=always wiki-media wiki-mysql && docker start wiki-mysql wiki-media`.

## Step D — drop ors-app
```bash
docker compose -f /var/www/openrouteservice/docker-compose.yml down   # stops+removes ors-app
```
Then remove the monit `openrouteservice` Remote Host check (edit the monit
config, `sudo monit reload`).
Rollback: `docker compose -f /var/www/openrouteservice/docker-compose.yml up -d` and restore the monit check.

## Step E — remove the stale discourse vhost
```bash
sudo rm /etc/nginx/sites-enabled/discourse         # keep sites-available/discourse for rollback
sudo nginx -t && sudo nginx -s reload
```
Rollback: `sudo ln -s ../sites-available/discourse /etc/nginx/sites-enabled/discourse && sudo nginx -t && sudo nginx -s reload`.

## Post-swap
- `docker ps` shows `freegledocker-tile-server/-wiki-media/-wiki-mysql` Up, no `ors-app`.
- Old `confident_curran`/`wiki-*` containers remain present but stopped with
  `restart=no` (warm rollback). Remove them only after a clean soak (§9 of the
  plan): `docker rm confident_curran wiki-media wiki-mysql`.
- Full rollback of the whole stage: revert Step A `.env`, restart the old
  containers (Steps B/C), `up -d` ors-app (Step D), re-link the discourse vhost
  (Step E).
