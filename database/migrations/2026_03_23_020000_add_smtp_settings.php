<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed SMTP settings defaults.
     */
    public function up(): void
    {
        $defaults = [
            ['key' => 'smtp_enabled', 'value' => '0'],
            ['key' => 'smtp_host', 'value' => null],
            ['key' => 'smtp_port', 'value' => '587'],
            ['key' => 'smtp_username', 'value' => null],
            ['key' => 'smtp_password', 'value' => null],
            ['key' => 'smtp_encryption', 'value' => 'tls'],
            ['key' => 'smtp_from_email', 'value' => null],
            ['key' => 'smtp_from_name', 'value' => null],
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
            'smtp_enabled',
            'smtp_host',
            'smtp_port',
            'smtp_username',
            'smtp_password',
            'smtp_encryption',
            'smtp_from_email',
            'smtp_from_name',
        ])->delete();
    }
};
