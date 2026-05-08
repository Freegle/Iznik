FROM php:8.4-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libwebp-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libgmp-dev \
    libicu-dev \
    libpq-dev \
    zip \
    unzip \
    supervisor \
    default-mysql-client \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Configure and install GD with JPEG/PNG/WebP support
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    pdo_pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gmp \
    zip \
    intl \
    gd

# Install Redis and PCOV PHP extensions (PCOV is faster than Xdebug for coverage)
RUN pecl install redis pcov && docker-php-ext-enable redis pcov

# Increase PHP memory limit for ParaTest parallel workers
RUN echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/memory-limit.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install Node.js for MJML
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install MJML globally
RUN npm install -g mjml

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Install PHP dependencies including dev dependencies for testing
RUN composer install --no-interaction --prefer-dist --optimize-autoloader || true

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
