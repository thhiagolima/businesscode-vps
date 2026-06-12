#!/bin/sh
# Bootstrap de certificados Let's Encrypt para o stack nginx+certbot.
# Rodar UMA vez na VPS, após DNS apontado e antes do primeiro `up` completo.
set -e

domains="api.businesscode.com.br dash.businesscode.com.br businesscode.com.br www.businesscode.com.br"
email="admin@businesscode.com.br"   # trocar
staging=0                            # 1 = ambiente de teste do Let's Encrypt

# 1) Certificados dummy para o nginx subir (ele referencia os arquivos)
for d in api.businesscode.com.br dash.businesscode.com.br businesscode.com.br; do
  path="/etc/letsencrypt/live/$d"
  docker compose run --rm --entrypoint "\
    sh -c 'mkdir -p $path && \
    openssl req -x509 -nodes -newkey rsa:2048 -days 1 \
      -keyout $path/privkey.pem -out $path/fullchain.pem \
      -subj /CN=localhost'" certbot
done

# 2) Sobe o nginx com os certs dummy
docker compose up -d nginx

# 3) Remove os dummy e pede os certs reais (HTTP-01 via webroot)
for d in api.businesscode.com.br dash.businesscode.com.br businesscode.com.br; do
  docker compose run --rm --entrypoint "rm -rf /etc/letsencrypt/live/$d /etc/letsencrypt/archive/$d /etc/letsencrypt/renewal/$d.conf" certbot
done

staging_arg=""
[ "$staging" = "1" ] && staging_arg="--staging"

# businesscode.com.br + www no mesmo cert
docker compose run --rm --entrypoint "\
  certbot certonly --webroot -w /var/www/certbot $staging_arg \
    --email $email --agree-tos --no-eff-email \
    -d businesscode.com.br -d www.businesscode.com.br" certbot

# api e dash em certs separados
docker compose run --rm --entrypoint "\
  certbot certonly --webroot -w /var/www/certbot $staging_arg \
    --email $email --agree-tos --no-eff-email \
    -d api.businesscode.com.br" certbot

docker compose run --rm --entrypoint "\
  certbot certonly --webroot -w /var/www/certbot $staging_arg \
    --email $email --agree-tos --no-eff-email \
    -d dash.businesscode.com.br" certbot

# 4) Recarrega o nginx com os certs reais
docker compose exec nginx nginx -s reload
echo "TLS pronto."
