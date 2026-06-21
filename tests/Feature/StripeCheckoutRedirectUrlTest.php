<?php

namespace Tests\Feature;

use App\Rules\StripeCheckoutRedirectUrl;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class StripeCheckoutRedirectUrlTest extends TestCase
{
    public function test_success_url_with_stripe_session_placeholder_passes_validation(): void
    {
        $validator = Validator::make(
            [
                'success_url' => 'https://matrixmobiletyresandautos.com/checkout/success?order_id=30&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => 'https://matrixmobiletyresandautos.com/checkout/cancel?order_id=30',
            ],
            [
                'success_url' => ['nullable', 'string', new StripeCheckoutRedirectUrl],
                'cancel_url' => ['nullable', 'string', new StripeCheckoutRedirectUrl],
            ]
        );

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }
}
