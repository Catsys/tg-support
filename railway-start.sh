#!/bin/bash
set -e

# Получаем порт из переменной окружения PORT (Railway автоматически устанавливает это)
PORT=${PORT:-8000}

echo "Starting application on port ${PORT}..."

# Замена порта в nginx конфиге
sed -i "s/listen __PORT__/listen ${PORT}/g" /etc/nginx/sites-available/default

# Проверка, что порт был заменен
if grep -q "__PORT__" /etc/nginx/sites-available/default; then
    echo "WARNING: Port placeholder __PORT__ was not replaced!"
else
    echo "Nginx configured to listen on port ${PORT}"
fi

# Проверка синтаксиса nginx
echo "Testing nginx configuration..."
nginx -t || {
    echo "ERROR: Nginx configuration test failed"
    exit 1
}

# Очистка кешей Laravel (если они есть) и создание новых
echo "Clearing Laravel caches..."
php artisan config:clear 2>&1 || echo "Warning: config:clear failed"
php artisan route:clear 2>&1 || echo "Warning: route:clear failed"
php artisan view:clear 2>&1 || echo "Warning: view:clear failed"

# Оптимизация Laravel для production (если не было сделано при сборке)
echo "Caching Laravel configuration..."
php artisan config:cache 2>&1 || echo "Warning: config:cache failed"
php artisan route:cache 2>&1 || echo "Warning: route:cache failed"
php artisan view:cache 2>&1 || echo "Warning: view:cache failed"

# Создание директории для логов supervisor, если её нет
mkdir -p /var/log/supervisor

echo "Starting supervisor..."

# Запуск supervisor в foreground режиме
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf -n
