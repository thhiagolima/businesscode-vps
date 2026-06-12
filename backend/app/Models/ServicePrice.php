<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePrice extends Model
{
    /**
     * Unidade micros: 1 micro = R$ 0,00001 (×10⁵).
     * Permite representar custos fracionários (SMS R$ 0,0605 = 6050 micros) sem
     * mudar o tipo das colunas cents (retrocompat). PricingService prefere micros
     * se >0, com fallback para cents arredondados.
     */
    public const MICROS_PER_CENT = 1000;
    public const MICROS_PER_REAL = 100000;

    protected $fillable = [
        'service',
        'cost_cents', 'sale_cents',
        'cost_micros', 'sale_micros',
        'updated_by',
    ];

    protected $casts = [
        'cost_cents'  => 'integer',
        'sale_cents'  => 'integer',
        'cost_micros' => 'integer',
        'sale_micros' => 'integer',
    ];

    /** Preferência: micros se preenchido; senão cents × 1000. */
    public function effectiveCostMicros(): int
    {
        return $this->cost_micros > 0 ? $this->cost_micros : $this->cost_cents * self::MICROS_PER_CENT;
    }

    public function effectiveSaleMicros(): int
    {
        return $this->sale_micros > 0 ? $this->sale_micros : $this->sale_cents * self::MICROS_PER_CENT;
    }

    public function marginCents(): int
    {
        return $this->sale_cents - $this->cost_cents;
    }

    public function marginMicros(): int
    {
        return $this->effectiveSaleMicros() - $this->effectiveCostMicros();
    }

    public function marginPercent(): float
    {
        $cost = $this->effectiveCostMicros();
        return $cost > 0
            ? round(($this->marginMicros() / $cost) * 100, 2)
            : 0.0;
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
