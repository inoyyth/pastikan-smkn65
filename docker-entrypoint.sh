#!/bin/bash
set -e

# Ensure data and uploads directories exist
mkdir -p /var/www/html/data /var/www/html/uploads

# Ensure www-data ownership and write permissions for SQLite and uploads
chown -R www-data:www-data /var/www/html/data /var/www/html/uploads
chmod -R 775 /var/www/html/data /var/www/html/uploads

# If SQLite database file exists, ensure write permissions
if [ -f /var/www/html/data/laporan.sqlite ]; then
    chmod 664 /var/www/html/data/laporan.sqlite
fi

exec "$@"
