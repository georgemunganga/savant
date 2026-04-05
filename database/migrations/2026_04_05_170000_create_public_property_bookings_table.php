<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('public_property_bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id')->nullable()->index();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('option_id');
            $table->unsignedBigInteger('property_unit_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('stay_mode', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('guests');
            $table->string('full_name', 150);
            $table->string('email', 150);
            $table->string('phone', 30)->nullable();
            $table->string('payment_plan', 30)->nullable();
            $table->string('status', 30)->default('confirmed');
            $table->string('source', 30)->default('website');
            $table->boolean('account_created')->default(false);
            $table->boolean('setup_email_sent')->default(false);
            $table->boolean('has_assignment')->default(false);
            $table->boolean('assignment_created')->default(false);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->foreign('owner_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->foreign('property_id')
                ->references('id')
                ->on('properties')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreign('option_id')
                ->references('id')
                ->on('public_property_options')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreign('property_unit_id')
                ->references('id')
                ->on('property_units')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down()
    {
        Schema::dropIfExists('public_property_bookings');
    }
};
