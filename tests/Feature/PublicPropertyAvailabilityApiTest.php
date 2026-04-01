<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyDetail;
use App\Models\PropertyUnit;
use App\Models\PublicPropertyOption;
use App\Models\Tenant;
use App\Models\TenantUnitAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPropertyAvailabilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_availability_check_returns_available_when_public_option_has_capacity(): void
    {
        [$property, $unit, $option] = $this->createPublicPropertyWithUnit([
            'max_occupancy' => 2,
            'rental_kind' => 'private_room',
        ]);

        $response = $this->postJson("/api/public/properties/{$property->id}/availability-check", [
            'option_id' => $option->id,
            'stay_mode' => 'days',
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-12',
            'guests' => 1,
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+260971111111',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.option_id', $option->id)
            ->assertJsonPath('data.availability_status', 'available');
    }

    public function test_availability_check_returns_full_when_overlapping_active_leases_fill_the_option(): void
    {
        [$property, $unit, $option] = $this->createPublicPropertyWithUnit([
            'max_occupancy' => 1,
            'rental_kind' => 'whole_unit',
        ]);

        $this->assignActiveTenant($property, $unit, [
            'lease_start_date' => '2026-04-01',
            'lease_end_date' => '2026-04-30',
        ]);

        $response = $this->postJson("/api/public/properties/{$property->id}/availability-check", [
            'option_id' => $option->id,
            'stay_mode' => 'days',
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-12',
            'guests' => 1,
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+260971111111',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.units_available', 0)
            ->assertJsonPath('data.availability_status', 'full');
    }

    public function test_availability_check_returns_unknown_when_unit_capacity_is_missing(): void
    {
        [$property, $unit, $option] = $this->createPublicPropertyWithUnit([
            'max_occupancy' => null,
            'rental_kind' => 'private_room',
        ]);

        $response = $this->postJson("/api/public/properties/{$property->id}/availability-check", [
            'option_id' => $option->id,
            'stay_mode' => 'weeks',
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-17',
            'guests' => 1,
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+260971111111',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.availability_status', 'unknown')
            ->assertJsonPath('data.units_unknown', 1);
    }

    public function test_availability_check_respects_manual_on_hold_status(): void
    {
        [$property, $unit, $option] = $this->createPublicPropertyWithUnit([
            'max_occupancy' => 2,
            'rental_kind' => 'private_room',
        ]);
        $unit->forceFill([
            'manual_availability_status' => \App\Models\PropertyUnit::MANUAL_AVAILABILITY_ON_HOLD,
        ])->save();

        $response = $this->postJson("/api/public/properties/{$property->id}/availability-check", [
            'option_id' => $option->id,
            'stay_mode' => 'days',
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-12',
            'guests' => 1,
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+260971111111',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.availability_status', 'on_hold');
    }

    public function test_waitlist_persists_a_record_for_the_selected_option(): void
    {
        [$property, $unit, $option] = $this->createPublicPropertyWithUnit([
            'max_occupancy' => 1,
            'rental_kind' => 'whole_unit',
        ]);

        $response = $this->postJson("/api/public/properties/{$property->id}/waitlist", [
            'option_id' => $option->id,
            'stay_mode' => 'months',
            'start_date' => '2026-05-01',
            'end_date' => '2026-06-01',
            'guests' => 1,
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+260971111111',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.waitlist.option_id', $option->id);

        $this->assertDatabaseHas('public_property_waitlists', [
            'property_id' => $property->id,
            'option_id' => $option->id,
            'email' => 'jane@example.com',
        ]);
    }

    public function test_cross_property_option_is_rejected(): void
    {
        [$firstProperty] = $this->createPublicPropertyWithUnit();
        [, , $otherOption] = $this->createPublicPropertyWithUnit([
            'name' => 'Other Property',
            'slug' => 'other-property',
        ]);

        $response = $this->postJson("/api/public/properties/{$firstProperty->id}/availability-check", [
            'option_id' => $otherOption->id,
            'stay_mode' => 'days',
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-12',
            'guests' => 1,
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+260971111111',
        ]);

        $response
            ->assertStatus(404)
            ->assertJsonPath('status', false);
    }

    private function createPublicPropertyWithUnit(array $overrides = []): array
    {
        $property = Property::query()->forceCreate([
            'property_type' => PROPERTY_TYPE_OWN,
            'name' => $overrides['name'] ?? 'Test Public Property',
            'number_of_unit' => 1,
            'description' => 'Public property description',
            'status' => RENT_CHARGE_ACTIVE_CLASS,
            'is_public' => true,
            'public_slug' => $overrides['slug'] ?? 'test-public-property',
            'public_category' => 'apartment',
            'public_summary' => 'Public summary',
            'public_home_sections' => 'featured,popular',
            'public_sort_order' => 1,
        ]);

        PropertyDetail::query()->forceCreate([
            'property_id' => $property->id,
            'address' => 'Kabulonga Road, Lusaka, Zambia',
        ]);

        $unit = PropertyUnit::query()->forceCreate([
            'property_id' => $property->id,
            'unit_name' => 'Unit A',
            'bedroom' => 1,
            'bath' => 1,
            'kitchen' => 1,
            'max_occupancy' => array_key_exists('max_occupancy', $overrides) ? $overrides['max_occupancy'] : 2,
            'general_rent' => 12000,
            'description' => 'Test unit',
            'square_feet' => '45',
            'amenities' => 'WiFi,Parking',
            'parking' => 'yes',
        ]);

        $option = PublicPropertyOption::query()->forceCreate([
            'property_id' => $property->id,
            'property_unit_id' => $unit->id,
            'rental_kind' => $overrides['rental_kind'] ?? 'whole_unit',
            'monthly_rate' => 12000,
            'nightly_rate' => 700,
            'max_guests' => 2,
            'status' => ACTIVE,
            'sort_order' => 1,
            'is_default' => true,
        ]);

        return [$property, $unit, $option];
    }

    private function assignActiveTenant(Property $property, PropertyUnit $unit, array $overrides = []): void
    {
        $user = User::query()->forceCreate([
            'first_name' => 'Tenant',
            'last_name' => 'User',
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
        ]);

        $tenant = Tenant::query()->forceCreate([
            'user_id' => $user->id,
            'job' => 'Engineer',
            'family_member' => 1,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'rent_type' => RENT_TYPE_MONTHLY,
            'lease_start_date' => $overrides['lease_start_date'] ?? '2026-04-01',
            'lease_end_date' => $overrides['lease_end_date'] ?? '2026-04-30',
            'general_rent' => 12000,
            'security_deposit' => 0,
            'late_fee' => 0,
            'incident_receipt' => 0,
            'status' => TENANT_STATUS_ACTIVE,
        ]);

        TenantUnitAssignment::query()->forceCreate([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
        ]);
    }
}
