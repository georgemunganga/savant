<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_property_waitlists', function (Blueprint $table) {
            $table->unsignedBigInteger('option_id')->nullable()->after('property_id');
            $table->foreign('option_id')
                ->references('id')
                ->on('public_property_options')
                ->onDelete('cascade')
                ->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('public_property_waitlists', function (Blueprint $table) {
            $table->dropForeign(['option_id']);
            $table->dropColumn('option_id');
        });
    }
};
