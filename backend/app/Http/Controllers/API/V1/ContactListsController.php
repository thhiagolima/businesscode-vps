<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Models\ContactList;
use Illuminate\Http\Request;

class ContactListsController extends Controller
{
    use Concerns\ChecksTenant;

    public function index()
    {
        $items = ContactList::orderBy('id', 'desc')->paginate(50);
        return ApiResponse::paginated($items);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $list = ContactList::create($data);
        return ApiResponse::success($list, 'Lista criada', [], 201);
    }

    public function show(int $id)
    {
        $list = ContactList::findOrFail($id);
        $this->ensureTenantOwns($list);
        return ApiResponse::success($list);
    }

    public function update(Request $request, int $id)
    {
        $list = ContactList::findOrFail($id);
        $this->ensureTenantOwns($list);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $list->update($data);
        return ApiResponse::success($list, 'Lista atualizada');
    }

    public function destroy(int $id)
    {
        $list = ContactList::findOrFail($id);
        $this->ensureTenantOwns($list);
        $list->delete();
        return ApiResponse::success([], 'Lista removida');
    }

    public function refreshCount(int $id)
    {
        $list = ContactList::findOrFail($id);
        $this->ensureTenantOwns($list);
        $count = Contact::where('contact_list_id', $list->id)->count();
        $list->update(['contact_count' => $count]);
        return ApiResponse::success($list, 'Contador atualizado');
    }
}
