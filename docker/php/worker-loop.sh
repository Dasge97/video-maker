#!/bin/sh
# El proceso de fondo: hace los pasos pendientes de cada vídeo. Si se cae, vuelve a arrancar;
# sigue donde iba porque el estado de cada vídeo está en su job.json.
cd /var/www/html

while true; do
    php bin/console app:worker --no-interaction || echo "El worker ha terminado con código $?"
    sleep 5
done
