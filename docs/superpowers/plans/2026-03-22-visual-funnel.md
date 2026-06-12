# Visual Funnel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a visual funnel editor (Vue Flow) with backend execution engine that automates WhatsApp message sequences with delays, conditions, and tags — triggered by keywords or manual enrollment.

**Architecture:** Backend: 4 new tables (funnels, nodes, edges, executions), FunnelEngineService processes executions, FunnelTickCommand cron runs every minute. Frontend: Vue Flow canvas with custom nodes, toolbar, and properties panel. Webhook integrates trigger system to route inbound messages to funnels.

**Tech Stack:** Laravel 12 (backend), Vue 3 + @vue-flow/core (frontend), Tabler UI

**Spec:** `docs/superpowers/specs/2026-03-22-visual-funnel-design.md`

---

## File Structure

### Backend — Create
| File | Responsibility |
|------|---------------|
| `backend/database/migrations/2026_03_22_300000_create_funnels_table.php` | Funnels table |
| `backend/database/migrations/2026_03_22_300010_create_funnel_nodes_table.php` | Nodes table |
| `backend/database/migrations/2026_03_22_300020_create_funnel_edges_table.php` | Edges table |
| `backend/database/migrations/2026_03_22_300030_create_funnel_executions_table.php` | Executions table |
| `backend/app/Models/Funnel.php` | Model (tenant scope) |
| `backend/app/Models/FunnelNode.php` | Model (no scope) |
| `backend/app/Models/FunnelEdge.php` | Model (no scope) |
| `backend/app/Models/FunnelExecution.php` | Model (tenant scope) |
| `backend/app/Services/Funnel/FunnelEngineService.php` | Execution engine |
| `backend/app/Services/Funnel/FunnelTriggerService.php` | Keyword matching |
| `backend/app/Http/Controllers/API/V1/FunnelController.php` | CRUD + canvas + enroll |
| `backend/app/Console/Commands/FunnelTickCommand.php` | Cron every minute |
| `backend/app/Jobs/StartFunnelExecutionJob.php` | Start execution async |
| `backend/app/Jobs/FunnelConditionCheckJob.php` | Re-evaluate condition |

### Backend — Modify
| File | Change |
|------|--------|
| `backend/routes/api.php` | Add funnel routes |
| `backend/routes/console.php` | Register FunnelTickCommand |
| `backend/app/Http/Controllers/API/V1/WhatsAppWebhookController.php:150-156` | Integrate trigger system |

### Frontend — Create
| File | Responsibility |
|------|---------------|
| `frontend/src/pages/funnels/Index.vue` | Funnel list page |
| `frontend/src/pages/funnels/Editor.vue` | Vue Flow editor page |
| `frontend/src/components/funnels/FunnelToolbar.vue` | Draggable block toolbar |
| `frontend/src/components/funnels/FunnelNodeStart.vue` | Start node |
| `frontend/src/components/funnels/FunnelNodeMessage.vue` | Message node |
| `frontend/src/components/funnels/FunnelNodeWait.vue` | Wait node |
| `frontend/src/components/funnels/FunnelNodeCondition.vue` | Condition node (2 outputs) |
| `frontend/src/components/funnels/FunnelNodeTag.vue` | Tag node |
| `frontend/src/components/funnels/NodePropertiesPanel.vue` | Properties editor |
| `frontend/src/components/funnels/TriggerConfig.vue` | Trigger configuration |

### Frontend — Modify
| File | Change |
|------|--------|
| `frontend/src/router/index.ts` | Add /funnels routes |
| `frontend/src/components/layout/AppSidebar.vue` | Add "Funis" nav |
| `frontend/package.json` | Add @vue-flow/* deps |

---

## Task 1: Migrations — 4 funnel tables

**Files:**
- Create: `backend/database/migrations/2026_03_22_300000_create_funnels_table.php`
- Create: `backend/database/migrations/2026_03_22_300010_create_funnel_nodes_table.php`
- Create: `backend/database/migrations/2026_03_22_300020_create_funnel_edges_table.php`
- Create: `backend/database/migrations/2026_03_22_300030_create_funnel_executions_table.php`

- [ ] **Step 1: Create funnels migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('funnels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'active', 'paused'])->default('draft');
            $table->boolean('is_default')->default(false);
            $table->json('triggers')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('funnels'); }
};
```

- [ ] **Step 2: Create funnel_nodes migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('funnel_nodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('funnel_id');
            $table->string('node_id');
            $table->enum('type', ['start', 'message', 'wait', 'condition', 'tag']);
            $table->string('label')->nullable();
            $table->json('config')->nullable();
            $table->float('position_x')->default(0);
            $table->float('position_y')->default(0);
            $table->timestamps();
            $table->foreign('funnel_id')->references('id')->on('funnels')->cascadeOnDelete();
            $table->unique(['funnel_id', 'node_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('funnel_nodes'); }
};
```

- [ ] **Step 3: Create funnel_edges migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('funnel_edges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('funnel_id');
            $table->string('edge_id');
            $table->string('source_node_id');
            $table->string('target_node_id');
            $table->string('label')->nullable();
            $table->timestamps();
            $table->foreign('funnel_id')->references('id')->on('funnels')->cascadeOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('funnel_edges'); }
};
```

- [ ] **Step 4: Create funnel_executions migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('funnel_executions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('funnel_id');
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('current_node_id');
            $table->enum('status', ['running', 'waiting', 'completed', 'cancelled'])->default('running');
            $table->timestamp('wait_until')->nullable();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('funnel_id')->references('id')->on('funnels')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
            $table->index(['status', 'wait_until']);
            $table->index(['contact_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('funnel_executions'); }
};
```

- [ ] **Step 5: Run migrations**

Run: `cd backend && php artisan migrate`

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations/2026_03_22_30*
git commit -m "feat: add funnel migrations - funnels, nodes, edges, executions"
```

---

## Task 2: Models — Funnel, FunnelNode, FunnelEdge, FunnelExecution

**Files:**
- Create: `backend/app/Models/Funnel.php`
- Create: `backend/app/Models/FunnelNode.php`
- Create: `backend/app/Models/FunnelEdge.php`
- Create: `backend/app/Models/FunnelExecution.php`

- [ ] **Step 1: Create all 4 models**

**Funnel.php** (with AppliesTenantScope):
```php
<?php
namespace App\Models;
use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;

class Funnel extends Model
{
    use AppliesTenantScope;
    protected $fillable = ['tenant_id','name','description','status','is_default','triggers'];
    protected $casts = ['is_default' => 'boolean', 'triggers' => 'array'];

    public function nodes() { return $this->hasMany(FunnelNode::class); }
    public function edges() { return $this->hasMany(FunnelEdge::class); }
    public function executions() { return $this->hasMany(FunnelExecution::class); }
}
```

**FunnelNode.php** (no scope):
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class FunnelNode extends Model
{
    protected $fillable = ['funnel_id','node_id','type','label','config','position_x','position_y'];
    protected $casts = ['config' => 'array', 'position_x' => 'float', 'position_y' => 'float'];

    public function funnel() { return $this->belongsTo(Funnel::class); }
}
```

**FunnelEdge.php** (no scope):
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class FunnelEdge extends Model
{
    protected $fillable = ['funnel_id','edge_id','source_node_id','target_node_id','label'];

    public function funnel() { return $this->belongsTo(Funnel::class); }
}
```

**FunnelExecution.php** (with AppliesTenantScope):
```php
<?php
namespace App\Models;
use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;

class FunnelExecution extends Model
{
    use AppliesTenantScope;
    protected $fillable = [
        'tenant_id','funnel_id','contact_id','conversation_id','current_node_id',
        'status','wait_until','last_message_id','metadata','started_at','completed_at',
    ];
    protected $casts = ['metadata'=>'array','wait_until'=>'datetime','started_at'=>'datetime','completed_at'=>'datetime'];

    public function funnel() { return $this->belongsTo(Funnel::class); }
    public function contact() { return $this->belongsTo(Contact::class); }
    public function conversation() { return $this->belongsTo(Conversation::class); }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l app/Models/Funnel.php && php -l app/Models/FunnelNode.php && php -l app/Models/FunnelEdge.php && php -l app/Models/FunnelExecution.php`

- [ ] **Step 3: Commit**

```bash
git add backend/app/Models/Funnel*.php
git commit -m "feat: add Funnel, FunnelNode, FunnelEdge, FunnelExecution models"
```

---

## Task 3: FunnelEngineService — execution engine

**Files:**
- Create: `backend/app/Services/Funnel/FunnelEngineService.php`

- [ ] **Step 1: Create FunnelEngineService**

The complete code for this service is provided in the spec (Section 4). It includes:
- `advance(FunnelExecution)` — loop that processes nodes sequentially (max 20 steps to prevent infinite loops)
- `resolveNextNode()` — finds next node via edge
- `executeNode()` — dispatches to type-specific handler
- `executeMessage()` — sends WhatsApp text or template, creates ConversationMessage
- `executeWait()` — sets wait_until and pauses execution
- `executeCondition()` — evaluates replied/keyword/timeout, follows correct edge
- `executeTag()` — adds/removes tag from Contact.meta.tags
- `checkReplied()` — checks for inbound messages after last_message_id
- `checkKeyword()` — regex match on latest inbound reply

Create `backend/app/Services/Funnel/FunnelEngineService.php` with the full code from the spec sections 4.1-4.5. Use `WhatsAppService` for sending and `SettingsService` for config. Log to channel `whatsapp`.

- [ ] **Step 2: Verify syntax**

Run: `php -l app/Services/Funnel/FunnelEngineService.php`

- [ ] **Step 3: Commit**

```bash
git add backend/app/Services/Funnel/FunnelEngineService.php
git commit -m "feat: add FunnelEngineService - execution engine for funnel nodes"
```

---

## Task 4: FunnelTriggerService + Jobs + FunnelTickCommand

**Files:**
- Create: `backend/app/Services/Funnel/FunnelTriggerService.php`
- Create: `backend/app/Jobs/StartFunnelExecutionJob.php`
- Create: `backend/app/Jobs/FunnelConditionCheckJob.php`
- Create: `backend/app/Console/Commands/FunnelTickCommand.php`
- Modify: `backend/routes/console.php`

- [ ] **Step 1: Create FunnelTriggerService**

```php
<?php
namespace App\Services\Funnel;

use App\Models\Funnel;

class FunnelTriggerService
{
    public function matchFunnel(string $content, int $tenantId): ?Funnel
    {
        $funnels = Funnel::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->get();

        foreach ($funnels as $funnel) {
            foreach ($funnel->triggers ?? [] as $trigger) {
                if (($trigger['type'] ?? '') === 'keyword' && !empty($trigger['pattern'])) {
                    if (preg_match('/' . $trigger['pattern'] . '/iu', $content)) {
                        return $funnel;
                    }
                }
            }
        }

        return $funnels->firstWhere('is_default', true);
    }
}
```

- [ ] **Step 2: Create StartFunnelExecutionJob**

```php
<?php
namespace App\Jobs;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\FunnelExecution;
use App\Models\FunnelNode;
use App\Services\Funnel\FunnelEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StartFunnelExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $funnelId,
        public int $contactId,
        public int $conversationId,
        public int $tenantId
    ) {}

    public function handle(FunnelEngineService $engine): void
    {
        $startNode = FunnelNode::where('funnel_id', $this->funnelId)
            ->where('type', 'start')
            ->first();

        if (! $startNode) {
            Log::channel('whatsapp')->warning('funnel.no_start_node', ['funnel' => $this->funnelId]);
            return;
        }

        $execution = FunnelExecution::create([
            'tenant_id'       => $this->tenantId,
            'funnel_id'       => $this->funnelId,
            'contact_id'      => $this->contactId,
            'conversation_id' => $this->conversationId,
            'current_node_id' => $startNode->node_id,
            'status'          => 'running',
            'started_at'      => now(),
        ]);

        $engine->advance($execution);

        Log::channel('whatsapp')->info('funnel.started', [
            'funnel'    => $this->funnelId,
            'contact'   => $this->contactId,
            'execution' => $execution->id,
        ]);
    }
}
```

- [ ] **Step 3: Create FunnelConditionCheckJob**

```php
<?php
namespace App\Jobs;

use App\Models\FunnelExecution;
use App\Models\FunnelNode;
use App\Services\Funnel\FunnelEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FunnelConditionCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $executionId) {}

    public function handle(FunnelEngineService $engine): void
    {
        $execution = FunnelExecution::withoutGlobalScopes()->find($this->executionId);
        if (! $execution || ! in_array($execution->status, ['running', 'waiting'])) return;

        $currentNode = FunnelNode::where('funnel_id', $execution->funnel_id)
            ->where('node_id', $execution->current_node_id)
            ->first();

        if (! $currentNode || $currentNode->type !== 'condition') return;

        // Re-evaluate condition and advance if resolved
        $execution->update(['status' => 'running']);
        $engine->advance($execution);

        Log::channel('whatsapp')->debug('funnel.condition_rechecked', ['execution' => $this->executionId]);
    }
}
```

- [ ] **Step 4: Create FunnelTickCommand**

```php
<?php
namespace App\Console\Commands;

use App\Models\FunnelExecution;
use App\Services\Funnel\FunnelEngineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FunnelTickCommand extends Command
{
    protected $signature = 'funnel:tick';
    protected $description = 'Process waiting funnel executions whose wait_until has passed';

    public function handle(FunnelEngineService $engine): int
    {
        $executions = FunnelExecution::withoutGlobalScopes()
            ->where('status', 'waiting')
            ->where('wait_until', '<=', now())
            ->limit(100)
            ->get();

        if ($executions->isEmpty()) return 0;

        $processed = 0;
        foreach ($executions as $execution) {
            try {
                $execution->update(['status' => 'running']);
                $engine->advance($execution);
                $processed++;
            } catch (\Throwable $e) {
                Log::channel('whatsapp')->error('funnel.tick_error', [
                    'execution' => $execution->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        if ($processed > 0) {
            Log::channel('whatsapp')->info('funnel.tick', ['processed' => $processed]);
        }

        return 0;
    }
}
```

- [ ] **Step 5: Register in scheduler**

In `backend/routes/console.php`, add after the existing DispatchScheduledCampaigns schedule:

```php
use App\Console\Commands\FunnelTickCommand;

Schedule::command(FunnelTickCommand::class)
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();
```

- [ ] **Step 6: Verify all syntax**

Run: `php -l app/Services/Funnel/FunnelTriggerService.php && php -l app/Jobs/StartFunnelExecutionJob.php && php -l app/Jobs/FunnelConditionCheckJob.php && php -l app/Console/Commands/FunnelTickCommand.php`

- [ ] **Step 7: Commit**

```bash
git add backend/app/Services/Funnel/FunnelTriggerService.php backend/app/Jobs/StartFunnelExecutionJob.php backend/app/Jobs/FunnelConditionCheckJob.php backend/app/Console/Commands/FunnelTickCommand.php backend/routes/console.php
git commit -m "feat: add funnel trigger service, jobs, and tick command"
```

---

## Task 5: FunnelController + Routes

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/FunnelController.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 1: Create FunnelController**

Full CRUD with `saveCanvas()`, `activate()`, `pause()`, `enroll()`, `executions()`. Uses the spec's code for `saveCanvas()` (full sync with transaction) and `enroll()` (cancels active + starts new via FunnelEngineService). Read the spec Section 5 for the exact code.

Key methods:
- `index()` — paginated list with execution counts
- `store()` — create draft funnel with auto-generated start node
- `show()` — funnel + nodes + edges eager loaded
- `update()` — update name/description/triggers
- `destroy()` — delete funnel (cascade deletes nodes/edges/executions)
- `saveCanvas()` — full sync of nodes + edges in transaction
- `activate()` — set status=active
- `pause()` — set status=paused
- `enroll()` — manual enrollment with FunnelEngineService
- `executions()` — paginated list of executions for the funnel

- [ ] **Step 2: Register routes**

Add import at top of `routes/api.php`:
```php
use App\Http\Controllers\API\V1\FunnelController;
```

Add inside `auth:sanctum` group (after chatbot routes):
```php
        // Funis
        Route::get('funnels',                    [FunnelController::class, 'index']);
        Route::post('funnels',                   [FunnelController::class, 'store']);
        Route::get('funnels/{id}',               [FunnelController::class, 'show']);
        Route::put('funnels/{id}',               [FunnelController::class, 'update']);
        Route::delete('funnels/{id}',            [FunnelController::class, 'destroy']);
        Route::put('funnels/{id}/canvas',        [FunnelController::class, 'saveCanvas']);
        Route::post('funnels/{id}/activate',     [FunnelController::class, 'activate']);
        Route::post('funnels/{id}/pause',        [FunnelController::class, 'pause']);
        Route::post('funnels/{id}/enroll',       [FunnelController::class, 'enroll']);
        Route::get('funnels/{id}/executions',    [FunnelController::class, 'executions']);
```

- [ ] **Step 3: Verify**

Run: `php -l app/Http/Controllers/API/V1/FunnelController.php && php artisan route:list --path=v1/funnels 2>&1 | wc -l`

- [ ] **Step 4: Commit**

```bash
git add backend/app/Http/Controllers/API/V1/FunnelController.php backend/routes/api.php
git commit -m "feat: add FunnelController with CRUD, canvas, and enrollment"
```

---

## Task 6: Webhook integration — trigger system

**Files:**
- Modify: `backend/app/Http/Controllers/API/V1/WhatsAppWebhookController.php:150-156`

- [ ] **Step 1: Integrate funnel triggers into processInboundMessage**

Read `WhatsAppWebhookController.php`. Find lines 150-156 where `ProcessInboundMessageJob` is dispatched:

```php
        if ($conversation->status === 'bot') {
            ProcessInboundMessageJob::dispatch(
                $conversation->id,
                $conversationMessage->id,
                $tenantId
            );
        }
```

Replace with funnel-aware logic:

```php
        if ($conversation->status === 'bot') {
            // Check if contact is in active funnel
            $activeExecution = \App\Models\FunnelExecution::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('contact_id', $contact->id)
                ->whereIn('status', ['running', 'waiting'])
                ->first();

            if ($activeExecution) {
                // Contact in funnel — update reply timestamp and recheck condition
                $meta = $activeExecution->metadata ?? [];
                $meta['last_reply_at'] = now()->toISOString();
                $activeExecution->update(['metadata' => $meta]);
                \App\Jobs\FunnelConditionCheckJob::dispatch($activeExecution->id);
            } else {
                // Try funnel trigger match
                $triggerService = app(\App\Services\Funnel\FunnelTriggerService::class);
                $matchedFunnel = $triggerService->matchFunnel($content ?? '', $tenantId);

                if ($matchedFunnel) {
                    \App\Jobs\StartFunnelExecutionJob::dispatch(
                        $matchedFunnel->id,
                        $contact->id,
                        $conversation->id,
                        $tenantId
                    );
                } else {
                    // Fallback to AI/bot
                    ProcessInboundMessageJob::dispatch(
                        $conversation->id,
                        $conversationMessage->id,
                        $tenantId
                    );
                }
            }
        }
```

- [ ] **Step 2: Verify syntax**

Run: `php -l app/Http/Controllers/API/V1/WhatsAppWebhookController.php`

- [ ] **Step 3: Commit**

```bash
git add backend/app/Http/Controllers/API/V1/WhatsAppWebhookController.php
git commit -m "feat: integrate funnel trigger system into WhatsApp webhook"
```

---

## Task 7: Install Vue Flow + create custom node components

**Files:**
- Modify: `frontend/package.json`
- Create: `frontend/src/components/funnels/FunnelNodeStart.vue`
- Create: `frontend/src/components/funnels/FunnelNodeMessage.vue`
- Create: `frontend/src/components/funnels/FunnelNodeWait.vue`
- Create: `frontend/src/components/funnels/FunnelNodeCondition.vue`
- Create: `frontend/src/components/funnels/FunnelNodeTag.vue`

- [ ] **Step 1: Install Vue Flow**

Run: `cd frontend && npm install @vue-flow/core @vue-flow/background @vue-flow/controls @vue-flow/minimap`

- [ ] **Step 2: Create 5 custom node components**

Each node component uses Vue Flow's `Handle` component for connections. Import from `@vue-flow/core`:

```ts
import { Handle, Position } from '@vue-flow/core'
```

**FunnelNodeStart.vue** — green header, no target handle (only source)
**FunnelNodeMessage.vue** — green border, shows truncated text/template name, target + source handles
**FunnelNodeWait.vue** — yellow border, shows "2 horas" etc, target + source handles
**FunnelNodeCondition.vue** — blue border, shows condition type, target handle + TWO source handles (id="yes" at 30%, id="no" at 70%)
**FunnelNodeTag.vue** — pink border, shows "add: tag_name", target + source handles

All nodes emit `select` on click. Each receives `data` prop from Vue Flow with `{ label, config }`.

Style each with inline styles matching the color scheme from the spec. Keep components small (~40-60 lines each).

- [ ] **Step 3: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/src/components/funnels/FunnelNode*.vue
git commit -m "feat: install Vue Flow and create 5 custom funnel node components"
```

---

## Task 8: FunnelToolbar + NodePropertiesPanel + TriggerConfig

**Files:**
- Create: `frontend/src/components/funnels/FunnelToolbar.vue`
- Create: `frontend/src/components/funnels/NodePropertiesPanel.vue`
- Create: `frontend/src/components/funnels/TriggerConfig.vue`

- [ ] **Step 1: Create FunnelToolbar**

Vertical sidebar (120px) with 4 draggable blocks. Each block has `draggable="true"` and `@dragstart` sets `dataTransfer.setData('node-type', type)`. Styled as colored pills matching node colors.

- [ ] **Step 2: Create NodePropertiesPanel**

Right panel (300px) that shows config form based on selected node type:
- **message**: radio text/template + textarea/select
- **wait**: number input + unit select (minutes/hours/days)
- **condition**: select type (replied/keyword/timeout) + conditional fields
- **tag**: select action (add/remove) + text input

Emits `update:config` when form changes. Props: `node: { type, config }`.

- [ ] **Step 3: Create TriggerConfig**

Footer component showing list of triggers. Each trigger has select type (keyword/default) + input pattern. "Add trigger" button. Emits `update:triggers`. Props: `triggers: Array`.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/funnels/FunnelToolbar.vue frontend/src/components/funnels/NodePropertiesPanel.vue frontend/src/components/funnels/TriggerConfig.vue
git commit -m "feat: add funnel toolbar, properties panel, and trigger config components"
```

---

## Task 9: Editor page — Vue Flow canvas

**Files:**
- Create: `frontend/src/pages/funnels/Editor.vue`

- [ ] **Step 1: Create Editor.vue**

Main editor page with 3-column layout:
- Toolbar (left 120px) — `FunnelToolbar`
- Canvas (center flex) — `VueFlow` with custom node types registered
- Properties (right 300px) — `NodePropertiesPanel` (shows when node selected)
- Footer — `TriggerConfig`

Key logic:
- `@drop` on canvas: creates new node at drop position from toolbar drag
- `@node-click`: selects node, shows properties panel
- Node type registration via Vue Flow's `nodeTypes` prop
- Save button: serializes nodes/edges → `PUT /funnels/{id}/canvas`
- Load on mount: `GET /funnels/{id}` → hydrate Vue Flow with nodes/edges
- Header: back button, funnel name (editable), Save button, Activate/Pause button

Auto-generates unique node IDs: `node_${Date.now()}_${Math.random().toString(36).substr(2,4)}`

- [ ] **Step 2: Build and verify**

Run: `npm run build 2>&1 | tail -5`

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/funnels/Editor.vue
git commit -m "feat: add funnel editor page with Vue Flow canvas"
```

---

## Task 10: Funnels list page + router + sidebar

**Files:**
- Create: `frontend/src/pages/funnels/Index.vue`
- Modify: `frontend/src/router/index.ts`
- Modify: `frontend/src/components/layout/AppSidebar.vue`

- [ ] **Step 1: Create Index.vue**

Funnel list page with:
- Table: Name, Status (badge), Active contacts count, Created date, Actions
- "Novo funil" button → `POST /funnels` then redirect to editor
- Row actions: Edit (→ editor), Activate/Pause, Delete (with confirm)
- Empty state

- [ ] **Step 2: Add routes**

In `router/index.ts`, add before catch-all:
```ts
  { path: '/funnels', component: () => import('@/pages/funnels/Index.vue'), meta: { title: 'Funis' } },
  { path: '/funnels/create', component: () => import('@/pages/funnels/Editor.vue'), meta: { title: 'Novo Funil' } },
  { path: '/funnels/:id/edit', component: () => import('@/pages/funnels/Editor.vue'), meta: { title: 'Editar Funil' } },
```

- [ ] **Step 3: Add sidebar nav item**

In `AppSidebar.vue`, after the Chatbot nav item, add:
```html
        <li class="nav-item">
          <a class="nav-link" :class="{ active: isActive('/funnels') }" @click.prevent="go('/funnels')">
            <span class="nav-link-icon"><i class="ti ti-chart-funnel"></i></span>
            <span class="nav-link-title">Funis</span>
          </a>
        </li>
```

- [ ] **Step 4: Build and verify**

Run: `cd frontend && npm run build 2>&1 | tail -5`

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/funnels/Index.vue frontend/src/router/index.ts frontend/src/components/layout/AppSidebar.vue
git commit -m "feat: add funnels list page with router and sidebar navigation"
```

---

## Task 11: Final verification

- [ ] **Step 1: Backend syntax check all new files**

Run: `cd backend && find app database -name '*.php' -newer artisan 2>/dev/null | xargs -I{} php -l {} 2>&1 | grep -v "No syntax errors"`
Expected: No output (all pass)

- [ ] **Step 2: Route check**

Run: `php artisan route:list --path=v1/funnels 2>&1 | wc -l`
Expected: ~12 lines (10 funnel routes + header)

- [ ] **Step 3: Scheduler check**

Run: `php artisan schedule:list 2>&1 | grep funnel`
Expected: Shows `funnel:tick` every minute

- [ ] **Step 4: Frontend build**

Run: `cd frontend && npm run build 2>&1 | tail -5`
Expected: `✓ built in Xs`

- [ ] **Step 5: Manual smoke test**

1. `/funnels` — list page renders
2. "Novo funil" → creates funnel, redirects to editor
3. Editor: drag blocks from toolbar to canvas → nodes appear
4. Connect nodes with edges (drag from handle to handle)
5. Click node → properties panel shows config form
6. Save → no errors
7. Activate → status changes to active
8. Sidebar shows "Funis" nav item with funnel icon
