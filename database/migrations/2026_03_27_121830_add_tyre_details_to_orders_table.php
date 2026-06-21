<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('tyre_brand')->nullable()->after('service_type');
            $table->string('tyre_model')->nullable()->after('tyre_brand');
            $table->string('tyre_size')->nullable()->after('tyre_model');
            $table->integer('tyre_quantity')->nullable()->default(1)->after('tyre_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['tyre_brand', 'tyre_model', 'tyre_size', 'tyre_quantity']);
        });
    }
};
