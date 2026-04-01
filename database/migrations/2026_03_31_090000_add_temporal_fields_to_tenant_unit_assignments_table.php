<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('tenant_unit_assignments', function (Blueprint $table) {
            $table->timestamp('assigned_at')->nullable()->after('unit_id');
            $table->timestamp('released_at')->nullable()->after('assigned_at');
            $table->text('release_reason')->nullable()->after('released_at');
            $table->unsignedBigInteger('released_by_user_id')->nullable()->after('release_reason');
            $table->boolean('is_current')->default(true)->after('released_by_user_id');

            $table->index(['unit_id', 'is_current'], 'tenant_unit_assignments_unit_current_index');
            $table->index(['tenant_id', 'is_current'], 'tenant_unit_assignments_tenant_current_index');
            $table->foreign('released_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        try {
            Schema::table('tenant_unit_assignments', function (Blueprint $table) {
                $table->dropUnique('tenant_unit_unique');
            });
        } catch (\Throwable $throwable) {
        }

        DB::table('tenant_unit_assignments')
            ->select(['id', 'tenant_id', 'property_id', 'unit_id', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(200, function ($assignments) {
                $tenantIds = $assignments->pluck('tenant_id')->filter()->unique()->values();
                $tenants = DB::table('tenants')
                    ->whereIn('id', $tenantIds)
                    ->select(['id', 'property_id', 'unit_id', 'status', 'close_date'])
                    ->get()
                    ->keyBy('id');

                foreach ($assignments as $assignment) {
                    $tenant = $tenants->get($assignment->tenant_id);
                    $assignedAt = $assignment->created_at
                        ?? $assignment->updated_at
                        ?? now()->toDateTimeString();

                    $isCurrent = false;
                    $releasedAt = null;
                    $releaseReason = null;

                    if ($tenant) {
                        $isCurrent = (int) $tenant->status !== TENANT_STATUS_CLOSE
                            && (int) $tenant->property_id === (int) $assignment->property_id
                            && (int) $tenant->unit_id === (int) $assignment->unit_id;

                        if (! $isCurrent) {
                            $releasedAt = $tenant->close_date
                                ? Carbon::parse($tenant->close_date)->endOfDay()->toDateTimeString()
                                : ($assignment->updated_at ?? $assignedAt);
                            $releaseReason = __('Backfilled from legacy tenant/unit state');
                        }
                    }

                    DB::table('tenant_unit_assignments')
                        ->where('id', $assignment->id)
                        ->update([
                            'assigned_at' => $assignedAt,
                            'released_at' => $releasedAt,
                            'release_reason' => $releaseReason,
                            'is_current' => $isCurrent,
                        ]);
                }
            });
    }

    public function down()
    {
        try {
            Schema::table('tenant_unit_assignments', function (Blueprint $table) {
                $table->unique(['tenant_id', 'unit_id'], 'tenant_unit_unique');
            });
        } catch (\Throwable $throwable) {
        }

        Schema::table('tenant_unit_assignments', function (Blueprint $table) {
            $table->dropForeign(['released_by_user_id']);
            $table->dropIndex('tenant_unit_assignments_unit_current_index');
            $table->dropIndex('tenant_unit_assignments_tenant_current_index');
            $table->dropColumn([
                'assigned_at',
                'released_at',
                'release_reason',
                'released_by_user_id',
                'is_current',
            ]);
        });
    }
};
