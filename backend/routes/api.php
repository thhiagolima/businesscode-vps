<?php

use App\Http\Controllers\API\V1\AuthController;
use App\Http\Controllers\API\V1\DashboardController;
use App\Http\Controllers\API\V1\ContactListsController;
use App\Http\Controllers\API\V1\ContactsController;
use App\Http\Controllers\API\V1\ImportController;
use App\Http\Controllers\API\V1\CampaignsController;
use App\Http\Controllers\API\V1\Admin\InfobipSettingsController;
use App\Http\Controllers\API\V1\Admin\AiSettingsController;
use App\Http\Controllers\API\V1\Admin\ElevenLabsSettingsController;
use App\Http\Controllers\API\V1\Admin\InfobipEmailController;
use App\Http\Controllers\API\V1\Admin\PlansController;
use App\Http\Controllers\API\V1\Admin\TenantsController;
use App\Http\Controllers\API\V1\Admin\TenantOverviewController;
use App\Http\Controllers\API\V1\Admin\TenantCampaignsController;
use App\Http\Controllers\API\V1\Admin\TenantContactsController;
use App\Http\Controllers\API\V1\Admin\TenantConversationsController;
use App\Http\Controllers\API\V1\Admin\TenantFunnelsController;
use App\Http\Controllers\API\V1\Admin\TenantUsersController;
use App\Http\Controllers\API\V1\Admin\TenantReportsController;
use App\Http\Controllers\API\V1\Admin\TenantAuditController;
use App\Http\Controllers\API\V1\Admin\TenantApiTokensController;
use App\Http\Controllers\API\V1\AiController;
use App\Http\Controllers\API\V1\AiGeneratorController;
use App\Http\Controllers\API\V1\AiContentModelController;
use App\Http\Controllers\API\V1\AudioGenerationController;
use App\Http\Controllers\API\V1\ReportController;
use App\Http\Controllers\API\V1\WhatsAppController;
use App\Http\Controllers\API\V1\WhatsAppWebhookController;
use App\Http\Controllers\API\V1\ConversationController;
use App\Http\Controllers\API\V1\Admin\WhatsAppSettingsController;
use App\Http\Controllers\API\V1\ChatbotController;
use App\Http\Controllers\API\V1\FunnelController;
use App\Http\Controllers\API\V1\WebhookController;
use App\Http\Controllers\API\V1\Admin\AiPersonaAdminController;
use App\Http\Controllers\API\V1\Admin\InfobipWhatsAppController;
use App\Http\Controllers\API\V1\Admin\TenantChannelsController;
use App\Http\Controllers\API\V1\InfobipWhatsAppWebhookController;
use App\Http\Controllers\API\V1\ChannelsController;
use App\Http\Controllers\API\V1\CheckoutController;
use App\Http\Controllers\API\V1\SubscriptionController;
use App\Http\Controllers\API\V1\PaymentController;
use App\Http\Controllers\API\V1\PublicPlansController;
use App\Http\Controllers\API\V1\Admin\CouponController;
use App\Http\Controllers\API\V1\Messaging\SmsController;
use App\Http\Controllers\API\V1\Messaging\VoiceController;
use App\Http\Controllers\API\V1\Messaging\EmailController;
use App\Http\Controllers\API\V1\EmailDomainsController;
use App\Http\Controllers\API\V1\Messaging\DispatchesController as MessagingDispatchesController;
use App\Http\Controllers\API\V1\Messaging\OptOutsController as MessagingOptOutsController;
use App\Http\Controllers\API\V1\Messaging\UnsubscribeController;
use App\Http\Controllers\API\V1\ApiTokensController;
use App\Http\Controllers\API\V1\InboundWebhookController;
use App\Http\Controllers\API\V1\Admin\MessagingAdminController;
use App\Http\Controllers\API\V1\Admin\ServicePricingController;
use App\Http\Controllers\API\V1\Admin\TenantPricingController;
use App\Http\Controllers\API\V1\Admin\TenantCreditLineController;
use App\Http\Controllers\API\V1\Admin\BillingReportController;
use App\Http\Controllers\API\V1\WebhooksController;
use App\Http\Controllers\API\V1\AccountController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Públicas
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:3,1');
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
    Route::get('auth/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])->middleware('throttle:20,1')->name('verification.verify');

    // Public auth configuration (captcha site key etc.)
    Route::get('auth/config', function () {
        return [
            'turnstile_site_key' => config('services.turnstile.site_key'),
            'require_email_verification' => (bool) config('business.require_email_verification'),
            'terms_version' => config('business.terms_version'),
            'privacy_version' => config('business.privacy_version'),
        ];
    })->middleware('throttle:60,1');

    // Webhooks externos (sem autenticação, validação por secret interno)
    Route::post('webhooks/infobip/delivery', [WebhookController::class, 'infobipDelivery'])
        ->withoutMiddleware(['auth:sanctum']);

    // Mesmo handler, mas com o secret no path. O notifyUrl por-mensagem da Infobip
    // não envia header de auth, então esta é a forma usada pelo notifyUrl.
    Route::post('webhooks/infobip/delivery/{token}', [WebhookController::class, 'infobipDelivery'])
        ->withoutMiddleware(['auth:sanctum'])
        ->middleware('throttle:webhook');

    // Email tracking (OPENED / CLICKED / BOUNCED / COMPLAINT / UNSUBSCRIBE).
    // Configure no painel Infobip em "Email" → "Tracking" → "Webhook URL".
    Route::post('webhooks/infobip/email-events', [WebhookController::class, 'infobipEmailEvents'])
        ->withoutMiddleware(['auth:sanctum'])
        ->middleware('throttle:webhook');

    Route::post('webhooks/infobip/email-events/{token}', [WebhookController::class, 'infobipEmailEvents'])
        ->withoutMiddleware(['auth:sanctum'])
        ->middleware('throttle:webhook');

    // WhatsApp webhooks (Meta Cloud API)
    Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])
        ->withoutMiddleware(['auth:sanctum'])
        ->middleware('throttle:webhook');
    Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle'])
        ->withoutMiddleware(['auth:sanctum'])
        ->middleware('throttle:webhook');

    // Infobip WhatsApp webhook (endpoint separado)
    Route::post('webhooks/infobip/whatsapp', [InfobipWhatsAppWebhookController::class, 'handle'])
        ->withoutMiddleware(['auth:sanctum'])
        ->middleware('throttle:webhook');

    // Checkout public routes
    Route::post('/checkout/validate-coupon', [CheckoutController::class, 'validateCoupon'])->middleware('throttle:10,1');
    Route::get('/checkout/config', [CheckoutController::class, 'config'])->middleware('throttle:60,1');

    // Public plans listing (used by landing + pricing SPA)
    Route::get('/plans', [PublicPlansController::class, 'index'])->middleware('throttle:60,1');

    // Public business identity (CNPJ, WhatsApp, company name) for landing / footer
    Route::get('/public/identity', [PublicPlansController::class, 'identity'])->middleware('throttle:60,1');

    // Public marketing stats (real data from DB)
    Route::get('/public/stats', [PublicPlansController::class, 'stats'])->middleware('throttle:60,1');

    // Public per-channel pricing in BRL (sale prices only — used by landing calculator)
    Route::get('/public/pricing', [PublicPlansController::class, 'pricing'])->middleware('throttle:60,1');

    // Mercado Pago webhook
    Route::post('/webhooks/mercadopago', [WebhookController::class, 'mercadopago'])->middleware('throttle:100,1');

    // Inbound webhook (validated by secret, no Sanctum)
    Route::post('webhooks/infobip/inbound', [InboundWebhookController::class, 'handle'])
        ->withoutMiddleware(['auth:sanctum'])
        ->middleware('throttle:webhook');

    // Public unsubscribe (token-validated)
    Route::get('messaging/unsubscribe/{token}', UnsubscribeController::class)
        ->middleware('throttle:60,1');

    // Protegidas
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::put('auth/profile', [AuthController::class, 'updateProfile']);
        Route::put('auth/password', [AuthController::class, 'changePassword']);
        Route::post('auth/resend-verification', [AuthController::class, 'resendVerification'])->middleware('throttle:3,1');

        Route::get('dashboard/stats', [DashboardController::class, 'stats']);

        // Relatórios
        Route::prefix('reports')->group(function () {
            Route::get('campaigns',              [ReportController::class, 'campaigns']);
            Route::get('campaigns/{id}',         [ReportController::class, 'campaign']);
            Route::get('campaigns/{id}/export',  [ReportController::class, 'export']);
            Route::get('credits',                [ReportController::class, 'credits']);
        });

        // Audit log (tenant pode consultar histórico das próprias operações; admin only).
        // Throttle agressivo para mitigar DoS/scraping/blind enumeration via LIKE.
        Route::get('audit-log', [\App\Http\Controllers\API\V1\AuditLogController::class, 'index'])
            ->middleware('throttle:30,1');

        // Contatos
        Route::apiResource('contact-lists', ContactListsController::class);
        Route::post('contacts/batch', [ContactsController::class, 'batch'])->middleware('throttle:10,1');
        Route::apiResource('contacts', ContactsController::class)->except(['show']);
        Route::post('contacts/import', [ImportController::class, 'upload']);
        Route::get('contacts/import/{id}', [ImportController::class, 'status']);

        // Campanhas
        Route::apiResource('campaigns', CampaignsController::class);
        Route::post('campaigns/{id}/send-now',   [CampaignsController::class, 'sendNow'])->middleware('throttle:campaign-dispatch');
        Route::post('campaigns/{id}/schedule',   [CampaignsController::class, 'schedule']);
        Route::post('campaigns/{id}/cancel',     [CampaignsController::class, 'cancel']);
        Route::post('campaigns/{id}/reset',      [CampaignsController::class, 'reset']);
        Route::get('campaigns/{id}/dispatches',  [CampaignsController::class, 'dispatches']);

        // IA
        Route::prefix('ai')->group(function () {
            // Novo gerador (Fase 5)
            Route::post('generate', [AiGeneratorController::class, 'generate'])->middleware('throttle:ai');
            Route::post('analyze', [AiGeneratorController::class, 'analyze'])->middleware('throttle:ai');
            Route::get('prompts', [AiGeneratorController::class, 'prompts'])->middleware('superadmin');
            Route::get('generations', [AiGeneratorController::class, 'history']);
            // Legacy (mantido por compatibilidade)
            Route::get('sessions', [AiController::class, 'sessions']);
        });

        // Modelos salvos
        Route::get('ai/models',         [AiContentModelController::class, 'index']);
        Route::post('ai/models',        [AiContentModelController::class, 'store']);
        Route::get('ai/models/{id}',    [AiContentModelController::class, 'show']);
        Route::delete('ai/models/{id}', [AiContentModelController::class, 'destroy']);

        // Áudio TTS
        Route::get('voices',                 [AudioGenerationController::class, 'voices']);
        Route::post('campaigns/{id}/audio',  [AudioGenerationController::class, 'generate'])->middleware(['throttle:audio', 'log.voice']);
        Route::get('campaigns/{id}/audio',   [AudioGenerationController::class, 'index']);
        Route::get('audio/{id}',             [AudioGenerationController::class, 'show']);
        Route::get('audio/{id}/refresh-url',[AudioGenerationController::class, 'refreshUrl']);

        // Sessões IA por campanha
        Route::get('campaigns/{id}/ai-sessions', [CampaignsController::class, 'aiSessions']);

        // WhatsApp
        Route::get('whatsapp/templates',        [WhatsAppController::class, 'templates']);
        Route::get('whatsapp/templates/{name}', [WhatsAppController::class, 'templatePreview']);
        Route::post('whatsapp/send-text',       [WhatsAppController::class, 'sendText'])->middleware('throttle:30,1');

        // Messaging — direct send (token abilities + per-channel throttle)
        Route::post('messaging/sms',   [SmsController::class,   'store'])
            ->middleware(['token.ability:messaging:sms',   'throttle:messaging-sms']);
        Route::post('messaging/voice', [VoiceController::class, 'store'])
            ->middleware(['token.ability:messaging:voice', 'throttle:messaging-voice', 'log.voice']);
        Route::post('messaging/email', [EmailController::class, 'store'])
            ->middleware(['token.ability:messaging:email', 'throttle:messaging-email']);

        // Dispatches read
        Route::get('messaging/dispatches',       [MessagingDispatchesController::class, 'index'])
            ->middleware('token.ability:messaging:read');
        Route::get('messaging/dispatches/{id}',  [MessagingDispatchesController::class, 'show'])
            ->middleware('token.ability:messaging:read');

        // Opt-outs (read = messaging:read; write = messaging:*)
        Route::get('messaging/opt-outs',           [MessagingOptOutsController::class, 'index'])
            ->middleware('token.ability:messaging:read');
        Route::get('messaging/opt-outs/export',    [MessagingOptOutsController::class, 'export'])
            ->middleware('token.ability:messaging:read');
        Route::post('messaging/opt-outs',          [MessagingOptOutsController::class, 'store'])
            ->middleware('token.ability:messaging:*');
        Route::post('messaging/opt-outs/import',   [MessagingOptOutsController::class, 'import'])
            ->middleware('token.ability:messaging:*');
        Route::delete('messaging/opt-outs/{id}',   [MessagingOptOutsController::class, 'destroy'])
            ->middleware('token.ability:messaging:*');

        // Email sender domains (per-tenant DNS-authenticated domains)
        Route::get('email-domains',              [EmailDomainsController::class, 'index']);
        Route::post('email-domains',             [EmailDomainsController::class, 'store']);
        Route::get('email-domains/{id}',         [EmailDomainsController::class, 'show']);
        Route::delete('email-domains/{id}',      [EmailDomainsController::class, 'destroy']);
        Route::post('email-domains/{id}/verify', [EmailDomainsController::class, 'verify']);

        // API tokens (server-to-server)
        Route::get('auth/api-tokens',         [ApiTokensController::class, 'index']);
        Route::post('auth/api-tokens',        [ApiTokensController::class, 'store']);
        Route::delete('auth/api-tokens/{id}', [ApiTokensController::class, 'destroy']);

        // Outbound webhooks (user-facing CRUD + test + deliveries)
        Route::get('webhooks/events', fn () => response()->json(['data' => \App\Support\WebhookEvents::ALL]));
        Route::get('webhooks/outbound',                       [WebhooksController::class, 'index']);
        Route::post('webhooks/outbound',                      [WebhooksController::class, 'store']);
        Route::get('webhooks/outbound/{id}',                  [WebhooksController::class, 'show']);
        Route::put('webhooks/outbound/{id}',                  [WebhooksController::class, 'update']);
        Route::delete('webhooks/outbound/{id}',               [WebhooksController::class, 'destroy']);
        Route::post('webhooks/outbound/{id}/test',            [WebhooksController::class, 'test']);
        Route::get('webhooks/outbound/{id}/deliveries',       [WebhooksController::class, 'deliveries']);

        // Account API (balance + pricing)
        Route::get('account/balance', [AccountController::class, 'balance']);
        Route::get('account/pricing', [AccountController::class, 'pricing']);

        // Conversas
        Route::get('conversations/unread-count', [ConversationController::class, 'unreadCount']);
        Route::get('conversations',               [ConversationController::class, 'index']);
        Route::get('conversations/{id}',          [ConversationController::class, 'show']);
        Route::post('conversations/{id}/messages', [ConversationController::class, 'sendMessage'])->middleware('throttle:30,1');
        Route::patch('conversations/{id}/status',  [ConversationController::class, 'updateStatus']);

        // Chatbot
        Route::get('chatbot/persona',     [ChatbotController::class, 'persona']);
        Route::put('chatbot/persona',     [ChatbotController::class, 'updatePersona']);
        Route::get('chatbot/settings',    [ChatbotController::class, 'settings']);
        Route::put('chatbot/settings',    [ChatbotController::class, 'updateSettings']);

        // Tenant channels (own channels)
        Route::get('channels', [ChannelsController::class, 'myChannels']);

        // Subscriptions
        Route::post('/subscriptions', [SubscriptionController::class, 'store']);
        Route::get('/subscriptions/current', [SubscriptionController::class, 'current']);
        Route::post('/subscriptions/cancel', [SubscriptionController::class, 'cancel']);

        // Payments
        Route::post('/payments/pix', [PaymentController::class, 'pix']);
        Route::post('/payments/boleto', [PaymentController::class, 'boleto']);
        Route::post('/payments/credits', [PaymentController::class, 'credits']);
        Route::get('/payments', [PaymentController::class, 'index']);
        Route::get('/payments/{id}/status', [PaymentController::class, 'status']);

        // Funis
        Route::post('funnels/import',            [FunnelController::class, 'import']);
        Route::get('funnels',                    [FunnelController::class, 'index']);
        Route::post('funnels',                   [FunnelController::class, 'store']);
        Route::get('funnels/{id}',               [FunnelController::class, 'show']);
        Route::put('funnels/{id}',               [FunnelController::class, 'update']);
        Route::delete('funnels/{id}',            [FunnelController::class, 'destroy']);
        Route::put('funnels/{id}/canvas',        [FunnelController::class, 'saveCanvas']);
        Route::post('funnels/{id}/activate',     [FunnelController::class, 'activate']);
        Route::post('funnels/{id}/pause',        [FunnelController::class, 'pause']);
        Route::post('funnels/{id}/enroll',       [FunnelController::class, 'enroll'])->middleware('throttle:10,1');
        Route::post('funnels/{id}/duplicate',    [FunnelController::class, 'duplicate']);
        Route::get('funnels/{id}/executions',    [FunnelController::class, 'executions']);
        Route::get('funnels/{id}/export',        [FunnelController::class, 'export']);

        // Admin (superadmin)
        Route::middleware('superadmin')->prefix('admin')->group(function () {
            // Settings
            Route::get('settings/infobip', [InfobipSettingsController::class, 'index']);
            Route::put('settings/infobip', [InfobipSettingsController::class, 'update']);
            Route::post('settings/infobip/test', [InfobipSettingsController::class, 'test']);
            Route::get('settings/ai', [AiSettingsController::class, 'index']);
            Route::put('settings/ai', [AiSettingsController::class, 'update']);
            Route::post('settings/ai/test', [AiSettingsController::class, 'test']);
            Route::get('settings/ai/models', [AiSettingsController::class, 'models']);
            Route::get('settings/elevenlabs', [ElevenLabsSettingsController::class, 'index']);
            Route::put('settings/elevenlabs', [ElevenLabsSettingsController::class, 'update']);
            Route::post('settings/elevenlabs/test', [ElevenLabsSettingsController::class, 'test']);
            Route::get('elevenlabs/voices', [ElevenLabsSettingsController::class, 'voices']);
            Route::post('elevenlabs/sync-voices', [ElevenLabsSettingsController::class, 'syncVoices']);

            Route::get('settings/whatsapp', [WhatsAppSettingsController::class, 'index']);
            Route::put('settings/whatsapp', [WhatsAppSettingsController::class, 'update']);
            Route::post('settings/whatsapp/test', [WhatsAppSettingsController::class, 'test']);
            Route::post('settings/whatsapp/sync-templates', [WhatsAppSettingsController::class, 'syncTemplates']);

            // Infobip WhatsApp Numbers
            Route::get('infobip-whatsapp/numbers',               [InfobipWhatsAppController::class, 'numbers']);
            Route::post('infobip-whatsapp/sync',                 [InfobipWhatsAppController::class, 'sync']);
            Route::put('infobip-whatsapp/numbers/{id}/assign',   [InfobipWhatsAppController::class, 'assign']);

            // Infobip Email Domains
            Route::get('infobip-email/domains',               [InfobipEmailController::class, 'domains']);
            Route::post('infobip-email/sync',                 [InfobipEmailController::class, 'sync']);
            Route::put('infobip-email/domains/{id}/assign',   [InfobipEmailController::class, 'assign']);

            // AI Personas
            Route::get('ai-personas',              [AiPersonaAdminController::class, 'index']);
            Route::get('ai-personas/{id}',         [AiPersonaAdminController::class, 'show']);
            Route::patch('ai-personas/{id}/approve',[AiPersonaAdminController::class, 'approve']);
            Route::patch('ai-personas/{id}/reject', [AiPersonaAdminController::class, 'reject']);

            // Plans
            Route::get('plans', [PlansController::class, 'index']);
            Route::post('plans', [PlansController::class, 'store']);
            Route::put('plans/{id}', [PlansController::class, 'update']);
            Route::delete('plans/{id}', [PlansController::class, 'destroy']);

            // Tenants
            Route::get('tenants', [TenantsController::class, 'index']);
            Route::get('tenants/{id}', [TenantsController::class, 'show']);
            Route::post('tenants', [TenantsController::class, 'store']);
            Route::put('tenants/{id}', [TenantsController::class, 'update']);
            Route::post('tenants/{id}/credits', [TenantsController::class, 'addCredits']);
            Route::delete('tenants/{id}', [TenantsController::class, 'destroy']);

            // Tenant Channels
            Route::get('tenants/{tenantId}/channels', [TenantChannelsController::class, 'index']);
            Route::put('tenants/{tenantId}/channels/{channel}', [TenantChannelsController::class, 'update']);

            // Coupons (admin)
            Route::get('coupons', [CouponController::class, 'index']);
            Route::post('coupons', [CouponController::class, 'store']);
            Route::put('coupons/{id}', [CouponController::class, 'update']);
            Route::delete('coupons/{id}', [CouponController::class, 'destroy']);

            // Messaging admin
            Route::get('messaging/pricing',  [MessagingAdminController::class, 'getPricing']);
            Route::put('messaging/pricing',  [MessagingAdminController::class, 'updatePricing']);
            Route::get('messaging/stats',    [MessagingAdminController::class, 'stats']);

            // Drill-down individual por tenant
            Route::prefix('tenants/{tenant}')->group(function () {
                Route::get('overview', [TenantOverviewController::class, 'index']);

                Route::get   ('campaigns',      [TenantCampaignsController::class, 'index']);
                Route::get   ('campaigns/{id}', [TenantCampaignsController::class, 'show']);
                Route::patch ('campaigns/{id}', [TenantCampaignsController::class, 'update']);
                Route::delete('campaigns/{id}', [TenantCampaignsController::class, 'destroy']);

                Route::get   ('contacts',          [TenantContactsController::class, 'index']);
                Route::post  ('contacts',          [TenantContactsController::class, 'store']);
                Route::put   ('contacts/{id}',     [TenantContactsController::class, 'update']);
                Route::delete('contacts/{id}',     [TenantContactsController::class, 'destroy']);
                Route::get   ('contact-lists',     [TenantContactsController::class, 'lists']);

                Route::get  ('conversations',      [TenantConversationsController::class, 'index']);
                Route::patch('conversations/{id}', [TenantConversationsController::class, 'update']);

                Route::get   ('funnels',      [TenantFunnelsController::class, 'index']);
                Route::patch ('funnels/{id}', [TenantFunnelsController::class, 'update']);
                Route::delete('funnels/{id}', [TenantFunnelsController::class, 'destroy']);

                Route::get   ('users',                     [TenantUsersController::class, 'index']);
                Route::post  ('users',                     [TenantUsersController::class, 'store']);
                Route::put   ('users/{id}',                [TenantUsersController::class, 'update']);
                Route::post  ('users/{id}/reset-password', [TenantUsersController::class, 'resetPassword']);
                Route::delete('users/{id}',                [TenantUsersController::class, 'destroy']);

                Route::get   ('api-tokens',      [TenantApiTokensController::class, 'index']);
                Route::post  ('api-tokens',      [TenantApiTokensController::class, 'store']);
                Route::delete('api-tokens/{id}', [TenantApiTokensController::class, 'destroy']);

                Route::get('reports', [TenantReportsController::class, 'index']);
                Route::get('audit',   [TenantAuditController::class,   'index']);
            });
        });

        // Admin Billing (finance role + superadmin via EnsureRole wildcard)
        Route::middleware('role:finance')->prefix('admin/billing')->group(function () {
            Route::get('pricing',                          [ServicePricingController::class, 'index']);
            Route::put('pricing/{service}',                [ServicePricingController::class, 'update']);
            Route::get('tenants/{tenant}/pricing',         [TenantPricingController::class, 'index']);
            Route::post('tenants/{tenant}/pricing',        [TenantPricingController::class, 'upsert']);
            Route::patch('tenants/{tenant}/credit-limit',  [TenantCreditLineController::class, 'setLimit']);
            Route::post('tenants/{tenant}/adjust',         [TenantCreditLineController::class, 'adjust']);
            Route::post('tenants/{tenant}/unlock',         [TenantCreditLineController::class, 'unlock']);
            Route::get('stats',                            [BillingReportController::class, 'stats']);
        });
    });
});

