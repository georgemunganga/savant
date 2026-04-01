<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('tenant_details', function (Blueprint $table) {
            $table->string('emergency_contact')->nullable()->after('permanent_zip_code');
        });
    }

    public function down()
    {
        Schema::table('tenant_details', function (Blueprint $table) {
            $table->dropColumn('emergency_contact');
        });
    }
};
