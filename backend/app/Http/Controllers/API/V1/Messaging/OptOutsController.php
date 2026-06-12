<?php

namespace App\Http\Controllers\API\V1\Messaging;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\CreateOptOutRequest;
use App\Models\AuditLog;
use App\Models\MessageOptOut;
use App\Services\Messaging\OptOutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OptOutsController extends Controller
{
    public function __construct(private OptOutService $optOuts) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['nullable', 'in:sms,voice,email,whatsapp,all'],
            'search'  => ['nullable', 'string', 'max:255'],
            'page'    => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $q = MessageOptOut::where('tenant_id', $request->user()->tenant_id);

        if (!empty($validated['channel'])) {
            $q->where('channel', $validated['channel']);
        }
        if (!empty($validated['search'])) {
            $needle = mb_strtolower(trim($validated['search']));
            // Identifier é armazenado normalizado em lowercase; busca direta.
            $q->where('identifier', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $needle) . '%');
        }

        $perPage = (int) ($validated['per_page'] ?? 30);
        $paginator = $q->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(CreateOptOutRequest $request): JsonResponse
    {
        $entry = $this->optOuts->add(
            $request->user()->tenant_id,
            $request->validated('channel'),
            $request->validated('identifier'),
            $request->validated('reason')
        );

        AuditLog::record('optout.created', 'MessageOptOut', $entry->id, [
            'channel' => $entry->channel,
        ]);

        return response()->json(['data' => $entry], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $entry = MessageOptOut::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $this->optOuts->remove($entry->tenant_id, $entry->channel, $entry->identifier);

        AuditLog::record('optout.removed', 'MessageOptOut', $entry->id, [
            'channel' => $entry->channel,
        ]);

        return response()->json(null, 204);
    }

    /**
     * Importa em massa um CSV com colunas (em qualquer ordem):
     *   channel,identifier,reason
     * Linhas inválidas são reportadas mas não bloqueiam o restante.
     */
    /**
     * Cap de segurança: número máximo de linhas que processamos por upload.
     * Acima disso, a importação é rejeitada com 422 — força o cliente a
     * dividir o arquivo. Evita DoS por upload gigante + consumo de memória.
     */
    private const MAX_IMPORT_LINES = 50_000;

    /**
     * Sanitiza valores que serão reaproveitados em export CSV para mitigar
     * CSV injection (CWE-1236). Se o valor começar com =, +, -, @, TAB ou CR,
     * o Excel/Calc interpretam como fórmula. Prefixamos uma apóstrofe.
     */
    private function safeCsvCell(string $v): string
    {
        if ($v === '') return $v;
        if (in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $v;
        }
        return $v;
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file'   => ['required', 'file', 'mimes:csv,txt', 'max:5120'], // 5MB
            'reason' => ['nullable', 'string', 'max:50'],
        ]);

        $defaultReason = (string) $request->input('reason', 'bulk_import');
        $allowedChannels = ['sms', 'voice', 'email', 'whatsapp', 'all'];

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if (! $handle) {
            return response()->json(['message' => 'Não foi possível ler o arquivo.'], 422);
        }

        $header = null;
        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $line     = 0;
        $tooLarge = false;

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $line++;
            if ($line > self::MAX_IMPORT_LINES) {
                $tooLarge = true;
                break;
            }
            if ($line === 1) {
                $header = array_map(fn($h) => mb_strtolower(trim($h)), $row);
                continue;
            }
            $assoc = $header ? array_combine($header, $row) : [];
            $channel    = mb_strtolower(trim((string) ($assoc['channel'] ?? '')));
            $identifier = trim((string) ($assoc['identifier'] ?? ''));
            $reason     = trim((string) ($assoc['reason'] ?? $defaultReason));

            if (! in_array($channel, $allowedChannels, true) || $identifier === '') {
                $skipped++;
                if (count($errors) < 20) {
                    $errors[] = "Linha {$line}: canal ou identificador inválido.";
                }
                continue;
            }

            // Identifier vai pro DB normalizado pelo OptOutService::add — não
            // precisa de escape adicional. Só limitamos comprimento para evitar
            // payload muito longo (max 255 já é validado pelo banco).
            if (mb_strlen($identifier) > 255) {
                $skipped++;
                if (count($errors) < 20) {
                    $errors[] = "Linha {$line}: identificador acima de 255 caracteres.";
                }
                continue;
            }

            $this->optOuts->add(
                $request->user()->tenant_id,
                $channel,
                $identifier,
                mb_substr($reason ?: $defaultReason, 0, 50),
            );
            $imported++;
        }
        fclose($handle);

        AuditLog::record('optout.bulk_import', 'MessageOptOut', null, [
            'imported' => $imported, 'skipped' => $skipped, 'truncated' => $tooLarge,
        ]);

        if ($tooLarge) {
            return response()->json([
                'message'  => 'Arquivo excede o limite de ' . self::MAX_IMPORT_LINES . ' linhas. Divida o arquivo em lotes menores.',
                'imported' => $imported,
                'skipped'  => $skipped,
                'errors'   => $errors,
            ], 422);
        }

        return response()->json([
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $tenantId = $request->user()->tenant_id;

        return response()->stream(function () use ($tenantId) {
            $handle = fopen('php://output', 'w');
            // BOM para abrir no Excel BR.
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, ['channel', 'identifier', 'reason', 'created_at'], ',');

            MessageOptOut::where('tenant_id', $tenantId)
                ->orderBy('id')
                ->chunk(500, function ($rows) use ($handle) {
                    foreach ($rows as $r) {
                        // P0R-09: CSV injection mitigation — prefix risky cells
                        // (=, +, -, @, TAB, CR) so Excel/Calc não interpretem
                        // como fórmula (CWE-1236).
                        fputcsv($handle, [
                            $this->safeCsvCell((string) $r->channel),
                            $this->safeCsvCell((string) $r->identifier),
                            $this->safeCsvCell((string) $r->reason),
                            optional($r->created_at)->toIso8601String(),
                        ], ',');
                    }
                });

            fclose($handle);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="opt-outs.csv"',
            'Cache-Control'       => 'no-cache',
        ]);
    }
}
