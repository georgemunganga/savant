<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('public_property_waitlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('stay_mode', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('guests');
            $table->string('full_name', 150);
            $table->string('email', 150);
            $table->string('phone', 30);
            $table->string('status', 30)->default('pending');
            $table->timestamps();

            $table->foreign('property_id')
                ->references('id')
                ->on('properties')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('public_property_waitlists');
    }
};
