<?php

namespace App\Services\Messaging;

/**
 * Substitutes {key} placeholders in a template string with values from a
 * variables map. Used by the direct messaging API endpoints to personalize
 * SMS/Voice/Email content per-call.
 *
 * Design choices:
 * - Unknown placeholders are left intact (e.g. `{typo}` stays as-is) so
 *   the caller can spot mistakes by reading the delivered message.
 * - Values are coerced to string; bools become "true"/"false".
 * - Substitution is single-pass — a value containing `{other}` won't be
 *   re-rendered.
 */
class TemplateRenderer
{
    public static function render(string $template, array $variables): string
    {
        if ($template === '' || empty($variables)) {
            return $template;
        }

        $pairs = [];
        foreach ($variables as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $pairs['{' . $key . '}'] = self::coerce($value);
        }

        if (empty($pairs)) {
            return $template;
        }

        // strtr() does single-pass simultaneous substitution, so a value
        // containing another placeholder (e.g. attacker-supplied
        // {nome}="{admin_token}") will NOT trigger recursive expansion.
        return strtr($template, $pairs);
    }

    private static function coerce(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return '';
    }
}
