<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed company branding settings used by order invoices.
     *
     * Only fills blanks: existing non-empty values are preserved so admins
     * can keep custom values they have already configured.
     */
    public function up(): void
    {
        $defaults = [
            'brand_name'     => 'Matrix Mobile Tyres and Autos',
            'address'        => '17 Flora Road, Coventry',
            'contact_number' => '07721570075',
        ];

        foreach ($defaults as $key => $value) {
            $existing = DB::table('settings')->where('key', $key)->first();

            if ($existing === null) {
                DB::table('settings')->insert([
                    'key'        => $key,
                    'value'      => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

            $current = (string) ($existing->value ?? '');
            if (trim($current) === '') {
                DB::table('settings')
                    ->where('key', $key)
                    ->update([
                        'value'      => $value,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive seed; nothing to roll back so user-edited values are preserved.
    }
};
