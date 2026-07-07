FROM node:20-slim

# Install dependencies for codebase search
RUN apt-get update && apt-get install -y \
    git \
    curl \
    grep \
    && rm -rf /var/lib/apt/lists/*

# Create non-root user for running the application
RUN useradd -m -s /bin/bash claude && \
    mkdir -p /app/codebase /home/claude/.claude/debug && \
    chown -R claude:claude /app /home/claude

WORKDIR /app

# Clone the Freegle codebase (public repos) as root, then fix ownership
RUN git clone --depth 1 https://github.com/Freegle/iznik-nuxt3.git /app/codebase/iznik-nuxt3 && \
    git clone --depth 1 https://github.com/Freegle/iznik-server.git /app/codebase/iznik-server && \
    git clone --depth 1 https://github.com/Freegle/iznik-server-go.git /app/codebase/iznik-server-go && \
    chown -R claude:claude /app/codebase

# Copy package files and install dependencies
COPY --chown=claude:claude package*.json ./
RUN npm install

# Copy application code
COPY --chown=claude:claude . .

# Copy entrypoint script
COPY --chown=claude:claude entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Switch to non-root user
USER claude
ENV HOME=/home/claude

EXPOSE 3000

ENTRYPOINT ["/entrypoint.sh"]
