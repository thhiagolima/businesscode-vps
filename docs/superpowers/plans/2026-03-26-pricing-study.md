# BusinessCode - Estudo Completo de Precificacao

**Data:** 2026-03-26
**Versao:** 1.0
**Autor:** Pricing Strategy Team

---

## SUMARIO EXECUTIVO

Este documento apresenta a estrategia de precificacao completa para a plataforma BusinessCode,
considerando custos reais de provedores, analise competitiva do mercado brasileiro, e um sistema
de creditos que equilibra acessibilidade (MEIs e pequenos negocios) com margens saudaveis.

**Decisao-chave:** 1 credito = R$ 0,10 de valor percebido pelo tenant.

---

## 1. ANALISE DE CUSTOS POR CANAL

### 1.1 SMS (via Infobip)

| Item                         | Custo p/ BusinessCode | Notas                                    |
|------------------------------|----------------------:|------------------------------------------|
| SMS domestico (ate 160 chars)| R$ 0,06 - 0,08       | Varia com volume negociado               |
| SMS longo (>160 chars = 2x)  | R$ 0,12 - 0,16       | Cada segmento de 160 chars = 1 SMS       |
| SMS interurbano/celular      | R$ 0,07 - 0,10       | Operadoras variam (Vivo, Claro, TIM, Oi) |
| **Custo medio adotado**      | **R$ 0,07**           | Considerando volume de 50k+ SMS/mes      |

**Referencia atual no seeder:** `credits_per_sms = 1` (ou seja, 1 credito = 1 SMS)

### 1.2 WhatsApp (via Meta Cloud API / Infobip)

| Tipo de conversa (Meta)     | Custo Meta (USD) | Custo Meta (BRL*) | Custo via Infobip (BRL) |
|-----------------------------|-----------------:|-------------------:|------------------------:|
| Marketing (business-init.)  | $0,0625          | R$ 0,34            | R$ 0,50 - 0,70          |
| Utility (business-init.)    | $0,0350          | R$ 0,19            | R$ 0,30 - 0,45          |
| Service (user-init. 24h)    | $0,0300          | R$ 0,16            | R$ 0,25 - 0,35          |
| Authentication              | $0,0315          | R$ 0,17            | R$ 0,28 - 0,40          |

*Cambio referencia: USD 1 = BRL 5,50

**Custo medio adotado (marketing template):** R$ 0,35 (Meta direto) / R$ 0,55 (Infobip)

**No sistema atual:** `credits_per_whatsapp = 3` - O tenant que usa Meta direto tem custo menor;
quem usa Infobip (numero atribuido pelo admin) tem custo maior para a plataforma.

### 1.3 Email (via Infobip)

| Item                        | Custo p/ BusinessCode | Notas                           |
|-----------------------------|----------------------:|---------------------------------|
| Email transacional          | R$ 0,005 - 0,010     | Ate 100k/mes                    |
| Email marketing (bulk)      | R$ 0,003 - 0,008     | Volume acima de 100k            |
| **Custo medio adotado**     | **R$ 0,006**          | Canal mais barato, maior margem |

**Referencia atual no seeder:** `credits_per_email = 2` -- **RECOMENDACAO: reduzir para 1**

### 1.4 Voz/TTS (Infobip + ElevenLabs)

| Componente                  | Custo p/ BusinessCode | Notas                                  |
|-----------------------------|----------------------:|----------------------------------------|
| Infobip Voice (por minuto)  | R$ 0,10 - 0,20       | Ligacao local celular                  |
| ElevenLabs TTS (por char)   | R$ 0,0003/char        | Ja configurado em `cost_per_char`      |
| Audio medio (450 chars)     | R$ 0,135 (ElevenLabs) | + R$ 0,10 (Infobip 30s) = R$ 0,235    |
| **Custo total por chamada** | **R$ 0,25**           | Mensagem de 30 segundos               |

**Referencia atual no seeder:** `credits_per_voice = 5`

**Nota:** ElevenLabs cobra por caractere. O sistema atual tem `sale_per_char = 0.001` (3.3x markup).
Para a campanha de voz, o custo e duplo: geracao de audio + disparo da ligacao.

### 1.5 IA - Geracao de Conteudo (via Grok/xAI)

| Item                        | Custo p/ BusinessCode | Notas                                   |
|-----------------------------|----------------------:|-----------------------------------------|
| Grok-3-mini input           | $0,005/1M tokens      | ~500 tokens por geracao                 |
| Grok-3-mini output          | $0,015/1M tokens      | ~800 tokens por geracao (3 variacoes)   |
| Custo por geracao (USD)     | $0,0145               | 500*0.000005 + 800*0.000015             |
| **Custo por geracao (BRL)** | **R$ 0,08**           | Custo extremamente baixo                |

**Referencia atual no seeder:** `credits_per_ai_generation = 10`
**Referencia no GrokService:** Hardcoded `debitCredits($tenantId, 10, ...)`

### 1.6 IA - Chatbot (via Grok/xAI)

| Item                        | Custo p/ BusinessCode | Notas                                   |
|-----------------------------|----------------------:|-----------------------------------------|
| Input (contexto + historico)| ~600 tokens           | System prompt + 10 msgs recentes        |
| Output (resposta)           | ~150 tokens           | max_tokens = 300 no ChatAiService       |
| Custo por reply (USD)       | $0,0053               | 600*0.000005 + 150*0.000015             |
| **Custo por reply (BRL)**   | **R$ 0,03**           | Ainda mais barato que geracao           |

**Referencia atual no seeder:** `credits_per_ai_chat = 2`

---

## 2. ANALISE COMPETITIVA - MERCADO BRASILEIRO

| Plataforma      | SMS         | WhatsApp       | Email         | Voz            | IA             | Plano Base      |
|-----------------|-------------|----------------|---------------|----------------|----------------|-----------------|
| **Zenvia**      | R$0,08-0,15 | R$0,40-0,80    | R$0,02-0,05   | R$0,15-0,30/min| N/A            | R$399/mes       |
| **Infobip**     | R$0,06-0,12 | R$0,35-0,70    | R$0,01-0,03   | R$0,10-0,25/min| N/A            | Sob consulta    |
| **RD Station**  | N/A         | N/A            | R$0,03-0,08   | N/A            | N/A            | R$50/mes (Lite) |
| **Octadesk**    | N/A         | R$0,50-1,00    | N/A           | N/A            | R$0,10-0,30    | R$149/mes       |
| **ManyChat**    | N/A         | $15/mes+extras | N/A           | N/A            | Incluso        | ~R$82/mes       |
| **Take Blip**   | N/A         | R$0,60-1,20    | N/A           | N/A            | R$0,15-0,40    | R$399/mes       |
| **Leadlovers**  | N/A         | R$0,40-0,90    | ~R$0,02-0,05  | N/A            | N/A            | R$197/mes       |
| **ActiveCamp.** | N/A         | N/A            | R$0,01-0,04   | N/A            | Incluso        | $29/mes (~R$160)|
| **BusinessCode**| **R$0,10**  | **R$0,40**     | **R$0,10**    | **R$0,50**     | **R$1,00**     | **R$97/mes**    |

### Posicionamento Competitivo

O BusinessCode se posiciona como a **unica plataforma multicanal completa no Brasil que inclui
SMS + WhatsApp + Email + Voz com TTS premium + IA generativa** em um unico pacote, com preco
acessivel para MEIs.

**Diferenciais vs concorrentes:**
- Zenvia/Infobip: Mais barato, com IA inclusa e interface amigavel
- RD Station: Mais canais (SMS, Voice, WhatsApp)
- Octadesk/Take Blip: Preco menor, com voz e SMS
- ManyChat: Plataforma brasileira, com suporte local e mais canais

---

## 3. SISTEMA DE CREDITOS - DESIGN FINAL

### 3.1 Premissa Central

```
1 CREDITO = R$ 0,10 de valor percebido
```

Isso permite ao tenant fazer contas de cabeca facilmente:
- "Tenho 1.000 creditos = R$ 100 de valor"
- "Um SMS custa 1 credito = R$ 0,10"
- "Uma mensagem de WhatsApp custa 4 creditos = R$ 0,40"

### 3.2 Tabela de Creditos por Acao

| Canal / Acao          | Creditos | Preco Efetivo | Custo Real | Margem   | Margem % |
|-----------------------|:--------:|--------------:|-----------:|---------:|---------:|
| Email                 |    1     |     R$ 0,10   |  R$ 0,006  | R$ 0,094 |   94,0%  |
| SMS                   |    1     |     R$ 0,10   |  R$ 0,07   | R$ 0,03  |   30,0%  |
| AI Chat (por reply)   |    2     |     R$ 0,20   |  R$ 0,03   | R$ 0,17  |   85,0%  |
| WhatsApp (marketing)  |    4     |     R$ 0,40   |  R$ 0,35   | R$ 0,05  |   12,5%  |
| WhatsApp (via Infobip)|    4     |     R$ 0,40   |  R$ 0,55   | -R$ 0,15 |  -37,5%  |
| Voz (30s)             |    5     |     R$ 0,50   |  R$ 0,25   | R$ 0,25  |   50,0%  |
| AI Geracao (3 var.)   |   10     |     R$ 1,00   |  R$ 0,08   | R$ 0,92  |   92,0%  |

### 3.3 Analise Critica de Margem

**ALERTA: WhatsApp via Infobip e deficitario.** Quando o tenant usa Infobip como provider,
o custo real (R$ 0,55) ultrapassa o preco de venda (R$ 0,40). Solucoes:

1. **Preferencial:** Incentivar tenants a conectar a propria WABA (Meta direto) -- margem positiva
2. **Alternativa:** Aumentar creditos de WhatsApp para 6 (R$ 0,60) quando provider = Infobip
3. **Hibrido:** WhatsApp via Meta = 4 creditos / via Infobip = 6 creditos (dinamico por tenant)

**Recomendacao:** Implementar precificacao dinamica por provider no `ProcessCampaignJob`.

**SMS merece atencao.** Margem de 30% e baixa para o canal de maior volume. Opcoes:
1. Manter 1 credito -- atrai clientes, compensa com volume alto de outros canais
2. Aumentar para 2 creditos (R$ 0,20/SMS) -- margem sobe para 65%, mas preco fica caro vs Zenvia

**Recomendacao:** Manter SMS em 1 credito como loss-leader que atrai clientes para canais de maior margem.

### 3.4 Mix de Margem Ponderada

Cenario tipico de uso de um tenant Pro (5.000 creditos/mes):

| Canal            | % de Uso | Creditos | Receita   | Custo     | Lucro     |
|------------------|:--------:|---------:|----------:|----------:|----------:|
| SMS              |   30%    |    1.500 |  R$ 150   |  R$ 105   |  R$ 45    |
| WhatsApp (Meta)  |   25%    |    1.250 |  R$ 125   |  R$ 109   |  R$ 16    |
| Email            |   20%    |    1.000 |  R$ 100   |  R$ 6     |  R$ 94    |
| AI Geracao       |   10%    |      500 |  R$ 50    |  R$ 4     |  R$ 46    |
| Voz              |   10%    |      500 |  R$ 50    |  R$ 25    |  R$ 25    |
| AI Chat          |    5%    |      250 |  R$ 25    |  R$ 3,75  |  R$ 21,25 |
| **TOTAL**        | **100%** |**5.000** |**R$ 500** |**R$ 252,75**|**R$ 247,25**|

**Margem ponderada do mix:** 49,5%
**Receita do plano Pro:** R$ 297/mes
**Custo real estimado:** ~R$ 150 (se usar todos os creditos)
**Margem do plano:** ~49,5%

**Nota:** Nem todo tenant consome 100% dos creditos. Taxa de utilizacao tipica: 60-75%.
Com 70% de utilizacao, custo real cai para ~R$ 105, e margem sobe para ~65%.

---

## 4. ESTRUTURA DE PLANOS

### 4.1 Plano Gratis (Free)

| Atributo                 | Valor                                          |
|--------------------------|-------------------------------------------------|
| **Preco**                | R$ 0,00/mes                                    |
| **Creditos**             | 50 creditos (one-time, nao renova)              |
| **Max Contatos**         | 50                                              |
| **Max Campanhas/mes**    | 3                                               |
| **Canais**               | Email + SMS apenas                              |
| **IA**                   | 1 geracao de teste                              |
| **WhatsApp**             | Nao incluso                                     |
| **Voz**                  | Nao incluso                                     |
| **Chatbot IA**           | Nao incluso                                     |
| **Suporte**              | Central de ajuda (self-service)                 |
| **Features**             | Dashboard basico, 1 lista de contatos, sem API  |

**O que o tenant consegue testar com 50 creditos:**
- 30 emails (30 creditos) + 20 SMS (20 creditos), OU
- 50 emails, OU
- 5 geracoes de IA (50 creditos)

**Objetivo:** Permitir que o usuario teste a plataforma e sinta valor antes de pagar.
Gatilho de conversao: "Seus creditos acabaram. Assine o Starter para continuar enviando."

### 4.2 Plano Starter

| Atributo                 | Valor                                          |
|--------------------------|-------------------------------------------------|
| **Preco**                | R$ 97,00/mes                                   |
| **Creditos/mes**         | 1.000 (renovam mensalmente)                    |
| **Custo por credito**    | R$ 0,097                                       |
| **Max Contatos**         | 500                                             |
| **Max Campanhas/mes**    | 10                                              |
| **Canais**               | SMS + Email + WhatsApp                          |
| **Voz**                  | Nao incluso                                     |
| **IA Geracao**           | 5 geracoes/mes (50 creditos reservados)         |
| **Chatbot IA**           | Nao incluso                                     |
| **Suporte**              | Email (resposta em ate 48h)                     |
| **Features**             | Dashboard, 3 listas, importacao CSV, relatorios basicos |
| **Credito extra (SMS)**  | R$ 0,12 por SMS extra                           |
| **Credito extra (Email)**| R$ 0,10 por email extra                         |
| **Credito extra (WhatsApp)**| R$ 0,50 por msg extra                        |
| **Credito extra (IA)**   | R$ 1,20 por geracao extra                       |

**Perfil ideal:** MEI ou autonomo que faz 2-3 campanhas/mes para ate 500 contatos.
**Renda tipica do perfil:** R$ 2.000-5.000/mes. O plano representa 2-5% do faturamento.

**O que R$ 97 compra:**
- 1.000 emails, OU
- 1.000 SMS, OU
- 250 msgs WhatsApp, OU
- 200 ligacoes de voz, OU
- 100 geracoes de IA

### 4.3 Plano Pro

| Atributo                 | Valor                                          |
|--------------------------|-------------------------------------------------|
| **Preco**                | R$ 297,00/mes                                  |
| **Creditos/mes**         | 5.000 (renovam mensalmente)                    |
| **Custo por credito**    | R$ 0,0594 (-39% vs Starter)                    |
| **Max Contatos**         | 5.000                                           |
| **Max Campanhas/mes**    | 50                                              |
| **Canais**               | SMS + Email + WhatsApp + Voz                    |
| **IA Geracao**           | Ilimitado (consome creditos)                    |
| **Chatbot IA**           | Incluso (consome creditos)                      |
| **Suporte**              | Prioritario (resposta em ate 24h)               |
| **Features**             | Tudo do Starter + API acesso, webhooks, agendamento avancado, relatorios completos, 10 listas, Chatbot IA com Persona, importacao Excel |
| **Credito extra (SMS)**  | R$ 0,10 por SMS extra                           |
| **Credito extra (Email)**| R$ 0,08 por email extra                         |
| **Credito extra (WhatsApp)**| R$ 0,45 por msg extra                        |
| **Credito extra (Voz)**  | R$ 0,55 por chamada extra                       |
| **Credito extra (IA)**   | R$ 1,00 por geracao extra                       |

**Perfil ideal:** Pequena empresa ou agencia de marketing digital.
**Renda tipica do perfil:** R$ 10.000-50.000/mes.

### 4.4 Plano Business

| Atributo                 | Valor                                          |
|--------------------------|-------------------------------------------------|
| **Preco**                | R$ 697,00/mes                                  |
| **Creditos/mes**         | 20.000 (renovam mensalmente)                   |
| **Custo por credito**    | R$ 0,0349 (-64% vs Starter)                    |
| **Max Contatos**         | Ilimitado                                       |
| **Max Campanhas/mes**    | Ilimitado                                       |
| **Canais**               | Todos (SMS + Email + WhatsApp + Voz)            |
| **IA Geracao**           | Ilimitado (consome creditos)                    |
| **Chatbot IA**           | Incluso + prioridade no atendimento             |
| **Suporte**              | Dedicado (WhatsApp + email, resposta em ate 4h) |
| **Features**             | Tudo do Pro + multi-usuarios, white-label (logo), API ilimitada, webhooks avancados, relatorios exportaveis, listas ilimitadas, integracao Zapier/N8N |
| **Credito extra (SMS)**  | R$ 0,08 por SMS extra                           |
| **Credito extra (Email)**| R$ 0,06 por email extra                         |
| **Credito extra (WhatsApp)**| R$ 0,40 por msg extra                        |
| **Credito extra (Voz)**  | R$ 0,45 por chamada extra                       |
| **Credito extra (IA)**   | R$ 0,80 por geracao extra                       |

**Perfil ideal:** Media empresa com equipe de marketing ou agencia com multiplos clientes.
**Renda tipica do perfil:** R$ 50.000+/mes.

---

## 5. COMPARATIVO DE PLANOS (VISAO LANDING PAGE)

```
+-------------------+------------+------------+------------+------------+
|                   |   GRATIS   |  STARTER   |    PRO     |  BUSINESS  |
+-------------------+------------+------------+------------+------------+
| Preco/mes         | R$ 0       | R$ 97      | R$ 297     | R$ 697     |
+-------------------+------------+------------+------------+------------+
| Creditos/mes      | 50 (unico) | 1.000      | 5.000      | 20.000     |
| Custo/credito     |     -      | R$ 0,097   | R$ 0,059   | R$ 0,035   |
+-------------------+------------+------------+------------+------------+
| Contatos          | 50         | 500        | 5.000      | Ilimitado  |
| Campanhas/mes     | 3          | 10         | 50         | Ilimitado  |
+-------------------+------------+------------+------------+------------+
| SMS               | Sim        | Sim        | Sim        | Sim        |
| Email             | Sim        | Sim        | Sim        | Sim        |
| WhatsApp          |     -      | Sim        | Sim        | Sim        |
| Voz/TTS           |     -      |     -      | Sim        | Sim        |
+-------------------+------------+------------+------------+------------+
| IA Geracao        | 1 teste    | 5/mes      | Ilimitado* | Ilimitado* |
| Chatbot IA        |     -      |     -      | Sim*       | Sim*       |
+-------------------+------------+------------+------------+------------+
| Listas contatos   | 1          | 3          | 10         | Ilimitado  |
| API / Webhooks    |     -      |     -      | Sim        | Sim        |
| Multi-usuarios    |     -      |     -      |     -      | Sim        |
| White-label       |     -      |     -      |     -      | Sim        |
+-------------------+------------+------------+------------+------------+
| Suporte           | FAQ        | Email 48h  | Prior. 24h | Dedicado 4h|
+-------------------+------------+------------+------------+------------+

* Consome creditos do plano
```

---

## 6. ESTRATEGIA FREEMIUM

### 6.1 O Que e Gratis Para Sempre

- Conta na plataforma
- Dashboard basico
- 1 lista de contatos (ate 50)
- Visualizacao de relatorios (limitado a 7 dias)
- Central de ajuda

### 6.2 Creditos de Onboarding (One-Time)

- **50 creditos** no cadastro (suficiente para testar SMS + Email)
- **Bonus de 50 creditos** ao completar o onboarding:
  1. Verificar email (+10)
  2. Criar primeira lista (+10)
  3. Importar 5+ contatos (+10)
  4. Enviar primeira campanha (+10)
  5. Configurar perfil da empresa (+10)

**Total possivel:** 100 creditos gratis = valor de R$ 10

### 6.3 Features Gated (Gatilhos de Conversao)

| Feature                    | Gratis | Starter | Pro    | Business |
|----------------------------|:------:|:-------:|:------:|:--------:|
| Canal WhatsApp             |   -    |   Sim   |  Sim   |   Sim    |
| Canal Voz/TTS              |   -    |    -    |  Sim   |   Sim    |
| Chatbot IA                 |   -    |    -    |  Sim   |   Sim    |
| IA sem limite              |   -    |    -    |  Sim   |   Sim    |
| Agendamento de campanha    |   -    |   Sim   |  Sim   |   Sim    |
| Importacao CSV/Excel       |   -    |   Sim   |  Sim   |   Sim    |
| API REST                   |   -    |    -    |  Sim   |   Sim    |
| Webhooks                   |   -    |    -    |  Sim   |   Sim    |
| Relatorios exportaveis     |   -    |    -    |  Sim   |   Sim    |
| Multi-usuarios             |   -    |    -    |   -    |   Sim    |
| White-label                |   -    |    -    |   -    |   Sim    |

### 6.4 Gatilhos de Upsell

| Situacao                              | De       | Para     | Mensagem                                                    |
|---------------------------------------|----------|----------|-------------------------------------------------------------|
| Creditos acabaram                     | Gratis   | Starter  | "Assine o Starter e receba 1.000 creditos todo mes"         |
| Tentou enviar WhatsApp                | Gratis   | Starter  | "WhatsApp disponivel a partir do Starter"                   |
| Atingiu 500 contatos                  | Starter  | Pro      | "Precisa de mais contatos? O Pro suporta ate 5.000"         |
| Tentou criar chatbot                  | Starter  | Pro      | "Chatbot IA disponivel no Pro. Teste gratis por 7 dias!"    |
| Tentou usar Voz/TTS                   | Starter  | Pro      | "Ligacoes com voz sintetica premium no Pro"                 |
| Atingiu 5.000 contatos               | Pro      | Business | "Contatos ilimitados e suporte dedicado no Business"        |
| Tentou multi-usuario                  | Pro      | Business | "Adicione sua equipe no plano Business"                     |
| Volume alto de creditos extras        | Qualquer | +1       | "Seu gasto extra ja compensa o upgrade. Economize X%"       |

### 6.5 Trial Strategy

- **Sem trial no Gratis:** Os 50-100 creditos de onboarding ja sao o trial
- **Trial de 7 dias do Pro** para quem esta no Starter e tenta usar feature gated
- **Sem trial no Business:** Contato comercial para demonstracao personalizada

---

## 7. ECONOMIA DE ESCALA - PACOTES DE CREDITOS EXTRAS

Para tenants que consomem mais que o plano inclui:

| Pacote          | Creditos | Preco      | Custo/Credito | Desconto vs Avulso |
|-----------------|:--------:|:----------:|:-------------:|:------------------:|
| Recarga P       | 500      | R$ 49,00   | R$ 0,098      | -                  |
| Recarga M       | 2.000    | R$ 179,00  | R$ 0,090      | 8%                 |
| Recarga G       | 5.000    | R$ 399,00  | R$ 0,080      | 18%                |
| Recarga XG      | 15.000   | R$ 999,00  | R$ 0,067      | 32%                |

---

## 8. PROJECAO FINANCEIRA - PRIMEIRO ANO

### Cenario Conservador (100 tenants ativos em 12 meses)

| Metrica                    | Mes 1  | Mes 6   | Mes 12   |
|----------------------------|-------:|--------:|---------:|
| Tenants Gratis             | 50     | 200     | 400      |
| Tenants Starter            | 5      | 25      | 50       |
| Tenants Pro                | 2      | 10      | 30       |
| Tenants Business           | 0      | 3       | 10       |
| **MRR**                    |R$ 1.079|R$ 7.016 |R$19.820  |
| Custo estimado providers   |R$ 200  |R$ 2.500 |R$ 7.500  |
| **Margem bruta**           | 81,5%  | 64,4%   | 62,2%    |

### Breakdown MRR Mes 12:
- 50 Starter x R$ 97 = R$ 4.850
- 30 Pro x R$ 297 = R$ 8.910
- 10 Business x R$ 697 = R$ 6.970
- Creditos extras (~15% dos tenants) = ~R$ 1.090 (estimado)
- **Total: ~R$ 21.820/mes**

---

## 9. RECOMENDACOES DE IMPLEMENTACAO

### 9.1 Alteracoes no Seeder de Planos

O seeder atual (`PlansSeeder.php`) precisa ser atualizado:

**Mudancas necessarias:**
1. Adicionar plano `free` (slug: `free`)
2. Renomear `enterprise` para `business`
3. Ajustar `credits_per_email` de 2 para 1
4. Adicionar `credits_per_whatsapp` na settings (ja existe = 3, mudar para 4)
5. Adicionar campos de features mais ricos (canais disponiveis, limites de IA, etc.)
6. Implementar `overage_rate_whatsapp` nos planos (falta no schema atual)

### 9.2 Alteracoes no Schema de Plans

Adicionar ao model `Plan`:
- `overage_rate_whatsapp` (decimal)
- `channels_available` (json) -- ex: ["sms","email","whatsapp","voice"]
- `ai_generations_limit` (integer, 0 = ilimitado)
- `ai_chatbot_enabled` (boolean)
- `api_access` (boolean)
- `max_users` (integer, 0 = ilimitado)
- `max_contact_lists` (integer, 0 = ilimitado)

### 9.3 Alteracoes no GlobalSettingsSeeder

```
credits_per_sms       = 1  (manter)
credits_per_email     = 1  (era 2, reduzir)
credits_per_whatsapp  = 4  (era 3, aumentar)
credits_per_voice     = 5  (manter)
credits_per_ai_generation = 10 (manter)
credits_per_ai_chat   = 2  (manter)
```

### 9.4 Precificacao Dinamica de WhatsApp

No `ProcessCampaignJob.php`, linha 173, implementar logica:

```
'whatsapp' => (int) $settings->get(
    $this->tenantId,
    'billing',
    'credits_per_whatsapp',
    $settings->getGlobal('billing', 'credits_per_whatsapp', '4')
),
```

Quando o admin atribui numero Infobip a um tenant, setar automaticamente:
`credits_per_whatsapp = 6` para aquele tenant (setting por tenant, nao global).

### 9.5 Implementacao do Plano Gratis

Atualmente o sistema nao tem plano gratis. Necessario:
1. Criar plano `free` no seeder
2. Ao registrar novo tenant, atribuir plano `free` + 50 creditos
3. Creditos do plano free NAO renovam mensalmente
4. Implementar verificacao de canal disponivel antes do disparo (ja existe `TenantChannel::isAvailable` no `CampaignStateMachine`)
5. Implementar gamificacao de onboarding (bonus de creditos por completar etapas)

---

## 10. PRICING CARDS PARA LANDING PAGE

### Card 1: Gratis

```
GRATIS
R$ 0 /mes

50 creditos para comecar
50 contatos
3 campanhas/mes

- SMS e Email
- Dashboard basico
- 1 geracao de IA para testar

[COMECAR GRATIS]
```

### Card 2: Starter

```
STARTER
R$ 97 /mes

1.000 creditos/mes
500 contatos
10 campanhas/mes

Tudo do Gratis, mais:
- WhatsApp Business
- 5 geracoes de IA/mes
- Agendamento de campanhas
- Importacao de contatos (CSV)
- 3 listas de contatos
- Suporte por email

[ASSINAR STARTER]
```

### Card 3: Pro (MAIS POPULAR)

```
PRO                    << MAIS POPULAR >>
R$ 297 /mes

5.000 creditos/mes
5.000 contatos
50 campanhas/mes

Tudo do Starter, mais:
- Voz com IA (ElevenLabs)
- Chatbot IA com Persona
- IA ilimitada (consome creditos)
- API REST + Webhooks
- 10 listas de contatos
- Relatorios completos
- Suporte prioritario 24h

[ASSINAR PRO]
```

### Card 4: Business

```
BUSINESS
R$ 697 /mes

20.000 creditos/mes
Contatos ilimitados
Campanhas ilimitadas

Tudo do Pro, mais:
- Multi-usuarios
- White-label (sua logo)
- API ilimitada
- Listas ilimitadas
- Integracao Zapier/N8N
- Suporte dedicado 4h

[ASSINAR BUSINESS]
ou
[FALAR COM VENDAS]
```

---

## 11. TABELA RESUMO FINAL - CREDITOS x PRECOS x MARGENS

| Acao               | Cred. | Preco Tenant | Custo BC  | Margem %  | Preco Starter | Preco Pro | Preco Business |
|--------------------|:-----:|:------------:|:---------:|:---------:|:-------------:|:---------:|:--------------:|
| Email              |   1   |   R$ 0,10    | R$ 0,006  |   94,0%   |   R$ 0,097    | R$ 0,059  |   R$ 0,035     |
| SMS                |   1   |   R$ 0,10    | R$ 0,07   |   30,0%   |   R$ 0,097    | R$ 0,059  |   R$ 0,035     |
| AI Chat            |   2   |   R$ 0,20    | R$ 0,03   |   85,0%   |   R$ 0,194    | R$ 0,119  |   R$ 0,070     |
| WhatsApp (Meta)    |   4   |   R$ 0,40    | R$ 0,35   |   12,5%   |   R$ 0,388    | R$ 0,238  |   R$ 0,140     |
| Voz/TTS (30s)      |   5   |   R$ 0,50    | R$ 0,25   |   50,0%   |   R$ 0,485    | R$ 0,297  |   R$ 0,175     |
| AI Geracao         |  10   |   R$ 1,00    | R$ 0,08   |   92,0%   |   R$ 0,970    | R$ 0,594  |   R$ 0,349     |

**Nota:** "Preco Starter/Pro/Business" = custo efetivo por acao considerando o custo por credito do plano.

---

## 12. VALORES PARA ATUALIZACAO NO CODIGO

### GlobalSettingsSeeder (billing group):

```
credits_per_sms            = 1
credits_per_email          = 1   (MUDOU de 2 para 1)
credits_per_whatsapp       = 4   (MUDOU de 3 para 4)
credits_per_voice          = 5   (mantido)
credits_per_ai_generation  = 10  (mantido)
credits_per_ai_chat        = 2   (mantido)
```

### PlansSeeder:

```
free:
  price_monthly    = 0.00
  credits_included = 50
  max_contacts     = 50
  max_campaigns    = 3

starter:
  price_monthly    = 97.00    (mantido)
  credits_included = 1000     (mantido)
  max_contacts     = 500      (mantido)
  max_campaigns    = 10       (mantido)

pro:
  price_monthly    = 297.00   (mantido)
  credits_included = 5000     (mantido)
  max_contacts     = 5000     (mantido)
  max_campaigns    = 50       (mantido)

business:
  price_monthly    = 697.00   (mantido)
  credits_included = 20000    (mantido)
  max_contacts     = 0        (ilimitado, mantido)
  max_campaigns    = 0        (ilimitado, mantido)
```

### Overage rates (preco por credito extra, em reais):

```
           | Starter | Pro    | Business |
SMS        | 0.1200  | 0.1000 | 0.0800   |
Email      | 0.1000  | 0.0800 | 0.0600   |
WhatsApp   | 0.5000  | 0.4500 | 0.4000   |
Voice      | 0.6000  | 0.5500 | 0.4500   |
AI         | 1.2000  | 1.0000 | 0.8000   |
```

---

**FIM DO ESTUDO DE PRECIFICACAO**

Proximo passo: Implementar as mudancas no seeder e model conforme secao 9.
