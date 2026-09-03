#!/usr/bin/env bash
set -e

cd /var/www/upperstack-aws-main
composer install --no-dev
chmod 664 .env

systemctl restart php8.4-fpm
systemctl restart nginx
