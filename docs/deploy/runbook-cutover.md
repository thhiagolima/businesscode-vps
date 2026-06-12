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
