<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key_name')->unique();      // e.g. "dvla", "google_maps"
            $table->string('label');                   // display name
            $table->string('description')->nullable(); // short description
            $table->string('icon_type')->default('globe'); // dvla|maps|openai|paypal
            $table->text('value')->nullable();         // encrypted API key
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_settings');
    }
};
