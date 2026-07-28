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

# Create non-root user for running the application
RUN useradd -m -s /bin/bash claude && \
    mkdir -p /app/codebase /home/claude/.claude/debug && \
    chown -R claude:claude /app /home/claude

WORKDIR /app

# Clone the Freegle MONOREPO (public) as root, then fix ownership. The old
# standalone repos (iznik-nuxt3, iznik-server-go, ...) were retired in Apr 2026
# and now just carry a "redirect to monorepo" commit, so cloning them left the
# agent 3+ months stale. The monorepo has the same subdirs (iznik-nuxt3/,
# iznik-server-go/, ...) at the same /app/codebase/<name> paths, minus secrets
# (.env is gitignored).
RUN git clone --depth 1 https://github.com/Freegle/Iznik.git /app/codebase && \
    chown -R claude:claude /app/codebase

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

# Switch to non-root user
USER claude
ENV HOME=/home/claude

EXPOSE 3000

ENTRYPOINT ["/entrypoint.sh"]
