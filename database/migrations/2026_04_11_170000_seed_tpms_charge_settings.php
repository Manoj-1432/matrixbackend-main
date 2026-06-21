<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed TPMS charge settings keys (safe for existing databases).
     */
    public function up(): void
    {
        $defaults = [
            ['key' => 'tpms_charge', 'value' => '0.00'],
            ['key' => 'tpms_charge_enabled', 'value' => '0'],
        ];

        foreach ($defaults as $row) {
            DB::table('settings')->upsert(
                array_merge($row, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                ['key'],
                ['updated_at'],
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'tpms_charge',
            'tpms_charge_enabled',
        ])->delete();
    }
};
