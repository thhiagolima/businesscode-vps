#!/bin/bash
set -e

echo "=== BusinessCode Deploy ==="
cd /var/www/businesscode/backend

echo "1. Pulling latest code..."
git pull origin main

echo "2. Installing dependencies..."
composer install --no-dev --optimize-autoloader

echo "3. Running migrations..."
php artisan migrate --force

echo "4. Caching config..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "5. Seeding (if needed)..."
php artisan db:seed --class=AiPromptsSeeder --force
php artisan db:seed --class=GlobalSettingsSeeder --force

echo "6. Restarting workers..."
php artisan queue:restart
supervisorctl restart businesscode-worker:*
supervisorctl restart businesscode-campaigns:*

echo "7. Building frontend..."
cd ../frontend
npm ci
npm run build

echo "=== Deploy complete ==="
