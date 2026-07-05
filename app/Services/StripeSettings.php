<?php

namespace App\Services;

use App\Models\ApiSetting;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Stripe;

class StripeSettings
{
    public function enabled(string $mode): bool
    {
        $keyName = $mode === 'live' ? 'stripe_live_secret_key' : 'stripe_test_secret_key';

        $setting = ApiSetting::query()->where('key_name', $keyName)->first();
        if (! $setting || ! $setting->is_enabled) {
            return false;
        }

        return trim((string) $setting->value) !== '';
    }

    /**
     * Stripe mode for checkout when the client does not send an explicit `mode` (test|live).
     *
     * Rules: exclusive live (live on, test off) → live; exclusive test → test.
     * If both toggles are on, pick the mode that has a secret key when only one does;
     * if both have keys, default to test (matches admin UI and avoids accidental live charges).
     */
    public function resolveCheckoutMode(): string
    {
        $liveOn = $this->enabled('live');
        $testOn = $this->enabled('test');
        $liveSecret = $this->secretKey('live');
        $testSecret = $this->secretKey('test');

        if ($liveOn && ! $testOn) {
            return 'live';
        }
        if ($testOn && ! $liveOn) {
            return 'test';
        }
        if ($liveOn && $testOn) {
            if ($liveSecret && ! $testSecret) {
                return 'live';
            }
            if ($testSecret && ! $liveSecret) {
                return 'test';
            }
            if ($liveSecret && $testSecret) {
                return app()->environment('production') ? 'live' : 'test';
            }
        }

        return 'test';
    }

    /**
     * Retrieve a Stripe Checkout Session, trying the preferred mode first then the other.
     *
     * @return array{0: StripeCheckoutSession, 1: string} session and mode used
     */
    public function retrieveCheckoutSession(string $sessionId, ?string $preferredMode = null): array
    {
        $modes = [];
        if ($preferredMode === 'live' || $preferredMode === 'test') {
            $modes[] = $preferredMode;
        }
        foreach (['live', 'test'] as $mode) {
            if (! in_array($mode, $modes, true)) {
                $modes[] = $mode;
            }
        }

        $lastException = null;
        foreach ($modes as $mode) {
            $secret = $this->secretKey($mode);
            if (! $secret) {
                continue;
            }
            try {
                Stripe::setApiKey($secret);
                /** @var StripeCheckoutSession $session */
                $session = StripeCheckoutSession::retrieve($sessionId);

                return [$session, $mode];
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        throw $lastException ?? new \RuntimeException('Unable to retrieve Stripe checkout session.');
    }

    public function secretKey(string $mode): ?string
    {
        $key = ApiSetting::query()
            ->where('key_name', $mode === 'live' ? 'stripe_live_secret_key' : 'stripe_test_secret_key')
            ->value('value');

        $v = is_string($key) ? trim($key) : '';
        return $v !== '' ? $v : null;
    }

    public function webhookSecret(string $mode): ?string
    {
        $key = ApiSetting::query()
            ->where('key_name', $mode === 'live' ? 'stripe_live_webhook_secret' : 'stripe_test_webhook_secret')
            ->value('value');

        $v = is_string($key) ? trim($key) : '';
        return $v !== '' ? $v : null;
    }

    public function publishableKey(string $mode): ?string
    {
        $key = ApiSetting::query()
            ->where('key_name', $mode === 'live' ? 'stripe_live_publishable_key' : 'stripe_test_publishable_key')
            ->value('value');

        $v = is_string($key) ? trim($key) : '';
        return $v !== '' ? $v : null;
    }
}

