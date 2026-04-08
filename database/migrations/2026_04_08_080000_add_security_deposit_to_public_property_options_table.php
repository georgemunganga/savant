<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_property_options', function (Blueprint $table) {
            $table->tinyInteger('security_deposit_type')
                ->default(0)
                ->after('nightly_rate');
            $table->decimal('security_deposit_value', 12, 2)
                ->default(0)
                ->after('security_deposit_type');
        });

        $hasUnitDepositType = Schema::hasColumn('property_units', 'security_deposit_type');
        $hasUnitDepositValue = Schema::hasColumn('property_units', 'security_deposit');

        DB::table('public_property_options')
            ->whereNotNull('property_unit_id')
            ->select(['id', 'property_unit_id'])
            ->orderBy('id')
            ->get()
            ->each(function ($option) use ($hasUnitDepositType, $hasUnitDepositValue) {
                $unit = DB::table('property_units')
                    ->where('id', $option->property_unit_id)
                    ->first();

                DB::table('public_property_options')
                    ->where('id', $option->id)
                    ->update([
                        'security_deposit_type' => $hasUnitDepositType
                            ? (int) ($unit->security_deposit_type ?? 0)
                            : 0,
                        'security_deposit_value' => $hasUnitDepositValue
                            ? (float) ($unit->security_deposit ?? 0)
                            : 0,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('public_property_options', function (Blueprint $table) {
            $table->dropColumn(['security_deposit_type', 'security_deposit_value']);
        });
    }
};
