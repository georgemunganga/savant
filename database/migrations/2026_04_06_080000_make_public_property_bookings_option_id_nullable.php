<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('public_property_bookings', function (Blueprint $table) {
                $table->unsignedBigInteger('option_id')->nullable()->change();
            });

            return;
        }

        Schema::table('public_property_bookings', function (Blueprint $table) {
            $table->dropForeign(['option_id']);
        });

        Schema::table('public_property_bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('option_id')->nullable()->change();
        });

        Schema::table('public_property_bookings', function (Blueprint $table) {
            $table->foreign('option_id')
                ->references('id')
                ->on('public_property_options')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('public_property_bookings', function (Blueprint $table) {
                $table->unsignedBigInteger('option_id')->nullable(false)->change();
            });

            return;
        }

        Schema::table('public_property_bookings', function (Blueprint $table) {
            $table->dropForeign(['option_id']);
        });

        Schema::table('public_property_bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('option_id')->nullable(false)->change();
        });

        Schema::table('public_property_bookings', function (Blueprint $table) {
            $table->foreign('option_id')
                ->references('id')
                ->on('public_property_options')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }
};
