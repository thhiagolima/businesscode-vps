# BusinessCode® — Documentação Completa do Projeto

> Plataforma SaaS multicanal de campanhas de marketing com IA.
> Stack: Vue 3 + TypeScript (frontend) · Laravel 12 + PHP (backend) · MySQL · Redis

---

## 1. Visão Geral

BusinessCode é uma plataforma multi-tenant para disparo de campanhas de marketing via **SMS**, **Torpedo de Voz**, **Email** e **WhatsApp**, com geração de conteúdo por IA (Grok/xAI), conversão de texto em áudio (ElevenLabs), chatbot com IA conversacional, funis visuais automatizados e inbox unificado.

**Domínio:** `dash.businesscode.com.br`
**Landing page:** `/landing.html` (HTML estático)
**SPA:** `/index.html` (Vue 3 app)
**API:** `api.businesscode.com.br/api/v1/`

---

## 2. Paleta de Cores

### Cores Principais

| Token | Hex | Uso |
|-------|-----|-----|
| `--bc-primary` | `#0064ff` | Botões, links, destaque |
| `--bc-primary-hover` | `#0052d4` | Hover de botões |
| `--bc-primary-subtle` | `rgba(0,100,255,0.08)` | Backgrounds sutis |
| `--bc-dark` | `#080c25` | Sidebar, headers escuros |
| `--bc-dark-lighter` | `#0f1338` | Superfícies dark |
| `--bc-dark-surface` | `#161b45` | Cards em dark mode |

### Cores de Estado

| Token | Hex | Uso |
|-------|-----|-----|
| `--bc-success` | `#10b981` | Sucesso, entregue, ativo |
| `--bc-danger` | `#ef4444` | Erro, falha, removido |
| `--bc-warning` | `#f59e0b` | Alerta, pendente |
| `--bc-info` | `#3b82f6` | Informativo |

### Cores de Texto

| Token | Hex | Uso |
|-------|-----|-----|
| `--bc-text` | `#1a1d2e` | Texto principal |
| `--bc-text-muted` | `#525974` | Texto secundário |
| `--bc-white` | `#ffffff` | Texto em fundo escuro |

### Cores de Superfície

| Token | Hex | Uso |
|-------|-----|-----|
| `--bc-bg` | `#f8f8f8` | Fundo da página |
| `--bc-gray` | `#e4e8ef` | Bordas, divisores |
| `--bc-gray-soft` | `#f0f2f7` | Fundo de cards leves |

### Tipografia

| Uso | Fonte | Peso |
|-----|-------|------|
| Corpo | DM Sans | 400, 500, 600, 700 |
| Código/números | JetBrains Mono | 400, 600 |

### Dimensões

| Token | Valor |
|-------|-------|
| `--bc-radius` | `10px` |
| `--bc-radius-lg` | `14px` |
| Breakpoints | Bootstrap 5 (576/768/992/1200px) |

### Dark Mode

Ativado via `data-bs-theme="dark"` no `<html>`. Tabler CSS aplica variáveis automaticamente. Customizações em `[data-bs-theme="dark"]` nos componentes.

---

## 3. Estrutura de Navegação

### Sidebar Principal

```
┌─────────────────────────┐
│ ▣ BusinessCode          │ ← Logo
├─────────────────────────┤
│ ⊞ Dashboard             │ → /dashboard
│ 👥 Contatos             │ → /contacts
│ 📢 Campanhas            │ → /campaigns
│ 📊 Relatórios     ▾     │
│    ├ Campanhas           │ → /reports/campaigns
│    └ Créditos            │ → /reports/credits
│ 💬 Conversas      (3)   │ → /conversations  (badge unread)
│ 🤖 Chatbot              │ → /chatbot/settings
│ ⌒ Funis                 │ → /funnels
├─ SISTEMA ───────────────┤
│ ⚙ Admin (superadmin)  ▾ │
│    ├ Planos              │ → /admin/plans
│    ├ Tenants             │ → /admin/tenants
│    ├ Infobip             │ → /admin/settings/infobip
│    ├ WhatsApp Infobip    │ → /admin/infobip-whatsapp
│    ├ IA / Grok           │ → /admin/settings/ai
│    ├ ElevenLabs          │ → /admin/settings/elevenlabs
│    ├ WhatsApp            │ → /admin/settings/whatsapp
│    └ Personas IA         │ → /admin/ai-personas
├─────────────────────────┤
│ ⚡ 1.250 créditos       │ ← Saldo (monospace)
│ ☀/🌙 Tema               │ ← Toggle light/dark
│ 👤 Thiago               │ → /profile
│    rhinnoafiliado@gm... │
└─────────────────────────┘
```

---

## 4. Páginas do Sistema

### 4.1 Landing Page (`/landing.html`)

Página estática de vendas. Seções:

1. **Navbar:** Logo, links (docs, preços, blog, contato), CTAs (Entrar, Criar conta)
2. **Hero:** Headline principal, subtítulo, stats ao vivo, CTAs (Começar grátis, Ver demo)
3. **Barra de stats:** Usuários, campanhas enviadas, taxa de entrega
4. **Dor → Solução:** Pain points (vermelho) vs. solução (card com gradiente)
5. **Mecanismo (3 passos):** Configure → Crie → Dispare
6. **Features (bento grid):** Cards de funcionalidades
7. **Versus:** Tabela comparativa com concorrentes
8. **Depoimentos:** 3 cards com estrelas, citação, autor
9. **Preços:** 4 planos (Free, Starter, Pro, Enterprise)
10. **FAQ:** Accordion
11. **CTA final:** Gradiente + botões

---

### 4.2 Autenticação

#### Login (`/login`)

| Elemento | Tipo | Descrição |
|----------|------|-----------|
| Email | input email | Com ícone, required |
| Senha | input password | Com ícone, required |
| Entrar | button submit | Spinner durante loading |
| Esqueceu senha? | link | → /auth/forgot-password |
| Criar conta | link | → /register |

Painel esquerdo: branding (logo, tagline, features). Painel direito: formulário.

#### Registro (`/register`)

| Elemento | Tipo | Descrição |
|----------|------|-----------|
| Nome | input text | Required, autofocus |
| Email | input email | Required |
| Senha | input password | Required, hint: "Mínimo 8 chars, maiúsculas e números" |
| Confirmar senha | input password | Required, deve conferir |
| Criar conta | button submit | Spinner durante loading |

#### Esqueci a Senha (`/auth/forgot-password`)

| Elemento | Tipo | Descrição |
|----------|------|-----------|
| Email | input email | Required |
| Enviar link | button | Rate limited (3/min) |

#### Redefinir Senha (`/auth/reset-password`)

Requer `?token=...&email=...` na URL. Redireciona para forgot se ausentes.

| Elemento | Tipo | Descrição |
|----------|------|-----------|
| Email | input email | Readonly, pré-preenchido |
| Nova senha | input password | Min 8, mixed case + numbers |
| Confirmar | input password | Must match |
| Redefinir | button submit | |

---

### 4.3 Dashboard (`/dashboard`)

**KPI Cards (5):**

| Card | Valor | Ícone | Badge/Info |
|------|-------|-------|------------|
| Total de campanhas | Número | `ti-speakerphone` | "X ativas" badge |
| Enviados (período) | Número | `ti-send` | Barra de progresso da taxa |
| Taxa de entrega | Porcentagem | — | Verde ≥80%, laranja ≥50%, vermelho <50% |
| Falhas | Número | `ti-alert-triangle` | Verde se 0, vermelho se >0 |
| Créditos | Número ou ∞ | `ti-bolt` | Colorido por faixa de saldo |

**Gráficos:**
- Área chart "Envios por dia" (últimos 14 dias)
- Donut chart "Por canal" (SMS, Voz, Email, WhatsApp)

**Tabelas:**
- "Últimas campanhas" — nome, canal, status, enviados, taxa
- "Créditos recentes" — descrição, data, valor (+/-)

**Filtro de período:** 7, 14, 30, 90 dias

---

### 4.4 Contatos (`/contacts`)

**Layout:** Sidebar com listas + Área principal com tabela

**Sidebar de Listas:**
- Lista de todas as ContactLists com contagem
- Criar nova lista inline
- Renomear / excluir lista

**Barra de Filtros:**

| Filtro | Tipo | Opções |
|--------|------|--------|
| Busca | text | Nome, telefone, email |
| Status | select | Todos, Ativo, Bloqueado, Inválido |

**Tabela:**

| Coluna | Tipo |
|--------|------|
| ☐ Checkbox | Seleção múltipla |
| Nome | String |
| Telefone | Monospace |
| Email | String |
| Status | Badge (ativo=verde, bloqueado=laranja, inválido=vermelho) |

**Ações em lote (quando selecionados):**
- Mover para lista
- Alterar status
- Excluir (com ConfirmModal)

**Importação CSV:** Modal com upload, mapeamento de colunas, preview

---

### 4.5 Campanhas (`/campaigns`)

**Filtros:**

| Filtro | Tipo | Opções |
|--------|------|--------|
| Busca | text | Nome da campanha |
| Canal | select | Todos, SMS, Voz, Email, WhatsApp |
| Status | select | Todos, Rascunho, Agendada, Processando, Rodando, Completa, Falha |

**Tabela:**

| Coluna | Tipo |
|--------|------|
| Nome | String + link |
| Canal | Badge (SMS=azul, Voz=laranja, Email=roxo, WhatsApp=verde) |
| Status | StatusBadge |
| Contatos | Número |
| Enviados / Falhas | Números monospace |
| Taxa | Porcentagem colorida |
| Agendamento | Data ou "—" |
| Ações | Editar, Duplicar, Excluir |

**Regras:**
- Apenas campanhas `draft` podem ser editadas/excluídas
- Campanhas completas/falhadas mostram "Ver relatório"

---

## 5. Fluxo Completo de Campanha

### 5.0 Seleção de Canal (`/campaigns/new`)

Grid de 4 cards. Cada canal tem 3 estados possíveis:

| Estado | Visual | Ação |
|--------|--------|------|
| **Habilitado** | Card clicável, seta hover | Cria campanha |
| **Pendente Setup** | Opacidade 0.5, ícone warning | "Configurar" → settings |
| **Bloqueado (upgrade)** | Borda tracejada, cadeado | "Upgrade" → /settings/plans |

Canais: SMS, Torpedo de Voz, WhatsApp, Email

Ao clicar: `POST /campaigns` com `{ name: '', type: 'sms' }` → redireciona para `/campaigns/create?id=N&channel=sms`

---

### 5.1 Step 1: Nome da Campanha

| Campo | Tipo | Validação | Obrigatório |
|-------|------|-----------|-------------|
| Nome da campanha | input text | min 1 char | ✅ |

**Visual:** Ícone do canal + label + descrição. Input centralizado (max 480px).

**Autosave:** `PUT /campaigns/{id}` com `{ name, type }`

**Validação `canAdvance`:** `!!form.name && !isLoading`

---

### 5.2 Step 2: Conteúdo

O conteúdo varia por canal:

---

#### 5.2.1 SMS — Modo IA

**Layout 2 colunas:** Briefing (esq 40%) | Variações (dir 60%)

**Coluna Esquerda — Briefing (card sticky):**

| Campo | Tipo | Validação | Obrigatório |
|-------|------|-----------|-------------|
| Produto/Serviço | input text | — | ✅ |
| Público-alvo | input text | — | ✅ |
| Principal benefício | input text | — | ✅ |
| Chamada para ação | input text | — | ✅ |
| Tom | select | professional, casual, urgent, inspiring, funny, direct | — |
| Versões | select | 1-5 | — |
| Link | input url | URL válida | — |
| Evitar palavras | input text | Separadas por vírgula | — |
| Limitar a 160 chars | checkbox | Apenas SMS | — |

**Botão:** "Gerar variações" → `POST /ai/generate`

**Coluna Direita — Variações:**

- **Tab "Geradas agora":** Cards clicáveis com número, texto, contagem de chars (SMS), botão copiar
- **Tab "Sessões anteriores":** Sessões passadas com variações e botão "Usar"
- **Badge:** Créditos consumidos

**Validação `canAdvance`:** `!!form.content` (conteúdo selecionado)

---

#### 5.2.2 SMS — Modo Manual

| Campo | Tipo | Validação |
|-------|------|-----------|
| Mensagem | textarea (5 rows) | — |

**Indicadores:** Contagem de caracteres, indicador de SMS split (>160 chars)
**Botões:** "Analisar com IA" (resultado exibido em card), "Salvar como modelo"

---

#### 5.2.3 Torpedo de Voz — Modo IA

**Layout 2 colunas:** Briefing + Voz + Áudio (esq 40%) | Variações + Áudios (dir 60%)

**Coluna Esquerda (card sticky):**

**Seção 1 — Briefing:**

| Campo | Tipo | Validação | Obrigatório |
|-------|------|-----------|-------------|
| Produto/Serviço | input text | — | ✅ |
| Público-alvo | input text | — | ✅ |
| Principal benefício | input text | — | ✅ |
| Chamada para ação | input text | — | ✅ |
| Tom | select | professional, casual, urgent, inspiring, fun, direct | — |
| Versões | select | 1-5 | — |

**Botão:** "Gerar roteiros" → `POST /ai/generate` com `channel: 'voice'`
**Hint:** "Ideal: 30-60 segundos (75-150 palavras)"

**Seção 2 — Escolher Voz** (visível após script selecionado):

Cards de voz em lista scrollável (max 240px):

| Elemento | Descrição |
|----------|-----------|
| Botão ▶ Play | Preview da voz (arquivo ElevenLabs) |
| Nome da voz | Ex: "Rachel", "Antoni" |
| Idioma · Gênero | Ex: "Portuguese · Female" |
| ✓ Check | Indica voz selecionada |

Vozes carregadas de `GET /voices` (ElevenLabs sincronizadas).

**Seção 3 — Gerar Áudio** (visível após voz selecionada):

| Métrica | Descrição |
|---------|-----------|
| Caracteres | Contagem do script |
| Duração | Estimativa (palavras ÷ 150 × 60) |
| Custo | Estimativa em créditos |

**Botão:** "Gerar áudio" → `POST /campaigns/{id}/audio`
**Player:** Reproduz o áudio gerado
**Botão:** "Confirmar este áudio" → emite `audio_url`

**Coluna Direita:**

- **Tab "Geradas agora":** Variações de roteiro com word count, duração estimada, copiar
- **Tab "Sessões anteriores":** Histórico com "Usar"
- **Seção "Áudios narrados":** Player de cada áudio gerado + duração + créditos + "Usar"

**Validação `canAdvance`:** `!!form.audio_url` (áudio confirmado)

---

#### 5.2.4 Torpedo de Voz — Modo Manual

| Campo | Tipo | Validação |
|-------|------|-----------|
| Roteiro | textarea (8 rows) | — |

**Indicadores:** Word count, duração estimada, "Analisar com IA"
Mesma seção de voz e áudio da coluna esquerda.

---

#### 5.2.5 Email — Modo IA

Mesmo layout de SMS IA, com campos adicionais:

| Campo | Tipo | Obrigatório |
|-------|------|-------------|
| Assunto do email | input text | ✅ |

**Validação `canAdvance`:** `!!form.subject && !!form.content`

---

#### 5.2.6 Email — Modo Manual

| Campo | Tipo |
|-------|------|
| Assunto do email | input text |
| Conteúdo HTML | textarea (8 rows) |

---

#### 5.2.7 WhatsApp

| Campo | Tipo | Obrigatório |
|-------|------|-------------|
| Template | select dropdown | ✅ |

**Preview do template:** Header, Body (com variáveis destacadas), Footer, Buttons

**Mapeamento de variáveis** (se template tem `{{1}}`, `{{2}}`):

| Variável | Mapeamento disponível |
|----------|----------------------|
| {{1}} | Nome do contato, Telefone, Email, Texto fixo |
| {{2}} | (mesmas opções) |

**Validação `canAdvance`:** `!!template_name && todasVariáveisMapeadas`

---

### 5.3 Step 3: Contatos

**3 modos de seleção:**

#### Modo 1: Lista Existente

| Campo | Tipo | Descrição |
|-------|------|-----------|
| Lista | select dropdown | Listas do tenant com contagem |

Preview: nome da lista, X contatos, primeiros telefones mascarados.

#### Modo 2: Upload CSV

| Campo | Tipo | Descrição |
|-------|------|-----------|
| Arquivo | file input / drag-drop | .csv ou .txt, max 10MB |

Preview: nome do arquivo, contagem, primeiros 10 números.

#### Modo 3: Digitar Números

| Campo | Tipo | Descrição |
|-------|------|-----------|
| Números | textarea (8 rows) | Um por linha, formato E.164 (+XXXXXXXXXXX) |

Contador: "X números válidos detectados" + "Y inválidos" (vermelho)

**Card de custo estimado:** "Custo estimado: X créditos" com badge de destinatários

**Validação `canAdvance`:** `lista selecionada com >0 contatos` OU `arquivo com >0 números` OU `>0 números digitados válidos`

---

### 5.4 Step 4: Agendamento

**2 opções em cards:**

| Opção | Descrição | Campos adicionais |
|-------|-----------|-------------------|
| **Enviar agora** | Disparo imediato | Nenhum |
| **Agendar** | Data futura | `datetime-local` (min: agora + 5min) |

**Validação `canAdvance`:** `modo 'now'` OU `(modo 'schedule' && data válida no futuro)`

---

### 5.5 Step 5: Revisão

**Coluna esquerda (7/12) — Checklist:**

| Item | Ícone | Conteúdo |
|------|-------|----------|
| Campanha | ✓ ou ⚠ | Nome + badge de canal |
| Conteúdo | ✓ ou ⚠ | Preview ou "Nenhum conteúdo" |
| Destinatários | ✓ ou ⚠ | Contagem + fonte (Lista/CSV/Manual) + preview números |
| Envio | ✓ | Data/hora ou "Imediato" |

**Card de custo:**

| Coluna | Valor |
|--------|-------|
| Contatos | Número total |
| Crédito/envio | 1 (SMS), 5 (Voz), 2 (Email), 3 (WhatsApp) |
| Total | Contatos × custo unitário |
| Saldo | Créditos atuais (verde se suficiente, vermelho se insuficiente) |

**Alerta se créditos insuficientes:** "Faltam X créditos"

**Coluna direita (5/12) — PhonePreview:** Mock de celular com preview da mensagem

**Botões finais:**
- "Enviar agora" (vermelho) → Modal de confirmação → Success screen
- "Agendar envio" (azul) → Modal de confirmação → Success screen

---

### 5.6 Tela de Sucesso

| Elemento | Descrição |
|----------|-----------|
| Ícone | Check verde grande |
| Título | "Campanha enviada!" ou "Campanha agendada!" |
| Subtítulo | Nome da campanha |
| Botão primário | "Ver campanha" → /campaigns/{id} |
| Botão secundário | "Criar outra" → /campaigns/new |

---

## 6. Detalhe da Campanha (`/campaigns/:id`)

**Header:**
- Nome + badge de canal + badge de status
- Botões de ação (baseados no status):
  - Draft: Editar, Enviar agora, Agendar, Excluir
  - Scheduled: Cancelar, Enviar agora
  - Running: (sem ações)
  - Completed: Ver relatório, Duplicar
  - Failed: Redefinir para rascunho, Duplicar

**Tabs:**
- **Resumo:** KPIs (enviados, entregues, falhas, taxa), conteúdo
- **Envios:** Tabela de dispatches (telefone, status, data envio, data entrega, erro)

---

## 7. Relatórios

### 7.1 Relatório de Campanhas (`/reports/campaigns`)

**Filtros:** Período (de/até), canal, status, busca

**Tabela:**

| Coluna | Tipo |
|--------|------|
| Nome | String |
| Canal | StatusBadge |
| Status | StatusBadge |
| Contatos | Número |
| Enviados | Monospace |
| Falhas | Monospace |
| Taxa | Porcentagem (verde ≥90%, laranja ≥70%, vermelho <70%) |
| Duração | Tempo |
| Ações | Ver detalhe, Exportar CSV |

### 7.2 Extrato de Créditos (`/reports/credits`)

**Resumo:** Saldo atual, gastos no período, recargas no período

**Tabela:**

| Coluna | Tipo |
|--------|------|
| Data | Timestamp |
| Descrição | String |
| Tipo | Badge (débito/crédito/reserva) |
| Valor | Número (+verde / -vermelho) |
| Saldo após | Monospace |

---

## 8. Conversas (`/conversations`)

**Layout 3 colunas:**

| Coluna | Largura | Conteúdo |
|--------|---------|----------|
| Lista | 320px | Conversas com avatar, nome, preview, timestamp, badge unread |
| Chat | flex | Thread de mensagens + input de texto |
| Info | 280px | Dados do contato, status, opções |

**Funcionalidades:**
- Polling real-time (3s aberto, 15s fechado)
- Notificação sonora em novas mensagens
- Status: bot / human / closed
- Transfer para humano
- Marcar como resolvido

---

## 9. Funis (`/funnels`)

**Listagem:** Tabela com nome, status, contatos ativos, triggers, ações (editar, exportar, ativar/pausar, excluir)

**Editor visual** (`/funnels/:id/edit`):
- Canvas Vue Flow (drag & drop)
- Tipos de nós: Start, Message, Condition, Wait, Tag, Transfer Human, AI Reply
- Painel de propriedades lateral
- Mobile: mensagem "Use em desktop"
- Import/Export JSON

---

## 10. Chatbot (`/chatbot/settings`)

**Formulário de Persona:**

| Campo | Tipo | Max | Obrigatório |
|-------|------|-----|-------------|
| Nome do bot | input | 50 chars | ✅ |
| Tom | select | formal/casual/friendly | ✅ |
| Nome da empresa | input | — | ✅ |
| Produtos/Serviços | textarea | 2000 chars | ✅ |
| Regras de negócio | textarea | 2000 chars | ✅ |
| Instruções especiais | textarea | — | — |
| Horário de funcionamento | input | — | — |

**Status:** Pendente → Aprovado / Rejeitado (pelo superadmin)

**Configuração:**
- Toggle: Chatbot ativado (requer persona aprovada)
- Modo: Sugestão (IA sugere, operador confirma) / Autônomo (IA responde)

---

## 11. Perfil (`/profile`)

| Seção | Campos |
|-------|--------|
| Dados pessoais | Nome (required), Email (required, validação formato) |
| Alterar senha | Senha atual, Nova senha (min 8, maiúscula+número), Confirmação |

---

## 12. Planos (`/settings/plans`)

**Banner do plano atual:** Nome, créditos, preço

**Grid de planos (4 colunas):**

| Plano | Preço | Créditos | Canais |
|-------|-------|----------|--------|
| Free | Grátis | 50 | SMS, Voz |
| Starter | R$ X/mês | Y | SMS, Voz, Email |
| Pro | R$ X/mês | Y | Todos |
| Enterprise | Sob consulta | Ilimitado | Todos + suporte |

**Tabela de custos por crédito:**
- 1 crédito = R$ 0,10
- SMS: 1 cr / Voz: 5 cr / Email: 2 cr / WhatsApp: 3 cr / IA: 10 cr

---

## 13. Painel Admin (Superadmin)

### 13.1 Planos (`/admin/plans`)

CRUD completo: nome, slug, preço, créditos, max contatos, max campanhas, features JSON, taxas de overage

### 13.2 Tenants (`/admin/tenants`)

**Tabela:** Nome, slug, plano, saldo, status, canais (badges), data criação
**Ações:** Gerenciar canais, adicionar créditos, editar, excluir (bloqueado se campanhas ativas)

### 13.3 Settings Infobip (`/admin/settings/infobip`)

| Campo | Tipo |
|-------|------|
| Base URL | input url (required) |
| API Key | input password (masked) |
| Sender SMS | input text |
| Sender Voice | input text |
| Sender Email | input text |
| Webhook Secret | input text |

Botões: Testar conexão, Salvar

### 13.4 Settings IA (`/admin/settings/ai`)

| Campo | Tipo |
|-------|------|
| API Key (Grok/xAI) | input password |
| Modelo | select |

### 13.5 Settings ElevenLabs (`/admin/settings/elevenlabs`)

| Campo | Tipo |
|-------|------|
| API Key | input password |
| Modelo TTS | select |
| Custo/char | input number |
| Preço venda/char | input number |
| Créditos/char | input number |

Botões: Sincronizar vozes, Testar

### 13.6 Settings WhatsApp (`/admin/settings/whatsapp`)

| Campo | Tipo |
|-------|------|
| Phone Number ID | input |
| Access Token | input password |
| App Secret | input |
| Verify Token | input |

Botões: Sincronizar templates, Testar

### 13.7 Personas IA (`/admin/ai-personas`)

Tabela de personas pendentes/aprovadas/rejeitadas. Ações: Aprovar, Rejeitar (com motivo).

---

## 14. Integrações Externas

| Serviço | Uso | Endpoints |
|---------|-----|-----------|
| **Infobip** | SMS, Voz, Email, delivery reports | API REST |
| **ElevenLabs** | Text-to-Speech (vozes) | API REST |
| **Grok/xAI** | Geração de conteúdo IA | API REST |
| **Meta Cloud API** | WhatsApp (envio + webhook) | Graph API |
| **Infobip WhatsApp** | WhatsApp alternativo | API REST |

---

## 15. Sistema de Créditos

| Canal | Custo por envio |
|-------|----------------|
| SMS | 1 crédito |
| Torpedo de Voz | 5 créditos |
| Email | 2 créditos |
| WhatsApp | 3 créditos |
| Geração IA | 10 créditos |
| Áudio TTS | ~0.5 crédito/caractere |

**Fluxo:** Verificação → Reserva atômica (lockForUpdate) → Envio → Débito real → Liberação de excedente

---

## 16. Tecnologias

### Frontend
- Vue 3.4 (Composition API + `<script setup>`)
- TypeScript 5.4
- Vue Router 4, Pinia 2 (com persistência)
- Vite 5 (bundler)
- Tabler CSS (Bootstrap 5)
- ApexCharts, Vue Flow
- Axios, DOMPurify

### Backend
- PHP 8.2+, Laravel 12
- Laravel Sanctum (token auth)
- MySQL 5.7+
- Redis (queue em produção)
- Laravel Horizon (monitoramento de filas)
- Job Batching (Bus::batch para campanhas)

### Infraestrutura
- Apache (XAMPP em desenvolvimento)
- SSL via Let's Encrypt
- Queue workers via supervisor
- Logs: canal campaign, infobip, whatsapp
