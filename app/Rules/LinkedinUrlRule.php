<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * R171: a LinkedIn profile URL. Host-checked, not `str_contains` — the advisor's explicit warning
 * (CYCLE-LOG, 12a design ADVISOR) was that `str_contains($value, 'linkedin.com')` accepts
 * `https://evil.com/linkedin.com` and `https://linkedin.com.evil.com`. `https` only; host must be
 * exactly `linkedin.com` or a subdomain of it (`www.linkedin.com`, `ae.linkedin.com`, ...).
 */
class LinkedinUrlRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a LinkedIn profile URL.');

            return;
        }

        $parts = parse_url($value);
        $host = strtolower($parts['host'] ?? '');
        $scheme = strtolower($parts['scheme'] ?? '');

        $validHost = $host === 'linkedin.com' || str_ends_with($host, '.linkedin.com');

        if ($scheme !== 'https' || ! $validHost) {
            $fail('The :attribute must be a linkedin.com URL.');
        }
    }
}
