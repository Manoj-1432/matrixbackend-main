<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed localization defaults.
     */
    public function up(): void
    {
        $defaults = [
            ['key' => 'timezone', 'value' => 'UTC'],
            ['key' => 'country',  'value' => 'United Kingdom'],
            ['key' => 'currency', 'value' => 'GBP'],
        ];

        foreach ($defaults as $row) {
            DB::table('settings')->upsert(
                array_merge($row, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                ['key'],          // unique key
                ['updated_at']    // only touch updated_at
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'timezone',
            'country',
            'currency',
        ])->delete();
    }
};
