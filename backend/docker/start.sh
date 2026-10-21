#!/bin/sh
# Arranque del contenedor: prepara Laravel, la base y los procesos programados, y luego inicia Apache.
set -e
cd /var/www/html

if [ -z "${APP_KEY:-}" ] || [ -z "${DB_URI:-}" ]; then
    echo "Faltan APP_KEY o DB_URI en las variables de entorno." >&2
    exit 1
fi
# Render genera un valor base64 de 256 bits sin el prefijo que Laravel espera.
case "$APP_KEY" in
    base64:*) ;;
    *) export APP_KEY="base64:$APP_KEY" ;;
esac

artisan() { runuser -u www-data -- php artisan "$@" --no-interaction; }

artisan config:cache
# Idempotentes: crean colecciones, índices, catálogo y el primer administrador si falta.
artisan cp:install || echo "cp:install falló; la API arranca igual y se reintenta en el próximo inicio." >&2
artisan cp:bootstrap-admin || echo "cp:bootstrap-admin falló." >&2

# Planillas, eventos de integración y cierre diario. Se alinea al inicio de cada minuto.
(
    while true; do
        sleep $((60 - $(date +%S | sed 's/^0//')))
        artisan schedule:run || true
    done
) &

exec apache2-foreground
