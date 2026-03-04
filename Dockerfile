FROM php:8.4-cli

# Install system dependencies and PostgreSQL extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    git \
    unzip \
    && docker-php-ext-install pdo_pgsql pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /app

# Copy application files
COPY . .

# Install Composer if composer.json exists
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN if [ -f "composer.json" ]; then composer install --no-dev --optimize-autoloader; fi

# Create a test file to verify extensions
RUN echo "<?php phpinfo(); ?>" > /app/info.php

# Expose port
EXPOSE 8080

# Start PHP built-in server
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t ."]
