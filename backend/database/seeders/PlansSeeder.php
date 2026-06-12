<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Planos comerciais — revisão 30/05/2026.
 *
 * Margem garantida positiva em todos os cenários (uso 0% / 50% mix / 100% mix
 * / 100% SMS pior caso). Cálculos validados em PricingPrecisionTest.
 *
 *  Plano     | Mensal | Saldo | Margem mix médio | Margem pior SMS
 *  Free      | R$ 0   | R$ 5  | CAC -R$ 3,33     | CAC -R$ 3,78
 *  Starter   | R$ 89  | R$100 | +R$ 22,40 (25%)  | +R$ 13,38 (15%)
 *  Pro       | R$ 219 | R$250 | +R$ 52,50 (24%)  | +R$ 29,94 (14%)
 *  Business  | R$ 699 | R$800 | +R$166,20 (24%)  | +R$ 94,00 (13%)
 *
 * Mix realista: 50% Email + 30% SMS + 20% Voz (custo BC = 66,6% do saldo).
 * Premissas detalhadas: docs/superpowers/plans/2026-05-30-pricing-revision.md (a criar).
 *
 * WhatsApp habilitado em TODOS os planos:
 *  - whatsapp_setup_fee_cents: cobrado once no onboarding WABA
 *  - whatsapp_billed_separately=true: mensagens não consomem saldo do plano
 */
class PlansSeeder extends Seeder
{
    public function run(): void
    {
        // ── Free — aquisição, saldo recorrente mensal R$ 5 ────────────
        Plan::updateOrCreate(
            ['slug' => 'free'],
            [
                'name'                       => 'Grátis',
                'price_monthly'              => 0.00,
                'price_annual'               => 0.00,
                'included_balance_cents'     => 500,        // R$ 5,00/mês recorrente
                'whatsapp_setup_fee_cents'   => 49900,      // R$ 499 (alto — filtra Free de WhatsApp)
                'whatsapp_billed_separately' => true,
                'max_contacts'               => 50,
                'max_campaigns'              => 3,
                'features'                   => json_encode([
                    'support'              => 'faq',
                    'channels'             => ['sms', 'email', 'whatsapp'],
                    'ai_generations_limit' => 1,
                    'ai_chatbot_enabled'   => false,
                    'funnels_enabled'      => false,
                    'api_access'           => false,
                    'max_contact_lists'    => 1,
                    'max_users'            => 1,
                    'max_funnels'          => 0,
                    'white_label'          => false,
                    'credits_renew'        => true,
                ]),
            ]
        );

        // ── Starter R$ 89/mês — PME pequena ───────────────────────────
        Plan::updateOrCreate(
            ['slug' => 'starter'],
            [
                'name'                       => 'Starter',
                'price_monthly'              => 89.00,
                'price_annual'               => 854.40,     // 89 × 12 × 0,80
                'included_balance_cents'     => 10000,      // R$ 100/mês
                'whatsapp_setup_fee_cents'   => 29900,      // R$ 299
                'whatsapp_billed_separately' => true,
                'max_contacts'               => 1000,
                'max_campaigns'              => 20,
                'features'                   => json_encode([
                    'support'              => 'email',
                    'channels'             => ['sms', 'email', 'voice', 'whatsapp'],
                    'ai_generations_limit' => 10,
                    'ai_chatbot_enabled'   => true,        // básico, limite via ai_chatbot_conversations_limit
                    'ai_chatbot_conversations_limit' => 100,
                    'funnels_enabled'      => true,
                    'max_funnels'          => 2,
                    'api_access'           => false,
                    'max_contact_lists'    => 5,
                    'max_users'            => 2,
                    'white_label'          => false,
                    'credits_renew'        => true,
                ]),
            ]
        );

        // ── Pro R$ 219/mês — agência / PME média (MAIS POPULAR) ──────
        Plan::updateOrCreate(
            ['slug' => 'pro'],
            [
                'name'                       => 'Pro',
                'price_monthly'              => 219.00,
                'price_annual'               => 2102.40,    // 219 × 12 × 0,80
                'included_balance_cents'     => 25000,      // R$ 250/mês
                'whatsapp_setup_fee_cents'   => 9900,       // R$ 99
                'whatsapp_billed_separately' => true,
                'max_contacts'               => 10000,
                'max_campaigns'              => 0,          // ilimitado
                'features'                   => json_encode([
                    'support'              => 'priority',
                    'channels'             => ['sms', 'email', 'voice', 'whatsapp'],
                    'ai_generations_limit' => 0,           // ilimitado (consome saldo)
                    'ai_chatbot_enabled'   => true,
                    'ai_chatbot_conversations_limit' => 0, // ilimitado
                    'funnels_enabled'      => true,
                    'max_funnels'          => 10,
                    'api_access'           => 'read_only',
                    'max_contact_lists'    => 20,
                    'max_users'            => 5,
                    'white_label'          => false,
                    'credits_renew'        => true,
                ]),
            ]
        );

        // ── Business R$ 699/mês — empresa / agência grande ───────────
        Plan::updateOrCreate(
            ['slug' => 'business'],
            [
                'name'                       => 'Business',
                'price_monthly'              => 699.00,
                'price_annual'               => 6710.40,    // 699 × 12 × 0,80
                'included_balance_cents'     => 80000,      // R$ 800/mês
                'whatsapp_setup_fee_cents'   => 0,          // incluso
                'whatsapp_billed_separately' => true,
                'max_contacts'               => 0,          // ilimitado
                'max_campaigns'              => 0,
                'features'                   => json_encode([
                    'support'              => 'dedicated',
                    'channels'             => ['sms', 'email', 'voice', 'whatsapp'],
                    'ai_generations_limit' => 0,
                    'ai_chatbot_enabled'   => true,
                    'ai_chatbot_conversations_limit' => 0,
                    'funnels_enabled'      => true,
                    'max_funnels'          => 0,
                    'api_access'           => 'full',
                    'max_contact_lists'    => 0,
                    'max_users'            => 20,
                    'white_label'          => true,
                    'credits_renew'        => true,
                    'sla'                  => 'business_hours',
                ]),
            ]
        );

        // ── Enterprise — sob consulta (não auto-purchase) ────────────
        Plan::updateOrCreate(
            ['slug' => 'enterprise'],
            [
                'name'                       => 'Enterprise',
                'price_monthly'              => 0.00,       // sob consulta — UI mostra "Falar com Vendas"
                'price_annual'               => 0.00,
                'included_balance_cents'     => 0,          // customizado
                'whatsapp_setup_fee_cents'   => 0,
                'whatsapp_billed_separately' => true,
                'max_contacts'               => 0,
                'max_campaigns'              => 0,
                'features'                   => json_encode([
                    'support'              => 'dedicated_24x7',
                    'channels'             => ['sms', 'email', 'voice', 'whatsapp'],
                    'ai_generations_limit' => 0,
                    'ai_chatbot_enabled'   => true,
                    'ai_chatbot_conversations_limit' => 0,
                    'funnels_enabled'      => true,
                    'max_funnels'          => 0,
                    'api_access'           => 'full',
                    'max_contact_lists'    => 0,
                    'max_users'            => 0,
                    'white_label'          => true,
                    'credits_renew'        => true,
                    'sla'                  => '24x7',
                    'dedicated_ip'         => true,
                    'sender_id'            => true,
                    'multi_tenant'         => true,
                    'sales_contact_only'   => true,
                ]),
            ]
        );
    }
}
