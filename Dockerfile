# Use official PHP image with Apache
FROM php:8.4-apache

# Disable conflicting MPM modules and enable prefork (required for PHP)
RUN apt-get update && apt-get install -y \
    libpq-dev \
    git \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql \
    && a2dismod mpm_event mpm_worker \
    && a2enmod mpm_prefork \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copy project files to web root
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html

# Configure Apache to use port 8080
RUN sed -i 's/80/8080/g' /etc/apache2/ports.conf \
    && sed -i 's/:80/:8080/g' /etc/apache2/sites-available/000-default.conf

# Ensure Apache can read files
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Create a phpinfo file for testing (optional)
RUN echo "<?php phpinfo(); ?>" > /var/www/html/phpinfo.php

# Expose port 8080
EXPOSE 8080

# Start Apache in foreground
CMD ["apache2-foreground"]
