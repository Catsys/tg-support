#!/bin/bash
set -e

# Получаем порт из переменной окружения PORT (Railway автоматически устанавливает это)
PORT=${PORT:-8000}

# Замена порта в nginx конфиге (используем /tmp для совместимости)
sed -i.bak "s/listen __PORT__/listen ${PORT}/g" /etc/nginx/sites-available/default
rm -f /etc/nginx/sites-available/default.bak

# Очистка кешей Laravel (если они есть) и создание новых
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

# Оптимизация Laravel для production (если не было сделано при сборке)
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Запуск supervisor
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
