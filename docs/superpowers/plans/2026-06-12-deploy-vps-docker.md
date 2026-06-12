# Deploy em VPS Linux com Docker — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Empacotar o BusinessCode SaaS (Laravel 12 + Vue SPA + site Astro) em Docker Compose para rodar numa VPS Linux única, com `api.` e `dash.` em subdomínios separados — **sem tocar no projeto em produção** (trabalho numa cópia isolada).

**Architecture:** Imagem `app` (PHP 8.2-fpm, multi-stage) atende a API; imagem `nginx` (com os assets estáticos do SPA e do site Astro baked) termina TLS e faz roteamento por host + FastCGI para o `app`. MySQL, Redis, um worker de filas e um scheduler completam o stack, todos orquestrados por `docker compose`. Filas e cache migram de `database` para Redis. Process supervision é feita pelo Docker (`restart: unless-stopped`), substituindo o Supervisor.

**Tech Stack:** Docker / Docker Compose, PHP 8.2-fpm-alpine, Nginx 1.27, MySQL 8.0, Redis 7, Node 20 (build de Vue/Astro), Certbot (TLS).

---

## Referência: spec

Baseado em `docs/superpowers/specs/2026-06-12-deploy-vps-docker-design.md`. Releia a Seção 3 (isolamento) e Seção 6 (mudanças) antes de começar.

## Estrutura de arquivos (na cópia `new_saas-vps`)

```
new_saas-vps/
├── backend/                      # Laravel (copiado, inalterado salvo .env)
├── frontend/
│   └── src/composables/useApi.ts # ÚNICA alteração de código de app
├── site/                         # Astro (copiado, inalterado)
├── docker/
│   ├── nginx/conf.d/api.conf
│   ├── nginx/conf.d/dash.conf
│   ├── nginx/conf.d/site.conf
│   ├── php/php.ini
│   ├── php/www.conf
│   └── entrypoint.sh
├── Dockerfile                    # multi-stage (frontend, site, app, nginx)
├── docker-compose.yml
├── .dockerignore
├── .env.example                  # variáveis do compose (DB/domínios) — committed
├── .env                          # real, NÃO committed
├── backend/.env.production.example# template do .env do Laravel — committed
├── backend/.env                  # real do Laravel, NÃO committed
└── scripts/init-letsencrypt.sh   # bootstrap TLS
```

**Decisões de implementação fixadas:**
- O `build.bat` (renomeia `index.html`→`app.html`) **não é replicado**: com `.dash` servindo só o SPA, o `dist/index.html` do Vite já é o entrypoint do SPA. O landing agora é o site Astro.
- **Supervisor é substituído** por serviços `worker`/`scheduler` no compose com `restart: unless-stopped`. Um único `queue:work` processa as 4 filas por prioridade.
- Serving de uploads locais: produção usa `FILESYSTEM_DISK=s3` (recomendado). Para storage local, ver nota na Task 6.

---

### Task 0: Criar cópia isolada e branch

**Files:**
- Create: `C:\projects\new_saas-vps\` (cópia limpa, fora do `htdocs`)

- [ ] **Step 1: Copiar o projeto excluindo artefatos pesados/sensíveis**

PowerShell (robocopy ignora `node_modules`, `vendor`, `dist`, `.git`, `.env`, logs):

```powershell
robocopy C:\xampp\htdocs\new_saas C:\projects\new_saas-vps /E `
  /XD node_modules vendor dist .git storage\logs `
  /XF .env .env.local *.log
```

Expected: robocopy termina com código < 8 (0–7 = sucesso). Código 1 é normal (arquivos copiados).

- [ ] **Step 2: Inicializar repo git novo e isolado na cópia**

```powershell
cd C:\projects\new_saas-vps
git init -b feat/deploy-vps-docker
git add -A
git commit -m "chore: cópia isolada do new_saas para preparar deploy em VPS Docker"
```

Expected: commit criado. `git log --oneline` mostra 1 commit.

- [ ] **Step 3: Confirmar que a produção não foi tocada**

```powershell
cd C:\xampp\htdocs\new_saas
git status --short
```

Expected: nenhuma mudança nova além do que já existia antes do início desta tarefa (a cópia está fora do `htdocs`, então não aparece aqui).

> A partir daqui, **todos os caminhos são relativos a `C:\projects\new_saas-vps`**.

---

### Task 1: `.dockerignore`

**Files:**
- Create: `.dockerignore`

- [ ] **Step 1: Criar `.dockerignore`**

```
# Dependências e builds (reconstruídos na imagem)
**/node_modules
**/vendor
**/dist
backend/storage/logs/*.log
backend/storage/framework/cache/*
backend/storage/framework/sessions/*
backend/storage/framework/views/*

# Segredos e ambiente
**/.env
**/.env.local
.env

# Git e metadados
.git
.gitignore
**/.DS_Store

# Docs/specs não vão para a imagem
docs/
```

- [ ] **Step 2: Commit**

```powershell
git add .dockerignore
git commit -m "chore(docker): adiciona .dockerignore"
```

---

### Task 2: `docker/php` — php.ini e pool FPM

**Files:**
- Create: `docker/php/php.ini`
- Create: `docker/php/www.conf`

- [ ] **Step 1: Criar `docker/php/php.ini`**

```ini
memory_limit = 512M
upload_max_filesize = 25M
post_max_size = 27M
max_execution_time = 120
expose_php = Off

opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 192
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
opcache.revalidate_freq = 0
```

- [ ] **Step 2: Criar `docker/php/www.conf`**

```ini
[www]
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
pm.max_requests = 500
; Repassa variáveis de ambiente do container para o PHP
clear_env = no
```

- [ ] **Step 3: Commit**

```powershell
git add docker/php
git commit -m "chore(docker): config php-fpm de produção (opcache, pool, env)"
```

> `opcache.validate_timestamps = 0` exige reiniciar o container `app` a cada deploy de código novo (imagem imutável). É o comportamento desejado.

---

### Task 3: `docker/entrypoint.sh`

**Files:**
- Create: `docker/entrypoint.sh`

- [ ] **Step 1: Criar `docker/entrypoint.sh`**

```sh
#!/bin/sh
set -e

cd /var/www/html

# storage é um volume montado: garante a estrutura mínima do Laravel
mkdir -p storage/framework/cache/data \
         storage/framework/sessions \
         storage/framework/views \
         storage/app/public \
         storage/logs
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# Descobre pacotes (não depende de DB)
php artisan package:discover --ansi || true

# Cacheia config/rotas/views só quando o servidor php-fpm sobe de fato
# (evita recachear em cada `docker compose run ... artisan ...` e nos workers)
if [ "${CONTAINER_ROLE:-app}" = "app" ] && [ "$1" = "php-fpm" ]; then
  php artisan storage:link 2>/dev/null || true
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
fi

exec "$@"
```

- [ ] **Step 2: Garantir permissão de execução (registrada no git)**

```powershell
git add docker/entrypoint.sh
git update-index --chmod=+x docker/entrypoint.sh
git commit -m "chore(docker): entrypoint (storage skeleton + cache condicional por role)"
```

Expected: `git ls-files -s docker/entrypoint.sh` mostra modo `100755`.

> Se `route:cache` falhar por closures em rotas, remova essa linha (a API do projeto usa controllers, então deve passar). Isso será validado na Task 10.

---

### Task 4: `Dockerfile` multi-stage

**Files:**
- Create: `Dockerfile`

- [ ] **Step 1: Criar `Dockerfile`**

```dockerfile
# syntax=docker/dockerfile:1.7

###########################################
# Stage 1: build do SPA Vue (dash)
###########################################
FROM node:20-alpine AS frontend-build
WORKDIR /app/frontend
COPY frontend/package*.json ./
RUN npm ci
COPY frontend/ ./
ARG VITE_API_URL=https://api.businesscode.com.br/v1
ENV VITE_API_URL=${VITE_API_URL}
RUN npm run build
# Saída: /app/frontend/dist/index.html é o entrypoint do SPA (sem rename)

###########################################
# Stage 2: build do site Astro (raiz)
###########################################
FROM node:20-alpine AS site-build
WORKDIR /app/site
COPY site/package*.json ./
RUN npm ci
COPY site/ ./
RUN npm run build
# Saída: /app/site/dist

###########################################
# Stage 3: imagem da aplicação PHP-FPM
###########################################
FROM php:8.2-fpm-alpine AS app

# Extensões PHP necessárias
RUN apk add --no-cache \
        icu-dev oniguruma-dev libzip-dev libpng-dev freetype-dev libjpeg-turbo-dev \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath mbstring gd intl zip opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY backend/ ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && chown -R www-data:www-data /var/www/html

USER www-data
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

###########################################
# Stage 4: imagem Nginx com assets estáticos
###########################################
FROM nginx:1.27-alpine AS nginx
COPY docker/nginx/conf.d/ /etc/nginx/conf.d/
COPY --from=frontend-build /app/frontend/dist /var/www/dash
COPY --from=site-build     /app/site/dist     /var/www/site
# public/ do Laravel (para try_files de estáticos no domínio api)
COPY backend/public /var/www/html/public
```

- [ ] **Step 2: Validar sintaxe do Dockerfile (sem build completo)**

```powershell
docker build --check .
```

Expected: sem erros de sintaxe (`Check complete, no warnings found` ou apenas warnings informativos).

- [ ] **Step 3: Commit**

```powershell
git add Dockerfile
git commit -m "feat(docker): Dockerfile multi-stage (frontend, site, app php-fpm, nginx)"
```

---

### Task 5: Configs Nginx (api, dash, site)

**Files:**
- Create: `docker/nginx/conf.d/api.conf`
- Create: `docker/nginx/conf.d/dash.conf`
- Create: `docker/nginx/conf.d/site.conf`

- [ ] **Step 1: Criar `docker/nginx/conf.d/api.conf`**

```nginx
server {
    listen 80;
    server_name api.businesscode.com.br;
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl;
    http2 on;
    server_name api.businesscode.com.br;

    ssl_certificate     /etc/letsencrypt/live/api.businesscode.com.br/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.businesscode.com.br/privkey.pem;

    root  /var/www/html/public;
    index index.php;

    client_max_body_size 25m;

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass  app:9000;
        fastcgi_param SCRIPT_FILENAME /var/www/html/public$fastcgi_script_name;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

- [ ] **Step 2: Criar `docker/nginx/conf.d/dash.conf`**

```nginx
server {
    listen 80;
    server_name dash.businesscode.com.br;
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl;
    http2 on;
    server_name dash.businesscode.com.br;

    ssl_certificate     /etc/letsencrypt/live/dash.businesscode.com.br/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/dash.businesscode.com.br/privkey.pem;

    root  /var/www/dash;
    index index.html;

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;

    location = /index.html {
        add_header Cache-Control "no-cache, no-store, must-revalidate";
    }

    location ~* \.(?:css|js|woff2?|svg|png|jpg|jpeg|gif|ico|webp|mp3)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # SPA fallback (Vue Router em history mode)
    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

- [ ] **Step 3: Criar `docker/nginx/conf.d/site.conf`**

```nginx
server {
    listen 80;
    server_name businesscode.com.br www.businesscode.com.br;
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl;
    http2 on;
    server_name businesscode.com.br www.businesscode.com.br;

    ssl_certificate     /etc/letsencrypt/live/businesscode.com.br/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/businesscode.com.br/privkey.pem;

    root  /var/www/site;
    index index.html;

    location ~* \.(?:css|js|woff2?|svg|png|jpg|jpeg|gif|ico|webp)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # URLs limpas do Astro (/precos -> /precos/index.html ou /precos.html)
    location / {
        try_files $uri $uri/ $uri.html /index.html;
    }

    error_page 404 /404.html;
}
```

- [ ] **Step 4: Commit**

```powershell
git add docker/nginx
git commit -m "feat(nginx): server blocks api (fastcgi) + dash (spa) + site (astro)"
```

> **Nota — storage local no domínio api:** se `FILESYSTEM_DISK=local` (em vez de `s3`), arquivos em `/storage/*` não serão servidos pelo nginx (ele só tem `backend/public` baked, sem o volume de storage). Para servir localmente, monte o volume `storage` no nginx em `/var/www/html/public/storage` (read-only) e crie o symlink. **Recomendação: usar S3** e evitar isso.

---

### Task 6: `docker-compose.yml`

**Files:**
- Create: `docker-compose.yml`

- [ ] **Step 1: Criar `docker-compose.yml`**

```yaml
services:
  app:
    build:
      context: .
      target: app
      args:
        VITE_API_URL: ${VITE_API_URL}
    image: businesscode/app:latest
    env_file: [ ./backend/.env ]
    environment:
      CONTAINER_ROLE: app
    volumes:
      - storage:/var/www/html/storage
    depends_on:
      mysql:  { condition: service_healthy }
      redis:  { condition: service_started }
    restart: unless-stopped

  worker:
    image: businesscode/app:latest
    env_file: [ ./backend/.env ]
    environment:
      CONTAINER_ROLE: worker
    command: php artisan queue:work --queue=campaigns,messaging,billing,default --sleep=3 --tries=3 --max-time=3600 --timeout=3600
    volumes:
      - storage:/var/www/html/storage
    depends_on:
      app:   { condition: service_started }
      redis: { condition: service_started }
    restart: unless-stopped

  scheduler:
    image: businesscode/app:latest
    env_file: [ ./backend/.env ]
    environment:
      CONTAINER_ROLE: scheduler
    command: php artisan schedule:work
    volumes:
      - storage:/var/www/html/storage
    depends_on:
      app: { condition: service_started }
    restart: unless-stopped

  nginx:
    build:
      context: .
      target: nginx
      args:
        VITE_API_URL: ${VITE_API_URL}
    image: businesscode/nginx:latest
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - letsencrypt:/etc/letsencrypt
      - certbot-www:/var/www/certbot
    depends_on: [ app ]
    restart: unless-stopped

  certbot:
    image: certbot/certbot
    volumes:
      - letsencrypt:/etc/letsencrypt
      - certbot-www:/var/www/certbot
    entrypoint: "/bin/sh -c 'trap exit TERM; while :; do certbot renew --webroot -w /var/www/certbot; sleep 12h & wait $${!}; done'"

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - mysql-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-p${DB_ROOT_PASSWORD}"]
      interval: 10s
      timeout: 5s
      retries: 10
    restart: unless-stopped

  redis:
    image: redis:7-alpine
    command: redis-server --appendonly yes
    volumes:
      - redis-data:/data
    restart: unless-stopped

volumes:
  mysql-data:
  redis-data:
  storage:
  letsencrypt:
  certbot-www:
```

- [ ] **Step 2: Commit**

```powershell
git add docker-compose.yml
git commit -m "feat(docker): compose (app, worker, scheduler, nginx, certbot, mysql, redis)"
```

> A imagem `app` é compartilhada por `app`/`worker`/`scheduler` (mesma tag, comandos diferentes). Para escalar workers: `docker compose up -d --scale worker=2`.

---

### Task 7: Variáveis de ambiente (templates)

**Files:**
- Create: `.env.example` (compose)
- Create: `backend/.env.production.example` (Laravel)

- [ ] **Step 1: Criar `.env.example` (raiz, usado pelo compose)**

```dotenv
# URL pública da API embutida no build do SPA (baked em tempo de build)
VITE_API_URL=https://api.businesscode.com.br/v1

# MySQL (container)
DB_DATABASE=businesscode_saas
DB_USERNAME=businesscode
DB_PASSWORD=TROCAR_senha_forte_app
DB_ROOT_PASSWORD=TROCAR_senha_forte_root
```

- [ ] **Step 2: Criar `backend/.env.production.example`**

```dotenv
APP_NAME=BusinessCode
APP_ENV=production
APP_KEY=                      # gerar: docker compose run --rm app php artisan key:generate --show
APP_DEBUG=false
APP_URL=https://api.businesscode.com.br
APP_TIMEZONE=America/Sao_Paulo

# Frontend (alimenta config/cors.php)
FRONTEND_URL=https://dash.businesscode.com.br

# Banco (host = nome do serviço no compose)
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=businesscode_saas
DB_USERNAME=businesscode
DB_PASSWORD=TROCAR_senha_forte_app

# Redis (cache + filas + sessão)
REDIS_HOST=redis
REDIS_PORT=6379
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

# Sanctum (auth é Bearer token; mantemos o domínio do dash por segurança)
SANCTUM_STATEFUL_DOMAINS=dash.businesscode.com.br

# Storage (recomendado S3 em produção)
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=

# Mail real (trocar de 'log')
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@businesscode.com.br
MAIL_FROM_NAME=BusinessCode

# Logs
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=error

# Integrações (preencher com chaves reais)
INFOBIP_API_KEY=
INFOBIP_FROM_EMAIL=
INFOBIP_WEBHOOK_SECRET=
INFOBIP_INBOUND_WEBHOOK_SECRET=
GROK_API_KEY=
ELEVENLABS_API_KEY=
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET=
MP_PUBLIC_KEY=
MP_ACCESS_TOKEN=
MP_WEBHOOK_SECRET=

# Superadmin (NÃO reutilizar a senha versionada antiga; gerar nova)
SUPERADMIN_EMAIL=admin@businesscode.com.br
SUPERADMIN_PASSWORD=TROCAR_senha_forte_admin
```

- [ ] **Step 3: Garantir que os `.env` reais fiquem fora do git**

Confirme que `.gitignore` (raiz da cópia) contém:

```
.env
backend/.env
```

Se não, adicione e commite.

- [ ] **Step 4: Commit dos templates**

```powershell
git add .env.example backend/.env.production.example .gitignore
git commit -m "chore(env): templates de ambiente (compose + laravel produção)"
```

---

### Task 8: Alteração de código no frontend (`baseURL` por env)

**Files:**
- Modify: `frontend/src/composables/useApi.ts`

- [ ] **Step 1: Inspecionar o arquivo atual**

Run:
```powershell
Get-Content frontend\src\composables\useApi.ts -TotalCount 15
```
Expected: ver a linha `baseURL: '/api/v1',` dentro de `axios.create({ ... })`.

- [ ] **Step 2: Trocar a `baseURL` para usar a env var**

Substituir:
```ts
  baseURL: '/api/v1',
```
por:
```ts
  baseURL: import.meta.env.VITE_API_URL || '/api/v1',
```

- [ ] **Step 3: Verificar que o build de dev ainda usa proxy**

Confirme que `frontend/vite.config.ts` mantém o bloco `server.proxy` para `/api` apontando a `http://127.0.0.1:8000` (usado só em `npm run dev`; em produção o valor vem de `VITE_API_URL`). Nenhuma mudança necessária aqui.

- [ ] **Step 4: Commit**

```powershell
git add frontend/src/composables/useApi.ts
git commit -m "feat(frontend): baseURL da API via VITE_API_URL (suporta api. dedicado)"
```

> O valor de produção (`https://api.businesscode.com.br/v1`) é injetado como build arg no Dockerfile e fica **baked** no bundle. Trocar de domínio exige rebuild da imagem.

---

### Task 9: Script de bootstrap TLS

**Files:**
- Create: `scripts/init-letsencrypt.sh`

- [ ] **Step 1: Criar `scripts/init-letsencrypt.sh`**

```sh
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
```

- [ ] **Step 2: Commit**

```powershell
git add scripts/init-letsencrypt.sh
git update-index --chmod=+x scripts/init-letsencrypt.sh
git commit -m "chore(tls): script de bootstrap de certificados Let's Encrypt"
```

> Este script roda **na VPS** (Task 11). No Docker Desktop local (Task 10) usamos certs dummy / HTTP, sem Let's Encrypt.

---

### Task 10: Validação local (Docker Desktop)

**Files:** nenhum (validação).

- [ ] **Step 1: Preparar `.env` locais**

```powershell
cd C:\projects\new_saas-vps
Copy-Item .env.example .env
Copy-Item backend\.env.production.example backend\.env
```
Edite `backend\.env` para teste local: `APP_URL=http://localhost:8080`, `FRONTEND_URL=http://localhost:8081`, `FILESYSTEM_DISK=local`, e preencha `DB_PASSWORD`/`DB_ROOT_PASSWORD` iguais ao `.env`.

- [ ] **Step 2: Validar a composição**

Run: `docker compose config`
Expected: YAML resolvido sem erros; variáveis substituídas.

- [ ] **Step 3: Build das imagens**

Run: `docker compose build`
Expected: stages `frontend-build`, `site-build`, `app`, `nginx` concluem sem erro.

- [ ] **Step 4: Subir o stack**

Run: `docker compose up -d`
Expected: `docker compose ps` mostra `mysql` healthy e `app`, `worker`, `scheduler`, `nginx`, `redis` em `running`.

- [ ] **Step 5: Gerar APP_KEY e rodar migrations**

```powershell
docker compose run --rm app php artisan key:generate --force
docker compose run --rm app php artisan migrate --force
```
Expected: `key:generate` grava APP_KEY no `backend/.env`; `migrate` aplica as 79 migrations sem erro.

> Após `key:generate`, reinicie o `app` para refazer o cache de config: `docker compose up -d --force-recreate app`.

- [ ] **Step 6: Smoke test da API**

Run (mapeie a porta 80 do nginx ou teste o container app direto):
```powershell
docker compose exec app php artisan route:list --path=api | Select-Object -First 20
```
Expected: lista de rotas `api/v1/...` carrega (confirma que `route:cache` funcionou).

- [ ] **Step 7: Verificar workers e scheduler**

```powershell
docker compose logs --tail=20 worker
docker compose logs --tail=20 scheduler
```
Expected: `worker` aguardando jobs (sem crash loop); `scheduler` logando "Running scheduled tasks" a cada minuto.

- [ ] **Step 8: Verificar conexão Redis (cache/fila)**

```powershell
docker compose exec app php artisan tinker --execute "cache()->put('ping','pong',60); echo cache()->get('ping');"
```
Expected: imprime `pong` (confirma `CACHE_STORE=redis`).

- [ ] **Step 9: Derrubar o stack de teste**

Run: `docker compose down`
Expected: containers removidos; volumes preservados.

- [ ] **Step 10: Commit (caso ajustes tenham sido feitos durante a validação)**

```powershell
git add -A
git commit -m "fix(docker): ajustes após validação local do stack" --allow-empty
```

---

### Task 11: Runbook de provisionamento da VPS

**Files:**
- Create: `docs/deploy/runbook-vps.md`

- [ ] **Step 1: Criar `docs/deploy/runbook-vps.md`**

````markdown
# Runbook — Provisionamento da VPS (BusinessCode)

## Pré-requisitos
- VPS Ubuntu 22.04+ (mín. 2 vCPU / 4 GB RAM; recomendado 4 vCPU / 8 GB).
- DNS com A records apontando para o IP da VPS:
  - `api.businesscode.com.br`
  - `dash.businesscode.com.br`
  - `businesscode.com.br` e `www.businesscode.com.br`

## 1. Instalar Docker + Compose
```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER   # relogar após isso
```

## 2. Clonar o projeto
```bash
sudo mkdir -p /opt/businesscode && sudo chown $USER /opt/businesscode
cd /opt/businesscode
git clone <repo-da-copia> .
git checkout feat/deploy-vps-docker
```

## 3. Configurar ambiente
```bash
cp .env.example .env
cp backend/.env.production.example backend/.env
# Editar AMBOS com segredos reais (DB, integrações, mail, S3)
# Senhas fortes em DB_PASSWORD / DB_ROOT_PASSWORD (iguais nos dois onde aplicável)
```

## 4. Bootstrap de TLS (após DNS propagado)
```bash
# Editar email no script antes de rodar
sh scripts/init-letsencrypt.sh
```

## 5. Subir o stack
```bash
docker compose build
docker compose up -d
docker compose run --rm app php artisan key:generate --force
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan db:seed --class=GlobalSettingsSeeder --force
docker compose run --rm app php artisan db:seed --class=AiPromptsSeeder --force
docker compose up -d --force-recreate app   # recarrega cache de config
```

## 6. Verificações
```bash
curl -I https://dash.businesscode.com.br        # 200 + index.html
curl -I https://businesscode.com.br             # 200 (Astro)
curl -s https://api.businesscode.com.br/api/v1/health  # se houver rota health
docker compose ps                               # todos running/healthy
```

## 7. Backups (cron no host)
```bash
# /etc/cron.d/businesscode-db-backup
0 3 * * * root docker compose -f /opt/businesscode/docker-compose.yml exec -T mysql \
  mysqldump -u root -p"$DB_ROOT_PASSWORD" businesscode_saas | gzip > /opt/backups/db-$(date +\%F).sql.gz
```
````

- [ ] **Step 2: Commit**

```powershell
git add docs/deploy/runbook-vps.md
git commit -m "docs(deploy): runbook de provisionamento da VPS"
```

---

### Task 12: Runbook de cutover (sem downtime do legado até validar)

**Files:**
- Create: `docs/deploy/runbook-cutover.md`

- [ ] **Step 1: Criar `docs/deploy/runbook-cutover.md`**

````markdown
# Runbook — Cutover para a VPS

Objetivo: validar a VPS em paralelo com o XAMPP em produção e só virar o DNS quando OK.

## Fase A — Validar a VPS sem mexer no DNS público
No seu computador, edite o `hosts` para apontar os domínios ao IP da VPS:
```
<IP_VPS> api.businesscode.com.br
<IP_VPS> dash.businesscode.com.br
<IP_VPS> businesscode.com.br
```
- Acesse o dashboard, faça login (auth Bearer), exercite campanhas, contatos.
- Dispare 1 webhook de teste (Infobip/Meta sandbox) para `https://api.businesscode.com.br/...`.
- Confirme filas processando (`docker compose logs worker`).
Remova as linhas do `hosts` ao terminar.

## Fase B — Migrar dados
```bash
# Export do MySQL legado (XAMPP)
mysqldump -u root businesscode_saas > dump.sql
# Import na VPS
docker compose exec -T mysql mysql -u root -p"$DB_ROOT_PASSWORD" businesscode_saas < dump.sql
# Migrar storage (áudios/uploads) se local -> S3 ou volume
```

## Fase C — Virar o DNS
- Baixar TTL dos A records antes (ex.: 300s) com antecedência.
- Atualizar A records `api.`, `dash.`, raiz e `www` para o IP da VPS.
- Atualizar URLs de webhook nos painéis Infobip / Meta / Mercado Pago para `api.businesscode.com.br`.

## Fase D — Desativar o legado
- Após 24–48h estável, parar os serviços do XAMPP (Apache, workers, scheduler).
- Manter backup do banco e do `.env` legado.

## Rollback
- Reverter A records para o IP antigo (XAMPP segue intacto durante todo o processo).
````

- [ ] **Step 2: Commit**

```powershell
git add docs/deploy/runbook-cutover.md
git commit -m "docs(deploy): runbook de cutover com validação paralela e rollback"
```

---

## Verificação final do plano

Ao concluir todas as tasks:
- `docker compose ps` → todos os serviços `running`/`healthy`.
- `https://dash...` serve o SPA; `https://businesscode.com.br` serve o Astro; `https://api...` responde à API e webhooks.
- Filas e scheduler ativos via containers (sem Supervisor).
- Produção legada (XAMPP) intocada até o cutover; rollback = reverter DNS.
