FROM ghcr.io/freegle/freegle-base:latest

WORKDIR /app

ENV ROUTING_PORT=8196 \
    OSM_PBF_PATH=/data/england-latest.osm.pbf

COPY go.mod go.sum ./
RUN go mod download

COPY . .

RUN go get github.com/go-sql-driver/mysql@v1.8.1 && go mod tidy && \
    go build -o /usr/local/bin/spatial-server .

EXPOSE 8196

CMD ["spatial-server"]
