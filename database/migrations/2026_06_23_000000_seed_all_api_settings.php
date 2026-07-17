<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('api_settings')) {
            return;
        }

        $now = now();

        $rows = [
            [
                'key_name'    => 'dvla_api_key',
                'label'       => 'DVLA API Key',
                'description' => 'Used for reg plate lookups (vehicle enquiry service).',
                'icon_type'   => 'globe',
                'is_enabled'  => true,
                'value'       => null,
            ],
            [
                'key_name'    => 'openai_api_key',
                'label'       => 'OpenAI API Key',
                'description' => 'Used for AI tyre recommendations.',
                'icon_type'   => 'globe',
                'is_enabled'  => false,
                'value'       => null,
            ],
            [
                'key_name'    => 'stripe_test_secret_key',
                'label'       => 'Stripe Test Secret Key',
                'description' => 'Server-side secret key (starts with sk_test_).',
                'icon_type'   => 'stripe',
                'is_enabled'  => false,
                'value'       => null,
            ],
            [
                'key_name'    => 'stripe_test_publishable_key',
                'label'       => 'Stripe Test Publishable Key',
                'description' => 'Client-side publishable key (starts with pk_test_).',
                'icon_type'   => 'stripe',
                'is_enabled'  => false,
                'value'       => null,
            ],
            [
                'key_name'    => 'stripe_test_webhook_secret',
                'label'       => 'Stripe Test Webhook Secret',
                'description' => 'Webhook signing secret for test endpoints (starts with whsec_).',
                'icon_type'   => 'stripe',
                'is_enabled'  => false,
                'value'       => null,
            ],
            [
                'key_name'    => 'stripe_live_secret_key',
                'label'       => 'Stripe Live Secret Key',
                'description' => 'Server-side secret key (starts with sk_live_).',
                'icon_type'   => 'stripe',
                'is_enabled'  => false,
                'value'       => null,
            ],
            [
                'key_name'    => 'stripe_live_publishable_key',
                'label'       => 'Stripe Live Publishable Key',
                'description' => 'Client-side publishable key (starts with pk_live_).',
                'icon_type'   => 'stripe',
                'is_enabled'  => false,
                'value'       => null,
            ],
            [
                'key_name'    => 'stripe_live_webhook_secret',
                'label'       => 'Stripe Live Webhook Secret',
                'description' => 'Webhook signing secret for live endpoints (starts with whsec_).',
                'icon_type'   => 'stripe',
                'is_enabled'  => false,
                'value'       => null,
            ],
        ];

        foreach ($rows as $row) {
            DB::table('api_settings')->upsert(
                [[
                    'key_name'    => $row['key_name'],
                    'label'       => $row['label'],
                    'description' => $row['description'],
                    'icon_type'   => $row['icon_type'],
                    'is_enabled'  => $row['is_enabled'],
                    'value'       => $row['value'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]],
                ['key_name'],
                ['label', 'description', 'icon_type', 'updated_at']
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('api_settings')) {
            return;
        }

        DB::table('api_settings')->whereIn('key_name', [
            'dvla_api_key',
            'openai_api_key',
            'stripe_test_secret_key',
            'stripe_test_publishable_key',
            'stripe_test_webhook_secret',
            'stripe_live_secret_key',
            'stripe_live_publishable_key',
            'stripe_live_webhook_secret',
        ])->delete();
    }
};
