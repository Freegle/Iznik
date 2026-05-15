FROM ghcr.io/freegle/freegle-base:latest

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

RUN go build -o /usr/local/bin/spatial-server .

EXPOSE 8194 8195

CMD ["spatial-server"]
