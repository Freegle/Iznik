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

# Install dependencies (using npm install since we don't have package-lock.json yet)
RUN npm install --fetch-retries=5 --fetch-retry-mintimeout=20000 --fetch-retry-maxtimeout=120000

# Copy source code
COPY . .

# Build the application
RUN npm run build

# Expose the port
EXPOSE 3000

# Start the server
CMD ["node", ".output/server/index.mjs"]
