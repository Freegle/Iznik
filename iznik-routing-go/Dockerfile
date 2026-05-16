FROM ghcr.io/freegle/freegle-base:latest

WORKDIR /app

ENV ROUTING_PORT=8196 \
    OSM_PBF_PATH=/data/england-latest.osm.pbf

COPY go.mod go.sum ./
RUN go mod download

COPY . .

RUN go build -o /usr/local/bin/routing-server .

EXPOSE 8196

CMD ["routing-server"]
