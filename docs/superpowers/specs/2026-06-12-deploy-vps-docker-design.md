# Deploy do BusinessCode SaaS em VPS Linux com Docker

**Data:** 2026-06-12
**Status:** Design aprovado — pronto para plano de implementação
**Escopo:** Preparar o sistema (hoje em Windows + XAMPP, em produção) para rodar em VPS Linux usando Docker Compose, com `api.` e `dash.` em subdomínios separados. **Sem alterar o projeto em produção** — todo o trabalho ocorre em uma cópia isolada.

---

## 1. Contexto e estado atual

O sistema roda hoje em **Windows + XAMPP (Apache)** e está **em produção**.

Estrutura do repositório (`c:\xampp\htdocs\new_saas`):
- **`backend/`** — Laravel 12, PHP 8.2, autenticação **Sanctum por Bearer token** (não cookie de sessão), MySQL, filas via driver `database` (filas: `default`, `campaigns`, `messaging`, `billing`), scheduler com 3 tarefas diárias (billing 03h/04h, verify-domains 05h). Sem WebSocket.
- **`frontend/`** — SPA Vue 3 + Vite. Chama a API por caminho relativo `/api/v1`; autentica por Bearer token persistido em `localStorage`.
- **`site/`** — Site estático em Astro (landing `businesscode.com.br`).

Deploy atual: `dash.businesscode.com.br` serve o SPA e faz `ProxyPass /api/ → 127.0.0.1:8000` (via `php artisan serve`). Já existem `backend/deploy/deploy.sh` e `supervisor-worker.conf` escritos para Linux. **Não há Docker nem CI/CD.**

### Descobertas que fundamentam o design
1. **Auth é Bearer token, não cookie de sessão** → separar `.api` de `.dash` em subdomínios distintos é trivial (sem problema de cookie cross-subdomain). CORS já existe em `config/cors.php` (lê `FRONTEND_URL`).
2. **Webhooks externos** (Infobip, WhatsApp/Meta, Mercado Pago) exigem URL pública estável em HTTPS → favorece um `api.` dedicado.
3. **Integrações obrigatórias** via env: Infobip, Grok/xAI, ElevenLabs, Mercado Pago, Turnstile; S3 opcional para storage.

---

## 2. Decisões aprovadas

| Decisão | Escolha | Motivo |
|---|---|---|
| Empacotamento | **Docker Compose** | Reprodutibilidade; isola PHP 8.2; sobe MySQL/Redis/workers declarativamente |
| API vs Dashboard | **Subdomínios separados** (`api.` + `dash.`) | Auth Bearer torna limpo; URL estável p/ webhooks; escala melhor |
| Topologia | **VPS única, tudo junto** | Simplicidade e custo para começar |
| Servidor web | **Nginx** | Mais leve; configs simples p/ php-fpm + proxy + SPA fallback |
| TLS | **Dentro do Compose** (nginx + certbot) | Simplicidade num VPS único |
| Site Astro | **Mesmo Compose/VPS** | Mantém tudo num só lugar |
| **Isolamento** | **Trabalhar em cópia isolada** | Projeto está em produção; não tocar no original até o cutover |

---

## 3. Estratégia de isolamento (produção intocada)

1. Criar uma **cópia do repositório em pasta nova, fora do caminho servido pelo XAMPP** (ex.: `c:\projects\new_saas-vps`, não dentro de `htdocs`, para o Apache nunca servir acidentalmente).
2. A cópia é feita via git (clone do repo local ou cópia sem `vendor/` e `node_modules/`), em um branch novo `feat/deploy-vps-docker`.
3. Toda a infra Docker e a única alteração de código (`useApi.ts`) acontecem **somente na cópia**.
4. Validação local com Docker Desktop antes de subir à VPS.
5. Produção (XAMPP) só é desligada/migrada no **cutover** final, após validação na VPS.

---

## 4. Arquitetura alvo (Docker Compose)

```
                    Internet (443)
                         │
                ┌────────▼─────────┐
                │   nginx (edge)   │  TLS (certbot) + roteamento por host
                └───┬─────┬─────┬──┘
   businesscode.com.br  dash.   api.
     (Astro static)  (SPA static)  proxy FastCGI → php-fpm
                                         │
                                   ┌─────▼─────┐
                                   │  php-fpm  │  Laravel 12 / PHP 8.2
                                   └──┬──┬──┬──┘
                              ┌───────┘  │  └────────┐
                         ┌────▼───┐  ┌───▼────┐  ┌───▼───┐
                         │ mysql  │  │ redis  │  │storage│
                         └────────┘  └────────┘  └ vol ──┘
        worker (supervisor: 4 filas)   scheduler (schedule:work)
```

### Serviços (containers)
| Serviço | Imagem | Função |
|---|---|---|
| `nginx` | nginx + assets buildados | TLS, serve SPA (`dash`) e site Astro (raiz), proxy FastCGI p/ `api` |
| `app` | PHP 8.2-fpm + código | Atende requisições da API |
| `worker` | = imagem `app` | Supervisor rodando `queue:work` para `default`, `campaigns`, `messaging`, `billing` |
| `scheduler` | = imagem `app` | `php artisan schedule:work` |
| `mysql` | mysql:8 | Banco (volume `mysql-data`) |
| `redis` | redis:7 | Cache + filas + sessão |
| `certbot` | certbot/certbot | Emissão/renovação automática de TLS |

### Volumes persistentes
- `mysql-data` — dados do banco
- `storage` — `backend/storage/app` (áudios TTS, uploads); S3 continua opcional via `FILESYSTEM_DISK=s3`
- `letsencrypt` — certificados TLS

### Imagem `app` (multi-stage)
- **Stage Node:** builda `frontend/dist` (com `VITE_API_URL`) e `site/dist`.
- **Stage PHP:** `composer install --no-dev --optimize-autoloader`.
- **Entrypoint:** `storage:link`, `config:cache`, `route:cache`, `view:cache`, depois inicia php-fpm.
- **Migrations:** rodam em passo dedicado do deploy (`docker compose run --rm app php artisan migrate --force`), **não** por worker, para evitar corrida.

---

## 5. Mapeamento de domínios

| Domínio | Servido por | Conteúdo |
|---|---|---|
| `businesscode.com.br` + `www` | nginx (static) | Site Astro (`site/dist`) |
| `dash.businesscode.com.br` | nginx (static) | SPA Vue (`frontend/dist`) |
| `api.businesscode.com.br` | nginx → php-fpm | Laravel API + webhooks (Infobip, WhatsApp/Meta, Mercado Pago) |

---

## 6. Mudanças necessárias

### 6.1 Código (mínimo)
- **`frontend/src/composables/useApi.ts`** → `baseURL: import.meta.env.VITE_API_URL || '/api/v1'`. Build com `VITE_API_URL=https://api.businesscode.com.br/v1`.
- **`frontend/vite.config.ts`** → proxy permanece apenas para desenvolvimento.
- **`build.bat`** (Windows-only) → equivalente no build Docker/Linux (renomeia/organiza os HTML como hoje, ou simplifica já que o landing é o Astro).

### 6.2 `.env` de produção (backend)
- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://api.businesscode.com.br`
- `FRONTEND_URL=https://dash.businesscode.com.br` (alimenta `config/cors.php`)
- `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `REDIS_HOST=redis`
- `DB_HOST=mysql`, `DB_DATABASE`, usuário MySQL **dedicado (não-root)** com senha forte
- Mail real (SMTP/SES) em vez de `log`
- Chaves de integração: Infobip, Grok, ElevenLabs, Mercado Pago, Turnstile

### 6.3 Novos arquivos de infra (na cópia)
- `Dockerfile` (multi-stage app), `docker-compose.yml`, `.dockerignore`
- `docker/nginx/` — server blocks: static SPA, static Astro, FastCGI `api`
- `docker/php/` — `php.ini`/pool fpm de produção
- `docker/supervisor/workers.conf` — adaptado do `supervisor-worker.conf` existente
- `docker/entrypoint.sh`

### 6.4 Operacional externo (não-código)
- **DNS:** A records `api.`, `dash.`, raiz + `www` → IP da VPS
- **Webhooks:** atualizar URLs nos painéis Infobip / Meta / Mercado Pago para `api.businesscode.com.br`
- **TLS:** certbot emite certs para `api.`, `dash.`, raiz e `www`

---

## 7. Segurança / saneamento

- `.env` atual tem `APP_DEBUG=true`, `SUPERADMIN_PASSWORD` em texto e `APP_KEY` versionada → **rotacionar** `APP_KEY` e a senha do superadmin; garantir que o `.env` de produção fique **fora do git e da imagem** (injeção via env/secret no Compose).
- Usuário MySQL dedicado (sem `root`).
- `APP_DEBUG=false` obrigatório.
- Headers de segurança no nginx; HSTS após validação de HTTPS.

---

## 8. Fluxo de deploy (fase 1, simples)

```
git pull
docker compose build
docker compose up -d
docker compose run --rm app php artisan migrate --force
```

Backups: cron de `mysqldump` para volume/destino externo.
**Fase 2 (opcional):** CI/CD com GitHub Actions (build de imagem + deploy).

---

## 9. Plano de fases

1. **Fase 0 — Cópia isolada:** criar `new_saas-vps` fora do htdocs, branch `feat/deploy-vps-docker`.
2. **Fase 1 — Infra Docker:** Dockerfile multi-stage, compose, nginx, supervisor, entrypoint; alteração do `useApi.ts`; `.env.production` de exemplo.
3. **Fase 2 — Validação local:** subir o stack no Docker Desktop, rodar migrations, testar SPA + API + 1 webhook simulado.
4. **Fase 3 — Provisionar VPS:** Docker + Compose, DNS, TLS via certbot.
5. **Fase 4 — Cutover:** apontar DNS, validar webhooks reais, desativar XAMPP.

---

## 10. Fora de escopo (YAGNI nesta etapa)
- CI/CD automatizado (fica para fase 2 pós-deploy).
- DB/Redis gerenciados (topologia escolhida é VPS única).
- Orquestração além do Compose (Kubernetes, Swarm).
- Migração do projeto `bottrade` (repositório separado, não relacionado).
