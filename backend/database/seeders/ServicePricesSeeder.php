<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Preços globais dos serviços (custo BC + venda ao cliente).
 *
 * Valores reais do briefing comercial (atualizado 03/06/2026):
 *   SMS      custo R$ 0,0605   venda R$ 0,0750  (margem +24%)
 *   Voz      custo R$ 0,03501  venda R$ 0,0550  (margem +57%)
 *   Email    custo R$ 0,0043   venda R$ 0,0200  (margem +365%)
 *   WhatsApp marketing  custo R$ 0,08  venda R$ 0,12  (margem +50%)
 *   WhatsApp utility    custo R$ 0,04  venda R$ 0,08  (margem +100%)
 *   WhatsApp auth       custo R$ 0,02  venda R$ 0,05  (margem +150%)
 *
 * Unidades:
 *   *_cents  = inteiros de R$ 0,01 (retrocompat com relatórios antigos)
 *   *_micros = inteiros de R$ 0,00001 (×10⁵) — fonte da verdade para cobrança
 *
 * Mix médio realista (50% Email / 30% SMS / 20% Voz) → custo BC = 66,6% do
 * valor vendido. Margem ponderada ~33%.
 */
class ServicePricesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // Mensageria — preços sub-centavo são autoritativos em `*_micros`.
            // `sale_cents` é o arredondado pra cima usado apenas em relatórios legados;
            // o débito real do balance lê `sale_micros` (PricingService::priceFor).
            ['service' => 'sms',           'cost_micros' => 6050, 'sale_micros' => 7500,  'cost_cents' => 6,  'sale_cents' => 8],
            ['service' => 'voice',         'cost_micros' => 3501, 'sale_micros' => 5500,  'cost_cents' => 4,  'sale_cents' => 6],
            ['service' => 'email',         'cost_micros' => 430,  'sale_micros' => 2000,  'cost_cents' => 0,  'sale_cents' => 2],

            // WhatsApp por categoria (Meta cobra por conversa 24h, não por mensagem)
            ['service' => 'whatsapp_marketing', 'cost_micros' => 8000, 'sale_micros' => 12000, 'cost_cents' => 8, 'sale_cents' => 12],
            ['service' => 'whatsapp_utility',   'cost_micros' => 4000, 'sale_micros' => 8000,  'cost_cents' => 4, 'sale_cents' => 8],
            ['service' => 'whatsapp_auth',      'cost_micros' => 2000, 'sale_micros' => 5000,  'cost_cents' => 2, 'sale_cents' => 5],

            // IA — preço por geração de mensagem variacional
            ['service' => 'ai_generation', 'cost_micros' => 10000, 'sale_micros' => 25000, 'cost_cents' => 10, 'sale_cents' => 25],
            // TTS — preço por minuto de áudio gerado por ElevenLabs
            ['service' => 'audio_tts',     'cost_micros' => 30000, 'sale_micros' => 60000, 'cost_cents' => 30, 'sale_cents' => 60],
        ];

        foreach ($rows as $row) {
            DB::table('service_prices')->updateOrInsert(
                ['service' => $row['service']],
                array_merge($row, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        // Cache 5min do PricingService precisa ser invalidado pra valores novos
        // serem lidos imediatamente após o seed.
        Cache::flush();
    }
}
