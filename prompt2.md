# Prompt — Fase 4 (complemento): Step 2 do Wizard — Conteúdo da Campanha

Este prompt substitui e detalha o Step2Content.vue definido no prompt da Fase 4.
Cole após aprovação do restante da Fase 4, ou inclua como parte dela.

---

## Texto do Prompt

```
Leia .trae/rules.md, .trae/skills/tabler-ui.md e .trae/skills/use-api.md.

Este prompt detalha exclusivamente o Step 2 do wizard de campanha:
a tela de conteúdo, com modo IA e modo manual.
Não altere nenhum outro componente ou rota existente.

---

## STEP 2 — Conteúdo da Campanha

### Visão Geral do Componente

components/campaigns/steps/Step2Content.vue

O step tem dois modos de preenchimento, alternáveis por toggle:
  ● Gerar com IA    — briefing → Grok → variações
  ● Escrever manual — textarea direto + assistente de melhoria

O canal (SMS/Voz/Email) é lido de wizard.campaign.type.
O canal é read-only neste step — apenas exibido como badge informativo.

Estado local do componente:
```typescript
const mode          = ref<'ai' | 'manual'>('ai')
const isGenerating  = ref(false)
const isAnalyzing   = ref(false) // modo manual: assistente
const selectedIndex = ref<number | null>(null)
```

---

### Layout geral do Step 2

```html
<div>
  <!-- Badge do canal (read-only) -->
  <div class="mb-3 d-flex align-items-center gap-2">
    <span class="badge bg-primary fs-6 px-3 py-2">
      <i :class="`ti ${channelIcon} me-2`"></i>{{ channelLabel }}
    </span>
    <span class="text-muted small" v-if="wizard.campaign?.strategy_locked">
      <i class="ti ti-lock me-1"></i>Canal bloqueado após geração
    </span>
  </div>

  <!-- Toggle de modo -->
  <div class="mb-4">
    <div class="form-selectgroup">
      <label class="form-selectgroup-item">
        <input type="radio" class="form-selectgroup-input"
          value="ai" v-model="mode">
        <span class="form-selectgroup-label">
          <i class="ti ti-sparkles me-2 text-primary"></i>
          Gerar com IA
        </span>
      </label>
      <label class="form-selectgroup-item">
        <input type="radio" class="form-selectgroup-input"
          value="manual" v-model="mode">
        <span class="form-selectgroup-label">
          <i class="ti ti-pencil me-2"></i>
          Escrever manualmente
        </span>
      </label>
    </div>
  </div>

  <!-- Conteúdo condicional por modo -->
  <AiMode    v-if="mode === 'ai'"     @select="onVariationSelect" />
  <ManualMode v-if="mode === 'manual'" @select="onManualSave" />

  <!-- Histórico de sessões -->
  <SessionHistory
    :campaign-id="wizard.campaign?.id"
    @select="onVariationSelect" />
</div>
```

---

### MODO IA — AiMode.vue (subcomponente)

#### Formulário de briefing

Campos obrigatórios:
- **Produto/Serviço** (input text)
  placeholder: "Ex: Academia FitLife, App de delivery, Curso de Excel"

- **Público-alvo** (input text)
  placeholder: "Ex: Mulheres 25-40 anos interessadas em emagrecimento"

- **Principal benefício** (input text)
  placeholder: "Ex: Perca 5kg em 30 dias com acompanhamento personalizado"

- **Chamada para ação** (input text)
  placeholder: "Ex: Acesse o link, Ligue agora, Venha até a loja"

- **Tom da mensagem** (select)
  Opções: Profissional | Casual e amigável | Urgente | Inspirador | Divertido | Direto ao ponto

- **Quantidade de versões** (select — máx 5)
  Opções: 1 | 2 | 3 | 4 | 5
  default: 3

Campos opcionais (collapsible — "Opções avançadas ▼"):
- **Link/URL** (input url, opcional)
  hint: ⚠ "O link será incluído na mensagem — verifique se é um encurtador para não ocupar caracteres demais."
  aviso condicional se link e canal=SMS e content já tem 140+ chars:
    badge warning: "Link pode ultrapassar 160 chars"

- **Palavras a evitar** (input text)
  placeholder: "Ex: grátis, promoção, desconto"
  hint: "Separadas por vírgula. Útil para evitar filtros anti-spam."

- **Restrição de caracteres** (only SMS)
  Checkbox: "Limitar a 160 caracteres (1 SMS)"
  default: true se canal=SMS

Botão principal:
```html
<button class="btn btn-primary w-100 mt-3"
  :disabled="!briefingValid || isGenerating"
  @click="generate">
  <span v-if="isGenerating"
    class="spinner-border spinner-border-sm me-2"></span>
  <i v-else class="ti ti-sparkles me-2"></i>
  {{ isGenerating ? 'Gerando variações...' : 'Gerar variações' }}
</button>
```

briefingValid: produto + publicoAlvo + beneficio + cta todos preenchidos.

#### Chamada à API de geração

POST /api/v1/ai/generate
Body:
```json
{
  "campaign_id": 42,
  "channel": "sms",
  "briefing": {
    "product":    "Academia FitLife",
    "audience":   "Mulheres 25-40",
    "benefit":    "Perca 5kg em 30 dias",
    "cta":        "Acesse o link",
    "tone":       "casual",
    "link":       "https://fitlife.co/promo",
    "avoid":      ["grátis", "promoção"],
    "max_chars":  160
  },
  "variations": 3
}
```

Resposta esperada:
```json
{
  "session_id": 7,
  "generation_id": "uuid",
  "variations": [
    { "id": "uuid-1", "text": "...", "chars": 142, "tone": "casual" },
    { "id": "uuid-2", "text": "...", "chars": 158, "tone": "casual" },
    { "id": "uuid-3", "text": "...", "chars": 135, "tone": "casual" }
  ],
  "credits_used": 10
}
```

#### Exibição das variações geradas

Após geração bem-sucedida, exibir abaixo do formulário:

```html
<div class="mt-4" v-if="variations.length">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <h4 class="mb-0">Variações geradas</h4>
    <span class="badge bg-primary-lt text-primary">
      <i class="ti ti-bolt me-1"></i>{{ creditsUsed }} créditos usados
    </span>
  </div>

  <div class="row g-3">
    <div class="col-12"
      v-for="(v, i) in variations" :key="v.id">
      <div class="card cursor-pointer"
        :class="{
          'border-primary border-2': selectedIndex === i,
          'border': selectedIndex !== i
        }"
        @click="selectVariation(i)">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <span class="badge bg-secondary">Versão {{ i + 1 }}</span>
            <div class="d-flex gap-2">
              <!-- Contador de chars (só SMS) -->
              <span v-if="channel === 'sms'"
                class="badge"
                :class="v.chars > 160 ? 'bg-danger' : 'bg-success'">
                {{ v.chars }}/160
              </span>
              <!-- Botão copiar -->
              <button class="btn btn-ghost-secondary btn-sm btn-icon"
                @click.stop="copyText(v.text)"
                data-bs-toggle="tooltip" title="Copiar texto">
                <i class="ti ti-copy"></i>
              </button>
              <!-- Botão salvar como modelo -->
              <button class="btn btn-ghost-secondary btn-sm btn-icon"
                @click.stop="saveAsModel(v)"
                data-bs-toggle="tooltip" title="Salvar como modelo">
                <i class="ti ti-bookmark"></i>
              </button>
            </div>
          </div>
          <p class="mb-0" style="white-space: pre-wrap; font-size: 0.9rem">
            {{ v.text }}
          </p>
        </div>
        <!-- Footer da variação selecionada -->
        <div class="card-footer bg-primary-lt" v-if="selectedIndex === i">
          <i class="ti ti-circle-check text-primary me-1"></i>
          <span class="text-primary small fw-medium">Variação selecionada para a campanha</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Botão gerar mais -->
  <button class="btn btn-ghost-secondary w-100 mt-3"
    :disabled="isGenerating"
    @click="generate">
    <i class="ti ti-refresh me-2"></i>
    Gerar novas variações com o mesmo briefing
  </button>
</div>
```

selectVariation(i):
  selectedIndex = i
  wizard.saveField('content', variations[i].text)
  wizard.saveField('ai_generation_session_id', sessionId)
  Se strategy_locked === false: wizard.saveField('strategy_locked', true)
  emit('select', variations[i])

---

### MODO MANUAL — ManualMode.vue (subcomponente)

#### Textarea principal

```html
<div class="mb-3">
  <label class="form-label required">
    Mensagem
    <span class="form-help ms-1" data-bs-toggle="tooltip"
      title="Escreva diretamente a mensagem que será enviada aos contatos">?</span>
  </label>
  <textarea
    class="form-control"
    :rows="channel === 'email' ? 8 : 5"
    v-model="manualContent"
    :placeholder="placeholder"
    @input="wizard.saveField('content', manualContent)"
    :maxlength="channel === 'sms' ? undefined : undefined">
  </textarea>

  <!-- Contador de chars (SMS) -->
  <div v-if="channel === 'sms'"
    class="d-flex justify-content-between mt-1">
    <small class="form-hint">
      <span v-if="charCount > 160" class="text-warning">
        <i class="ti ti-alert-triangle me-1"></i>
        Mensagem dividida em {{ Math.ceil(charCount / 160) }} SMSs
      </span>
    </small>
    <small :class="charCount > 160 ? 'text-warning' : 'text-muted'">
      {{ charCount }} caracteres
    </small>
  </div>
</div>

<!-- Email: campo de assunto adicional -->
<div class="mb-3" v-if="channel === 'email'">
  <label class="form-label required">Assunto do email</label>
  <input type="text" class="form-control"
    v-model="subject"
    @input="wizard.saveField('subject', subject)"
    placeholder="Ex: Oferta exclusiva para você!">
</div>

<!-- Voice: campo de URL do áudio -->
<div class="mb-3" v-if="channel === 'voice'">
  <label class="form-label">URL do arquivo de áudio</label>
  <input type="url" class="form-control"
    v-model="audioUrl"
    @input="wizard.saveField('audio_url', audioUrl)"
    placeholder="https://...">
  <div class="form-hint">
    Cole a URL de um arquivo .mp3 ou .wav já hospedado.
    Ou use o gerador de voz com IA na aba acima.
  </div>
  <audio v-if="audioUrl" controls :src="audioUrl"
    class="w-100 mt-2"></audio>
</div>
```

#### Botões de ação do modo manual

```html
<div class="d-flex gap-2 flex-wrap mt-3">
  <!-- Analisar com IA -->
  <button class="btn btn-outline-primary"
    :disabled="!manualContent || isAnalyzing"
    @click="openAnalysisModal">
    <span v-if="isAnalyzing"
      class="spinner-border spinner-border-sm me-2"></span>
    <i v-else class="ti ti-brain me-2"></i>
    {{ isAnalyzing ? 'Analisando...' : 'Analisar com IA' }}
  </button>

  <!-- Salvar como modelo -->
  <button class="btn btn-outline-secondary"
    :disabled="!manualContent"
    @click="saveAsModel({ text: manualContent })">
    <i class="ti ti-bookmark me-2"></i>
    Salvar como modelo
  </button>
</div>
```

#### AnalysisModal.vue — Assistente de Melhoria

Modal tamanho lg, acionado pelo botão "Analisar com IA".

Ao abrir, automaticamente chama:
POST /api/v1/ai/analyze
Body:
```json
{
  "campaign_id": 42,
  "channel": "sms",
  "content": "texto atual da mensagem"
}
```

Resposta:
```json
{
  "analysis": {
    "score": 72,
    "strengths": ["CTA claro", "Texto conciso"],
    "improvements": ["Falta senso de urgência", "Público não especificado"],
    "spam_risk": "low"
  },
  "improved_versions": [
    { "id": "uuid-1", "text": "...", "chars": 148, "change": "Adicionou urgência" },
    { "id": "uuid-2", "text": "...", "chars": 155, "change": "Personalizou público" },
    { "id": "uuid-3", "text": "...", "chars": 142, "change": "Versão mais direta" }
  ],
  "credits_used": 10
}
```

Layout do modal:
```html
<div class="modal-body">
  <!-- Loading inicial -->
  <div v-if="isAnalyzing" class="text-center py-5">
    <div class="spinner-border text-primary mb-3"></div>
    <p class="text-muted">Analisando sua mensagem...</p>
  </div>

  <div v-else>
    <!-- Pontuação geral -->
    <div class="row g-3 mb-4">
      <div class="col-auto">
        <div class="card text-center px-4 py-3">
          <div class="h1 mb-0"
            :style="`color: ${scoreColor}`">
            {{ analysis.score }}
          </div>
          <div class="text-muted small">pontuação</div>
        </div>
      </div>
      <div class="col">
        <!-- Pontos fortes -->
        <div class="mb-2" v-if="analysis.strengths.length">
          <strong class="text-success small">
            <i class="ti ti-circle-check me-1"></i>Pontos fortes
          </strong>
          <ul class="mb-0 mt-1" style="font-size: .85rem">
            <li v-for="s in analysis.strengths">{{ s }}</li>
          </ul>
        </div>
        <!-- Melhorias -->
        <div v-if="analysis.improvements.length">
          <strong class="text-warning small">
            <i class="ti ti-alert-triangle me-1"></i>Sugestões de melhoria
          </strong>
          <ul class="mb-0 mt-1" style="font-size: .85rem">
            <li v-for="imp in analysis.improvements">{{ imp }}</li>
          </ul>
        </div>
      </div>
      <!-- Risco spam -->
      <div class="col-auto">
        <span class="badge"
          :class="{
            'bg-success': analysis.spam_risk === 'low',
            'bg-warning text-dark': analysis.spam_risk === 'medium',
            'bg-danger': analysis.spam_risk === 'high'
          }">
          Spam: {{ spamLabel }}
        </span>
      </div>
    </div>

    <!-- Versões melhoradas -->
    <h5 class="mb-3">Versões melhoradas</h5>
    <div class="row g-2">
      <div class="col-12"
        v-for="(v, i) in improvedVersions" :key="v.id">
        <div class="card border cursor-pointer"
          :class="{ 'border-primary border-2': selectedImproved === i }"
          @click="selectedImproved = i">
          <div class="card-body py-2">
            <div class="d-flex justify-content-between mb-1">
              <span class="badge bg-azure-lt text-azure small">
                {{ v.change }}
              </span>
              <div class="d-flex gap-1">
                <span v-if="channel === 'sms'"
                  class="badge"
                  :class="v.chars > 160 ? 'bg-danger' : 'bg-success'">
                  {{ v.chars }}/160
                </span>
                <button class="btn btn-ghost-secondary btn-sm btn-icon"
                  @click.stop="copyText(v.text)">
                  <i class="ti ti-copy"></i>
                </button>
              </div>
            </div>
            <p class="mb-0 small" style="white-space: pre-wrap">{{ v.text }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal-footer">
  <button class="btn btn-link link-secondary me-auto"
    data-bs-dismiss="modal">Fechar</button>
  <button class="btn btn-outline-secondary"
    :disabled="selectedImproved === null"
    @click="saveImprovedAsModel">
    <i class="ti ti-bookmark me-2"></i>Salvar como modelo
  </button>
  <button class="btn btn-primary"
    :disabled="selectedImproved === null"
    @click="useImproved">
    <i class="ti ti-check me-2"></i>Usar esta versão
  </button>
</div>
```

useImproved():
  manualContent = improvedVersions[selectedImproved].text
  wizard.saveField('content', manualContent)
  fechar modal

---

### HISTÓRICO DE SESSÕES — SessionHistory.vue (subcomponente)

Exibido abaixo do modo IA ou manual — sempre visível se houver histórico.

```html
<div class="mt-4" v-if="sessions.length">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <h5 class="mb-0">
      <i class="ti ti-history me-2 text-muted"></i>
      Histórico de gerações
    </h5>
    <span class="badge bg-secondary">{{ sessions.length }} sessão(ões)</span>
  </div>

  <!-- Acordeão — cada sessão colapsável -->
  <div class="accordion" id="sessionsAccordion">
    <div class="accordion-item"
      v-for="(session, si) in sessions" :key="session.id">
      <h2 class="accordion-header">
        <button class="accordion-button collapsed"
          type="button"
          data-bs-toggle="collapse"
          :data-bs-target="`#session-${session.id}`">
          <div class="d-flex align-items-center gap-3 w-100 me-3">
            <span class="badge bg-secondary">
              Sessão {{ sessions.length - si }}
            </span>
            <span class="text-muted small">
              {{ formatDate(session.created_at) }}
            </span>
            <span class="badge bg-primary-lt text-primary ms-auto">
              {{ session.variations.length }} versões
            </span>
          </div>
        </button>
      </h2>
      <div :id="`session-${session.id}`"
        class="accordion-collapse collapse"
        data-bs-parent="#sessionsAccordion">
        <div class="accordion-body p-2">
          <!-- Briefing resumido (se sessão IA) -->
          <div v-if="session.briefing"
            class="bg-light rounded p-2 mb-3 small text-muted">
            <strong>Briefing:</strong>
            {{ session.briefing.product }} · {{ session.briefing.tone }}
            · {{ session.briefing.variations }} versões
          </div>

          <!-- Variações da sessão -->
          <div class="row g-2">
            <div class="col-12"
              v-for="(v, vi) in session.variations" :key="v.id">
              <div class="card border cursor-pointer"
                :class="{
                  'border-primary border-2':
                    wizard.campaign?.content === v.text
                }"
                @click="selectFromHistory(v, session)">
                <div class="card-body py-2">
                  <div class="d-flex justify-content-between mb-1">
                    <span class="badge bg-secondary-lt text-secondary small">
                      Versão {{ vi + 1 }}
                    </span>
                    <div class="d-flex gap-1">
                      <span class="badge bg-success-lt text-success small"
                        v-if="wizard.campaign?.content === v.text">
                        ✓ Em uso
                      </span>
                      <button class="btn btn-ghost-secondary btn-sm btn-icon"
                        @click.stop="copyText(v.text)">
                        <i class="ti ti-copy"></i>
                      </button>
                      <button class="btn btn-ghost-secondary btn-sm btn-icon"
                        @click.stop="saveAsModel(v)"
                        data-bs-toggle="tooltip" title="Salvar como modelo">
                        <i class="ti ti-bookmark"></i>
                      </button>
                    </div>
                  </div>
                  <p class="mb-0 small" style="white-space: pre-wrap">
                    {{ v.text }}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
```

Carregamento:
  GET /api/v1/campaigns/{id}/ai-sessions
  Retorna array de ai_generation_sessions ordenadas por created_at DESC
  Carrega ao montar o componente e após cada nova geração

selectFromHistory(v, session):
  wizard.saveField('content', v.text)
  wizard.saveField('ai_generation_session_id', session.id)
  toast.success('Versão selecionada.')
  emit('select', v)

---

### SALVAR COMO MODELO — SaveModelModal.vue

Modal sm acionado pelo botão "Salvar como modelo" em qualquer variação
(modo IA, modo manual, histórico ou assistente).

```html
<div class="modal-body">
  <div class="mb-3">
    <label class="form-label required">Nome do modelo</label>
    <input type="text" class="form-control"
      v-model="modelName" autofocus
      placeholder="Ex: Oferta academia — tom casual"
      :class="{ 'is-invalid': errors.name }">
    <div class="invalid-feedback" v-if="errors.name">
      {{ errors.name[0] }}
    </div>
  </div>

  <!-- Preview do conteúdo que será salvo -->
  <div class="mb-3">
    <label class="form-label text-muted">Conteúdo</label>
    <div class="bg-light rounded p-2 small"
      style="white-space: pre-wrap; max-height: 100px; overflow-y: auto">
      {{ contentToSave }}
    </div>
  </div>

  <div class="mb-0">
    <div class="badge bg-primary me-1">{{ channelLabel }}</div>
    <span class="text-muted small">
      Disponível em: IA → Modelos salvos
    </span>
  </div>
</div>

<div class="modal-footer">
  <button class="btn btn-link link-secondary"
    data-bs-dismiss="modal">Cancelar</button>
  <button class="btn btn-primary"
    :disabled="!modelName || isSaving"
    @click="saveModel">
    <span v-if="isSaving"
      class="spinner-border spinner-border-sm me-2"></span>
    Salvar modelo
  </button>
</div>
```

POST /api/v1/ai/models:
```json
{
  "name": "Oferta academia — tom casual",
  "channel": "sms",
  "content": "texto da variação",
  "briefing": { ... } // se vier de sessão IA, null se manual
}
```

Sucesso: toast "Modelo salvo! Acesse em Gerador de Conteúdo → Modelos salvos."

---

### Backend — Endpoints necessários

#### POST /api/v1/ai/generate
Responsável: AiGeneratorController@generate

Validar: campaign_id (required, exists), channel (required), briefing (required array),
         variations (required, integer, min:1, max:5)

Verificar: campaign pertence ao tenant autenticado (GlobalScope cuida)
           campaign.status = draft

Criar ai_generation_session (status=pending):
  tenant_id, campaign_id, channel, briefing (json), status=pending

Chamar GrokService::generateVariations(channel, briefing, variations):
  - Carrega prompt ativo de ai_prompts onde service = channel e is_active = true
  - Compõe prompt substituindo variáveis do briefing
  - Registra ai_generation (status=pending) ANTES da chamada
  - Chama Grok API
  - Atualiza ai_generation (status=completed, tokens, cost_usd)
  - Debita créditos via CreditService::debit(
      tenantId, 10, 'ai_generation', generation.id, 'Geração IA'
    )

Atualizar ai_generation_session:
  status=completed, variations (json array)

Retornar:
  session_id, generation_id, variations[], credits_used

#### POST /api/v1/ai/analyze
Responsável: AiGeneratorController@analyze

Validar: campaign_id, channel, content (required string, min:10)

Mesma lógica de geração, mas com prompt de análise:
  - Usa ai_prompts onde service = "{channel}_analysis"
  - Retorna: analysis { score, strengths, improvements, spam_risk }
             improved_versions[]

#### GET /api/v1/campaigns/{id}/ai-sessions
Responsável: CampaignController@aiSessions

Retorna: ai_generation_sessions onde campaign_id = id, ordenado por created_at DESC
         Incluir campo briefing e variations de cada sessão

#### POST /api/v1/ai/models
Responsável: AiContentModelController@store

Validar: name (required), channel (required), content (required), briefing (nullable json)
Criar AiContentModel (tenant_id via GlobalScope creating)
Retornar modelo criado

---

### Migrations necessárias para este step

1. create_ai_generation_sessions_table
   - id
   - tenant_id → FK tenants, cascadeOnDelete
   - campaign_id → FK campaigns, cascadeOnDelete, nullable
   - channel (enum: sms/voice/email)
   - status (enum: pending/completed/failed, default: pending)
   - briefing (json nullable)
   - variations (json nullable)
   - timestamps
   - INDEX: tenant_id, campaign_id

2. create_ai_generations_table
   - id
   - tenant_id → FK tenants, cascadeOnDelete
   - generation_id (uuid unique)
   - session_id → FK ai_generation_sessions, cascadeOnDelete, nullable
   - service (string) — sms / voice / email / sms_analysis
   - prompt_version (string nullable)
   - input_payload (json)
   - output (json nullable)
   - tokens_input (unsignedInt default 0)
   - tokens_output (unsignedInt default 0)
   - cost_usd (decimal 10,6 default 0)
   - model (string nullable)
   - status (enum: pending/completed/failed, default: pending)
   - timestamps
   - INDEX: tenant_id, generation_id, status

3. create_ai_prompts_table
   - id
   - service (string) — sms / voice / email / sms_analysis / voice_analysis / email_analysis
   - version (string) — v1, v2...
   - system_prompt (text)
   - user_template (text) — com variáveis {{product}}, {{audience}}, etc.
   - model (string default 'grok-beta')
   - is_active (boolean default false)
   - timestamps
   - UNIQUE: service + version
   - INDEX: service + is_active

4. create_ai_content_models_table
   - id
   - tenant_id → FK tenants, cascadeOnDelete
   - name (string)
   - channel (enum: sms/voice/email)
   - content (text)
   - briefing (json nullable)
   - timestamps
   - INDEX: tenant_id, channel

5. Adicionar coluna ai_generation_session_id em campaigns:
   add_ai_session_to_campaigns_table
   - campaigns.ai_generation_session_id (FK ai_generation_sessions, nullOnDelete, nullable)

Rodar: php artisan migrate

---

### Seeder de Prompts Iniciais

AiPromptsSeeder — inserir prompts base por canal:

SMS Generation (service='sms', version='v1', is_active=true):
  system_prompt: "Você é um especialista em marketing direto via SMS no Brasil.
    Crie mensagens curtas, impactantes e com CTA claro.
    Nunca use emojis em excesso. Nunca use palavras como 'grátis' ou 'promoção' sem contexto.
    Sempre respeite o limite de caracteres solicitado."
  user_template: "Produto/Serviço: {{product}}
    Público-alvo: {{audience}}
    Principal benefício: {{benefit}}
    Chamada para ação: {{cta}}
    Tom: {{tone}}
    {{#link}}Link a incluir: {{link}}{{/link}}
    {{#avoid}}Palavras a evitar: {{avoid}}{{/avoid}}
    {{#max_chars}}Limite: {{max_chars}} caracteres por mensagem{{/max_chars}}
    Gere exatamente {{variations}} versões diferentes.
    Retorne APENAS um JSON: { \"variations\": [{\"text\":\"...\"}] }"

SMS Analysis (service='sms_analysis', version='v1', is_active=true):
  system_prompt: "Você é um especialista em análise de mensagens de marketing SMS."
  user_template: "Analise esta mensagem SMS e retorne APENAS um JSON:
    Mensagem: \"{{content}}\"
    Retorne: {
      \"analysis\": { \"score\": 0-100, \"strengths\": [], \"improvements\": [], \"spam_risk\": \"low|medium|high\" },
      \"improved_versions\": [{\"text\":\"...\", \"change\":\"descrição da mudança\"}]
    }
    Gere exatamente 3 versões melhoradas."

Criar prompts equivalentes para voice e email com user_template adaptado.

---

### Revisão deste componente

Chamar agent `reviewer` com escopo do Step 2:

IA Governance:
  - ai_generation criado ANTES da chamada Grok (status=pending)
  - Tokens e custo registrados após geração
  - Prompt carregado de ai_prompts (nunca hardcoded)
  - Créditos debitados via CreditService após geração

Frontend:
  - isGenerating bloqueia botão (sem duplo clique)
  - Variações selecionadas destacadas (border-primary)
  - strategy_locked setado ao selecionar primeira variação IA
  - Histórico carregado ao montar + atualizado após cada geração
  - SaveModelModal reutilizável (mesma instância para IA, manual e histórico)
  - DOMPurify instalado para sanitizar HTML do email

Apresentar checklist antes de entregar.
```