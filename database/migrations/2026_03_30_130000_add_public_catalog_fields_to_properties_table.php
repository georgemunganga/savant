<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('status');
            $table->string('public_slug')->nullable()->after('is_public');
            $table->string('public_category', 30)->nullable()->after('public_slug');
            $table->text('public_summary')->nullable()->after('public_category');
            $table->string('public_home_sections')->nullable()->after('public_summary');
            $table->integer('public_sort_order')->default(0)->after('public_home_sections');

            $table->unique('public_slug');
            $table->index(['is_public', 'status']);
            $table->index('public_category');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['is_public', 'status']);
            $table->dropIndex(['public_category']);
            $table->dropUnique(['public_slug']);
            $table->dropColumn([
                'is_public',
                'public_slug',
                'public_category',
                'public_summary',
                'public_home_sections',
                'public_sort_order',
            ]);
        });
    }
};
