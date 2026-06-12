<?php

namespace App\Services\Messaging;

use App\Models\EmailSenderDomain;
use App\Services\Infobip\InfobipService;
use App\Services\SettingsService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class EmailDomainService
{
    public const MAX_VERIFICATION_ATTEMPTS = 30;

    public function __construct(
        private InfobipService $infobip,
        private SettingsService $settings,
    ) {}

    /**
     * Register a new sender domain on Infobip and persist the returned DNS records.
     *
     * @throws RuntimeException on Infobip error
     */
    public function register(int $tenantId, string $domain, bool $trackOpens, bool $trackClicks, int $userId): EmailSenderDomain
    {
        $domain = mb_strtolower(trim($domain));

        $client = $this->infobip->makeClient();

        // Infobip /email/1/domains POST schema (2026): domainName + targetedDailyTraffic
        // (int, required) + optional dkimKeyLength. Tracking is set via separate PUT.
        $targetedTraffic = (int) $this->settings->getGlobal('infobip', 'email_targeted_daily_traffic', 1000);
        $dkimKeyLength   = (int) $this->settings->getGlobal('infobip', 'email_dkim_key_length', 2048);

        try {
            $resp = $client->post('/email/1/domains', [
                'domainName'           => $domain,
                'targetedDailyTraffic' => $targetedTraffic,
                'dkimKeyLength'        => $dkimKeyLength,
            ]);
        } catch (RequestException $e) {
            $resp = $e->response;
        }

        if (!$resp->successful()) {
            $error = $resp->json('requestError.serviceException.text') ?? $resp->body();
            $error = is_string($error) ? mb_substr($error, 0, 500) : json_encode($error);
            Log::channel('infobip')->warning('email_domain.register.failed', [
                'domain' => $domain, 'status' => $resp->status(), 'error' => $error,
            ]);
            throw new RuntimeException("Falha ao registrar dominio no Infobip: {$error}");
        }

        // Apply tracking preferences via dedicated endpoint (no-op on failure — domain still created).
        try {
            $trackResp = $client->put('/email/1/domains/' . urlencode($domain) . '/tracking', [
                'open'   => $trackOpens,
                'clicks' => $trackClicks,
            ]);
            if (!$trackResp->successful()) {
                Log::channel('infobip')->warning('email_domain.tracking.failed', [
                    'domain' => $domain, 'status' => $trackResp->status(),
                ]);
            }
        } catch (RequestException $e) {
            Log::channel('infobip')->warning('email_domain.tracking.exception', [
                'domain' => $domain, 'error' => $e->getMessage(),
            ]);
        }

        $body = $resp->json() ?? [];
        $records = $this->parseDnsRecords($body['dnsRecords'] ?? []);

        $model = EmailSenderDomain::create([
            'tenant_id'             => $tenantId,
            'domain'                => $domain,
            'infobip_domain_id'     => isset($body['domainId']) ? (string) $body['domainId'] : null,
            'status'                => 'pending',
            'dkim_selector'         => $records['dkim_selector'],
            'dkim_value'            => $records['dkim_value'],
            'spf_value'             => $records['spf_value'],
            'return_path_value'     => $records['return_path_value'],
            'dkim_verified'         => $records['dkim_verified'],
            'spf_verified'          => $records['spf_verified'],
            'return_path_verified'  => $records['return_path_verified'],
            'tracking_opens'        => $trackOpens,
            'tracking_clicks'       => $trackClicks,
            'created_by'            => $userId,
        ]);

        Log::channel('infobip')->info('email_domain.registered', [
            'tenant_id' => $tenantId, 'domain' => $domain, 'infobip_id' => $model->infobip_domain_id,
        ]);

        return $model;
    }

    /**
     * Re-check verification status against Infobip and update the local model.
     *
     * @return array{ok: bool, status: string, dkim: bool, spf: bool, return_path: bool}
     */
    public function verify(EmailSenderDomain $domain): array
    {
        $client = $this->infobip->makeClient();

        try {
            $resp = $client->get('/email/1/domains/' . urlencode($domain->domain));
        } catch (RequestException $e) {
            $resp = $e->response;
        }

        if (!$resp->successful()) {
            $error = $resp->json('requestError.serviceException.text') ?? $resp->body();
            $error = is_string($error) ? mb_substr($error, 0, 500) : json_encode($error);

            $domain->verification_attempts = $domain->verification_attempts + 1;
            $domain->last_verification_error = $error;
            if ($domain->verification_attempts >= self::MAX_VERIFICATION_ATTEMPTS) {
                $domain->status = 'failed';
            }
            $domain->save();

            Log::channel('infobip')->warning('email_domain.verify.failed', [
                'domain' => $domain->domain, 'status' => $resp->status(), 'error' => $error,
            ]);

            return [
                'ok' => false, 'status' => $domain->status,
                'dkim' => $domain->dkim_verified, 'spf' => $domain->spf_verified, 'return_path' => $domain->return_path_verified,
            ];
        }

        $body = $resp->json() ?? [];
        $records = $this->parseDnsRecords($body['dnsRecords'] ?? []);

        $domain->dkim_verified        = $records['dkim_verified'];
        $domain->spf_verified         = $records['spf_verified'];
        $domain->return_path_verified = $records['return_path_verified'];
        $domain->last_verified_at     = now();
        $domain->verification_attempts = $domain->verification_attempts + 1;
        $domain->last_verification_error = null;

        // Re-populate DNS records if missing (e.g. external register)
        if (!$domain->dkim_value && $records['dkim_value']) {
            $domain->dkim_selector = $records['dkim_selector'];
            $domain->dkim_value    = $records['dkim_value'];
        }
        if (!$domain->spf_value && $records['spf_value']) {
            $domain->spf_value = $records['spf_value'];
        }
        if (!$domain->return_path_value && $records['return_path_value']) {
            $domain->return_path_value = $records['return_path_value'];
        }

        $allVerified = $domain->dkim_verified && $domain->spf_verified && $domain->return_path_verified;
        // Infobip may return a top-level `active` boolean — trust ours unless both agree
        if ($allVerified || ($body['active'] ?? false) === true) {
            $domain->status = 'active';
        } elseif ($domain->verification_attempts >= self::MAX_VERIFICATION_ATTEMPTS) {
            $domain->status = 'failed';
        } else {
            $domain->status = 'verifying';
        }

        $domain->save();

        Log::channel('infobip')->info('email_domain.verify.ok', [
            'domain' => $domain->domain, 'status' => $domain->status,
            'dkim' => $domain->dkim_verified, 'spf' => $domain->spf_verified, 'return_path' => $domain->return_path_verified,
        ]);

        return [
            'ok' => true, 'status' => $domain->status,
            'dkim' => $domain->dkim_verified, 'spf' => $domain->spf_verified, 'return_path' => $domain->return_path_verified,
        ];
    }

    /**
     * Pull every email domain from Infobip and upsert locally.
     *
     * Existing rows are updated in place (tenant_id preserved). New rows are
     * created with tenant_id=NULL (pool, awaiting admin assignment).
     *
     * @return array{synced: int}
     * @throws \RuntimeException on HTTP error
     */
    public function syncFromInfobip(): array
    {
        $client = $this->infobip->makeClient();

        $synced = 0;
        $page   = 0;
        // Infobip /email/1/domains: max page size is 20 (default 10). Anything
        // larger returns 400 Bad Request. See infobip.com/docs/api/channels/email/email-domains/get-all-domains
        $size   = 20;
        $maxPages = 1000;

        do {
            try {
                $resp = $client->get('/email/1/domains', ['page' => $page, 'size' => $size]);
            } catch (\Illuminate\Http\Client\RequestException $e) {
                $resp = $e->response;
            }

            if (!$resp->successful()) {
                $error = $resp->json('requestError.serviceException.text') ?? $resp->body();
                $error = is_string($error) ? mb_substr($error, 0, 500) : json_encode($error);
                \Illuminate\Support\Facades\Log::channel('infobip')->warning('email_domain.sync.failed', [
                    'status' => $resp->status(), 'error' => $error,
                ]);
                throw new \RuntimeException("Falha ao listar dominios do Infobip: {$error}");
            }

            $body    = $resp->json() ?? [];
            $results = $body['results'] ?? [];
            $paging  = $body['paging']  ?? null;

            if (empty($results)) {
                break;
            }

            foreach ($results as $entry) {
                $domain = mb_strtolower((string) ($entry['domainName'] ?? ''));
                if ($domain === '') {
                    continue;
                }

                $records = $this->parseDnsRecords($entry['dnsRecords'] ?? []);
                $allVerified = $records['dkim_verified'] && $records['spf_verified'] && $records['return_path_verified'];
                $remoteActive = ($entry['active'] ?? false) === true;

                if ($allVerified || $remoteActive) {
                    $status = 'active';
                } elseif ($records['dkim_verified'] || $records['spf_verified'] || $records['return_path_verified']) {
                    $status = 'verifying';
                } else {
                    $status = 'pending';
                }

                // updateOrCreate by `domain` (now globally unique). tenant_id is
                // ONLY set on create — never overwrite an existing assignment.
                $existing = EmailSenderDomain::withoutGlobalScopes()
                    ->where('domain', $domain)
                    ->first();

                $payload = [
                    'infobip_domain_id'    => isset($entry['domainId']) ? (string) $entry['domainId'] : ($existing->infobip_domain_id ?? null),
                    'status'               => $status,
                    'dkim_selector'        => $records['dkim_selector'] ?? ($existing->dkim_selector ?? null),
                    'dkim_value'           => $records['dkim_value']    ?? ($existing->dkim_value    ?? null),
                    'spf_value'            => $records['spf_value']     ?? ($existing->spf_value     ?? null),
                    'return_path_value'    => $records['return_path_value'] ?? ($existing->return_path_value ?? null),
                    'dkim_verified'        => $records['dkim_verified'],
                    'spf_verified'         => $records['spf_verified'],
                    'return_path_verified' => $records['return_path_verified'],
                    'tracking_opens'       => isset($entry['tracking']['open'])   ? (bool) $entry['tracking']['open']   : ($existing->tracking_opens  ?? false),
                    'tracking_clicks'      => isset($entry['tracking']['clicks']) ? (bool) $entry['tracking']['clicks'] : ($existing->tracking_clicks ?? false),
                    'last_verified_at'     => now(),
                ];

                if ($existing) {
                    $existing->fill($payload)->save();
                } else {
                    EmailSenderDomain::withoutGlobalScopes()->create(array_merge(
                        ['tenant_id' => null, 'domain' => $domain],
                        $payload
                    ));
                }

                $synced++;
            }

            $totalPages = (int) ($paging['totalPages'] ?? 1);
            $page++;
        } while ($paging !== null && $page < $totalPages && $page < $maxPages);

        \Illuminate\Support\Facades\Log::channel('infobip')->info('email_domain.sync.ok', ['count' => $synced]);

        return ['synced' => $synced];
    }

    /**
     * Delete the domain on Infobip and soft-delete locally.
     */
    public function deleteRemote(EmailSenderDomain $domain): bool
    {
        $client = $this->infobip->makeClient();

        try {
            $resp = $client->delete('/email/1/domains/' . urlencode($domain->domain));
            $remoteOk = $resp->successful() || $resp->status() === 404;
        } catch (\Throwable $e) {
            // If infobip is unreachable, still soft-delete locally to avoid lock-in
            Log::channel('infobip')->warning('email_domain.delete.remote_exception', [
                'domain' => $domain->domain, 'error' => $e->getMessage(),
            ]);
            $remoteOk = false;
        }

        $domain->delete();

        Log::channel('infobip')->info('email_domain.deleted', [
            'domain' => $domain->domain, 'remote_ok' => $remoteOk,
        ]);

        return $remoteOk;
    }

    /**
     * Find an active sender domain for the tenant that owns the given email's @-part.
     */
    public function findActiveForEmail(int $tenantId, string $email): ?EmailSenderDomain
    {
        $parts = explode('@', mb_strtolower(trim($email)));
        if (count($parts) !== 2) {
            return null;
        }
        $emailDomain = $parts[1];

        return EmailSenderDomain::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('domain', $emailDomain)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Parse Infobip's dnsRecords array into our 4 fields.
     *
     * Infobip's response shape can vary; we look defensively at type + name patterns.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array{
     *   dkim_selector: string|null, dkim_value: string|null,
     *   spf_value: string|null, return_path_value: string|null,
     *   dkim_verified: bool, spf_verified: bool, return_path_verified: bool
     * }
     */
    private function parseDnsRecords(array $records): array
    {
        $result = [
            'dkim_selector'        => null,
            'dkim_value'           => null,
            'spf_value'            => null,
            'return_path_value'    => null,
            'dkim_verified'        => false,
            'spf_verified'         => false,
            'return_path_verified' => false,
        ];

        foreach ($records as $rec) {
            $type     = mb_strtoupper((string) ($rec['type'] ?? $rec['recordType'] ?? ''));
            $name     = (string) ($rec['name'] ?? $rec['recordName'] ?? '');
            $value    = (string) ($rec['expectedValue'] ?? $rec['value'] ?? $rec['recordValue'] ?? '');
            $verified = (bool)   ($rec['verified'] ?? $rec['status'] ?? false);

            $nameLower = mb_strtolower($name);
            $valueLower = mb_strtolower($value);

            if ($type === 'TXT' && (str_contains($nameLower, '_domainkey') || str_contains($nameLower, 'dkim'))) {
                // DKIM: name like "selector._domainkey.example.com"
                $result['dkim_value']    = $value;
                $result['dkim_verified'] = $verified;

                // Extract selector from name
                if (preg_match('/^([^.]+)\._domainkey\./i', $name, $m)) {
                    $result['dkim_selector'] = $m[1];
                } elseif (preg_match('/^([^.]+)/', $name, $m) && $result['dkim_selector'] === null) {
                    $result['dkim_selector'] = $m[1];
                }
            } elseif ($type === 'TXT' && (str_starts_with($valueLower, 'v=spf') || str_contains($valueLower, 'include:'))) {
                $result['spf_value']    = $value;
                $result['spf_verified'] = $verified;
            } elseif ($type === 'CNAME') {
                // return-path / bounce CNAME
                $result['return_path_value']    = $value;
                $result['return_path_verified'] = $verified;
            }
        }

        return $result;
    }
}
