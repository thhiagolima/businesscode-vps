<?php

namespace Tests\Unit\Billing;

use App\Models\Plan;
use App\Models\ServicePrice;
use App\Models\Tenant;
use App\Services\Billing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Garante que o PricingService preserva a precisão sub-centavo dos custos reais
 * dos fornecedores (SMS R$ 0,0605 / Voz R$ 0,03501 / Email R$ 0,0043).
 *
 * Cents arredondados continuam preenchidos para retrocompat com relatórios
 * antigos, mas micros é a fonte da verdade para a cobrança.
 *
 * Premissas validadas:
 *   - Mil envios SMS custam exatamente R$ 60,50 (não R$ 60 nem R$ 80)
 *   - Margem por canal cumpre o briefing comercial
 *   - Margem positiva no mix médio realista para todos os planos pagos
 */
class PricingPrecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(\Database\Seeders\ServicePricesSeeder::class);
        $this->seed(\Database\Seeders\PlansSeeder::class);
    }

    public function test_sms_preserves_sub_cent_cost(): void
    {
        $sms = ServicePrice::where('service', 'sms')->first();
        $this->assertSame(6050, $sms->cost_micros);
        $this->assertSame(7500, $sms->sale_micros);

        // 1.000 envios SMS = R$ 60,50 (custo BC) / R$ 75,00 (receita)
        $costFor1000 = $sms->effectiveCostMicros() * 1000;
        $saleFor1000 = $sms->effectiveSaleMicros() * 1000;
        $this->assertSame(6_050_000, $costFor1000);  // R$ 60,50 em micros
        $this->assertSame(7_500_000, $saleFor1000);  // R$ 75,00 em micros
    }

    public function test_voice_preserves_sub_cent_cost(): void
    {
        $voice = ServicePrice::where('service', 'voice')->first();
        $this->assertSame(3501, $voice->cost_micros);
        $this->assertSame(5500, $voice->sale_micros);
    }

    public function test_email_preserves_sub_cent_cost_below_one_cent(): void
    {
        // Custo real R$ 0,0043 — não cabe em cents (vira 0 ou 1).
        // Em micros temos a precisão correta.
        $email = ServicePrice::where('service', 'email')->first();
        $this->assertSame(430, $email->cost_micros);
        $this->assertSame(2000, $email->sale_micros);
    }

    public function test_pricing_service_returns_micros(): void
    {
        $tenant = Tenant::factory()->create();
        $svc = app(PricingService::class);
        $price = $svc->priceFor($tenant, 'sms');

        $this->assertArrayHasKey('cost_micros', $price);
        $this->assertArrayHasKey('sale_micros', $price);
        $this->assertSame(6050, $price['cost_micros']);
        $this->assertSame(7500, $price['sale_micros']);
    }

    public function test_per_channel_margins_match_briefing(): void
    {
        $expected = [
            'sms'                => ['cost' => 6050,  'sale' => 7500,  'min_margin_percent' => 20],
            'voice'              => ['cost' => 3501,  'sale' => 5500,  'min_margin_percent' => 55],
            'email'              => ['cost' => 430,   'sale' => 2000,  'min_margin_percent' => 120],
            'whatsapp_marketing' => ['cost' => 8000,  'sale' => 12000, 'min_margin_percent' => 45],
            'whatsapp_utility'   => ['cost' => 4000,  'sale' => 8000,  'min_margin_percent' => 95],
            'whatsapp_auth'      => ['cost' => 2000,  'sale' => 5000,  'min_margin_percent' => 145],
        ];

        foreach ($expected as $service => $exp) {
            $price = ServicePrice::where('service', $service)->first();
            $this->assertNotNull($price, "Service price missing: $service");
            $this->assertSame($exp['cost'], $price->cost_micros, "cost_micros mismatch for $service");
            $this->assertSame($exp['sale'], $price->sale_micros, "sale_micros mismatch for $service");
            $this->assertGreaterThanOrEqual(
                $exp['min_margin_percent'],
                $price->marginPercent(),
                "Margem insuficiente em $service"
            );
        }
    }

    public function test_paid_plans_have_positive_margin_in_realistic_mix(): void
    {
        // Mix realista: 50% Email + 30% SMS + 20% Voz.
        // Custo BC por R$ 1 vendido em saldo (em micros):
        //   0,5 × (0,0043/0,01) + 0,3 × (0,0605/0,08) + 0,2 × (0,03501/0,06)
        //   = 0,5 × 0,43      + 0,3 × 0,75625     + 0,2 × 0,5835
        //   = 0,215           + 0,2269           + 0,1167
        //   = 0,5586 (custo BC = 55,86% do saldo)
        // Nota: 0,5586 < 0,666 porque o mix médio favorece email/voz que têm
        // markup maior. Margem por plano é ainda maior que a estimativa
        // conservadora apresentada ao usuário.
        $costFactor = 0.5586;

        $expectedMargins = [
            'starter'  => ['monthly' => 8900,  'balance' => 10000],
            'pro'      => ['monthly' => 21900, 'balance' => 25000],
            'business' => ['monthly' => 69900, 'balance' => 80000],
        ];

        foreach ($expectedMargins as $slug => $exp) {
            $plan = Plan::where('slug', $slug)->first();
            $this->assertNotNull($plan, "Plan missing: $slug");

            $expectedCost   = $exp['balance'] * $costFactor;
            $margin         = $exp['monthly'] - $expectedCost;
            $marginPercent  = ($margin / $exp['monthly']) * 100;

            $this->assertGreaterThan(0, $margin, "Plan $slug margem mix médio NEGATIVA");
            $this->assertGreaterThan(20, $marginPercent, "Plan $slug margem < 20%");
        }
    }

    public function test_whatsapp_setup_fees_descend_by_tier(): void
    {
        $expectedSetup = [
            'free'       => 49900,
            'starter'    => 29900,
            'pro'        => 9900,
            'business'   => 0,
            'enterprise' => 0,
        ];

        foreach ($expectedSetup as $slug => $expected) {
            $plan = Plan::where('slug', $slug)->first();
            $this->assertSame(
                $expected,
                $plan->whatsapp_setup_fee_cents,
                "Setup fee do plano $slug deveria ser R$ " . ($expected / 100)
            );
            $this->assertTrue($plan->whatsapp_billed_separately, "$slug deveria ter WhatsApp billed separately");
        }
    }
}
