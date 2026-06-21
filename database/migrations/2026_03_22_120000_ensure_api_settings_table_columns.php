<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Older or hand-built `api_settings` tables may omit columns from the canonical
 * migration. Align the schema without dropping existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('api_settings')) {
            return;
        }

        if (! Schema::hasColumn('api_settings', 'label')) {
            Schema::table('api_settings', function (Blueprint $table) {
                $table->string('label')->default('')->after('key_name');
            });
        }
        if (! Schema::hasColumn('api_settings', 'description')) {
            Schema::table('api_settings', function (Blueprint $table) {
                $table->string('description')->nullable()->after('label');
            });
        }
        if (! Schema::hasColumn('api_settings', 'icon_type')) {
            Schema::table('api_settings', function (Blueprint $table) {
                $table->string('icon_type')->default('globe')->after('description');
            });
        }
        if (! Schema::hasColumn('api_settings', 'value')) {
            Schema::table('api_settings', function (Blueprint $table) {
                $table->text('value')->nullable()->after('icon_type');
            });
        }
        if (! Schema::hasColumn('api_settings', 'is_enabled')) {
            Schema::table('api_settings', function (Blueprint $table) {
                $table->boolean('is_enabled')->default(true)->after('value');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive upgrade; do not drop columns that may pre-exist.
    }
};
