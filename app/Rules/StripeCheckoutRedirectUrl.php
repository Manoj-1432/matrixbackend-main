<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts redirect URLs for Stripe Checkout, including the {CHECKOUT_SESSION_ID} placeholder.
 */
class StripeCheckoutRedirectUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        if (strlen($value) > 2048) {
            $fail('The :attribute may not be greater than 2048 characters.');

            return;
        }

        $probe = str_replace('{CHECKOUT_SESSION_ID}', 'cs_placeholder00000000000000000000', $value);

        if (! filter_var($probe, FILTER_VALIDATE_URL)) {
            $fail('The :attribute must be a valid URL.');
        }
    }
}
