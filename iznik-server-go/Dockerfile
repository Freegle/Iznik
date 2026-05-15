FROM ghcr.io/freegle/freegle-base:latest

WORKDIR /app

ENV MYSQL_USER=root \
    MYSQL_PASSWORD=iznik \
    MYSQL_PROTOCOL=tcp \
    MYSQL_HOST=percona \
    MYSQL_PORT=3306 \
    MYSQL_DBNAME=iznik \
    IMAGE_DOMAIN=apiv1.localhost \
    USER_SITE=freegle.localhost \
    JWT_SECRET=secret \
    GROUP_DOMAIN=groups.freegle.test \
    PASSWORD_SALT=zzzz

COPY go.mod go.sum ./
RUN go mod download

# Pre-fetch knn-server dependencies as a separate layer for cache efficiency.
COPY knn-server/go.mod knn-server/go.sum knn-server/
RUN cd knn-server && go mod download

COPY . .
RUN go mod tidy

# Build knn-server as a standalone binary.
RUN cd knn-server && go build -o /usr/local/bin/knn-server .

# Write build info for /api/version endpoint
RUN echo "$(git rev-parse --short HEAD 2>/dev/null || echo 'unknown') $(date -u '+%Y-%m-%d %H:%M:%S UTC')" > /app/BUILD_INFO

# Generate swagger documentation during build
RUN chmod +x generate-swagger.sh && ./generate-swagger.sh

ENV KNN_PORT=8194 \
    KNN_ADMIN_PORT=8195 \
    KNN_INDEX_PATH=/data/knn-locations.db

EXPOSE 8192 8194 8195

CMD echo "Start against DB $MYSQL_HOST:$MYSQL_PORT/$MYSQL_DBNAME with user $MYSQL_USER password $MYSQL_PASSWORD" \
  && (while true; do knn-server >> /tmp/knn_server.out 2>&1; sleep 1; done &) \
  && while true; do go run main.go >> /tmp/iznik_api.out 2>&1; sleep 1; done
