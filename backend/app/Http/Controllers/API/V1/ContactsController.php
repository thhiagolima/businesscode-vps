<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Contact;
use Illuminate\Http\Request;

class ContactsController extends Controller
{
    use Concerns\ChecksTenant;
    public function index(Request $request)
    {
        $request->validate([
            'contact_list_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,blocked,invalid'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $q = Contact::query();
        if ($request->filled('contact_list_id')) {
            $listId = $request->integer('contact_list_id');
            // Verify list belongs to tenant
            $tenantId = auth()->user()->tenant_id;
            if (auth()->user()->role !== 'superadmin') {
                $listExists = \App\Models\ContactList::where('id', $listId)
                    ->where('tenant_id', $tenantId)
                    ->exists();
                if (!$listExists) {
                    return ApiResponse::error('Lista não encontrada', [], 404);
                }
            }
            $q->where('contact_list_id', $listId);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }
        if ($request->filled('search')) {
            $s = str_replace(['%', '_'], ['\\%', '\\_'], $request->string('search'));
            $q->where(function ($w) use ($s) {
                $w->where('phone', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('name', 'like', "%{$s}%");
            });
        }
        $perPage = min((int) $request->input('per_page', 20), 50);
        $items = $q->orderBy('id', 'desc')->paginate($perPage);
        return ApiResponse::paginated($items, 'OK');
    }

    public function store(Request $request)
    {
        $tenantId = auth()->user()->tenant_id;
        $ruleExists = \Illuminate\Validation\Rule::exists('contact_lists', 'id')
            ->where('tenant_id', $tenantId);

        $data = $request->validate([
            'contact_list_id' => ['required', 'integer', $ruleExists],
            'name'  => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[1-9]\d{6,14}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'status'=> ['nullable', 'in:active,blocked,invalid'],
        ]);

        if (empty($data['phone']) && empty($data['email'])) {
            return ApiResponse::error('Informe phone ou email', [], 422);
        }

        $contact = Contact::create($data + ['status' => $data['status'] ?? 'active']);
        return ApiResponse::success($contact, 'Contato criado', [], 201);
    }

    public function update(Request $request, int $id)
    {
        $contact = Contact::findOrFail($id);
        $this->ensureTenantOwns($contact);
        $data = $request->validate([
            'name'  => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[1-9]\d{6,14}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'status'=> ['nullable', 'in:active,blocked,invalid'],
        ]);
        $contact->update($data);
        return ApiResponse::success($contact, 'Contato atualizado');
    }

    public function destroy(int $id)
    {
        $contact = Contact::findOrFail($id);
        $this->ensureTenantOwns($contact);
        $contact->delete();
        return ApiResponse::success([], 'Contato removido');
    }

    public function batch(Request $request)
    {
        $validated = $request->validate([
            'action'         => ['required', 'in:move,status,delete'],
            'contact_ids'    => ['required', 'array', 'max:200'],
            'contact_ids.*'  => ['integer'],
            'target_list_id' => ['required_if:action,move', 'nullable', 'integer'],
            'target_status'  => ['required_if:action,status', 'nullable', 'in:active,blocked,invalid'],
        ]);

        if ($validated['action'] === 'move' && !empty($validated['target_list_id'])) {
            $tenantId = auth()->user()->tenant_id;
            if (auth()->user()->role !== 'superadmin') {
                $targetExists = \App\Models\ContactList::where('id', $validated['target_list_id'])
                    ->where('tenant_id', $tenantId)
                    ->exists();
                if (!$targetExists) {
                    return ApiResponse::error('Lista de destino não encontrada', [], 404);
                }
            }
        }

        $tenantId = auth()->user()->tenant_id;
        if (auth()->user()->role !== 'superadmin') {
            $ownedCount = Contact::whereIn('id', $validated['contact_ids'])
                ->where('tenant_id', $tenantId)
                ->count();
            if ($ownedCount !== count($validated['contact_ids'])) {
                return ApiResponse::error('Acesso negado a alguns contatos.', [], 403);
            }
        }

        $query = Contact::whereIn('id', $validated['contact_ids']);

        switch ($validated['action']) {
            case 'move':
                $oldListIds = (clone $query)->pluck('contact_list_id')->unique();
                $query->update(['contact_list_id' => $validated['target_list_id']]);
                foreach ($oldListIds as $listId) {
                    \App\Models\ContactList::where('id', $listId)->update([
                        'contact_count' => Contact::where('contact_list_id', $listId)->count()
                    ]);
                }
                \App\Models\ContactList::where('id', $validated['target_list_id'])->update([
                    'contact_count' => Contact::where('contact_list_id', $validated['target_list_id'])->count()
                ]);
                break;
            case 'status':
                $query->update(['status' => $validated['target_status']]);
                break;
            case 'delete':
                $listIds = (clone $query)->pluck('contact_list_id')->unique();
                $query->delete();
                foreach ($listIds as $listId) {
                    \App\Models\ContactList::where('id', $listId)->update([
                        'contact_count' => Contact::where('contact_list_id', $listId)->count()
                    ]);
                }
                break;
        }

        AuditLog::record('contacts.batch', 'Contact', null, ['action' => $validated['action'], 'count' => count($validated['contact_ids'])]);

        return ApiResponse::success([], 'Operação concluída');
    }
}
