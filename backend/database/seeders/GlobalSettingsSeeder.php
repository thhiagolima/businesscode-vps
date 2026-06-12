<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GlobalSettingsSeeder extends Seeder
{
    /**
     * Pricing study: docs/superpowers/plans/2026-03-26-pricing-study.md
     *
     * Custos reais confirmados (BRL):
     *   Email:    R$0,0043/email
     *   SMS:      R$0,0605/msg
     *   Voz:      R$0,001167/segundo (R$0,035 por 30s) + ElevenLabs R$0,06 = R$0,095 total
     *   WhatsApp: R$0,34 (Meta) / R$0,55 (Infobip)
     *   IA Gen:   R$0,003 (Grok-3-mini)
     *   IA Chat:  R$0,002 (Grok-3-mini)
     *
     * 1 crédito = R$0,10 valor percebido
     */
    public function run(): void
    {
        $defaults = [
            // ── Infobip ──
            ['infobip',     'api_key',                  '',                        'encrypted'],
            ['infobip',     'base_url',                 'api.infobip.com',         'string'],
            ['infobip',     'sender_sms',               'BusinessCode',            'string'],
            ['infobip',     'sender_voice',             '',                        'string'],
            ['infobip',     'sender_email',             '',                        'string'],

            // ── AI (Grok/xAI) ──
            ['ai',          'grok_api_key',             '',                        'encrypted'],
            ['ai',          'grok_model',               'grok-3-mini',             'string'],

            // ── ElevenLabs TTS ──
            ['elevenlabs',  'api_key',                  '',                        'encrypted'],
            ['elevenlabs',  'model_id',                 'eleven_multilingual_v2',  'string'],
            ['elevenlabs',  'cost_per_char',            '0.000133',                'string'],  // R$0,06 / 450 chars
            ['elevenlabs',  'sale_per_char',            '0.000667',                'string'],  // R$0,30 / 450 chars (margem 78%)
            ['elevenlabs',  'credits_per_char',         '1',                       'string'],

            // ── Billing: Créditos por ação ──
            // Cada crédito = R$0,10 valor percebido
            ['billing',     'credits_per_sms',          '1',                       'string'],  // R$0,10 venda | R$0,0605 custo | margem 39%
            ['billing',     'credits_per_voice',        '3',                       'string'],  // R$0,30 venda | R$0,095 custo  | margem 68%
            ['billing',     'credits_per_email',        '1',                       'string'],  // R$0,10 venda | R$0,0043 custo | margem 96%
            ['billing',     'credits_per_whatsapp',     '4',                       'string'],  // R$0,40 venda | R$0,34 custo   | margem 15% (Meta)
            ['billing',     'credits_per_whatsapp_infobip', '6',                   'string'],  // R$0,60 venda | R$0,55 custo   | margem 8% (Infobip)
            ['billing',     'credits_per_ai_generation','8',                       'string'],  // R$0,80 venda | R$0,003 custo  | margem 99,6%
            ['billing',     'credits_per_ai_chat',      '1',                       'string'],  // R$0,10 venda | R$0,002 custo  | margem 98%

            // ── Custos reais (para dashboard admin / relatórios) ──
            ['costs',       'sms_cost_brl',             '0.0605',                  'string'],
            ['costs',       'email_cost_brl',           '0.0043',                  'string'],
            ['costs',       'voice_second_cost_brl',    '0.001167',                'string'],
            ['costs',       'elevenlabs_450chars_brl',  '0.06',                    'string'],
            ['costs',       'whatsapp_meta_cost_brl',   '0.34',                    'string'],
            ['costs',       'whatsapp_infobip_cost_brl','0.55',                    'string'],
            ['costs',       'grok_generation_cost_brl', '0.003',                   'string'],
            ['costs',       'grok_chat_cost_brl',       '0.002',                   'string'],
        ];

        foreach ($defaults as [$group, $key, $value, $type]) {
            DB::table('settings')->insertOrIgnore([
                'tenant_id'  => null,
                'group'      => $group,
                'key'        => $key,
                'value'      => $value,
                'type'       => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
