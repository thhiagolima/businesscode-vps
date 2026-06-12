<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignDispatch;
use App\Models\Contact;
use App\Services\Infobip\InfobipService;
use App\Services\Messaging\EmailDomainService;
use App\Services\Messaging\OptOutService;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\SettingsService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendCampaignBatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300; // 5 min per batch

    public function __construct(
        public int $campaignId,
        public int $tenantId,
        public array $contactIds = [],     // For list-based: array of contact IDs
        public array $adhocPhones = [],    // For adhoc: array of phone strings
    ) {
        $this->onQueue('campaigns');
    }

    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\SetTenantContext($this->tenantId)];
    }

    public function handle(InfobipService $infobip, SettingsService $settings): void
    {
        if ($this->batch()?->cancelled()) return;

        $campaign = Campaign::withoutGlobalScopes()
            ->where('id', $this->campaignId)
            ->where('tenant_id', $this->tenantId)
            ->first();

        if (!$campaign || $campaign->status !== 'running') return;

        $campaignSettings = $campaign->settings ?? [];
        $from = $campaignSettings['from'] ?? $settings->getGlobal('infobip', 'default_sender', 'InfoSMS');
        $fromEmail = $campaignSettings['from_email'] ?? $settings->getGlobal('infobip', 'from_email', '');
        $fromEmailName = $campaignSettings['from_email_name'] ?? $settings->getGlobal('infobip', 'from_email_name', '');
        $audioUrl = $campaign->audio_url ?? null;

        // Email anti-spoofing: only allow sending from a domain authenticated for THIS tenant.
        // Prevents tenant A from using "ceo@bigbank.com" via campaign settings.
        $fromEmailAuthorized = true;
        if ($campaign->type === 'email' && $fromEmail !== '') {
            $fromEmailAuthorized = (bool) app(EmailDomainService::class)
                ->findActiveForEmail($this->tenantId, $fromEmail);
        }

        $sentCount = 0;
        $failedCount = 0;
        $optOuts = app(OptOutService::class);

        // Process adhoc phones
        foreach ($this->adhocPhones as $phone) {
            $phone = trim($phone);
            if (empty($phone)) continue;

            if (!preg_match('/^\+?[1-9]\d{6,14}$/', $phone)) {
                $failedCount++;
                Log::channel('campaign')->warning('dispatch.invalid_phone', [
                    'campaign_id' => $campaign->id,
                    'phone' => $phone,
                ]);
                continue;
            }

            $alreadySent = CampaignDispatch::withoutGlobalScopes()
                ->where('campaign_id', $campaign->id)
                ->where('phone', $phone)
                ->whereIn('status', ['sent', 'delivered'])
                ->exists();
            if ($alreadySent) { $sentCount++; continue; }

            // LGPD compliance: honour opt-out before reaching the provider.
            if ($optOuts->isOptedOut($this->tenantId, (string) $campaign->type, $phone)) {
                $failedCount++;
                $this->recordAdhocDispatch($campaign, $phone, [
                    'ok' => false, 'message_id' => null, 'error' => 'opt_out',
                ]);
                Log::channel('campaign')->info('dispatch.skipped_opt_out', [
                    'campaign_id' => $campaign->id, 'phone' => $phone,
                ]);
                continue;
            }

            // Create virtual contact for sendToContact
            $contact = new Contact(['phone' => $phone, 'name' => null, 'email' => null]);
            $contact->id = null;

            [$result, $destination] = $this->sendToContact(
                $campaign, $contact, $infobip,
                $from, $fromEmail, $fromEmailName, $audioUrl
            );

            $this->recordAdhocDispatch($campaign, $phone, $result);

            if ($result['ok']) {
                $sentCount++;
                Log::channel('campaign')->info('campaign.send.ok', [
                    'campaign_id' => $campaign->id, 'phone' => $phone, 'message_id' => $result['message_id'],
                ]);
            } else {
                $failedCount++;
                Log::channel('campaign')->warning('campaign.send.failed', [
                    'campaign_id' => $campaign->id, 'phone' => $phone, 'error' => $result['error'],
                ]);
            }
        }

        // Process contact IDs
        if (!empty($this->contactIds)) {
            $contacts = Contact::withoutGlobalScopes()
                ->whereIn('id', $this->contactIds)
                ->where('status', 'active')
                ->get();

            foreach ($contacts as $contact) {
                $alreadySent = CampaignDispatch::withoutGlobalScopes()
                    ->where('campaign_id', $campaign->id)
                    ->where('contact_id', $contact->id)
                    ->whereIn('status', ['sent', 'delivered'])
                    ->exists();
                if ($alreadySent) { $sentCount++; continue; }

                // LGPD compliance: honour opt-out per channel.
                // Email channel checks email identifier; all others check phone.
                $identifier = $campaign->type === 'email' ? (string) $contact->email : (string) $contact->phone;
                if ($identifier !== '' && $optOuts->isOptedOut($this->tenantId, (string) $campaign->type, $identifier)) {
                    $failedCount++;
                    $this->recordDispatch($campaign, $contact, $identifier, [
                        'ok' => false, 'message_id' => null, 'error' => 'opt_out',
                    ]);
                    Log::channel('campaign')->info('dispatch.skipped_opt_out', [
                        'campaign_id' => $campaign->id, 'contact_id' => $contact->id,
                    ]);
                    continue;
                }

                // Block email send if from_email domain is not authenticated for this tenant.
                if ($campaign->type === 'email' && ! $fromEmailAuthorized) {
                    $failedCount++;
                    $this->recordDispatch($campaign, $contact, $identifier, [
                        'ok' => false, 'message_id' => null, 'error' => 'unauthorized_from_email',
                    ]);
                    Log::channel('campaign')->warning('dispatch.blocked_unauthorized_from_email', [
                        'campaign_id' => $campaign->id, 'tenant_id' => $this->tenantId, 'from' => $fromEmail,
                    ]);
                    continue;
                }

                // For email channel, generate an unsubscribe token per dispatch
                // so each recipient gets a unique one-shot URL (CAN-SPAM/LGPD compliance).
                $unsubscribeToken = $campaign->type === 'email' ? Str::random(64) : null;

                [$result, $destination] = $this->sendToContact(
                    $campaign, $contact, $infobip,
                    $from, $fromEmail, $fromEmailName, $audioUrl, $unsubscribeToken
                );

                $this->recordDispatch($campaign, $contact, $destination, $result, $unsubscribeToken);

                if ($result['ok']) {
                    $sentCount++;
                    Log::channel('campaign')->info('campaign.send.ok', [
                        'campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'phone' => $destination,
                    ]);
                } else {
                    $failedCount++;
                    Log::channel('campaign')->warning('campaign.send.failed', [
                        'campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'phone' => $destination, 'error' => $result['error'],
                    ]);
                }
            }
        }

        // Atomic update of counts
        Campaign::withoutGlobalScopes()->where('id', $campaign->id)->update([
            'sent_count' => DB::raw("sent_count + {$sentCount}"),
            'failed_count' => DB::raw("failed_count + {$failedCount}"),
        ]);
    }

    /**
     * Chama o método correto do InfobipService conforme o canal da campanha.
     *
     * @return array{array{ok: bool, message_id: string|null, error: string|null}, string}
     */
    private function sendToContact(
        Campaign $campaign,
        Contact $contact,
        InfobipService $infobip,
        string $from,
        string $fromEmail,
        string $fromEmailName,
        ?string $audioUrl,
        ?string $unsubscribeToken = null
    ): array {
        return match ($campaign->type) {
            'sms' => [
                $infobip->sendSms($contact->phone, $campaign->content, $from),
                $contact->phone,
            ],
            'email' => [
                $infobip->sendEmail(
                    $contact->email,
                    $campaign->subject ?? '(sem assunto)',
                    $this->withUnsubscribeFooter($campaign->content, $unsubscribeToken),
                    $fromEmail,
                    $fromEmailName
                ),
                $contact->email,
            ],
            'voice' => [
                $infobip->sendVoice($contact->phone, $campaign->content ?? '', $from, $audioUrl),
                $contact->phone,
            ],
            'whatsapp' => [
                $this->sendWhatsAppTemplate($contact, $campaign, app(WhatsAppService::class)),
                $contact->phone,
            ],
            default => [
                ['ok' => false, 'message_id' => null, 'error' => "Canal '{$campaign->type}' não suportado."],
                '',
            ],
        };
    }

    private function sendWhatsAppTemplate(Contact $contact, Campaign $campaign, WhatsAppService $whatsapp): array
    {
        $settings = $campaign->settings ?? [];
        $templateName = $settings['template_name'] ?? '';
        $language     = $settings['template_language'] ?? 'pt_BR';
        $components   = $settings['components'] ?? [];

        // Substituir variáveis dinâmicas
        $replacements = [
            '{nome}'     => $contact->name ?? '',
            '{telefone}' => $contact->phone ?? '',
            '{email}'    => $contact->email ?? '',
        ];

        $components = json_decode(
            str_replace(array_keys($replacements), array_values($replacements), json_encode($components)),
            true
        );

        $result = $whatsapp->sendTemplate($contact->phone, $templateName, $language, $components, $this->tenantId);

        // Criar/reabrir conversa + registrar mensagem
        if ($result['ok']) {
            $conversation = \App\Models\Conversation::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $this->tenantId, 'phone' => $contact->phone],
                [
                    'contact_id'      => $contact->id,
                    'channel'         => 'whatsapp',
                    'status'          => 'bot',
                    'last_message_at' => now(),
                ]
            );

            \App\Models\ConversationMessage::create([
                'conversation_id'    => $conversation->id,
                'tenant_id'          => $this->tenantId,
                'direction'          => 'outbound',
                'sender_type'        => 'campaign',
                'type'               => 'template',
                'template_name'      => $templateName,
                'content'            => $campaign->content,
                'external_message_id'=> $result['message_id'],
                'status'             => 'sent',
                'created_at'         => now(),
                'sent_at'            => now(),
                'metadata'           => ['campaign_id' => $campaign->id],
            ]);
        }

        return $result;
    }

    /**
     * Cria ou atualiza o registro de CampaignDispatch para o contato.
     */
    private function recordDispatch(
        Campaign $campaign,
        Contact $contact,
        string $destination,
        array $result,
        ?string $unsubscribeToken = null
    ): void {
        $now = now();

        $row = [
            'tenant_id'           => $campaign->tenant_id,
            'status'              => $result['ok'] ? 'sent' : 'failed',
            'phone'               => $destination,
            'message_content'     => $campaign->content,
            'external_message_id' => $result['message_id'],
            'error_message'       => $result['error'],
            'sent_at'             => $result['ok'] ? $now : null,
            'failed_at'           => $result['ok'] ? null : $now,
            'delivered_at'        => null,
            'updated_at'          => $now,
            'created_at'          => $now,
        ];
        if ($unsubscribeToken !== null) {
            $row['unsubscribe_token'] = $unsubscribeToken;
        }

        DB::table('campaign_dispatches')->updateOrInsert(
            [
                'campaign_id' => $campaign->id,
                'contact_id'  => $contact->id,
            ],
            $row
        );
    }

    /**
     * Append an unsubscribe footer to email content. Required by LGPD art. 18
     * and CAN-SPAM for any commercial email.
     */
    private function withUnsubscribeFooter(?string $content, ?string $unsubscribeToken): string
    {
        $content = (string) $content;
        if ($unsubscribeToken === null || $unsubscribeToken === '') {
            return $content;
        }
        $url = rtrim((string) config('app.url'), '/') . '/api/v1/messaging/unsubscribe/' . $unsubscribeToken;
        $footer = '<hr><p style="font-size:12px;color:#666;text-align:center;">'
            . 'Para não receber mais e-mails como este, '
            . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">descadastre-se aqui</a>.'
            . '</p>';
        return $content . $footer;
    }

    private function recordAdhocDispatch(Campaign $campaign, string $phone, array $result): void
    {
        $now = now();
        DB::table('campaign_dispatches')->updateOrInsert(
            [
                'campaign_id' => $campaign->id,
                'phone'       => $phone,
            ],
            [
                'tenant_id'           => $campaign->tenant_id,
                'contact_id'          => null,
                'status'              => $result['ok'] ? 'sent' : 'failed',
                'message_content'     => $campaign->content,
                'external_message_id' => $result['message_id'],
                'error_message'       => $result['error'],
                'sent_at'             => $result['ok'] ? $now : null,
                'failed_at'           => $result['ok'] ? null : $now,
                'delivered_at'        => null,
                'updated_at'          => $now,
                'created_at'          => $now,
            ]
        );
    }
}
