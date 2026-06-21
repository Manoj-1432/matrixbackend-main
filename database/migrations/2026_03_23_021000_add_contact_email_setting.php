<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed contact email setting default.
     */
    public function up(): void
    {
        DB::table('settings')->upsert(
            [
                'key' => 'contact_email',
                'value' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            ['key'],
            ['updated_at']
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'contact_email')->delete();
    }
};
