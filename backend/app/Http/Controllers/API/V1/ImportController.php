<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\ImportContactsJob;
use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImportController extends Controller
{
    public function upload(Request $request)
    {
        $tenantId = auth()->user()->tenant_id;
        $ruleExists = \Illuminate\Validation\Rule::exists('contact_lists', 'id')
            ->where('tenant_id', $tenantId);

        $data = $request->validate([
            'file'             => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'contact_list_id'  => ['nullable', 'integer', $ruleExists],
            'mapping'          => ['nullable', 'array'],
            'mapping.*'        => ['string', 'in:phone,name,email,ignore'],
        ]);

        // Check first line for formula injection
        $firstLine = fgets(fopen($request->file('file')->getRealPath(), 'r'));
        if ($firstLine && preg_match('/^[=\+\-\@]/', trim($firstLine))) {
            return ApiResponse::error('Arquivo contém conteúdo potencialmente inseguro', [], 422);
        }

        $path = $request->file('file')->store('imports');

        $import = Import::create([
            'file_path'       => $path,
            'status'          => 'pending',
            'contact_list_id' => $data['contact_list_id'] ?? null,
            'mapping'         => $data['mapping'] ?? ['phone' => 'phone', 'name' => 'name', 'email' => 'email'],
        ]);

        ImportContactsJob::dispatch($import->id, auth()->user()->tenant_id);

        return ApiResponse::success(['import_id' => $import->id], 'Importação iniciada', [], 201);
    }

    public function status(int $id)
    {
        $import = Import::findOrFail($id);
        if ($import->tenant_id !== auth()->user()->tenant_id && auth()->user()->role !== 'superadmin') {
            abort(403, 'Acesso negado');
        }
        return ApiResponse::success([
            'id'             => $import->id,
            'status'         => $import->status,
            'total_rows'     => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'error_message'  => $import->error_message,
        ], 'OK');
    }
}
