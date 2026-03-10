#!/bin/bash
set -e

# Use Railway's PORT env var, default to 80
PORT="${PORT:-80}"

# Configure Apache to listen on the correct port
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Suppress ServerName warning
echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Start Apache
exec apache2-foreground
