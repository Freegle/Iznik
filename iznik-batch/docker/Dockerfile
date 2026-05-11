FROM ghcr.io/freegle/freegle-batch-base:latest

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
