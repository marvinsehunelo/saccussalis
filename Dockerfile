# Use official PHP image with Apache
FROM php:8.4-apache

# Install PostgreSQL driver and required extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    git \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql

# Copy project files to web root
COPY . /var/www/html/

# Expose port
EXPOSE 8080

# Start Apache in foreground
CMD ["apache2-foreground"]
