# Use official PHP image with Apache
FROM php:8.4-apache

# Install dependencies and Postgres drivers
RUN apt-get update && apt-get install -y \
    libpq-dev \
    git \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Fix MPM conflict: Remove all MPM configs before enabling prefork
RUN rm -f /etc/apache2/mods-enabled/mpm_* \
    && a2enmod mpm_prefork \
    && a2enmod rewrite

# Copy files
COPY . /var/www/html/
WORKDIR /var/www/html

# Railway uses dynamic PORT - configure Apache to use whatever Railway provides
RUN sed -i 's/Listen 80/Listen ${PORT}/g' /etc/apache2/ports.conf \
    && sed -i 's/:80/:${PORT}/g' /etc/apache2/sites-available/000-default.conf

# Ensure Apache can read files
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Create a phpinfo file for testing
RUN echo "<?php phpinfo(); ?>" > /var/www/html/phpinfo.php

# Expose port (Railway will map dynamically)
EXPOSE 8080

# IMPORTANT: Use shell form to allow $PORT variable expansion
CMD apache2-foreground
