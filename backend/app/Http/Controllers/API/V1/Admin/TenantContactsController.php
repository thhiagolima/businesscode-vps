<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Tenant;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantContactsController extends Controller
{
    public function index(Request $request, int $tenant)
    {
        Tenant::findOrFail($tenant);
        $validated = $request->validate([
            'search'          => ['nullable', 'string', 'max:255'],
            'contact_list_id' => ['nullable', 'integer'],
            'status'          => ['nullable', 'in:active,opted_out,blocked'],
        ]);

        $q = Contact::where('tenant_id', $tenant);
        if (!empty($validated['search'])) {
            $s = str_replace(['%', '_'], ['\\%', '\\_'], $validated['search']);
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', "%{$s}%")
                   ->orWhere('email', 'like', "%{$s}%")
                   ->orWhere('phone', 'like', "%{$s}%");
            });
        }
        if (!empty($validated['contact_list_id'])) $q->where('contact_list_id', $validated['contact_list_id']);
        if (!empty($validated['status']))          $q->where('status', $validated['status']);

        return ApiResponse::paginated($q->orderByDesc('id')->paginate(20));
    }

    public function store(Request $request, int $tenant)
    {
        Tenant::findOrFail($tenant);
        $listExists = Rule::exists('contact_lists', 'id')->where('tenant_id', $tenant);

        $data = $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'phone'           => ['nullable', 'string', 'max:32'],
            'email'           => ['nullable', 'email', 'max:255'],
            'contact_list_id' => ['nullable', 'integer', $listExists],
            'status'          => ['nullable', 'in:active,opted_out,blocked'],
        ]);
        $data['tenant_id'] = $tenant;

        // contacts.contact_list_id is NOT NULL at the DB level. Admin-created contacts
        // without an explicit list go into a per-tenant default bucket so the support
        // flow doesn't require pre-selecting a list.
        if (empty($data['contact_list_id'])) {
            $data['contact_list_id'] = ContactList::firstOrCreate(
                ['tenant_id' => $tenant, 'name' => 'Sem lista'],
                ['contact_count' => 0],
            )->id;
        }

        $contact = Contact::create($data);

        AdminAuditLogger::log('contact', 'create', $tenant, $contact->id, ['name' => $contact->name]);

        return ApiResponse::success($contact, 'Contato criado', [], 201);
    }

    public function update(Request $request, int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $contact = Contact::where('tenant_id', $tenant)->findOrFail($id);

        $data = $request->validate([
            'name'   => ['sometimes', 'string', 'max:255'],
            'phone'  => ['sometimes', 'nullable', 'string', 'max:32'],
            'email'  => ['sometimes', 'nullable', 'email', 'max:255'],
            'status' => ['sometimes', 'in:active,opted_out,blocked'],
        ]);

        $before = $contact->only(array_keys($data));
        $contact->update($data);

        AdminAuditLogger::log('contact', 'update', $tenant, $id, ['before' => $before, 'after' => $data]);

        return ApiResponse::success($contact->fresh());
    }

    public function destroy(int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $contact = Contact::where('tenant_id', $tenant)->findOrFail($id);
        $snap = $contact->only(['name', 'email', 'phone']);
        $contact->delete();

        AdminAuditLogger::log('contact', 'delete', $tenant, $id, $snap);

        return ApiResponse::success([], 'Contato removido');
    }

    public function lists(int $tenant)
    {
        Tenant::findOrFail($tenant);
        $lists = ContactList::where('tenant_id', $tenant)
            ->select(['id', 'name', 'contact_count'])
            ->orderByDesc('id')
            ->get();
        return ApiResponse::success($lists);
    }
}
