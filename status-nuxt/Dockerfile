FROM node:20-slim

WORKDIR /app

# Install dependencies for dockerode and docker CLI
RUN apt-get update && apt-get install -y \
    docker.io \
    && rm -rf /var/lib/apt/lists/*

# Docker API version compatibility (debian docker.io is older than host daemon)
ENV DOCKER_API_VERSION=1.44

# Copy package files
COPY package*.json ./

# node:20-slim ships npm 10.8.2, whose dependency resolver crashes on the
# current nuxt 3.x peer graph with "Cannot read properties of null (reading
# 'edgesOut')" - an arborist bug, not a problem with our dependencies. There is
# no package-lock.json here (see below), so every build re-resolves from the
# registry and picked this up the day a matching nuxt release landed. npm 11
# resolves the same package.json cleanly.
RUN npm install -g npm@11

# Install dependencies (using npm install since we don't have package-lock.json yet).
# Without a lock file this build is exposed to registry drift, which is what bit
# it above; committing one is the durable fix.
RUN npm install --fetch-retries=5 --fetch-retry-mintimeout=20000 --fetch-retry-maxtimeout=120000

# Copy source code
COPY . .

# Build the application
RUN npm run build

# Expose the port
EXPOSE 3000

# Start the server
CMD ["node", ".output/server/index.mjs"]
