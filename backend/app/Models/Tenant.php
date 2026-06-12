<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Tenant extends Model
{
    use HasFactory, Notifiable;

    /**
     * Route mail notifications to the tenant's primary user.
     *
     * Tenants don't store their own email; instead we relay to the first user
     * with role superadmin|admin|finance (in that order of preference).
     */
    public function routeNotificationForMail($notification): ?string
    {
        $user = $this->users()
            ->whereIn('role', ['superadmin', 'admin', 'finance'])
            ->orderByRaw("FIELD(role, 'superadmin','admin','finance')")
            ->first()
            ?? $this->users()->first();

        return $user?->email;
    }

    protected $fillable = [
        'name',
        'slug',
        'plan_id',
        'status',
        'trial_ends_at',
        'balance_cents',
        'credit_limit_cents',
        'billing_status',
        'billing_cycle_day',
        'last_billing_at',
        'overdue_since',
        'overdue_attempts',
        'mp_customer_id',
        'mp_default_card_id',
    ];

    protected $casts = [
        'trial_ends_at'      => 'datetime',
        'balance_cents'      => 'integer',
        'credit_limit_cents' => 'integer',
        'billing_cycle_day'  => 'integer',
        'overdue_attempts'   => 'integer',
        'last_billing_at'    => 'datetime',
        'overdue_since'      => 'datetime',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function channels()
    {
        return $this->hasMany(TenantChannel::class);
    }

    public function enabledChannels()
    {
        return $this->hasMany(TenantChannel::class)->where('status', '!=', 'disabled');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)->whereIn('status', ['active', 'authorized'])->latest();
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function availableBalanceCents(): int
    {
        return $this->balance_cents + $this->credit_limit_cents;
    }

    public function isBillingBlocked(): bool
    {
        return in_array($this->billing_status, ['suspended', 'blocked'], true);
    }

    public function balanceTransactions()
    {
        return $this->hasMany(BalanceTransaction::class);
    }
}
