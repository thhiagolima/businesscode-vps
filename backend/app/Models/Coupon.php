<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'discount_type', 'discount_value', 'max_uses',
        'times_used', 'valid_from', 'valid_until', 'active',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'max_uses' => 'integer',
        'times_used' => 'integer',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'active' => 'boolean',
    ];

    public function isValid(): bool
    {
        if (!$this->active) return false;
        if ($this->max_uses !== null && $this->times_used >= $this->max_uses) return false;
        if ($this->valid_from && now()->lt($this->valid_from)) return false;
        if ($this->valid_until && now()->gt($this->valid_until->endOfDay())) return false;
        return true;
    }

    public function calculateDiscount(float $price): float
    {
        if ($this->discount_type === 'percentage') {
            return round($price * ($this->discount_value / 100), 2);
        }
        return min($this->discount_value, $price);
    }
}
