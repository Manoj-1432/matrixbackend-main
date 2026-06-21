<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->date('fitting_date')->nullable()->after('slot_id');
            $table->index(['slot_id', 'fitting_date'], 'orders_slot_fitting_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_slot_fitting_date_index');
            $table->dropColumn('fitting_date');
        });
    }
};
