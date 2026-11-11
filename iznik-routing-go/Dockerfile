# Two images from one file. The default (last stage, "dev") is the full toolchain image: dev and
# CI run `go test` inside the running container, so it needs Go and the module cache. Production
# builds the "runtime" target (docker-compose.override.batchprod.yml): the server and the cmd/
# tools on Alpine, with curl for the health checks, about 50 MB against 3 GB. BuildKit builds only
# the stages the chosen target needs, so neither build pays for the other.

FROM ghcr.io/freegle/freegle-base:latest AS source

WORKDIR /app

ENV ROUTING_PORT=8196 \
    OSM_PBF_PATH=/data/uk-latest.osm.pbf

COPY go.mod go.sum ./
RUN go mod download

COPY . .

FROM source AS static
# No cgo in this module, so static binaries run on Alpine's musl. The cmd/ tools come too, so
# `docker exec <container> placesextract ...` works where `go run ./cmd/placesextract` cannot.
RUN CGO_ENABLED=0 go build -trimpath -o /out/spatial-server . \
    && CGO_ENABLED=0 go build -trimpath -o /out/ ./cmd/...

FROM alpine:3.22 AS runtime
RUN apk add --no-cache ca-certificates curl tzdata
WORKDIR /app
ENV ROUTING_PORT=8196 \
    OSM_PBF_PATH=/data/uk-latest.osm.pbf
COPY --from=static /out/ /usr/local/bin/
EXPOSE 8196
CMD ["spatial-server"]

FROM source AS dev
RUN go build -o /usr/local/bin/spatial-server .
EXPOSE 8196
CMD ["spatial-server"]
