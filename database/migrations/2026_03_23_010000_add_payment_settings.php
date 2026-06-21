<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed payment settings defaults.
     */
    public function up(): void
    {
        $defaults = [
            ['key' => 'online_payment', 'value' => '1'],
            ['key' => 'cash_on_delivery', 'value' => '1'],
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
            'online_payment',
            'cash_on_delivery',
        ])->delete();
    }
};
