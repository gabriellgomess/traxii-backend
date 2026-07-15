#!/bin/sh

# Exit immediately if a command exits with a non-zero status
set -e

# Cache configuration, routes, and views for production
echo "Caching Laravel config, routes, and views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Recreate the public storage symlink (public/ is rebuilt on every deploy)
echo "Linking public storage..."
php artisan storage:link --force

# Ensure the mounted volume is writable by PHP-FPM (www-data)
chown -R www-data:www-data /var/www/html/storage

# Start PHP-FPM in background
echo "Starting PHP-FPM..."
php-fpm -D

# Start Nginx in foreground
echo "Starting Nginx..."
nginx -g "daemon off;"
