FROM node:20-slim
RUN apt-get update && apt-get install -y --no-install-recommends curl && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY package.json .
# npm's --fetch-retries only retries individual package fetches; a mid-stream
# ECONNRESET (connection reset by the registry/proxy on a build VM) still aborts
# the whole command. Retry the entire install at the shell level to survive it,
# with exponential backoff so we ride out a sustained registry wobble.
RUN n=0; delay=15; until npm install --fetch-retries=5 --fetch-retry-mintimeout=20000 --fetch-retry-maxtimeout=120000; do \
      n=$((n+1)); \
      [ "$n" -ge 8 ] && { echo "npm install failed after $n attempts" >&2; exit 1; }; \
      echo "npm install attempt $n hit a transient network error; retrying in ${delay}s..."; \
      sleep "$delay"; delay=$((delay*2)); [ "$delay" -gt 120 ] && delay=120; \
    done
COPY server.mjs .
# Pre-download model on build so startup is fast. This fetches from huggingface.co,
# which has sustained gateway-timeout windows that outlast a short flat retry — and a
# build-time fetch failure aborts the WHOLE "Build containers" step, so it breaks every
# PR's CI at once, not just this image. Retry generously with exponential backoff
# (8 attempts, 15s->120s cap, ~10 min total) instead of 5x15s (~75s) so a passing
# build no longer hinges on huggingface being up for one ~minute window.
RUN echo "import {pipeline,env} from '@huggingface/transformers'; env.cacheDir='/app/model-cache'; await pipeline('feature-extraction','nomic-ai/nomic-embed-text-v1.5',{quantized:true}); console.log('Model cached.');" > /app/prebuild.mjs && \
    n=0; delay=15; until node /app/prebuild.mjs; do \
      n=$((n+1)); \
      [ "$n" -ge 8 ] && { echo "model prebuild failed after $n attempts" >&2; exit 1; }; \
      echo "model prebuild attempt $n hit a transient network error; retrying in ${delay}s..."; \
      sleep "$delay"; delay=$((delay*2)); [ "$delay" -gt 120 ] && delay=120; \
    done && rm /app/prebuild.mjs
EXPOSE 3200
CMD ["node", "server.mjs"]
