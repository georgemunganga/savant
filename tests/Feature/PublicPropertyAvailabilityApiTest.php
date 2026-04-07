<?php

namespace Tests\Feature;

use App\Mail\TenantPortalActionMail;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\PropertyDetail;
use App\Models\PropertyUnit;
use App\Models\PublicPropertyBooking;
use App\Models\PublicPropertyOption;
use App\Models\Tenant;
use App\Models\TenantUnitAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

    public function test_property_level_shared_space_uses_aggregate_bedspace_capacity_instead_of_treating_the_property_as_full(): void
    {
        [$property, $firstUnit, $option] = $this->createPublicPropertyWithUnit([
            'category' => 'boarding',
            'rental_kind' => 'shared_space',
            'property_unit_id' => null,
            'max_occupancy' => 4,
            'max_guests' => 6,
        ]);

        PropertyUnit::query()->forceCreate([
            'property_id' => $property->id,
            'unit_name' => 'Unit B',
            'bedroom' => 1,
            'bath' => 1,
            'kitchen' => 1,
            'max_occupancy' => 2,
            'general_rent' => 9000,
            'description' => 'Second unit',
            'square_feet' => '38',
            'amenities' => 'WiFi,Parking',
            'parking' => 'yes',
        ]);

        $this->assignActiveTenant($property, $firstUnit, [
            'lease_start_date' => '2026-04-01',
            'lease_end_date' => '2026-04-30',
        ]);
        $this->assignActiveTenant($property, $firstUnit, [
            'lease_start_date' => '2026-04-05',
            'lease_end_date' => '2026-04-20',
        ]);

        $this->postJson("/api/public/properties/{$property->id}/availability-check", [
            'option_id' => $option->id,
            'stay_mode' => 'months',
            'start_date' => '2026-04-10',
            'end_date' => '2026-05-10',
            'guests' => 1,
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+260971111111',
        ])
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.inventory_mode', 'bedspace')
            ->assertJsonPath('data.capacity_total', 6)
            ->assertJsonPath('data.capacity_available', 4)
            ->assertJsonPath('data.eligible_units_total', 2)
            ->assertJsonPath('data.availability_status', 'available');
    }

    public function test_property_level_shared_space_excludes_on_hold_units_from_bedspace_capacity_instead_of_blocking_the_entire_property(): void
    {
        [$property, $firstUnit, $option] = $this->createPublicPropertyWithUnit([
            'category' => 'boarding',
            'rental_kind' => 'shared_space',
            'property_unit_id' => null,
            'max_occupancy' => 4,
            'max_guests' => 6,
        ]);

        $firstUnit->forceFill([
            'manual_availability_status' => \App\Models\PropertyUnit::MANUAL_AVAILABILITY_ON_HOLD,
        ])->save();

        PropertyUnit::query()->forceCreate([
            'property_id' => $property->id,
            'unit_name' => 'Unit B',
            'bedroom' => 1,
            'bath' => 1,
            'kitchen' => 1,
            'max_occupancy' => 2,
            'general_rent' => 9000,
            'description' => 'Available unit',
            'square_feet' => '38',
            'amenities' => 'WiFi,Parking',
            'parking' => 'yes',
        ]);

        $this->postJson("/api/public/properties/{$property->id}/availability-check", [
            'option_id' => $option->id,
            'stay_mode' => 'months',
            'start_date' => '2026-04-10',
            'end_date' => '2026-05-10',
            'guests' => 2,
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+260971111111',
        ])
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.inventory_mode', 'bedspace')
            ->assertJsonPath('data.capacity_total', 2)
            ->assertJsonPath('data.capacity_available', 2)
            ->assertJsonPath('data.eligible_units_total', 1)
            ->assertJsonPath('data.availability_status', 'available');
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

    public function test_booking_confirm_creates_a_tenant_account_and_assignment_for_a_unit_backed_option(): void
    {
        Mail::fake();
        $this->enableTenantPortalMail();

        $owner = $this->createOwnerUser();
        [$property, $unit, $option] = $this->createPublicPropertyWithUnit([
            'owner_user_id' => $owner->id,
            'max_occupancy' => 2,
            'rental_kind' => 'whole_unit',
        ]);

        $response = $this->postJson("/api/public/properties/{$property->id}/bookings/confirm", [
            'option_id' => $option->id,
            'stay_mode' => 'months',
            'start_date' => '2026-05-01',
            'end_date' => '2026-06-01',
            'guests' => 2,
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+260971111111',
            'payment_plan' => 'later',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.requires_confirmation', false)
            ->assertJsonPath('data.booking.account_created', true)
            ->assertJsonPath('data.booking.setup_email_sent', true)
            ->assertJsonPath('data.booking.has_assignment', true)
            ->assertJsonPath('data.booking.assignment_created', true);

        $tenantUser = User::query()->where('email', 'jane@example.com')->firstOrFail();
        $tenant = Tenant::query()->where('user_id', $tenantUser->id)->firstOrFail();

        $this->assertSame(USER_ROLE_TENANT, (int) $tenantUser->role);
        $this->assertSame($property->id, (int) $tenant->property_id);
        $this->assertSame($unit->id, (int) $tenant->unit_id);
        $this->assertSame('2026-05-01', (string) $tenant->lease_start_date);
        $this->assertSame('2026-06-01', (string) $tenant->lease_end_date);
        $this->assertDatabaseHas('tenant_unit_assignments', [
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
        ]);
        $this->assertDatabaseHas('public_property_bookings', [
            'property_id' => $property->id,
            'option_id' => $option->id,
            'tenant_id' => $tenant->id,
            'email' => 'jane@example.com',
            'status' => PublicPropertyBooking::STATUS_CONFIRMED,
        ]);

        Mail::assertSent(TenantPortalActionMail::class, 2);
    }

    public function test_booking_confirm_reuses_an_existing_tenant_and_leaves_whole_property_options_pending_assignment(): void
    {
        Mail::fake();
        $this->enableTenantPortalMail();

        $owner = $this->createOwnerUser();
        [$property, $unit, $option] = $this->createPublicPropertyWithUnit([
            'owner_user_id' => $owner->id,
            'rental_kind' => 'whole_property',
            'property_unit_id' => null,
        ]);

        $user = User::query()->forceCreate([
            'first_name' => 'Existing',
            'last_name' => 'Tenant',
            'email' => 'existing@example.com',
            'password' => Hash::make('secret123!'),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
            'owner_user_id' => $owner->id,
            'email_verified_at' => now(),
        ]);

        $tenant = Tenant::query()->forceCreate([
            'user_id' => $user->id,
            'owner_user_id' => $owner->id,
            'job' => 'Engineer',
            'family_member' => 1,
            'property_id' => null,
            'unit_id' => null,
            'rent_type' => RENT_TYPE_MONTHLY,
            'general_rent' => 0,
            'security_deposit' => 0,
            'late_fee' => 0,
            'incident_receipt' => 0,
            'status' => TENANT_STATUS_ACTIVE,
        ]);

        $this->postJson("/api/public/properties/{$property->id}/bookings/confirm", [
            'option_id' => $option->id,
            'stay_mode' => 'months',
            'start_date' => '2026-05-01',
            'end_date' => '2026-06-01',
            'guests' => 1,
            'full_name' => 'Existing Tenant',
            'email' => 'existing@example.com',
            'phone' => '+260971111111',
            'payment_plan' => 'later',
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.requires_confirmation', false)
            ->assertJsonPath('data.booking.account_created', false)
            ->assertJsonPath('data.booking.setup_email_sent', false)
            ->assertJsonPath('data.booking.has_assignment', false)
            ->assertJsonPath('data.booking.assignment_created', true);

        $tenant->refresh();

        $this->assertSame($property->id, (int) $tenant->property_id);
        $this->assertNull($tenant->unit_id);
        $this->assertSame('2026-05-01', (string) $tenant->lease_start_date);
        $this->assertSame('2026-06-01', (string) $tenant->lease_end_date);
        $this->assertSame(1, User::query()->where('email', 'existing@example.com')->count());

        Mail::assertNothingSent();
    }

    public function test_booking_confirm_allows_phone_to_be_optional(): void
    {
        Mail::fake();
        $this->enableTenantPortalMail();

        $owner = $this->createOwnerUser();
        [$property, $unit, $option] = $this->createPublicPropertyWithUnit([
            'owner_user_id' => $owner->id,
            'max_occupancy' => 2,
            'rental_kind' => 'whole_unit',
        ]);

        $this->postJson("/api/public/properties/{$property->id}/bookings/confirm", [
            'option_id' => $option->id,
            'stay_mode' => 'months',
            'start_date' => '2026-07-01',
            'end_date' => '2026-08-01',
            'guests' => 1,
            'full_name' => 'Optional Phone',
            'email' => 'optional-phone@example.com',
            'payment_plan' => 'later',
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.requires_confirmation', false)
            ->assertJsonPath('data.booking.account_created', true);

        $this->assertDatabaseHas('users', [
            'email' => 'optional-phone@example.com',
            'contact_number' => null,
        ]);
    }

    public function test_booking_confirm_reuses_existing_tenant_when_phone_number_is_already_saved(): void
    {
        Mail::fake();
        $this->enableTenantPortalMail();

        $owner = $this->createOwnerUser();
        [$firstProperty] = $this->createPublicPropertyWithUnit([
            'owner_user_id' => $owner->id,
            'slug' => 'first-property',
        ]);
        [$secondProperty, $unit, $option] = $this->createPublicPropertyWithUnit([
            'owner_user_id' => $owner->id,
            'slug' => 'second-property',
        ]);

        $user = User::query()->forceCreate([
            'first_name' => 'Repeat',
            'last_name' => 'Guest',
            'email' => 'repeat@example.com',
            'contact_number' => '+260972827372',
            'password' => Hash::make('secret123!'),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
            'owner_user_id' => $owner->id,
            'email_verified_at' => now(),
        ]);

        $tenant = Tenant::query()->forceCreate([
            'user_id' => $user->id,
            'owner_user_id' => $owner->id,
            'job' => 'Engineer',
            'family_member' => 1,
            'property_id' => $firstProperty->id,
            'unit_id' => null,
            'rent_type' => RENT_TYPE_MONTHLY,
            'general_rent' => 0,
            'security_deposit' => 0,
            'late_fee' => 0,
            'incident_receipt' => 0,
            'status' => TENANT_STATUS_ACTIVE,
        ]);

        $this->postJson("/api/public/properties/{$secondProperty->id}/bookings/confirm", [
            'option_id' => $option->id,
            'stay_mode' => 'months',
            'start_date' => '2026-09-01',
            'end_date' => '2026-10-01',
            'guests' => 1,
            'full_name' => 'Repeat Guest',
            'email' => 'repeat@example.com',
            'phone' => '+260972827372',
            'payment_plan' => 'later',
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.requires_confirmation', false)
            ->assertJsonPath('data.booking.account_created', false)
            ->assertJsonPath('data.booking.tenant_id', $tenant->id);

        $this->assertSame(1, User::query()->where('email', 'repeat@example.com')->count());
        $this->assertSame(1, User::query()->where('contact_number', '+260972827372')->count());
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

    public function test_booking_confirm_requires_confirmation_when_existing_tenant_has_an_active_or_upcoming_stay(): void
    {
        Mail::fake();
        $this->enableTenantPortalMail();

        $owner = $this->createOwnerUser();
        [$firstProperty, $firstUnit] = $this->createPublicPropertyWithUnit([
            'owner_user_id' => $owner->id,
            'slug' => 'existing-stay-property',
        ]);
        [$secondProperty, , $secondOption] = $this->createPublicPropertyWithUnit([
            'owner_user_id' => $owner->id,
            'slug' => 'new-booking-property',
        ]);

        $user = User::query()->forceCreate([
            'first_name' => 'Repeat',
            'last_name' => 'Tenant',
            'email' => 'repeat-confirm@example.com',
            'password' => Hash::make('secret123!'),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
            'owner_user_id' => $owner->id,
            'email_verified_at' => now(),
        ]);

        $tenant = Tenant::query()->forceCreate([
            'user_id' => $user->id,
            'owner_user_id' => $owner->id,
            'job' => 'Engineer',
            'family_member' => 1,
            'property_id' => $firstProperty->id,
            'unit_id' => $firstUnit->id,
            'lease_start_date' => '2026-05-01',
            'lease_end_date' => '2026-06-01',
            'rent_type' => RENT_TYPE_MONTHLY,
            'general_rent' => 12000,
            'security_deposit' => 0,
            'late_fee' => 0,
            'incident_receipt' => 0,
            'status' => TENANT_STATUS_ACTIVE,
        ]);

        TenantUnitAssignment::query()->forceCreate([
            'tenant_id' => $tenant->id,
            'property_id' => $firstProperty->id,
            'unit_id' => $firstUnit->id,
        ]);

        $this->postJson("/api/public/properties/{$secondProperty->id}/bookings/confirm", [
            'option_id' => $secondOption->id,
            'stay_mode' => 'months',
            'start_date' => '2026-07-01',
            'end_date' => '2026-08-01',
            'guests' => 1,
            'full_name' => 'Repeat Tenant',
            'email' => 'repeat-confirm@example.com',
            'phone' => '+260971111111',
            'payment_plan' => 'later',
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.requires_confirmation', true)
            ->assertJsonPath('data.conflict_summary.current_stay.property_name', $firstProperty->name)
            ->assertJsonPath('data.conflict_summary.open_booking', null);

        $this->assertDatabaseMissing('public_property_bookings', [
            'tenant_id' => $tenant->id,
            'property_id' => $secondProperty->id,
        ]);

        Mail::assertNothingSent();
    }

    public function test_confirmed_repeat_booking_creates_an_additional_booking_without_overwriting_the_current_assignment(): void
    {
        Mail::fake();
        $this->enableTenantPortalMail();

        $owner = $this->createOwnerUser();
        [$firstProperty, $firstUnit] = $this->createPublicPropertyWithUnit([
            'owner_user_id' => $owner->id,
            'slug' => 'existing-stay-property',
        ]);
        [$secondProperty, , $secondOption] = $this->createPublicPropertyWithUnit([
            'owner_user_id' => $owner->id,
            'slug' => 'new-booking-property',
        ]);

        $user = User::query()->forceCreate([
            'first_name' => 'Repeat',
            'last_name' => 'Tenant',
            'email' => 'repeat-confirmed@example.com',
            'password' => Hash::make('secret123!'),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
            'owner_user_id' => $owner->id,
            'email_verified_at' => now(),
        ]);

        $tenant = Tenant::query()->forceCreate([
            'user_id' => $user->id,
            'owner_user_id' => $owner->id,
            'job' => 'Engineer',
            'family_member' => 1,
            'property_id' => $firstProperty->id,
            'unit_id' => $firstUnit->id,
            'lease_start_date' => '2026-05-01',
            'lease_end_date' => '2026-06-01',
            'rent_type' => RENT_TYPE_MONTHLY,
            'general_rent' => 12000,
            'security_deposit' => 0,
            'late_fee' => 0,
            'incident_receipt' => 0,
            'status' => TENANT_STATUS_ACTIVE,
        ]);

        TenantUnitAssignment::query()->forceCreate([
            'tenant_id' => $tenant->id,
            'property_id' => $firstProperty->id,
            'unit_id' => $firstUnit->id,
        ]);

        $this->postJson("/api/public/properties/{$secondProperty->id}/bookings/confirm", [
            'option_id' => $secondOption->id,
            'stay_mode' => 'months',
            'start_date' => '2026-07-01',
            'end_date' => '2026-08-01',
            'guests' => 1,
            'full_name' => 'Repeat Tenant',
            'email' => 'repeat-confirmed@example.com',
            'phone' => '+260971111111',
            'payment_plan' => 'later',
            'confirm_existing_booking' => true,
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.requires_confirmation', false)
            ->assertJsonPath('data.booking.account_created', false)
            ->assertJsonPath('data.booking.has_assignment', false)
            ->assertJsonPath('data.booking.assignment_created', false);

        $tenant->refresh();

        $this->assertSame($firstProperty->id, (int) $tenant->property_id);
        $this->assertSame($firstUnit->id, (int) $tenant->unit_id);
        $this->assertSame('2026-05-01', (string) $tenant->lease_start_date);
        $this->assertSame('2026-06-01', (string) $tenant->lease_end_date);

        $this->assertDatabaseHas('public_property_bookings', [
            'tenant_id' => $tenant->id,
            'property_id' => $secondProperty->id,
            'option_id' => $secondOption->id,
            'property_unit_id' => null,
            'has_assignment' => false,
            'assignment_created' => false,
        ]);

        Mail::assertNothingSent();
    }

    public function test_booking_confirm_blocks_additional_booking_when_existing_tenant_has_a_pending_invoice(): void
    {
        Mail::fake();
        $this->enableTenantPortalMail();

        $owner = $this->createOwnerUser();
        [$firstProperty, $firstUnit] = $this->createPublicPropertyWithUnit([
            'owner_user_id' => $owner->id,
            'slug' => 'existing-stay-property',
        ]);
        [$secondProperty, , $secondOption] = $this->createPublicPropertyWithUnit([
            'owner_user_id' => $owner->id,
            'slug' => 'new-booking-property',
        ]);

        $user = User::query()->forceCreate([
            'first_name' => 'Repeat',
            'last_name' => 'Tenant',
            'email' => 'pending-fee@example.com',
            'password' => Hash::make('secret123!'),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
            'owner_user_id' => $owner->id,
            'email_verified_at' => now(),
        ]);

        $tenant = Tenant::query()->forceCreate([
            'user_id' => $user->id,
            'owner_user_id' => $owner->id,
            'job' => 'Engineer',
            'family_member' => 1,
            'property_id' => $firstProperty->id,
            'unit_id' => $firstUnit->id,
            'lease_start_date' => '2026-05-01',
            'lease_end_date' => '2026-06-01',
            'rent_type' => RENT_TYPE_MONTHLY,
            'general_rent' => 12000,
            'security_deposit' => 0,
            'late_fee' => 250,
            'incident_receipt' => 0,
            'status' => TENANT_STATUS_ACTIVE,
        ]);

        TenantUnitAssignment::query()->forceCreate([
            'tenant_id' => $tenant->id,
            'property_id' => $firstProperty->id,
            'unit_id' => $firstUnit->id,
        ]);

        $invoice = Invoice::query()->forceCreate([
            'tenant_id' => $tenant->id,
            'owner_user_id' => $owner->id,
            'property_id' => $firstProperty->id,
            'property_unit_id' => $firstUnit->id,
            'name' => 'Rent Invoice',
            'invoice_no' => 'INV-PENDING-' . random_int(1000, 9999),
            'month' => '2026-05',
            'due_date' => '2026-05-15',
            'amount' => 12000,
            'status' => INVOICE_STATUS_PENDING,
            'late_fee' => 0,
        ]);

        $this->postJson("/api/public/properties/{$secondProperty->id}/bookings/confirm", [
            'option_id' => $secondOption->id,
            'stay_mode' => 'months',
            'start_date' => '2026-07-01',
            'end_date' => '2026-08-01',
            'guests' => 1,
            'full_name' => 'Repeat Tenant',
            'email' => 'pending-fee@example.com',
            'phone' => '+260971111111',
            'payment_plan' => 'later',
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.requires_fee_clearance', true)
            ->assertJsonPath('data.pending_fee.invoice_id', $invoice->id)
            ->assertJsonPath('data.pending_fee.invoice_no', $invoice->invoice_no)
            ->assertJsonPath('data.pending_fee.amount_due', 12000)
            ->assertJsonPath('data.requires_confirmation', false);

        $this->assertDatabaseMissing('public_property_bookings', [
            'tenant_id' => $tenant->id,
            'property_id' => $secondProperty->id,
        ]);

        Mail::assertNothingSent();
    }

    public function test_booking_confirm_blocks_additional_booking_when_existing_tenant_has_an_overdue_invoice(): void
    {
        Mail::fake();
        $this->enableTenantPortalMail();

        $owner = $this->createOwnerUser();
        [$firstProperty, $firstUnit] = $this->createPublicPropertyWithUnit([
            'owner_user_id' => $owner->id,
            'slug' => 'existing-stay-property',
        ]);
        [$secondProperty, , $secondOption] = $this->createPublicPropertyWithUnit([
            'owner_user_id' => $owner->id,
            'slug' => 'new-booking-property',
        ]);

        $user = User::query()->forceCreate([
            'first_name' => 'Repeat',
            'last_name' => 'Tenant',
            'email' => 'overdue-fee@example.com',
            'password' => Hash::make('secret123!'),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
            'owner_user_id' => $owner->id,
            'email_verified_at' => now(),
        ]);

        $tenant = Tenant::query()->forceCreate([
            'user_id' => $user->id,
            'owner_user_id' => $owner->id,
            'job' => 'Engineer',
            'family_member' => 1,
            'property_id' => $firstProperty->id,
            'unit_id' => $firstUnit->id,
            'lease_start_date' => '2026-05-01',
            'lease_end_date' => '2026-06-01',
            'rent_type' => RENT_TYPE_MONTHLY,
            'general_rent' => 12000,
            'security_deposit' => 0,
            'late_fee' => 300,
            'incident_receipt' => 0,
            'status' => TENANT_STATUS_ACTIVE,
        ]);

        TenantUnitAssignment::query()->forceCreate([
            'tenant_id' => $tenant->id,
            'property_id' => $firstProperty->id,
            'unit_id' => $firstUnit->id,
        ]);

        $invoice = Invoice::query()->forceCreate([
            'tenant_id' => $tenant->id,
            'owner_user_id' => $owner->id,
            'property_id' => $firstProperty->id,
            'property_unit_id' => $firstUnit->id,
            'name' => 'Rent Invoice',
            'invoice_no' => 'INV-OVERDUE-' . random_int(1000, 9999),
            'month' => '2026-05',
            'due_date' => '2026-05-15',
            'amount' => 12000,
            'status' => INVOICE_STATUS_OVER_DUE,
            'late_fee' => 300,
        ]);

        $this->postJson("/api/public/properties/{$secondProperty->id}/bookings/confirm", [
            'option_id' => $secondOption->id,
            'stay_mode' => 'months',
            'start_date' => '2026-07-01',
            'end_date' => '2026-08-01',
            'guests' => 1,
            'full_name' => 'Repeat Tenant',
            'email' => 'overdue-fee@example.com',
            'phone' => '+260971111111',
            'payment_plan' => 'later',
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.requires_fee_clearance', true)
            ->assertJsonPath('data.pending_fee.invoice_id', $invoice->id)
            ->assertJsonPath('data.pending_fee.invoice_no', $invoice->invoice_no)
            ->assertJsonPath('data.pending_fee.amount_due', 12300)
            ->assertJsonPath('data.pending_fee.status', 'overdue')
            ->assertJsonPath('data.requires_confirmation', false);

        $this->assertDatabaseMissing('public_property_bookings', [
            'tenant_id' => $tenant->id,
            'property_id' => $secondProperty->id,
        ]);

        Mail::assertNothingSent();
    }

    private function createPublicPropertyWithUnit(array $overrides = []): array
    {
        $property = Property::query()->forceCreate([
            'owner_user_id' => $overrides['owner_user_id'] ?? null,
            'property_type' => PROPERTY_TYPE_OWN,
            'name' => $overrides['name'] ?? 'Test Public Property',
            'number_of_unit' => 1,
            'description' => 'Public property description',
            'status' => RENT_CHARGE_ACTIVE_CLASS,
            'is_public' => true,
            'public_slug' => $overrides['slug'] ?? 'test-public-property',
            'public_category' => $overrides['category'] ?? 'apartment',
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
            'property_unit_id' => array_key_exists('property_unit_id', $overrides)
                ? $overrides['property_unit_id']
                : $unit->id,
            'rental_kind' => $overrides['rental_kind'] ?? 'whole_unit',
            'monthly_rate' => 12000,
            'nightly_rate' => 700,
            'max_guests' => $overrides['max_guests'] ?? 2,
            'status' => ACTIVE,
            'sort_order' => 1,
            'is_default' => true,
        ]);

        return [$property, $unit, $option];
    }

    private function enableTenantPortalMail(): void
    {
        config([
            'settings.app_name' => 'Savant',
            'settings.send_email_status' => ACTIVE,
        ]);

        putenv('MAIL_STATUS=1');
        putenv('MAIL_USERNAME=tester@example.test');
        $_ENV['MAIL_STATUS'] = '1';
        $_ENV['MAIL_USERNAME'] = 'tester@example.test';
        $_SERVER['MAIL_STATUS'] = '1';
        $_SERVER['MAIL_USERNAME'] = 'tester@example.test';
    }

    private function createOwnerUser(): User
    {
        return User::query()->forceCreate([
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => 'owner' . random_int(1000, 9999) . '@example.test',
            'password' => Hash::make('owner-secret'),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_OWNER,
        ]);
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
