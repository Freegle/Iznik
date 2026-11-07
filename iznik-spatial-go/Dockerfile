# Two images from one file. The default (last stage, "dev") is the full toolchain image: dev and
# CI run `go test` inside the running container, so it needs Go and the module cache. Production
# builds the "runtime" target (docker-compose.override.batchprod.yml): just the server on Alpine,
# with curl for the health checks, about 30 MB against 3 GB. BuildKit builds only the stages
# the chosen target needs, so neither build pays for the other.

FROM ghcr.io/freegle/freegle-base:latest AS source

WORKDIR /app

ENV MYSQL_USER=root \
    MYSQL_PASSWORD=iznik \
    MYSQL_PROTOCOL=tcp \
    MYSQL_HOST=percona \
    MYSQL_PORT=3306 \
    MYSQL_DBNAME=iznik \
    SPATIAL_PORT=8194 \
    SPATIAL_ADMIN_PORT=8195 \
    SPATIAL_INDEX_DIR=/data

COPY go.mod go.sum ./
RUN go mod download

COPY . .

FROM source AS static
# No cgo in this module, so a static binary runs on Alpine's musl.
RUN CGO_ENABLED=0 go build -trimpath -o /out/spatial-server .

FROM alpine:3.22 AS runtime
RUN apk add --no-cache ca-certificates curl tzdata
WORKDIR /app
ENV MYSQL_USER=root \
    MYSQL_PASSWORD=iznik \
    MYSQL_PROTOCOL=tcp \
    MYSQL_HOST=percona \
    MYSQL_PORT=3306 \
    MYSQL_DBNAME=iznik \
    SPATIAL_PORT=8194 \
    SPATIAL_ADMIN_PORT=8195 \
    SPATIAL_INDEX_DIR=/data
COPY --from=static /out/ /usr/local/bin/
EXPOSE 8194 8195
CMD ["spatial-server"]

FROM source AS dev
RUN go build -o /usr/local/bin/spatial-server .
EXPOSE 8194 8195
CMD ["spatial-server"]
