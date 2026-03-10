FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions needed by the app
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install mysqli pdo pdo_mysql gd zip mbstring

# Enable Apache modules
RUN a2enmod rewrite headers expires deflate

# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# PHP settings
RUN { \
    echo "date.timezone = Asia/Manila"; \
    echo "upload_max_filesize = 50M"; \
    echo "post_max_size = 50M"; \
    echo "max_execution_time = 300"; \
    echo "memory_limit = 256M"; \
    echo "display_errors = On"; \
    echo "error_reporting = E_ALL"; \
    } > /usr/local/etc/php/conf.d/custom.ini

# Copy app files
COPY . /var/www/html/

# Make entrypoint executable and fix line endings
RUN chmod +x /var/www/html/docker-entrypoint.sh \
    && sed -i 's/\r$//' /var/www/html/docker-entrypoint.sh

# Ensure uploads and config directories are writable
RUN mkdir -p /var/www/html/assets/uploads/logos \
    && chown -R www-data:www-data /var/www/html/assets/uploads \
    && chown -R www-data:www-data /var/www/html/config

CMD ["/var/www/html/docker-entrypoint.sh"]
