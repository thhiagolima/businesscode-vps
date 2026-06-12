# Skill: Multitenancy

## Quando Usar
Sempre que criar um novo Model, Controller ou query
que acesse dados que pertencem a um tenant específico.

## Regra Fundamental
Todo dado de tenant deve ser isolado.
Tenant A nunca pode ver ou modificar dados do Tenant B.

---

## GlobalScope nos Models

Todo model de tenant DEVE ter GlobalScope aplicado:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    protected $fillable = [
        'tenant_id', 'name', 'type', 'status', 'content',
        'contact_list_id', 'scheduled_at', 'settings',
    ];

    protected $casts = [
        'settings'     => 'array',
        'scheduled_at' => 'datetime',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ✅ GlobalScope aplicado no boot
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            if (auth()->check()) {
                $query->where('tenant_id', auth()->user()->tenant_id);
            }
        });

        // Preencher tenant_id automaticamente ao criar
        static::creating(function (self $model) {
            if (auth()->check() && empty($model->tenant_id)) {
                $model->tenant_id = auth()->user()->tenant_id;
            }
        });
    }
}
```

---

## Verificação Extra nos Controllers

Mesmo com GlobalScope, sempre verificar propriedade
em operações de escrita (update, delete):

```php
public function update(Request $request, int $id): JsonResponse
{
    // GlobalScope já filtra por tenant, mas findOrFail
    // garante 404 se não pertencer ao tenant
    $campaign = Campaign::findOrFail($id);

    // Double-check explícito (defense in depth)
    if ($campaign->tenant_id !== auth()->user()->tenant_id) {
        return ApiResponse::error('Acesso negado.', [], 403);
    }

    $campaign->update($request->validated());
    return ApiResponse::success($campaign);
}
```

---

## Models com tenant_id — Template

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactList extends Model
{
    protected $fillable = ['tenant_id', 'name', 'description'];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', fn(Builder $q) =>
            $q->when(auth()->check(), fn($q) =>
                $q->where('tenant_id', auth()->user()->tenant_id)
            )
        );

        static::creating(fn(self $m) =>
            $m->tenant_id ??= auth()->user()?->tenant_id
        );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

---

## Migration Template com tenant_id

```php
Schema::create('contact_lists', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')
        ->constrained()
        ->cascadeOnDelete();      // apaga junto com o tenant
    $table->string('name');
    $table->text('description')->nullable();
    $table->unsignedInteger('contact_count')->default(0);
    $table->timestamps();

    $table->index('tenant_id');   // índice para performance
});
```

---

## Settings Globais vs Settings de Tenant

```php
// ─── Settings GLOBAIS (sistema) ────────────────────────────
// tenant_id = NULL
// Infobip, Grok, ElevenLabs, billing rates
// SEMPRE usar withoutGlobalScopes()

$apiKey = Setting::withoutGlobalScopes()
    ->whereNull('tenant_id')
    ->where('group', 'infobip')
    ->where('key', 'api_key')
    ->value('value');

// ─── Settings de TENANT ────────────────────────────────────
// tenant_id = $tenantId
// Preferências do tenant, configurações específicas
// GlobalScope cuida automaticamente

$pref = Setting::where('group', 'notifications')
    ->where('key', 'email_reports')
    ->value('value');
// ↑ GlobalScope já filtra pelo tenant autenticado
```

---

## Jobs e Multitenancy

Jobs não têm usuário autenticado, então o GlobalScope
não funciona automaticamente. Sempre passar tenant_id:

```php
// ❌ Errado — GlobalScope não funciona em jobs
class ProcessCampaignJob implements ShouldQueue
{
    public function handle(): void
    {
        $campaign = Campaign::find($this->campaignId); // pode retornar null!
    }
}

// ✅ Correto — withoutGlobalScopes em jobs
class ProcessCampaignJob implements ShouldQueue
{
    public function __construct(
        public int $campaignId,
        public int $tenantId,    // sempre passar tenant_id
    ) {}

    public function handle(): void
    {
        $campaign = Campaign::withoutGlobalScopes()
            ->where('id', $this->campaignId)
            ->where('tenant_id', $this->tenantId) // sempre filtrar
            ->firstOrFail();

        $contacts = Contact::withoutGlobalScopes()
            ->where('contact_list_id', $campaign->contact_list_id)
            ->where('tenant_id', $this->tenantId)
            ->where('opted_out', false)
            ->get();
    }
}
```

---

## Checklist de Multitenancy

Para cada novo Model criado, verificar:

```
[ ] $fillable inclui tenant_id
[ ] GlobalScope adicionado no booted()
[ ] Evento creating preenche tenant_id automaticamente
[ ] Migration tem foreignId('tenant_id')->constrained()->cascadeOnDelete()
[ ] Migration tem index('tenant_id')
[ ] Controller usa findOrFail() (GlobalScope garante o filtro)
[ ] Jobs usam withoutGlobalScopes() + filtro manual de tenant_id
[ ] Testes verificam que tenant A não acessa dados do tenant B
```

---

## Nunca Fazer

```php
// ❌ Query sem filtro de tenant em controller
Campaign::all(); // retorna todos os tenants!
Campaign::where('status', 'completed')->get();

// ❌ Usar updateOrInsert com tenant_id null em settings globais
Setting::updateOrInsert(
    ['tenant_id' => null, 'group' => 'infobip', 'key' => 'api_key'],
    ['value' => $value]
); // NULL != NULL no SQL — nunca atualiza, sempre insere!

// ❌ Job sem tenant_id
ProcessCampaignJob::dispatch($campaignId);
// ✅
ProcessCampaignJob::dispatch($campaignId, $tenantId);
```
