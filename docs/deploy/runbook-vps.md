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
