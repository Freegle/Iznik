# Automated review of Pending posts (plans/active/automod-flowchart.md).
# No database access, internal network only - see docker-compose.yml.

# ai-flower is a git dependency, so npm has to build it from source to
# prepare it. That build installs ai-flower's own devDependencies too,
# including better-sqlite3 (an alternative storage adapter this service
# never uses - it uses MemoryStorage), which needs python3/make/g++ to
# compile its native addon. node:22-slim has none of those, so install
# is done in this throwaway builder stage and only the resulting
# node_modules is carried into the runtime image below.
FROM node:22-slim AS builder
WORKDIR /app
RUN apt-get update && apt-get install -y --no-install-recommends python3 make g++ \
  && rm -rf /var/lib/apt/lists/*
COPY package.json package-lock.json ./
RUN npm ci --omit=dev

FROM node:22-slim

WORKDIR /app

ENV NODE_ENV=production
# One cache path, the same one docker-compose.yml names: the models are baked
# in here at build time and nothing downloads at runtime.
ENV HF_CACHE_DIR=/app/model-cache
ENV PORT=8090

# The claude CLI, for the subscription-token backend (ClaudeCliBackend). Pinned,
# since the backend depends on this version's flags (--tools, --max-turns) and
# JSON output, and a build must not pick up whatever is newest that day. The
# auto-updater is off for the same reason: the version in the image is the
# version that runs.
ENV DISABLE_AUTOUPDATER=1
RUN npm install -g @anthropic-ai/claude-code@2.1.288 && npm cache clean --force

COPY --from=builder /app/node_modules ./node_modules
COPY package.json package-lock.json ./

COPY chart.json chart-versions.json ./
COPY src ./src
COPY scripts ./scripts

# Downloads both NLI models into HF_CACHE_DIR at build time so the running
# container never needs network access to Hugging Face. If this step fails,
# the build fails loudly instead of the service silently downloading (or
# failing to download) on its first request.
RUN node scripts/prefetch-models.mjs

EXPOSE 8090

# Not root. The service reads member text and runs the claude CLI, so it needs no more than to read
# /app (root-owned, world-readable, models included) and write its own temp directory (/tmp).
USER node

# /health answers 503 once the backend has failed AUTOMOD_UNHEALTHY_AFTER
# reviews in a row (src/server.js), so a service that holds every post because
# its token has lapsed shows as unhealthy rather than quietly healthy.
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
  CMD node -e "fetch('http://localhost:' + (process.env.PORT || 8090) + '/health').then((r) => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))"

CMD ["node", "src/server.js"]
