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

        // Clear any values that were encrypted with a lost APP_KEY so they don't
        // cause DecryptException when read as plain text.
        DB::table('api_settings')->update(['value' => null]);
    }

    public function down(): void {}
};
