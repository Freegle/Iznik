# ---------------------------------------------------------------------------
# mrml MJML compiler — in-process PHP extension (Rust).
# Replaces the per-compile HTTP round-trip to the Node mjml sidecar with a
# local function call (~170x faster). Built against php:8.4-cli so the
# extension ABI matches the runtime base image (also php:8.4-cli).
#
# alekitto/mjml-php pins mrml 5, which does NOT inline <mj-style inline> CSS
# (mrml gained that in 6.0.0, behind the css-inline Cargo feature). CSS
# inlining onto elements is required for Gmail, so we bump to mrml 6 and
# enable css-inline. mrml 5->6 needs no source changes to the extension.
#
# Built here (per-branch) rather than in Dockerfile.base because the base
# image only rebuilds on push to master, so a base-only change would not be
# exercised by a PR's CI. No BuildKit-only features (the orb builds the batch
# image with the legacy builder on WSL) — the cargo build RUN layer is cached
# by Docker layer/registry caching between runs instead.
# ---------------------------------------------------------------------------
FROM php:8.4-cli AS mjml-ext-builder
RUN apt-get update && apt-get install -y --no-install-recommends \
    git curl ca-certificates build-essential clang libclang-dev pkg-config \
    && rm -rf /var/lib/apt/lists/*
RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain stable
ENV PATH="/root/.cargo/bin:${PATH}"
WORKDIR /build
RUN git clone --depth 1 --branch v1.2.1 https://github.com/alekitto/mjml-php.git . \
    && sed -i 's/^mrml = "5"/mrml = { version = "6", features = ["css-inline"] }/' Cargo.toml \
    && rm -f Cargo.lock \
    && grep -q 'css-inline' Cargo.toml
# Fix an upstream bug: RENDER_EXCEPTION is declared but never assigned, so every
# render ERROR path calls .expect("did not set exception ce") → a Rust panic that
# cannot unwind across FFI → the whole PHP process aborts (a malformed template
# would kill the batch worker). Throw a plain, catchable \Exception instead via
# PhpException::default (already used elsewhere in the same file).
RUN perl -0777 -pi -e 's/PhpException::new\(e\.to_string\(\), 0, unsafe \{\s*RENDER_EXCEPTION\.expect\("did not set exception ce"\)\s*\}\)/PhpException::default(e.to_string())/g' src/mjml.rs \
    && ! grep -q 'RENDER_EXCEPTION.expect' src/mjml.rs
RUN cargo build --release && cp target/release/libmjml.so /mjml.so

# ---------------------------------------------------------------------------
# Application image.
# ---------------------------------------------------------------------------
FROM ghcr.io/freegle/freegle-batch-base:latest

# Install + enable the mrml extension built above. Fail the build if the
# extension does not load (so a broken build never ships).
COPY --from=mjml-ext-builder /mjml.so /tmp/mjml.so
RUN cp /tmp/mjml.so "$(php-config --extension-dir)/mjml.so" \
    && rm /tmp/mjml.so \
    && docker-php-ext-enable mjml \
    && php -m | grep -qi '^mjml$'

WORKDIR /var/www/html

# Copy application files
COPY . .

# Install PHP dependencies including dev dependencies for testing
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Create .env file from example for Laravel to find (testing uses phpunit.xml env vars)
RUN cp .env.example .env || touch .env

# Generate application key
RUN php artisan key:generate --force

# Create storage directories with correct permissions
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p storage/logs \
    && chmod -R 777 storage bootstrap/cache

# Copy supervisor config
COPY docker/supervisor.conf /etc/supervisor/conf.d/laravel-worker.conf

# Copy entrypoint script
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Ensure interactive shells start in the working directory
RUN echo "cd /var/www/html" >> /root/.bashrc

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-n"]
