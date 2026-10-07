FROM node:20-slim

# Install dependencies: git/curl/grep for codebase search + ripgrep for the SDK's
# Grep tool; python3/make/g++ to build better-sqlite3's native binding.
RUN apt-get update && apt-get install -y \
    git \
    curl \
    grep \
    ripgrep \
    python3 \
    make \
    g++ \
    && rm -rf /var/lib/apt/lists/*

# Create non-root user for running the application. Its uid is the host user's
# (CLAUDE_UID, default 1000), because session mode bind-mounts the host's
# ~/.claude/.credentials.json, which is mode 600: any other uid cannot read it.
# The base image's own "node" user holds uid 1000, so it is removed first.
ARG CLAUDE_UID=1000
RUN if getent passwd node >/dev/null; then userdel -r node; fi && \
    useradd -m -u ${CLAUDE_UID} -s /bin/bash claude && \
    mkdir -p /app/codebase /home/claude/.claude/debug && \
    chown -R claude:claude /app /home/claude

WORKDIR /app

# The agent's checkout of the Freegle MONOREPO is fetched at RUNTIME into the empty
# /app/codebase created above, by syncCodebase() on startup and every 30 minutes after
# (see codebase.js). It is deliberately NOT cloned here: a clone in the image build makes
# `docker compose build` depend on reaching github.com anonymously, and when that was refused
# the whole CI stack bringup failed with it. A build-time clone is also frozen at whenever the
# image was built, while the runtime one is current.
#
# The monorepo has the subdirs the agent reads (iznik-nuxt3/, iznik-server-go/, ...) at
# /app/codebase/<name>, minus secrets (.env is gitignored). The old standalone repos were
# retired in Apr 2026 and now carry only a "redirect to monorepo" commit.

# Copy package files and install dependencies
COPY --chown=claude:claude package*.json ./
RUN npm install

# Copy application code
COPY --chown=claude:claude . .

# Create the audit directory owned by claude BEFORE the ai-support-audit named
# volume mounts over it, so the fresh volume inherits claude ownership. Otherwise
# Docker mounts it root-owned and the durable audit append (AUDIT_LOG_PATH)
# silently fails for the non-root claude user.
RUN mkdir -p /app/audit && chown claude:claude /app/audit

# Copy entrypoint script
COPY --chown=claude:claude entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# The entrypoint starts as root only to take ownership of the audit volume (a
# volume created under an earlier uid keeps that owner), then runs the server
# as claude.
ENV HOME=/home/claude

EXPOSE 3000

ENTRYPOINT ["/entrypoint.sh"]
