#!/bin/sh
# exit 0 if spatial (routing/isochrone server) answers /health, else non-zero
docker exec freegledocker-spatial curl -fsS -m 5 -o /dev/null http://localhost:8196/health
