FROM ghcr.io/freegle/freegle-base:latest

WORKDIR /app

ENV IZNIK_API_V1=http://freegle-apiv1:80/api \
    IZNIK_API_V2=http://freegle-apiv2:8192/api

# sharp 0.32 compiles native bindings against system libvips when libvips-dev
# is present (skips its GitHub binary download — Katapult CI can't reach it).
# That source build needs glib + pkg-config headers, which libvips-dev's Depends:
# don't pull and --no-install-recommends skips Recommends:. Install explicitly.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libvips-dev libglib2.0-dev pkg-config \
    && rm -rf /var/lib/apt/lists/*

COPY package*.json setup-hooks.* ./
COPY patches/ ./patches/
RUN npm install --legacy-peer-deps

COPY . .

EXPOSE 3002

CMD export NODE_OPTIONS=--max-old-space-size=8192 && \
    rm -rf /tmp/nitro/worker-* && \
    if [ "$NUXT_DEV_MODE" = "true" ]; then \
        export HOST=0 && npm run dev; \
    else \
        npm run build && export HOST=0 && npm run start; \
    fi
