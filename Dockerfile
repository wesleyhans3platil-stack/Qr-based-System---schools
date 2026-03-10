FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions needed by the app
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install mysqli pdo pdo_mysql gd zip mbstring

# Enable Apache mod_rewrite
RUN a2enmod rewrite headers expires deflate

# Set document root to /var/www/html
ENV APACHE_DOCUMENT_ROOT=/var/www/html

# Configure Apache to listen on Railway's PORT
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Set PHP timezone
RUN echo "date.timezone = Asia/Manila" > /usr/local/etc/php/conf.d/timezone.ini

# Increase upload limits for bulk import
RUN echo "upload_max_filesize = 50M\npost_max_size = 50M\nmax_execution_time = 300\nmemory_limit = 256M" > /usr/local/etc/php/conf.d/uploads.ini

# Copy app files
COPY . /var/www/html/

# Ensure uploads directory is writable
RUN mkdir -p /var/www/html/assets/uploads/logos \
    && chown -R www-data:www-data /var/www/html/assets/uploads \
    && chown -R www-data:www-data /var/www/html/config

EXPOSE ${PORT}

CMD ["apache2-foreground"]
