<?php
return [
    'overdue_grace_days' => (int) env('BILLING_OVERDUE_GRACE_DAYS', 7),
    'overdue_retry_hour' => (int) env('BILLING_OVERDUE_RETRY_HOUR', 4),
    'monthly_dispatch_hour' => (int) env('BILLING_MONTHLY_DISPATCH_HOUR', 3),
    'credit_limit_warning_pct' => (int) env('BILLING_CREDIT_LIMIT_WARNING_PCT', 80),
    'default_included_balance_cents' => (int) env('BILLING_DEFAULT_INCLUDED_BALANCE_CENTS', 0),
    'migration_price_cents' => (int) env('BILLING_MIGRATION_PRICE_CENTS', 15),
    'brl_enabled' => filter_var(env('BILLING_BRL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];
