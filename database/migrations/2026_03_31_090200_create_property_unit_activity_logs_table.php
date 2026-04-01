<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('property_unit_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event_type', 80);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('property_id')->references('id')->on('properties')->cascadeOnDelete();
            $table->foreign('unit_id')->references('id')->on('property_units')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['unit_id', 'occurred_at'], 'property_unit_activity_logs_unit_occurred_index');
            $table->index(['property_id', 'occurred_at'], 'property_unit_activity_logs_property_occurred_index');
        });
    }

    public function down()
    {
        Schema::dropIfExists('property_unit_activity_logs');
    }
};
