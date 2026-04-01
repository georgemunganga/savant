<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_property_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('property_unit_id')->nullable();
            $table->string('rental_kind', 30);
            $table->decimal('monthly_rate', 12, 2)->nullable();
            $table->decimal('nightly_rate', 12, 2)->nullable();
            $table->unsignedInteger('max_guests')->nullable();
            $table->unsignedTinyInteger('status')->default(ACTIVE);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')
                ->references('id')
                ->on('properties')
                ->onDelete('cascade')
                ->onUpdate('cascade');
            $table->foreign('property_unit_id')
                ->references('id')
                ->on('property_units')
                ->onDelete('cascade')
                ->onUpdate('cascade');
            $table->index(['property_id', 'status']);
            $table->unique(['property_id', 'property_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_property_options');
    }
};
