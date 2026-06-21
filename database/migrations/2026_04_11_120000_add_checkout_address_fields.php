<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('city', 100)->nullable()->after('address');
            $table->string('postcode', 20)->nullable()->after('city');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->text('customer_comment')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['city', 'postcode']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('customer_comment');
        });
    }
};
