FROM ghcr.io/freegle/freegle-base:latest

# ONNX Runtime C library — required for native Go embedding pool.
# SetIntraOpNumThreads/SetInterOpNumThreads parallelise GEMM tiles and
# independent graph nodes (Q/K/V projections) across all CPU cores.
#
# Fetched from Microsoft's NuGet package: the GitHub repository and its release
# downloads were removed in November 2026. The hash pins the exact package, so a
# changed or substituted download fails the build. A version bump needs a new hash.
ARG ONNXRUNTIME_VERSION=1.20.1
ARG ONNXRUNTIME_SHA256=9c32108e9a1c78b090a95c2a219f244dd5f067d4caf68fb0f967103f3f20839c
RUN curl -fsSL -o /tmp/ort.nupkg "https://api.nuget.org/v3-flatcontainer/microsoft.ml.onnxruntime/${ONNXRUNTIME_VERSION}/microsoft.ml.onnxruntime.${ONNXRUNTIME_VERSION}.nupkg" && \
    echo "${ONNXRUNTIME_SHA256}  /tmp/ort.nupkg" | sha256sum -c - && \
    unzip -q /tmp/ort.nupkg 'runtimes/linux-x64/native/libonnxruntime.so' 'build/native/include/*' -d /tmp/ort && \
    cp /tmp/ort/runtimes/linux-x64/native/libonnxruntime.so "/usr/local/lib/libonnxruntime.so.${ONNXRUNTIME_VERSION}" && \
    ln -sf "/usr/local/lib/libonnxruntime.so.${ONNXRUNTIME_VERSION}" /usr/local/lib/libonnxruntime.so.1 && \
    ln -sf "/usr/local/lib/libonnxruntime.so.${ONNXRUNTIME_VERSION}" /usr/local/lib/libonnxruntime.so && \
    cp -r /tmp/ort/build/native/include /usr/local/include/onnxruntime && \
    rm -rf /tmp/ort /tmp/ort.nupkg && \
    ldconfig

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

COPY . .
RUN go mod tidy

# Write build info for /api/version endpoint
RUN echo "$(git rev-parse --short HEAD 2>/dev/null || echo 'unknown') $(date -u '+%Y-%m-%d %H:%M:%S UTC')" > /app/BUILD_INFO

# Generate swagger documentation during build
RUN chmod +x generate-swagger.sh && ./generate-swagger.sh

EXPOSE 8192

CMD echo "Start against DB $MYSQL_HOST:$MYSQL_PORT/$MYSQL_DBNAME with user $MYSQL_USER password $MYSQL_PASSWORD" \
  && while true; do go run main.go >> /tmp/iznik_api.out 2>&1; sleep 1; done
