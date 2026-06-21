<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed default values for all settings keys.
     * Uses upsert so it is safe to run multiple times.
     */
    public function up(): void
    {
        $defaults = [
            ['key' => 'brand_name',           'value' => null],
            ['key' => 'logo_url',             'value' => null],
            ['key' => 'website_title',        'value' => null],
            ['key' => 'address',              'value' => null],
            ['key' => 'contact_number',       'value' => null],
            ['key' => 'vat_number',           'value' => null],
            ['key' => 'vat_percentage',       'value' => '20'],
            ['key' => 'vat_enabled',          'value' => '1'],
            ['key' => 'platform_fee',         'value' => '0.00'],
            ['key' => 'platform_fee_enabled', 'value' => '1'],
            ['key' => 'maintenance_mode',     'value' => '0'],
            ['key' => 'maintenance_message',  'value' => 'We are currently undergoing scheduled maintenance. Please check back soon.'],
        ];

        foreach ($defaults as $row) {
            DB::table('settings')->upsert(
                array_merge($row, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                ['key'],          // unique key to match on
                ['updated_at'],   // only touch updated_at — never overwrite a user's saved value
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'brand_name',
            'logo_url',
            'website_title',
            'address',
            'contact_number',
            'vat_number',
            'vat_percentage',
            'vat_enabled',
            'platform_fee',
            'platform_fee_enabled',
            'maintenance_mode',
            'maintenance_message',
        ])->delete();
    }
};
