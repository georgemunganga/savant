<?php

use App\Models\PropertyUnit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('property_units', function (Blueprint $table) {
            $table->string('manual_availability_status', 30)
                ->default(PropertyUnit::MANUAL_AVAILABILITY_ACTIVE)
                ->after('max_occupancy');
            $table->text('manual_status_reason')->nullable()->after('manual_availability_status');
            $table->timestamp('manual_status_changed_at')->nullable()->after('manual_status_reason');
            $table->unsignedBigInteger('manual_status_changed_by')->nullable()->after('manual_status_changed_at');
            $table->timestamp('last_vacated_at')->nullable()->after('manual_status_changed_by');

            $table->index('manual_availability_status', 'property_units_manual_availability_status_index');
            $table->foreign('manual_status_changed_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        $activeCounts = DB::table('tenant_unit_assignments')
            ->join('tenants', 'tenant_unit_assignments.tenant_id', '=', 'tenants.id')
            ->join('users', 'tenants.user_id', '=', 'users.id')
            ->whereNull('users.deleted_at')
            ->where('tenants.status', TENANT_STATUS_ACTIVE)
            ->where('tenant_unit_assignments.is_current', true)
            ->selectRaw('tenant_unit_assignments.unit_id, COUNT(DISTINCT tenant_unit_assignments.tenant_id) as total')
            ->groupBy('tenant_unit_assignments.unit_id')
            ->pluck('total', 'tenant_unit_assignments.unit_id');

        $sharedOptionUnitIds = DB::table('public_property_options')
            ->where('rental_kind', 'shared_space')
            ->whereNotNull('property_unit_id')
            ->pluck('property_unit_id')
            ->flip();

        DB::table('property_units')
            ->select(['id', 'max_occupancy'])
            ->orderBy('id')
            ->chunkById(200, function ($units) use ($activeCounts, $sharedOptionUnitIds) {
                foreach ($units as $unit) {
                    if (! is_null($unit->max_occupancy) && (int) $unit->max_occupancy > 0) {
                        continue;
                    }

                    $activeCount = (int) ($activeCounts[$unit->id] ?? 0);
                    $defaultCapacity = $sharedOptionUnitIds->has($unit->id) ? 2 : 1;
                    $capacity = max($defaultCapacity, $activeCount);

                    DB::table('property_units')
                        ->where('id', $unit->id)
                        ->update(['max_occupancy' => $capacity]);
                }
            });

        $latestReleasedAt = DB::table('tenant_unit_assignments')
            ->whereNotNull('released_at')
            ->selectRaw('unit_id, MAX(released_at) as released_at')
            ->groupBy('unit_id')
            ->pluck('released_at', 'unit_id');

        DB::table('property_units')
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(200, function ($units) use ($latestReleasedAt) {
                foreach ($units as $unit) {
                    $releasedAt = $latestReleasedAt[$unit->id] ?? null;
                    if (! $releasedAt) {
                        continue;
                    }

                    DB::table('property_units')
                        ->where('id', $unit->id)
                        ->update(['last_vacated_at' => $releasedAt]);
                }
            });
    }

    public function down()
    {
        Schema::table('property_units', function (Blueprint $table) {
            $table->dropForeign(['manual_status_changed_by']);
            $table->dropIndex('property_units_manual_availability_status_index');
            $table->dropColumn([
                'manual_availability_status',
                'manual_status_reason',
                'manual_status_changed_at',
                'manual_status_changed_by',
                'last_vacated_at',
            ]);
        });
    }
};
