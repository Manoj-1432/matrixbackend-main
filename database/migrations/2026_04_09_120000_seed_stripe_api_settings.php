<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed Stripe API settings rows so Stripe can be configured without relying
     * on the API Settings UI being visited first.
     */
    public function up(): void
    {
        if (! class_exists(\App\Models\ApiSetting::class)) {
            return;
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('api_settings')) {
            return;
        }

        $rows = [
            [
                'key_name' => 'stripe_test',
                'label' => 'Stripe (Test)',
                'description' => 'Stripe test mode settings (secret/publishable/webhook).',
                'icon_type' => 'stripe',
                'is_enabled' => false,
            ],
            [
                'key_name' => 'stripe_live',
                'label' => 'Stripe (Live)',
                'description' => 'Stripe live mode settings (secret/publishable/webhook).',
                'icon_type' => 'stripe',
                'is_enabled' => false,
            ],
            [
                'key_name' => 'stripe_test_secret_key',
                'label' => 'Stripe Test Secret Key',
                'description' => 'Server-side secret key (starts with sk_test_).',
                'icon_type' => 'stripe',
                'is_enabled' => false,
            ],
            [
                'key_name' => 'stripe_test_publishable_key',
                'label' => 'Stripe Test Publishable Key',
                'description' => 'Client-side publishable key (starts with pk_test_).',
                'icon_type' => 'stripe',
                'is_enabled' => false,
            ],
            [
                'key_name' => 'stripe_test_webhook_secret',
                'label' => 'Stripe Test Webhook Secret',
                'description' => 'Webhook signing secret for test endpoints (starts with whsec_).',
                'icon_type' => 'stripe',
                'is_enabled' => false,
            ],
            [
                'key_name' => 'stripe_live_secret_key',
                'label' => 'Stripe Live Secret Key',
                'description' => 'Server-side secret key (starts with sk_live_).',
                'icon_type' => 'stripe',
                'is_enabled' => false,
            ],
            [
                'key_name' => 'stripe_live_publishable_key',
                'label' => 'Stripe Live Publishable Key',
                'description' => 'Client-side publishable key (starts with pk_live_).',
                'icon_type' => 'stripe',
                'is_enabled' => false,
            ],
            [
                'key_name' => 'stripe_live_webhook_secret',
                'label' => 'Stripe Live Webhook Secret',
                'description' => 'Webhook signing secret for live endpoints (starts with whsec_).',
                'icon_type' => 'stripe',
                'is_enabled' => false,
            ],
        ];

        $now = now();

        foreach ($rows as $row) {
            DB::table('api_settings')->upsert(
                [[
                    'key_name' => $row['key_name'],
                    'label' => $row['label'],
                    'description' => $row['description'],
                    'icon_type' => $row['icon_type'],
                    'is_enabled' => $row['is_enabled'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]],
                ['key_name'],
                ['label', 'description', 'icon_type', 'is_enabled', 'updated_at']
            );
        }
    }

    public function down(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('api_settings')) {
            return;
        }

        DB::table('api_settings')->whereIn('key_name', [
            'stripe_test',
            'stripe_live',
            'stripe_test_secret_key',
            'stripe_test_publishable_key',
            'stripe_test_webhook_secret',
            'stripe_live_secret_key',
            'stripe_live_publishable_key',
            'stripe_live_webhook_secret',
        ])->delete();
    }
};

