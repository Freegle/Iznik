FROM ghcr.io/freegle/freegle-base:latest

WORKDIR /app

ENV IZNIK_API_V2=http://freegle-apiv2:8192/api

# Copy the iznik-nuxt3 directory from build context (modtools is a subdirectory)
COPY iznik-nuxt3 .

# Install dependencies in parent directory and generate .nuxt
# npm's own --fetch-retries covers registry fetches, but a dependency pulled
# straight from a GitHub tarball (uzurv/Calendar-PhoneGap-Plugin-ios-17-support)
# is fetched by codeload, which rate-limits: on 2026-08-17 it answered 429 Too
# Many Requests and red-lined master for a commit that touched no JS at all.
# Retry the whole install at the shell level with exponential backoff so a
# rate-limit window is ridden out rather than failing the build. Same pattern,
# and the same clamp-must-stay-an-if trap, as docker/embedding-sidecar.
RUN n=0; delay=15; until npm install --legacy-peer-deps --fetch-retries=5 --fetch-retry-mintimeout=20000 --fetch-retry-maxtimeout=120000; do \
      n=$((n+1)); \
      [ "$n" -ge 6 ] && { echo "npm install failed after $n attempts" >&2; exit 1; }; \
      echo "npm install attempt $n failed (rate limit or transient network); retrying in ${delay}s..."; \
      sleep "$delay"; delay=$((delay*2)); \
      if [ "$delay" -gt 120 ]; then delay=120; fi; \
    done \
 && npx nuxi prepare

# Change to modtools directory
WORKDIR /app/modtools

# Install modtools specific dependencies
# npm's own --fetch-retries covers registry fetches, but a dependency pulled
# straight from a GitHub tarball (uzurv/Calendar-PhoneGap-Plugin-ios-17-support)
# is fetched by codeload, which rate-limits: on 2026-08-17 it answered 429 Too
# Many Requests and red-lined master for a commit that touched no JS at all.
# Retry the whole install at the shell level with exponential backoff so a
# rate-limit window is ridden out rather than failing the build. Same pattern,
# and the same clamp-must-stay-an-if trap, as docker/embedding-sidecar.
RUN n=0; delay=15; until npm install --legacy-peer-deps --fetch-retries=5 --fetch-retry-mintimeout=20000 --fetch-retry-maxtimeout=120000; do \
      n=$((n+1)); \
      [ "$n" -ge 6 ] && { echo "npm install failed after $n attempts" >&2; exit 1; }; \
      echo "npm install attempt $n failed (rate limit or transient network); retrying in ${delay}s..."; \
      sleep "$delay"; delay=$((delay*2)); \
      if [ "$delay" -gt 120 ]; then delay=120; fi; \
    done

EXPOSE 3000

CMD export NODE_OPTIONS=--max-old-space-size=8192 && \
    rm -rf /tmp/nitro/worker-* && \
    echo "relayhost = [mailhog]:1025" >> /etc/postfix/main.cf && \
    postfix start && \
    if [ "$NUXT_DEV_MODE" = "true" ]; then \
        export HOST=0 && npm run dev; \
    else \
        npm run build && export HOST=0 && npm run start; \
    fi
