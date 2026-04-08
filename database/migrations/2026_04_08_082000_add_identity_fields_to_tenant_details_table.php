<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_details', function (Blueprint $table) {
            $table->string('nationality_country_id', 50)->nullable()->after('permanent_zip_code');
            $table->string('identity_document_type', 30)->nullable()->after('nationality_country_id');
            $table->string('identity_document_number', 100)->nullable()->after('identity_document_type');
            $table->string('year_of_study', 100)->nullable()->after('identity_document_number');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_details', function (Blueprint $table) {
            $table->dropColumn([
                'nationality_country_id',
                'identity_document_type',
                'identity_document_number',
                'year_of_study',
            ]);
        });
    }
};
