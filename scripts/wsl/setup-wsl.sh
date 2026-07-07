#!/bin/bash
# Setup script for a fresh Ubuntu 24.04 WSL instance for Freegle development.
# This runs as root inside the new WSL instance.
# Installs everything needed to match the existing Freegle development environment.
# Usage: ./setup-wsl.sh <username>

set -e

USERNAME="${1:?Usage: ./setup-wsl.sh <username>}"
USER_HOME="/home/$USERNAME"

echo "=== Setting up Freegle WSL instance ==="

# Update package lists
echo "Updating packages..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

# --- User setup ---
if ! id "$USERNAME" &>/dev/null; then
    echo "Creating user $USERNAME..."
    useradd -m -s /bin/bash "$USERNAME"
    echo "$USERNAME ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/$USERNAME
    chmod 0440 /etc/sudoers.d/$USERNAME
fi
usermod -aG sudo "$USERNAME" 2>/dev/null || true

# --- Add third-party APT repositories ---

echo "Adding third-party repositories..."

# Google Chrome
curl -fsSL https://dl.google.com/linux/linux_signing_key.pub | gpg --dearmor -o /etc/apt/keyrings/google-chrome.gpg 2>/dev/null
echo "deb [arch=amd64 signed-by=/etc/apt/keyrings/google-chrome.gpg] http://dl.google.com/linux/chrome/deb/ stable main" \
    > /etc/apt/sources.list.d/google-chrome.list

# GitHub CLI
curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | tee /etc/apt/keyrings/githubcli-archive-keyring.gpg >/dev/null
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" \
    > /etc/apt/sources.list.d/github-cli.list

# Node.js 22 LTS (via NodeSource)
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - 2>&1 | tail -5

# Docker Engine
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc 2>/dev/null
chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu \
    $(. /etc/os-release && echo "$VERSION_CODENAME") stable" > /etc/apt/sources.list.d/docker.list

apt-get update -qq

# --- Install packages ---

# Core tools and utilities
echo "Installing core tools..."
apt-get install -y -qq \
    apt-transport-https \
    ca-certificates \
    curl \
    wget \
    gnupg \
    lsb-release \
    git \
    sudo \
    systemd \
    vim \
    jq \
    unzip \
    openssh-client \
    net-tools \
    dnsutils \
    tcpdump \
    inotify-tools \
    expect \
    sqlite3 \
    mysql-client \
    dos2unix \
    > /dev/null 2>&1

# Node.js 22 LTS
echo "Installing Node.js 22 LTS..."
apt-get install -y -qq nodejs > /dev/null 2>&1

# Python
echo "Installing Python..."
apt-get install -y -qq \
    python3-pip \
    python3-venv \
    > /dev/null 2>&1

# Java (needed for some tooling)
echo "Installing Java..."
apt-get install -y -qq default-jdk > /dev/null 2>&1

# GitHub CLI
echo "Installing GitHub CLI..."
apt-get install -y -qq gh > /dev/null 2>&1

# Google Chrome (for Playwright/MCP browser automation)
echo "Installing Google Chrome..."
apt-get install -y -qq google-chrome-stable > /dev/null 2>&1

# Playwright browser dependencies (fonts, graphics libs, xvfb)
echo "Installing Playwright/browser dependencies..."
apt-get install -y -qq \
    xvfb \
    xterm \
    ffmpeg \
    fonts-freefont-ttf \
    fonts-ipafont-gothic \
    fonts-liberation \
    fonts-noto-color-emoji \
    fonts-tlwg-loma-otf \
    fonts-unifont \
    fonts-wqy-zenhei \
    xfonts-cyrillic \
    xfonts-scalable \
    libasound2t64 \
    libatk-bridge2.0-0t64 \
    libatk1.0-0t64 \
    libatomic1 \
    libatspi2.0-0t64 \
    libavif16 \
    libcairo-gobject2 \
    libcairo2 \
    libcups2t64 \
    libdbus-1-3 \
    libenchant-2-2 \
    libepoxy0 \
    libevent-2.1-7t64 \
    libflite1 \
    libfontconfig1 \
    libfreetype6 \
    libgdk-pixbuf-2.0-0 \
    libgles2 \
    libglib2.0-0t64 \
    libgstreamer-gl1.0-0 \
    libgstreamer-plugins-bad1.0-0 \
    libgstreamer-plugins-base1.0-0 \
    libgstreamer1.0-0 \
    libgtk-4-1 \
    libharfbuzz-icu0 \
    libharfbuzz0b \
    libhyphen0 \
    libicu74 \
    libjpeg-turbo8 \
    liblcms2-2 \
    libmanette-0.2-0 \
    libnspr4 \
    libnss3 \
    libopus0 \
    libpango-1.0-0 \
    libpangocairo-1.0-0 \
    libpng16-16t64 \
    libsecret-1-0 \
    libvpx9 \
    libwayland-client0 \
    libwayland-egl1 \
    libwayland-server0 \
    libwebp7 \
    libwebpdemux2 \
    libwoff1 \
    libx11-6 \
    libx11-xcb1 \
    libx264-164 \
    libxcb-shm0 \
    libxcb1 \
    libxcomposite1 \
    libxcursor1 \
    libxdamage1 \
    libxext6 \
    libxfixes3 \
    libxi6 \
    libxkbcommon0 \
    libxml2 \
    libxrandr2 \
    libxrender1 \
    libxslt1.1 \
    gstreamer1.0-libav \
    gstreamer1.0-plugins-bad \
    gstreamer1.0-plugins-base \
    gstreamer1.0-plugins-good \
    > /dev/null 2>&1

# Docker Engine (pin to 27.5.x to maintain API compatibility with container images)
echo "Installing Docker Engine..."
DOCKER_VERSION="5:27.5.1-1~ubuntu.24.04~noble"
apt-get install -y -qq \
    "docker-ce=$DOCKER_VERSION" \
    "docker-ce-cli=$DOCKER_VERSION" \
    containerd.io \
    docker-buildx-plugin \
    docker-compose-plugin \
    > /dev/null 2>&1
apt-mark hold docker-ce docker-ce-cli > /dev/null 2>&1

# Add user to docker group
usermod -aG docker "$USERNAME"

# Install standalone docker-compose (v2 binary, for compatibility)
echo "Installing standalone docker-compose..."
COMPOSE_VERSION=$(curl -fsSL https://api.github.com/repos/docker/compose/releases/latest 2>/dev/null | jq -r .tag_name)
if [ -n "$COMPOSE_VERSION" ] && [ "$COMPOSE_VERSION" != "null" ]; then
    curl -fsSL "https://github.com/docker/compose/releases/download/${COMPOSE_VERSION}/docker-compose-linux-x86_64" \
        -o /usr/local/bin/docker-compose 2>/dev/null
    chmod +x /usr/local/bin/docker-compose
else
    echo "WARNING: Could not determine latest docker-compose version, skipping standalone install"
fi

# --- Snap packages ---
# Task (Taskwarrior) - install if snap is available
if command -v snap &>/dev/null; then
    echo "Installing Task via snap..."
    snap install task --classic 2>/dev/null || true
fi

# --- Systemd / WSL config ---

echo "Configuring WSL and systemd..."
# Ensure systemd is enabled
if [ ! -f /etc/wsl.conf ] || ! grep -q 'systemd=true' /etc/wsl.conf; then
    cat >> /etc/wsl.conf << 'WSLCONF'
[boot]
systemd=true
WSLCONF
fi

# Enable Docker to start on boot
systemctl enable docker 2>/dev/null || true
systemctl enable containerd 2>/dev/null || true

# Start Docker if systemd is running
if systemctl is-system-running &>/dev/null; then
    systemctl start docker 2>/dev/null || true
fi

# --- SSH keys ---

echo "Setting up SSH..."
mkdir -p "$USER_HOME/.ssh"
chown -R "$USERNAME:$USERNAME" "$USER_HOME/.ssh"
chmod 700 "$USER_HOME/.ssh"

# Auto-detect Windows username and copy SSH keys if accessible
WIN_USER=$(cmd.exe /c "echo %USERNAME%" 2>/dev/null | tr -d '\r' || true)
if [ -n "$WIN_USER" ] && [ -d "/mnt/c/Users/$WIN_USER/.ssh" ]; then
    echo "Copying SSH keys from Windows user $WIN_USER..."
    cp /mnt/c/Users/"$WIN_USER"/.ssh/id_* "$USER_HOME/.ssh/" 2>/dev/null || true
    cp /mnt/c/Users/"$WIN_USER"/.ssh/known_hosts "$USER_HOME/.ssh/" 2>/dev/null || true
    chown -R "$USERNAME:$USERNAME" "$USER_HOME/.ssh"
    chmod 600 "$USER_HOME/.ssh"/id_* 2>/dev/null || true
fi

# --- Claude Code CLI (native installer) ---

echo "Installing Claude Code CLI..."
su - "$USERNAME" -c 'curl -fsSL https://claude.ai/install.sh | bash' 2>&1 || {
    echo "WARNING: Claude Code installation failed. Install manually later with:"
    echo "  curl -fsSL https://claude.ai/install.sh | bash"
}

# Ensure Claude is on PATH in .profile for non-interactive shells
CLAUDE_BIN="$USER_HOME/.claude/local/bin"
if [ -d "$CLAUDE_BIN" ]; then
    if ! grep -q '.claude/local/bin' "$USER_HOME/.profile" 2>/dev/null; then
        echo 'export PATH="$HOME/.claude/local/bin:$PATH"' >> "$USER_HOME/.profile"
        chown "$USERNAME:$USERNAME" "$USER_HOME/.profile"
    fi
fi

# --- Summary ---

echo ""
echo "=== Setup complete ==="
echo "Node.js:        $(node --version 2>/dev/null || echo 'check after restart')"
echo "npm:            $(npm --version 2>/dev/null || echo 'check after restart')"
echo "Docker:         $(docker --version 2>/dev/null || echo 'check after restart')"
echo "docker-compose: $(docker-compose version 2>/dev/null || echo 'check after restart')"
echo "Chrome:         $(google-chrome --version 2>/dev/null || echo 'installed')"
echo "GitHub CLI:     $(gh --version 2>/dev/null | head -1 || echo 'installed')"
echo "Python:         $(python3 --version 2>/dev/null)"
echo "Java:           $(java -version 2>&1 | head -1)"
echo "Claude:         $(su - $USERNAME -c 'claude --version' 2>/dev/null || echo 'installed - verify after restart')"
echo ""
echo "User $USERNAME created and added to docker group."
echo ""
echo "IMPORTANT: After entering the new instance, run 'claude login' to authenticate."
