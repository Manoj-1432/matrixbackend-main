<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('width');
            $table->unsignedInteger('profile');
            $table->unsignedInteger('rim');
            $table->string('label');
            $table->timestamps();

            $table->unique(['width', 'profile', 'rim']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sizes');
    }
};

