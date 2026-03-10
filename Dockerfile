FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions needed by the app
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install mysqli pdo pdo_mysql gd zip mbstring

# PHP settings
RUN { \
    echo "date.timezone = Asia/Manila"; \
    echo "upload_max_filesize = 50M"; \
    echo "post_max_size = 50M"; \
    echo "max_execution_time = 300"; \
    echo "memory_limit = 256M"; \
    } > /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html

# Copy app files
COPY . .

# Ensure uploads and config directories are writable
RUN mkdir -p assets/uploads/logos \
    && chmod -R 777 assets/uploads config

EXPOSE ${PORT:-8080}

CMD php -S 0.0.0.0:${PORT:-8080} router.php
