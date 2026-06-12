<?php

/**
 * Business identity & policies.
 *
 * No business-identity value should be hardcoded in controllers, services,
 * views or emails. Everything comes from env so operations can change them
 * without a deploy.
 */
return [
    // Legal / brand
    'company_name'   => env('BUSINESS_COMPANY_NAME', 'BusinessCode'),
    'brand_name'     => env('BUSINESS_BRAND_NAME', 'BusinessCode'),
    'company_cnpj'   => env('BUSINESS_CNPJ', ''),
    'company_legal_name' => env('BUSINESS_LEGAL_NAME', ''),

    // Contact
    'support_email'  => env('BUSINESS_SUPPORT_EMAIL', ''),
    'sales_whatsapp' => env('BUSINESS_SALES_WHATSAPP', ''),
    'sales_whatsapp_prompt' => env('BUSINESS_SALES_WHATSAPP_PROMPT', 'Olá! Quero saber sobre o plano Enterprise'),

    // Site
    'site_url'       => env('BUSINESS_SITE_URL', env('APP_URL')),
    'docs_url'       => env('BUSINESS_DOCS_URL'),

    // Billing policies
    'annual_discount_percent' => (float) env('BUSINESS_ANNUAL_DISCOUNT', 20.0),
    'subscription_pending_timeout_hours' => (int) env('BUSINESS_PENDING_SUB_TIMEOUT_HOURS', 24),
    'trial_days' => (int) env('BUSINESS_TRIAL_DAYS', 0),

    // LGPD
    'terms_version' => env('BUSINESS_TERMS_VERSION', '1.0.0'),
    'privacy_version' => env('BUSINESS_PRIVACY_VERSION', '1.0.0'),

    // Email verification
    'require_email_verification' => (bool) env('BUSINESS_REQUIRE_EMAIL_VERIFICATION', false),
];
