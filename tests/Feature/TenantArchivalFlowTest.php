<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Owner;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\Tenant;
use App\Models\TenantDetails;
use App\Models\TenantUnitAssignment;
use App\Models\User;
use App\Services\TenantService;
use App\Services\UnitAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantArchivalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_closing_a_tenant_archives_the_record_disables_access_and_backfills_assignment_history(): void
    {
        $owner = $this->createOwnerUser();
        [$property, $unit] = $this->createPropertyAndUnit($owner, ['max_occupancy' => 1]);
        $tenant = $this->createTenant($owner, $property, $unit, [
            'with_assignment' => false,
            'with_invoice' => true,
            'status' => TENANT_STATUS_ACTIVE,
        ]);

        $this->actingAs($owner);

        $this->postJson(route('owner.tenant.close.history.store', $tenant->id), [
            'close_refund_amount' => 250,
            'close_charge' => 75,
            'close_date' => '2026-03-31',
            'close_reason' => 'Lease completed',
        ])->assertOk()->assertJsonPath('status', true);

        $tenant->refresh();
        $tenantUser = User::query()->findOrFail($tenant->user_id);

        $this->assertSame(TENANT_STATUS_CLOSE, (int) $tenant->status);
        $this->assertNull($tenant->property_id);
        $this->assertNull($tenant->unit_id);
        $this->assertSame('2026-03-31', (string) $tenant->close_date);
        $this->assertSame('Lease completed', $tenant->close_reason);
        $this->assertSame(USER_STATUS_INACTIVE, (int) $tenantUser->status);
        $this->assertDatabaseHas('tenant_details', ['tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('invoices', ['tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('tenant_unit_assignments', [
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
        ]);

        Auth::logout();

        $this->post('/login', [
            'email' => $tenantUser->email,
            'password' => 'secret123!',
        ])->assertRedirect(route('login'))->assertSessionHas('error');

        $this->postJson('/api/login', [
            'email' => $tenantUser->email,
            'password' => 'secret123!',
        ])->assertStatus(500)->assertJsonPath('status', false);

        $this->actingAs($tenantUser);
        $this->get('/tenant')->assertRedirect(route('login'));
    }

    public function test_closed_tenant_unit_can_be_reassigned_to_a_new_active_tenant_without_deleting_history(): void
    {
        $owner = $this->createOwnerUser();
        [$property, $unit] = $this->createPropertyAndUnit($owner, ['max_occupancy' => 1]);
        $archivedTenant = $this->createTenant($owner, $property, $unit, [
            'with_assignment' => true,
            'status' => TENANT_STATUS_ACTIVE,
        ]);

        $this->actingAs($owner);

        $this->postJson(route('owner.tenant.close.history.store', $archivedTenant->id), [
            'close_refund_amount' => 0,
            'close_charge' => 0,
            'close_date' => '2026-03-31',
            'close_reason' => 'Moved out',
        ])->assertOk()->assertJsonPath('status', true);

        $replacement = $this->createTenant($owner, null, null, [
            'status' => TENANT_STATUS_DRAFT,
            'with_assignment' => false,
            'property_id' => null,
            'unit_id' => null,
        ]);

        $service = new TenantService();

        $service->step2(new Request([
            'id' => $replacement->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'lease_start_date' => '2026-04-01',
            'lease_end_date' => '2026-05-01',
            'general_rent' => 1500,
            'security_deposit_type' => 0,
            'security_deposit' => 0,
            'late_fee_type' => 0,
            'late_fee' => 0,
            'incident_receipt' => 0,
            'due_date' => 5,
        ]));

        $service->step3(new Request([
            'id' => $replacement->id,
        ]));

        $replacement->refresh();
        $archivedTenant->refresh();

        $this->assertSame(TENANT_STATUS_ACTIVE, (int) $replacement->status);
        $this->assertSame($property->id, (int) $replacement->property_id);
        $this->assertSame($unit->id, (int) $replacement->unit_id);
        $this->assertSame(TENANT_STATUS_CLOSE, (int) $archivedTenant->status);
        $this->assertDatabaseCount('tenant_unit_assignments', 2);

        $activeOccupants = TenantUnitAssignment::query()
            ->join('tenants', 'tenant_unit_assignments.tenant_id', '=', 'tenants.id')
            ->where('tenant_unit_assignments.unit_id', $unit->id)
            ->where('tenants.status', TENANT_STATUS_ACTIVE)
            ->count(DB::raw('DISTINCT tenants.id'));

        $this->assertSame(1, $activeOccupants);
    }

    public function test_closing_a_tenant_marks_the_assignment_released_and_updates_unit_vacancy_timestamp(): void
    {
        $owner = $this->createOwnerUser();
        [$property, $unit] = $this->createPropertyAndUnit($owner, ['max_occupancy' => 1]);
        $tenant = $this->createTenant($owner, $property, $unit, [
            'with_assignment' => true,
            'status' => TENANT_STATUS_ACTIVE,
        ]);

        $this->actingAs($owner);

        $this->postJson(route('owner.tenant.close.history.store', $tenant->id), [
            'close_refund_amount' => 0,
            'close_charge' => 0,
            'close_date' => '2026-03-31',
            'close_reason' => 'Moved out cleanly',
        ])->assertOk()->assertJsonPath('status', true);

        $assignment = TenantUnitAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('property_id', $property->id)
            ->where('unit_id', $unit->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($assignment);
        $this->assertFalse((bool) $assignment->is_current);
        $this->assertNotNull($assignment->released_at);
        $this->assertSame('Moved out cleanly', $assignment->release_reason);
        $this->assertSame($owner->id, (int) $assignment->released_by_user_id);
        $this->assertDatabaseHas('property_units', [
            'id' => $unit->id,
        ]);
        $this->assertDatabaseHas('property_unit_activity_logs', [
            'unit_id' => $unit->id,
            'tenant_id' => $tenant->id,
            'event_type' => 'tenant_moved_out',
        ]);
        $this->assertNotNull($unit->fresh()->last_vacated_at);
    }

    public function test_shared_unit_allows_a_second_active_tenant_and_reports_full_capacity_at_the_limit(): void
    {
        $owner = $this->createOwnerUser();
        [$property, $unit] = $this->createPropertyAndUnit($owner, [
            'max_occupancy' => 2,
        ]);
        $firstTenant = $this->createTenant($owner, $property, $unit, [
            'with_assignment' => true,
            'status' => TENANT_STATUS_ACTIVE,
        ]);

        $this->actingAs($owner);

        $secondTenant = $this->createTenant($owner, null, null, [
            'status' => TENANT_STATUS_DRAFT,
            'with_assignment' => false,
            'property_id' => null,
            'unit_id' => null,
            'email' => 'second@example.test',
        ]);

        $service = new TenantService();
        $service->step2(new Request([
            'id' => $secondTenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'lease_start_date' => '2026-04-01',
            'lease_end_date' => '2026-05-01',
            'general_rent' => 1500,
            'security_deposit_type' => 0,
            'security_deposit' => 0,
            'late_fee_type' => 0,
            'late_fee' => 0,
            'incident_receipt' => 0,
            'due_date' => 5,
        ]));
        $service->step3(new Request([
            'id' => $secondTenant->id,
        ]));

        $activeAssignments = TenantUnitAssignment::query()
            ->join('tenants', 'tenant_unit_assignments.tenant_id', '=', 'tenants.id')
            ->where('tenant_unit_assignments.unit_id', $unit->id)
            ->where('tenant_unit_assignments.is_current', true)
            ->where('tenants.status', TENANT_STATUS_ACTIVE)
            ->count(DB::raw('DISTINCT tenants.id'));

        $this->assertSame(2, $activeAssignments);

        $unitSummary = (new UnitAvailabilityService())->getUnits([
            'owner_user_id' => $owner->id,
            'property_ids' => [$property->id],
            'unit_ids' => [$unit->id],
        ])->first();

        $this->assertNotNull($unitSummary);
        $this->assertSame('full', $unitSummary->occupancy_state);
        $this->assertSame(0, (int) $unitSummary->available_slots);
        $this->assertStringContainsString('2/2', $unitSummary->occupancy_label);
    }

    public function test_only_draft_tenants_can_be_deleted(): void
    {
        $owner = $this->createOwnerUser();
        [$property, $unit] = $this->createPropertyAndUnit($owner, ['max_occupancy' => 2]);
        $activeTenant = $this->createTenant($owner, $property, $unit, [
            'with_assignment' => true,
            'status' => TENANT_STATUS_ACTIVE,
        ]);
        $draftTenant = $this->createTenant($owner, null, null, [
            'status' => TENANT_STATUS_DRAFT,
            'with_assignment' => false,
            'property_id' => null,
            'unit_id' => null,
        ]);

        $this->actingAs($owner);

        $this->postJson(route('owner.tenant.delete'), [
            'tenant_id' => $activeTenant->id,
            'email' => $activeTenant->user->email,
        ])->assertStatus(500)->assertJsonPath('status', false);

        $this->assertDatabaseHas('tenants', ['id' => $activeTenant->id]);

        $this->postJson(route('owner.tenant.delete'), [
            'tenant_id' => $draftTenant->id,
            'email' => $draftTenant->user->email,
        ])->assertOk()->assertJsonPath('status', true);

        $this->assertSoftDeleted('tenants', ['id' => $draftTenant->id]);
        $this->assertSoftDeleted('tenant_details', ['tenant_id' => $draftTenant->id]);
        $this->assertDatabaseMissing('users', ['id' => $draftTenant->user_id]);
    }

    public function test_live_tenant_collection_excludes_archived_tenants(): void
    {
        $owner = $this->createOwnerUser();
        [$property, $unit] = $this->createPropertyAndUnit($owner);
        $activeTenant = $this->createTenant($owner, $property, $unit, [
            'with_assignment' => true,
            'status' => TENANT_STATUS_ACTIVE,
            'email' => 'active@example.test',
        ]);
        $archivedTenant = $this->createTenant($owner, $property, $unit, [
            'with_assignment' => true,
            'status' => TENANT_STATUS_CLOSE,
            'email' => 'archived@example.test',
        ]);

        $this->actingAs($owner);

        $visibleTenantIds = (new TenantService())->getAll()->pluck('id')->all();

        $this->assertContains($activeTenant->id, $visibleTenantIds);
        $this->assertNotContains($archivedTenant->id, $visibleTenantIds);
    }

    private function createOwnerUser(): User
    {
        $owner = User::query()->forceCreate([
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => 'owner@example.test',
            'password' => Hash::make('owner-secret'),
            'contact_number' => '260000000001',
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_OWNER,
            'owner_user_id' => null,
        ]);

        Owner::query()->forceCreate([
            'user_id' => $owner->id,
            'status' => ACTIVE,
        ]);

        return $owner;
    }

    private function createPropertyAndUnit(User $owner, array $unitOverrides = []): array
    {
        $property = Property::query()->forceCreate([
            'owner_user_id' => $owner->id,
            'property_type' => PROPERTY_TYPE_OWN,
            'name' => 'Test Property',
            'number_of_unit' => 1,
            'description' => 'Tenant archive test property',
            'status' => RENT_CHARGE_ACTIVE_CLASS,
        ]);

        $unit = PropertyUnit::query()->forceCreate(array_merge([
            'property_id' => $property->id,
            'unit_name' => 'Unit A',
            'bedroom' => 1,
            'bath' => 1,
            'kitchen' => 1,
            'general_rent' => 1500,
            'security_deposit' => 0,
            'late_fee' => 0,
            'incident_receipt' => 0,
            'max_occupancy' => 1,
        ], $unitOverrides));

        return [$property, $unit];
    }

    private function createTenant(User $owner, ?Property $property, ?PropertyUnit $unit, array $overrides = []): Tenant
    {
        $tenantUser = User::query()->forceCreate([
            'first_name' => $overrides['first_name'] ?? 'Tenant',
            'last_name' => $overrides['last_name'] ?? 'User',
            'email' => $overrides['email'] ?? 'tenant' . random_int(1000, 9999) . '@example.test',
            'password' => Hash::make($overrides['password'] ?? 'secret123!'),
            'contact_number' => $overrides['contact_number'] ?? '26000000' . random_int(100, 999),
            'status' => $overrides['user_status'] ?? USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
            'owner_user_id' => $owner->id,
        ]);

        $tenant = Tenant::query()->forceCreate([
            'user_id' => $tenantUser->id,
            'owner_user_id' => $owner->id,
            'job' => 'Engineer',
            'image_id' => null,
            'family_member' => 1,
            'property_id' => array_key_exists('property_id', $overrides) ? $overrides['property_id'] : $property?->id,
            'unit_id' => array_key_exists('unit_id', $overrides) ? $overrides['unit_id'] : $unit?->id,
            'rent_type' => RENT_TYPE_MONTHLY,
            'due_date' => 5,
            'lease_start_date' => '2026-03-01',
            'lease_end_date' => '2026-03-31',
            'general_rent' => 1500,
            'security_deposit' => 0,
            'security_deposit_type' => 0,
            'late_fee' => 0,
            'late_fee_type' => 0,
            'incident_receipt' => 0,
            'status' => $overrides['status'] ?? TENANT_STATUS_ACTIVE,
            'close_refund_amount' => 0,
            'close_charge' => 0,
            'close_date' => null,
            'close_reason' => null,
            'tenant_type' => 'person',
        ]);

        TenantDetails::query()->forceCreate([
            'tenant_id' => $tenant->id,
            'previous_address' => 'Old address',
            'permanent_address' => 'Permanent address',
        ]);

        if (($overrides['with_assignment'] ?? true) && $property && $unit) {
            TenantUnitAssignment::query()->forceCreate([
                'tenant_id' => $tenant->id,
                'property_id' => $property->id,
                'unit_id' => $unit->id,
            ]);
        }

        if (($overrides['with_invoice'] ?? false) && $property && $unit) {
            Invoice::query()->forceCreate([
                'tenant_id' => $tenant->id,
                'owner_user_id' => $owner->id,
                'property_id' => $property->id,
                'property_unit_id' => $unit->id,
                'name' => 'Rent Invoice',
                'invoice_no' => 'INV-' . $tenant->id . '-' . random_int(1000, 9999),
                'month' => '2026-03',
                'due_date' => '2026-03-15',
                'amount' => 1500,
                'status' => INVOICE_STATUS_PENDING,
            ]);
        }

        return $tenant->fresh(['user']);
    }
}
