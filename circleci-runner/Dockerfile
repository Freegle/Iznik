# Custom CircleCI Runner with required tools for Freegle builds
# Uses Machine Runner 3.0 (launch-agent is deprecated)
FROM circleci/runner-agent:machine-3

USER root

# Install required tools for Freegle builds
RUN apt-get update && apt-get install -y \
    git \
    curl \
    jq \
    wget \
    sudo \
    apt-transport-https \
    ca-certificates \
    gnupg \
    lsb-release \
    openssh-client \
    && rm -rf /var/lib/apt/lists/*

# Install Docker CLI (to communicate with host Docker)
RUN curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y docker-ce-cli docker-compose-plugin \
    && rm -rf /var/lib/apt/lists/*

# Install standalone docker-compose (v2)
RUN curl -fsSL "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose \
    && chmod +x /usr/local/bin/docker-compose

# Allow circleci user to use sudo without password (needed for some build steps)
RUN echo "circleci ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers

# Add GitHub's SSH host keys to known_hosts to avoid interactive prompts
RUN mkdir -p /root/.ssh /home/circleci/.ssh \
    && ssh-keyscan -t rsa,ecdsa,ed25519 github.com >> /root/.ssh/known_hosts 2>/dev/null \
    && ssh-keyscan -t rsa,ecdsa,ed25519 github.com >> /home/circleci/.ssh/known_hosts 2>/dev/null \
    && chmod 600 /root/.ssh/known_hosts /home/circleci/.ssh/known_hosts \
    && chown -R circleci:circleci /home/circleci/.ssh

# Switch back to circleci user
USER circleci
