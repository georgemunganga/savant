<?php

namespace Database\Seeders;

use App\Models\PropertyUnit;
use App\Models\TenantUnitAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class UserSeeder extends Seeder
{
    private array $tableColumns = [];

    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // Admin
        User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'first_name' => 'Mr',
                'last_name' => 'Admin',
                'password' => Hash::make('12345678'),
                'contact_number' => '01951973806',
                'status' => 1,
                'role' => 4,
                'email_verified_at' => now(),
            ]
        );

        // Owner
        $owner = User::firstOrCreate(
            ['email' => 'owner@gmail.com'],
            [
                'first_name' => 'Mr',
                'last_name' => 'Owner',
                'password' => Hash::make('12345678'),
                'contact_number' => '01952973806',
                'status' => 1,
                'role' => 1,
                'email_verified_at' => now(),
            ]
        );

        if ($owner->owner_user_id !== $owner->id) {
            $owner->owner_user_id = $owner->id;
            $owner->save();
        }

        $this->seedTestTenant($owner->id);
    }

    private function seedTestTenant(int $ownerId): void
    {
        DB::transaction(function () use ($ownerId) {
            $propertyId = $this->upsertProperty($ownerId);
            $unitId = $this->upsertPropertyUnit($propertyId);
            $user = User::updateOrCreate(
                ['email' => 'tenant@gmail.com'],
                $this->filterColumns('users', [
                    'first_name' => 'Test',
                    'last_name' => 'Tenant',
                    'password' => Hash::make('12345678'),
                    'contact_number' => null,
                    'status' => USER_STATUS_ACTIVE,
                    'role' => USER_ROLE_TENANT,
                    'owner_user_id' => $ownerId,
                    'email_verified_at' => now(),
                ])
            );

            $tenantId = $this->upsertTenant($user->id, $ownerId, $propertyId, $unitId);

            if (Schema::hasTable('tenant_unit_assignments')) {
                $this->syncTenantAssignment($tenantId, $propertyId, $unitId);
            }

            $this->seedOwnerSupportConfig($ownerId);
            $this->seedOwnerDocumentConfigs($ownerId);
        });
    }

    private function upsertProperty(int $ownerId): int
    {
        $propertyQuery = DB::table('properties')
            ->where('name', 'Savant Test Property');

        if (Schema::hasColumn('properties', 'owner_user_id')) {
            $propertyQuery->where('owner_user_id', $ownerId);
        }

        $existingId = $propertyQuery->value('id');

        $payload = $this->filterColumns('properties', [
            'owner_user_id' => $ownerId,
            'property_type' => PROPERTY_TYPE_OWN,
            'name' => 'Savant Test Property',
            'number_of_unit' => 1,
            'description' => 'Seeded property for local tenant login testing.',
            'unit_type' => PROPERTY_UNIT_TYPE_SINGLE,
            'status' => PROPERTY_STATUS_ACTIVE,
            'updated_at' => now(),
        ]);

        if ($existingId) {
            DB::table('properties')
                ->where('id', $existingId)
                ->update($payload);

            return (int) $existingId;
        }

        return (int) DB::table('properties')->insertGetId($payload + [
            'created_at' => now(),
        ]);
    }

    private function upsertPropertyUnit(int $propertyId): int
    {
        $existingId = DB::table('property_units')
            ->where('property_id', $propertyId)
            ->where('unit_name', 'Unit A')
            ->value('id');

        $payload = $this->filterColumns('property_units', [
            'property_id' => $propertyId,
            'unit_name' => 'Unit A',
            'bedroom' => 1,
            'bath' => 1,
            'kitchen' => 1,
            'max_occupancy' => 1,
            'general_rent' => 2500,
            'security_deposit' => 0,
            'late_fee' => 0,
            'incident_receipt' => 0,
            'rent_type' => PROPERTY_UNIT_RENT_TYPE_MONTHLY,
            'lease_start_date' => now()->toDateString(),
            'description' => 'Seeded unit for local tenant login testing.',
            'square_feet' => '450',
            'amenities' => 'WiFi,Furnished,Security',
            'parking' => '1',
            'condition' => 'ready',
            'manual_availability_status' => PropertyUnit::MANUAL_AVAILABILITY_ACTIVE,
            'manual_status_reason' => null,
            'manual_status_changed_at' => null,
            'manual_status_changed_by' => null,
            'last_vacated_at' => null,
            'updated_at' => now(),
        ]);

        if ($existingId) {
            DB::table('property_units')
                ->where('id', $existingId)
                ->update($payload);

            return (int) $existingId;
        }

        return (int) DB::table('property_units')->insertGetId($payload + [
            'created_at' => now(),
        ]);
    }

    private function upsertTenant(int $userId, int $ownerId, int $propertyId, int $unitId): int
    {
        $existingId = DB::table('tenants')
            ->where('user_id', $userId)
            ->value('id');

        $payload = $this->filterColumns('tenants', [
            'user_id' => $userId,
            'owner_user_id' => $ownerId,
            'job' => 'QA Tester',
            'family_member' => 1,
            'property_id' => $propertyId,
            'unit_id' => $unitId,
            'rent_type' => RENT_TYPE_MONTHLY,
            'lease_start_date' => now()->toDateString(),
            'general_rent' => 2500,
            'security_deposit' => 0,
            'late_fee' => 0,
            'incident_receipt' => 0,
            'status' => TENANT_STATUS_ACTIVE,
            'close_date' => null,
            'close_reason' => null,
            'updated_at' => now(),
        ]);

        if ($existingId) {
            DB::table('tenants')
                ->where('id', $existingId)
                ->update($payload);

            return (int) $existingId;
        }

        return (int) DB::table('tenants')->insertGetId($payload + [
            'created_at' => now(),
        ]);
    }

    private function syncTenantAssignment(int $tenantId, int $propertyId, int $unitId): void
    {
        $stalePayload = $this->filterColumns('tenant_unit_assignments', [
            'is_current' => false,
            'released_at' => now(),
            'release_reason' => 'Superseded by UserSeeder',
            'updated_at' => now(),
        ]);

        if ($stalePayload !== []) {
            DB::table('tenant_unit_assignments')
                ->where('tenant_id', $tenantId)
                ->where('unit_id', '!=', $unitId)
                ->update($stalePayload);
        }

        $match = $this->filterColumns('tenant_unit_assignments', [
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
        ]);

        $payload = $this->filterColumns('tenant_unit_assignments', [
            'tenant_id' => $tenantId,
            'property_id' => $propertyId,
            'unit_id' => $unitId,
            'assigned_at' => now(),
            'released_at' => null,
            'release_reason' => null,
            'released_by_user_id' => null,
            'is_current' => true,
            'updated_at' => now(),
        ]);

        TenantUnitAssignment::updateOrCreate($match, $payload);
    }

    private function seedOwnerSupportConfig(int $ownerId): void
    {
        $this->upsertOwnerScopedNames('maintenance_issues', $ownerId, [
            'Plumbing',
            'Electrical',
            'Security',
        ]);

        $this->upsertOwnerScopedNames('ticket_topics', $ownerId, [
            'Maintenance',
            'Billing',
            'General',
        ]);
    }

    private function seedOwnerDocumentConfigs(int $ownerId): void
    {
        $this->upsertOwnerScopedRecords('kyc_configs', $ownerId, 'name', [
            [
                'name' => 'Passport Copy',
                'details' => 'Upload a clear passport copy for identity verification.',
                'tenant_id' => null,
                'is_both' => DEACTIVATE,
                'status' => ACTIVE,
            ],
            [
                'name' => 'National ID',
                'details' => 'Upload the front and back of your national ID for identity verification.',
                'tenant_id' => null,
                'is_both' => ACTIVE,
                'status' => ACTIVE,
            ],
        ]);
    }

    private function upsertOwnerScopedNames(string $table, int $ownerId, array $names): void
    {
        foreach ($names as $name) {
            $query = DB::table($table)->where('name', $name);

            if ($this->tableHasColumn($table, 'owner_user_id')) {
                $query->where('owner_user_id', $ownerId);
            }

            $existingId = $query->value('id');
            $timestamp = now();
            $payload = $this->filterColumns($table, [
                'name' => $name,
                'owner_user_id' => $ownerId,
                'status' => ACTIVE,
                'deleted_at' => null,
                'updated_at' => $timestamp,
            ]);

            if ($existingId) {
                DB::table($table)->where('id', $existingId)->update($payload);
                continue;
            }

            DB::table($table)->insert($payload + $this->filterColumns($table, [
                'created_at' => $timestamp,
            ]));
        }
    }

    private function upsertOwnerScopedRecords(
        string $table,
        int $ownerId,
        string $lookupKey,
        array $records
    ): void {
        foreach ($records as $record) {
            $query = DB::table($table)->where($lookupKey, $record[$lookupKey]);

            if ($this->tableHasColumn($table, 'owner_user_id')) {
                $query->where('owner_user_id', $ownerId);
            }

            $existingId = $query->value('id');
            $timestamp = now();
            $payload = $this->filterColumns($table, $record + [
                'owner_user_id' => $ownerId,
                'deleted_at' => null,
                'updated_at' => $timestamp,
            ]);

            if ($existingId) {
                DB::table($table)->where('id', $existingId)->update($payload);
                continue;
            }

            DB::table($table)->insert($payload + $this->filterColumns($table, [
                'created_at' => $timestamp,
            ]));
        }
    }

    private function filterColumns(string $table, array $payload): array
    {
        if (! isset($this->tableColumns[$table])) {
            $this->tableColumns[$table] = array_flip(Schema::getColumnListing($table));
        }

        return array_intersect_key($payload, $this->tableColumns[$table]);
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        if (! isset($this->tableColumns[$table])) {
            $this->tableColumns[$table] = array_flip(Schema::getColumnListing($table));
        }

        return isset($this->tableColumns[$table][$column]);
    }
}
