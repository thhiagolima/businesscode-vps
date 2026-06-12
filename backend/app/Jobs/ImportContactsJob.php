<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Import;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportContactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $importId,
        public int $tenantId
    ) {}

    public function handle(): void
    {
        $import = Import::withoutGlobalScopes()
            ->where('id', $this->importId)
            ->where('tenant_id', $this->tenantId)
            ->firstOrFail();

        $import->update(['status' => 'processing']);

        $path = storage_path('app/'.$import->file_path);
        if (!is_file($path)) {
            $import->update(['status' => 'failed', 'error_message' => 'Arquivo não encontrado']);
            return;
        }

        $mapping = $import->mapping ?? ['phone' => 'phone', 'name' => 'name', 'email' => 'email'];

        $handle = fopen($path, 'r');
        if (!$handle) {
            $import->update(['status' => 'failed', 'error_message' => 'Falha ao abrir arquivo']);
            return;
        }

        $delimiter = ';';
        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers || count($headers) < 2) {
            rewind($handle);
            $delimiter = ',';
            $headers = fgetcsv($handle, 0, $delimiter);
        }
        if (!$headers || count($headers) < 1) {
            fclose($handle);
            $import->update(['status' => 'failed', 'error_message' => 'CSV vazio']);
            return;
        }

        $headerIndex = [];
        foreach ($headers as $i => $h) {
            $headerIndex[trim(strtolower($h))] = $i;
        }

        $total = 0;
        $processed = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $total++;

            $get = function (string $key) use ($row, $mapping, $headerIndex) {
                $col = $mapping[$key] ?? $key;
                $idx = $headerIndex[trim(strtolower($col))] ?? null;
                return $idx !== null ? trim((string)($row[$idx] ?? '')) : null;
            };

            $phone = $get('phone');
            $name  = $this->sanitizeCell($get('name'));
            $email = $this->sanitizeCell($get('email'));

            if (empty($phone) && empty($email)) {
                continue;
            }

            Contact::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id'       => $this->tenantId,
                    'contact_list_id' => $import->contact_list_id,
                    'phone'           => $phone ?: null,
                ],
                [
                    'name'  => $name ?: null,
                    'email' => $email ?: null,
                    'status'=> 'active',
                ]
            );

            $processed++;
            if ($processed % 200 === 0) {
                $import->update(['processed_rows' => $processed, 'total_rows' => $total]);
            }
        }

        fclose($handle);

        $import->update([
            'status'         => 'completed',
            'processed_rows' => $processed,
            'total_rows'     => $total,
        ]);

        if ($import->contact_list_id) {
            $count = Contact::withoutGlobalScopes()
                ->where('tenant_id', $this->tenantId)
                ->where('contact_list_id', $import->contact_list_id)
                ->count();
            ContactList::withoutGlobalScopes()
                ->where('id', $import->contact_list_id)
                ->update(['contact_count' => $count]);
        }
    }

    private function sanitizeCell(?string $value): ?string
    {
        if ($value === null || $value === '') return $value;
        $value = trim($value);
        if (preg_match('/^[=\+\-\@]/', $value)) {
            $value = "'" . $value;
        }
        return $value;
    }

    public function failed(\Throwable $e): void
    {
        Import::withoutGlobalScopes()
            ->where('id', $this->importId)
            ->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

        Log::channel('campaign')->error('contacts.import.failed', [
            'import_id' => $this->importId,
            'error'     => $e->getMessage(),
        ]);
    }
}
