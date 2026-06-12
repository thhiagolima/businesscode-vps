<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Funnel;
use App\Models\FunnelEdge;
use App\Models\FunnelExecution;
use App\Models\FunnelNode;
use App\Services\Funnel\FunnelEngineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FunnelController extends Controller
{
    use Concerns\ChecksTenant;

    public function index()
    {
        $items = Funnel::withCount(['executions as active_contacts' => function ($q) {
            $q->whereIn('status', ['running', 'waiting']);
        }])->orderByDesc('updated_at')->paginate(20);

        return ApiResponse::paginated($items, 'OK');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $funnel = Funnel::create($data + ['status' => 'draft']);

        // Auto-create start node
        FunnelNode::create([
            'funnel_id'  => $funnel->id,
            'node_id'    => 'start_1',
            'type'       => 'start',
            'label'      => 'Início',
            'position_x' => 250,
            'position_y' => 50,
        ]);

        return ApiResponse::success($funnel->fresh()->load(['nodes', 'edges']), 'Funil criado', [], 201);
    }

    public function show(int $id)
    {
        $funnel = Funnel::with(['nodes', 'edges'])->withCount(['executions as active_contacts' => function ($q) {
            $q->whereIn('status', ['running', 'waiting']);
        }])->findOrFail($id);
        $this->ensureTenantOwns($funnel);

        return ApiResponse::success($funnel);
    }

    public function update(int $id, Request $request)
    {
        $funnel = Funnel::findOrFail($id);
        $this->ensureTenantOwns($funnel);
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'triggers'    => ['nullable', 'array', 'max:20'],
            'is_default'  => ['nullable', 'boolean'],
        ]);

        // Ensure only 1 default per tenant
        if (!empty($data['is_default'])) {
            Funnel::where('id', '!=', $funnel->id)->update(['is_default' => false]);
        }

        $funnel->update($data);
        return ApiResponse::success($funnel->fresh(), 'Funil atualizado');
    }

    public function destroy(int $id)
    {
        $funnel = Funnel::findOrFail($id);
        $this->ensureTenantOwns($funnel);
        $funnel->delete();
        return ApiResponse::success([], 'Funil removido');
    }

    public function saveCanvas(int $id, Request $request)
    {
        $funnel = Funnel::findOrFail($id);
        $this->ensureTenantOwns($funnel);

        $data = $request->validate([
            'nodes'              => ['required', 'array', 'max:100'],
            'nodes.*.node_id'    => ['required', 'string', 'max:50'],
            'nodes.*.type'       => ['required', 'in:start,message,wait,condition,tag,transfer_human,ai_reply'],
            'nodes.*.label'      => ['nullable', 'string', 'max:255'],
            'nodes.*.config'     => ['nullable', 'array'],
            'nodes.*.position_x' => ['required', 'numeric', 'between:-10000,10000'],
            'nodes.*.position_y' => ['required', 'numeric', 'between:-10000,10000'],
            'edges'              => ['present', 'array', 'max:200'],
            'edges.*.edge_id'        => ['required', 'string', 'max:50'],
            'edges.*.source_node_id' => ['required', 'string', 'max:50'],
            'edges.*.target_node_id' => ['required', 'string', 'max:50'],
            'edges.*.label'          => ['nullable', 'string', 'max:100'],
        ]);

        $configError = $this->validateNodeConfigs($data['nodes']);
        if ($configError) {
            return ApiResponse::error($configError, [], 422);
        }

        DB::transaction(function () use ($funnel, $data) {
            FunnelNode::where('funnel_id', $funnel->id)->delete();
            FunnelEdge::where('funnel_id', $funnel->id)->delete();

            foreach ($data['nodes'] as $node) {
                FunnelNode::create(['funnel_id' => $funnel->id] + $node);
            }
            foreach ($data['edges'] as $edge) {
                FunnelEdge::create(['funnel_id' => $funnel->id] + $edge);
            }
        });

        return ApiResponse::success($funnel->fresh()->load(['nodes', 'edges']), 'Canvas salvo');
    }

    public function activate(int $id)
    {
        $funnel = Funnel::findOrFail($id);
        $this->ensureTenantOwns($funnel);

        $hasStart = FunnelNode::where('funnel_id', $funnel->id)->where('type', 'start')->exists();
        if (! $hasStart) {
            return ApiResponse::error('Funil precisa de um nó de início para ser ativado.', [], 422);
        }

        $funnel->update(['status' => 'active']);
        return ApiResponse::success($funnel->fresh(), 'Funil ativado');
    }

    public function pause(int $id)
    {
        $funnel = Funnel::findOrFail($id);
        $this->ensureTenantOwns($funnel);
        $funnel->update(['status' => 'paused']);
        return ApiResponse::success($funnel->fresh(), 'Funil pausado');
    }

    public function enroll(int $id, Request $request)
    {
        $funnel = Funnel::findOrFail($id);
        $this->ensureTenantOwns($funnel);
        $data = $request->validate([
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
        ]);

        $tenantId = auth()->user()->tenant_id;
        $contact = Contact::where('id', $data['contact_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Max concurrent executions check
        $activeExecutions = FunnelExecution::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->whereIn('status', ['running', 'waiting'])
            ->count();
        if ($activeExecutions >= 3) {
            return ApiResponse::error('Contato já está em muitos funis ativos (máx 3)', [], 429);
        }

        // Prevent re-enrollment in same funnel within 5 minutes (anti-loop)
        $recentExecution = FunnelExecution::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('funnel_id', $funnel->id)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($recentExecution) {
            return ApiResponse::error('Contato foi inscrito neste funil recentemente. Aguarde 5 minutos.', [], 429);
        }

        // Cancel active execution if exists
        FunnelExecution::where('contact_id', $contact->id)
            ->whereIn('status', ['running', 'waiting'])
            ->update(['status' => 'cancelled']);

        $startNode = FunnelNode::where('funnel_id', $funnel->id)->where('type', 'start')->first();
        if (! $startNode) {
            return ApiResponse::error('Funil não tem nó de início', [], 422);
        }

        $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'phone' => $contact->phone],
            ['contact_id' => $contact->id, 'channel' => 'whatsapp', 'status' => 'bot']
        );

        $execution = FunnelExecution::create([
            'tenant_id'       => $tenantId,
            'funnel_id'       => $funnel->id,
            'contact_id'      => $contact->id,
            'conversation_id' => $conversation->id,
            'current_node_id' => $startNode->node_id,
            'status'          => 'running',
            'started_at'      => now(),
        ]);

        app(FunnelEngineService::class)->advance($execution);

        return ApiResponse::success($execution->fresh(), 'Contato inscrito no funil');
    }

    public function duplicate(int $id)
    {
        $funnel = Funnel::findOrFail($id);
        $this->ensureTenantOwns($funnel);

        $newFunnel = DB::transaction(function () use ($funnel) {
            $clone = $funnel->replicate(['id', 'created_at', 'updated_at']);
            $clone->name = $funnel->name . ' (cópia)';
            $clone->status = 'draft';
            $clone->is_default = false;
            $clone->save();

            foreach ($funnel->nodes as $node) {
                $nodeClone = $node->replicate(['id', 'created_at', 'updated_at']);
                $nodeClone->funnel_id = $clone->id;
                $nodeClone->save();
            }

            foreach ($funnel->edges as $edge) {
                $edgeClone = $edge->replicate(['id', 'created_at', 'updated_at']);
                $edgeClone->funnel_id = $clone->id;
                $edgeClone->save();
            }

            return $clone;
        });

        return ApiResponse::success($newFunnel->load(['nodes', 'edges']), 'Funil duplicado');
    }

    public function executions(int $id)
    {
        $funnel = Funnel::findOrFail($id);
        $this->ensureTenantOwns($funnel);
        $items = FunnelExecution::where('funnel_id', $funnel->id)
            ->with('contact:id,name,phone')
            ->orderByDesc('created_at')
            ->paginate(20);

        return ApiResponse::paginated($items, 'OK');
    }

    public function export(int $id)
    {
        $funnel = Funnel::with(['nodes', 'edges'])->findOrFail($id);
        $this->ensureTenantOwns($funnel);

        $export = [
            'version'     => '1.0',
            'name'        => $funnel->name,
            'description' => $funnel->description,
            'triggers'    => $funnel->triggers,
            'nodes'       => $funnel->nodes->map(fn ($n) => [
                'node_id'    => $n->node_id,
                'type'       => $n->type,
                'label'      => $n->label,
                'config'     => $n->config,
                'position_x' => $n->position_x,
                'position_y' => $n->position_y,
            ])->values(),
            'edges'       => $funnel->edges->map(fn ($e) => [
                'edge_id'        => $e->edge_id,
                'source_node_id' => $e->source_node_id,
                'target_node_id' => $e->target_node_id,
                'label'          => $e->label,
            ])->values(),
        ];

        $json = json_encode($export);
        $signature = hash_hmac('sha256', $json, config('app.key'));
        $export['_signature'] = $signature;

        return response()->json($export)
            ->header('Content-Disposition', "attachment; filename=\"funnel-{$id}.json\"");
    }

    public function import(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:json,txt', 'max:2048'],
        ]);

        $content = json_decode(file_get_contents($request->file('file')->getRealPath()), true);

        if (empty($content['nodes']) || empty($content['version'])) {
            return ApiResponse::error('Arquivo JSON inválido', [], 422);
        }

        if (count($content['nodes'] ?? []) > 100) {
            return ApiResponse::error('Funil excede 100 nós', [], 422);
        }
        if (count($content['edges'] ?? []) > 200) {
            return ApiResponse::error('Funil excede 200 conexões', [], 422);
        }

        $signature = $content['_signature'] ?? null;
        unset($content['_signature']);

        if (!$signature) {
            return ApiResponse::error('Arquivo sem assinatura de integridade', [], 422);
        }

        $expected = hash_hmac('sha256', json_encode($content), config('app.key'));
        if (!hash_equals($expected, $signature)) {
            return ApiResponse::error('Assinatura inválida — arquivo pode ter sido alterado', [], 422);
        }

        $allowedTypes = ['start', 'message', 'wait', 'condition', 'tag', 'transfer_human', 'ai_reply'];

        foreach ($content['nodes'] ?? [] as $node) {
            if (!in_array($node['type'] ?? '', $allowedTypes)) {
                return ApiResponse::error("Tipo de nó inválido: '{$node['type']}'", [], 422);
            }
        }

        $funnel = DB::transaction(function () use ($content) {
            $funnel = Funnel::create([
                'name'        => ($content['name'] ?? 'Funil importado') . ' (importado)',
                'description' => $content['description'] ?? null,
                'status'      => 'draft',
                'triggers'    => $content['triggers'] ?? [],
            ]);

            foreach ($content['nodes'] ?? [] as $node) {
                FunnelNode::create([
                    'funnel_id'  => $funnel->id,
                    'node_id'    => $node['node_id'],
                    'type'       => $node['type'],
                    'label'      => $node['label'] ?? null,
                    'config'     => $node['config'] ?? null,
                    'position_x' => $node['position_x'] ?? 0,
                    'position_y' => $node['position_y'] ?? 0,
                ]);
            }

            foreach ($content['edges'] ?? [] as $edge) {
                FunnelEdge::create([
                    'funnel_id'      => $funnel->id,
                    'edge_id'        => $edge['edge_id'],
                    'source_node_id' => $edge['source_node_id'],
                    'target_node_id' => $edge['target_node_id'],
                    'label'          => $edge['label'] ?? null,
                ]);
            }

            return $funnel;
        });

        return ApiResponse::success($funnel->load(['nodes', 'edges']), 'Funil importado', [], 201);
    }

    private function validateNodeConfigs(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            $type = $node['type'] ?? '';
            $config = $node['config'] ?? [];

            switch ($type) {
                case 'message':
                    if (!empty($config['text']) && strlen($config['text']) > 5000) {
                        return 'Mensagem excede 5000 caracteres';
                    }
                    break;
                case 'wait':
                    $duration = $config['duration'] ?? 0;
                    $unit = $config['unit'] ?? 'hours';
                    if ($duration < 1 || $duration > 720) {
                        return 'Duração de espera deve ser entre 1 e 720';
                    }
                    if (!in_array($unit, ['minutes', 'hours', 'days'])) {
                        return 'Unidade de espera inválida';
                    }
                    break;
                case 'condition':
                    $condType = $config['condition_type'] ?? '';
                    if (!in_array($condType, ['replied', 'keyword', 'timeout', 'has_tag'])) {
                        return 'Tipo de condição inválido';
                    }
                    if ($condType === 'keyword' && !empty($config['keyword'])) {
                        // Test that keyword is a safe literal pattern
                        $safePattern = preg_quote($config['keyword'], '/');
                        if (@preg_match('/' . $safePattern . '/iu', '') === false) {
                            return 'Regex de palavra-chave inválida';
                        }
                    }
                    break;
                case 'tag':
                    if (empty($config['tag']) || strlen($config['tag'] ?? '') > 50) {
                        return 'Tag inválida (máx 50 caracteres)';
                    }
                    if (!in_array($config['action'] ?? '', ['add', 'remove'])) {
                        return 'Ação de tag deve ser add ou remove';
                    }
                    break;
            }
        }
        return null;
    }
}
