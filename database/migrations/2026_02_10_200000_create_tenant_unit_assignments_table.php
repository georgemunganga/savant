<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        Schema::create('tenant_unit_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('unit_id');
            $table->timestamps();

            $table->unique(['tenant_id', 'unit_id'], 'tenant_unit_unique');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('property_id')->references('id')->on('properties')->cascadeOnDelete();
            $table->foreign('unit_id')->references('id')->on('property_units')->cascadeOnDelete();
        });

        DB::statement("
            INSERT INTO tenant_unit_assignments (tenant_id, property_id, unit_id, created_at, updated_at)
            SELECT t.id, t.property_id, t.unit_id, NOW(), NOW()
            FROM tenants t
            WHERE t.property_id IS NOT NULL AND t.unit_id IS NOT NULL AND t.deleted_at IS NULL
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tenant_unit_assignments');
    }
};
