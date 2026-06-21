<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tyres', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands');
            $table->string('model');
            $table->foreignId('size_id')->constrained('sizes');
            $table->foreignId('season_id')->nullable()->constrained('seasons')->nullOnDelete();
            $table->foreignId('tyre_type_id')->nullable()->constrained('tyre_types')->nullOnDelete();
            $table->foreignId('fuel_efficiency_id')->nullable()->constrained('fuel_efficiencies')->nullOnDelete();
            $table->foreignId('speed_rating_id')->nullable()->constrained('speed_ratings')->nullOnDelete();
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('stock')->default(0);
            $table->text('description')->nullable();
            $table->boolean('status')->default(true);
            $table->string('image_url')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'model']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tyres');
    }
};
