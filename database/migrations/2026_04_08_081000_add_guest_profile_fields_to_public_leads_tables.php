<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_property_waitlists', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('phone');
            $table->string('nationality_country_id', 50)->nullable()->after('date_of_birth');
            $table->string('id_type', 30)->nullable()->after('nationality_country_id');
            $table->string('id_number', 100)->nullable()->after('id_type');
            $table->string('occupation', 150)->nullable()->after('id_number');
            $table->boolean('is_student')->default(false)->after('occupation');
            $table->string('year_of_study', 100)->nullable()->after('is_student');
        });

        Schema::table('public_property_bookings', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('phone');
            $table->string('nationality_country_id', 50)->nullable()->after('date_of_birth');
            $table->string('id_type', 30)->nullable()->after('nationality_country_id');
            $table->string('id_number', 100)->nullable()->after('id_type');
            $table->string('occupation', 150)->nullable()->after('id_number');
            $table->boolean('is_student')->default(false)->after('occupation');
            $table->string('year_of_study', 100)->nullable()->after('is_student');
        });
    }

    public function down(): void
    {
        Schema::table('public_property_waitlists', function (Blueprint $table) {
            $table->dropColumn([
                'date_of_birth',
                'nationality_country_id',
                'id_type',
                'id_number',
                'occupation',
                'is_student',
                'year_of_study',
            ]);
        });

        Schema::table('public_property_bookings', function (Blueprint $table) {
            $table->dropColumn([
                'date_of_birth',
                'nationality_country_id',
                'id_type',
                'id_number',
                'occupation',
                'is_student',
                'year_of_study',
            ]);
        });
    }
};
