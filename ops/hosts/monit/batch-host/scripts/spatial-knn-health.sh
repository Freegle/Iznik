#!/bin/sh
# exit 0 if spatial-knn (KNN server) answers /health, else non-zero
docker exec freegledocker-spatial-knn curl -fsS -m 5 -o /dev/null http://localhost:8194/health
