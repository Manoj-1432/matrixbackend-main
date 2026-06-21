<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `slots` table for booking time-slot management.
     * Each row represents a recurring weekly time window on a given day.
     */
    public function up(): void
    {
        Schema::create('slots', function (Blueprint $table) {
            $table->id();
            $table->enum('day', [
                'monday',
                'tuesday',
                'wednesday',
                'thursday',
                'friday',
                'saturday',
                'sunday',
            ]);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('max_bookings')->default(1);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            // Prevent duplicate slots for the same day/time combination
            $table->unique(['day', 'start_time', 'end_time'], 'slots_day_time_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slots');
    }
};
