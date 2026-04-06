<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyDetail;
use App\Models\PropertyUnit;
use App\Models\Invoice;
use App\Models\PublicPropertyBooking;
use App\Models\PublicPropertyOption;
use App\Models\PublicPropertyWaitlist;
use App\Models\Tenant;
use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\TenantPortalActionMail;
use Tests\TestCase;

class OwnerWebsiteLeadPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Language::query()->forceCreate([
            'name' => 'English',
            'code' => 'en',
            'rtl' => 0,
            'status' => 1,
            'default' => 1,
        ]);

        session(['local' => 'en']);
    }

    public function test_owner_can_view_bookings_and_waitlist_tabs(): void
    {
        $owner = $this->createOwnerUser();
        [$property, $unit, $option] = $this->createPublicPropertyWithUnit($owner->id);
        $tenantUser = User::query()->forceCreate([
            'first_name' => 'Guest',
            'last_name' => 'User',
            'email' => 'guest@example.com',
            'password' => Hash::make('secret123'),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
            'owner_user_id' => $owner->id,
        ]);
        $tenant = Tenant::query()->forceCreate([
            'user_id' => $tenantUser->id,
            'owner_user_id' => $owner->id,
            'job' => 'Engineer',
            'family_member' => 1,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'rent_type' => RENT_TYPE_MONTHLY,
            'general_rent' => 0,
            'security_deposit' => 0,
            'late_fee' => 0,
            'incident_receipt' => 0,
            'status' => TENANT_STATUS_ACTIVE,
        ]);

        PublicPropertyBooking::query()->create([
            'owner_user_id' => $owner->id,
            'property_id' => $property->id,
            'option_id' => $option->id,
            'property_unit_id' => $unit->id,
            'tenant_id' => $tenant->id,
            'user_id' => $tenantUser->id,
            'stay_mode' => 'months',
            'start_date' => '2026-05-01',
            'end_date' => '2026-06-01',
            'guests' => 1,
            'full_name' => 'Booked Guest',
            'email' => 'booked@example.com',
            'phone' => '+260971111111',
            'payment_plan' => 'later',
            'status' => PublicPropertyBooking::STATUS_CONFIRMED,
            'source' => 'website',
            'confirmed_at' => now(),
        ]);

        PublicPropertyBooking::query()->create([
            'owner_user_id' => $owner->id,
            'property_id' => $property->id,
            'option_id' => $option->id,
            'property_unit_id' => null,
            'tenant_id' => $tenant->id,
            'user_id' => $tenantUser->id,
            'stay_mode' => 'months',
            'start_date' => '2026-06-01',
            'end_date' => '2026-07-01',
            'guests' => 1,
            'full_name' => 'Pending Assignment Guest',
            'email' => 'pending-assignment@example.com',
            'phone' => '+260974444444',
            'payment_plan' => 'later',
            'status' => PublicPropertyBooking::STATUS_CONFIRMED,
            'source' => 'website',
            'has_assignment' => false,
            'assignment_created' => false,
            'confirmed_at' => now(),
        ]);

        PublicPropertyWaitlist::query()->create([
            'property_id' => $property->id,
            'option_id' => $option->id,
            'stay_mode' => 'weeks',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-08',
            'guests' => 1,
            'full_name' => 'Waiting Guest',
            'email' => 'waiting@example.com',
            'phone' => '+260972222222',
            'status' => PublicPropertyWaitlist::STATUS_PENDING,
        ]);

        $this->actingAs($owner);

        $this->get(route('owner.website-leads.index'))
            ->assertOk()
            ->assertSee('Website Leads')
            ->assertSee('Booked Guest')
            ->assertSee('Live Bookings')
            ->assertSee('Assign tenant to unit')
            ->assertSee('Select Property')
            ->assertSee('Select Unit');

        $this->get(route('owner.website-leads.index', ['tab' => 'waitlist']))
            ->assertOk()
            ->assertSee('Waiting Guest')
            ->assertSee('Waiting List');
    }

    public function test_owner_can_update_booking_and_waitlist_statuses(): void
    {
        $owner = $this->createOwnerUser();
        [$property, $unit, $option] = $this->createPublicPropertyWithUnit($owner->id);

        $booking = PublicPropertyBooking::query()->create([
            'owner_user_id' => $owner->id,
            'property_id' => $property->id,
            'option_id' => $option->id,
            'property_unit_id' => $unit->id,
            'stay_mode' => 'months',
            'start_date' => '2026-05-01',
            'end_date' => '2026-06-01',
            'guests' => 1,
            'full_name' => 'Booked Guest',
            'email' => 'booked@example.com',
            'phone' => '+260971111111',
            'payment_plan' => 'later',
            'status' => PublicPropertyBooking::STATUS_CONFIRMED,
            'source' => 'website',
            'confirmed_at' => now(),
        ]);

        $waitlist = PublicPropertyWaitlist::query()->create([
            'property_id' => $property->id,
            'option_id' => $option->id,
            'stay_mode' => 'weeks',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-08',
            'guests' => 1,
            'full_name' => 'Waiting Guest',
            'email' => 'waiting@example.com',
            'phone' => '+260972222222',
            'status' => PublicPropertyWaitlist::STATUS_PENDING,
        ]);

        $this->actingAs($owner);

        $this->post(route('owner.website-leads.booking.status', $booking->id), [
            'status' => PublicPropertyBooking::STATUS_COMPLETED,
        ])
            ->assertRedirect();

        $this->post(route('owner.website-leads.waitlist.status', $waitlist->id), [
            'status' => PublicPropertyWaitlist::STATUS_CONTACTED,
        ])
            ->assertRedirect();

        $this->assertDatabaseHas('public_property_bookings', [
            'id' => $booking->id,
            'status' => PublicPropertyBooking::STATUS_COMPLETED,
        ]);

        $this->assertDatabaseHas('public_property_waitlists', [
            'id' => $waitlist->id,
            'status' => PublicPropertyWaitlist::STATUS_CONTACTED,
        ]);
    }

    public function test_owner_can_assign_a_pending_booking_to_a_unit_and_send_assignment_email(): void
    {
        Mail::fake();
        $this->enableTenantPortalMail();

        $owner = $this->createOwnerUser();
        [$property, $unit, $option] = $this->createPublicPropertyWithUnit($owner->id);
        $tenantUser = User::query()->forceCreate([
            'first_name' => 'Pending',
            'last_name' => 'Guest',
            'email' => 'pending@example.com',
            'password' => Hash::make('secret123'),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
            'owner_user_id' => $owner->id,
            'email_verified_at' => now(),
        ]);
        $tenant = Tenant::query()->forceCreate([
            'user_id' => $tenantUser->id,
            'owner_user_id' => $owner->id,
            'job' => 'Engineer',
            'family_member' => 1,
            'property_id' => $property->id,
            'unit_id' => null,
            'lease_start_date' => '2026-05-01',
            'lease_end_date' => '2026-06-01',
            'rent_type' => RENT_TYPE_MONTHLY,
            'general_rent' => 12000,
            'security_deposit' => 0,
            'late_fee' => 0,
            'incident_receipt' => 0,
            'status' => TENANT_STATUS_ACTIVE,
        ]);

        $booking = PublicPropertyBooking::query()->create([
            'owner_user_id' => $owner->id,
            'property_id' => $property->id,
            'option_id' => $option->id,
            'property_unit_id' => null,
            'tenant_id' => $tenant->id,
            'user_id' => $tenantUser->id,
            'stay_mode' => 'months',
            'start_date' => '2026-05-01',
            'end_date' => '2026-06-01',
            'guests' => 1,
            'full_name' => 'Pending Guest',
            'email' => 'pending@example.com',
            'phone' => '+260973333333',
            'payment_plan' => 'later',
            'status' => PublicPropertyBooking::STATUS_CONFIRMED,
            'source' => 'website',
            'has_assignment' => false,
            'assignment_created' => false,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($owner);

        $this->post(route('owner.website-leads.booking.assign-unit', $booking->id), [
            'property_id' => $property->id,
            'unit_id' => $unit->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
        ]);

        $this->assertDatabaseHas('public_property_bookings', [
            'id' => $booking->id,
            'property_unit_id' => $unit->id,
            'has_assignment' => true,
            'assignment_created' => true,
        ]);

        Mail::assertSent(TenantPortalActionMail::class, 1);
    }

    public function test_owner_can_change_the_booking_property_before_assigning_the_unit(): void
    {
        Mail::fake();
        $this->enableTenantPortalMail();

        $owner = $this->createOwnerUser();
        [$firstProperty, $firstUnit, $firstOption] = $this->createPublicPropertyWithUnit($owner->id, [
            'name' => 'Original Website Property',
            'slug' => 'original-website-property',
        ]);
        [$secondProperty, $secondUnit] = $this->createPublicPropertyWithUnit($owner->id, [
            'name' => 'Reassigned Property',
            'slug' => 'reassigned-property',
        ]);

        $tenantUser = User::query()->forceCreate([
            'first_name' => 'Pending',
            'last_name' => 'Guest',
            'email' => 'reassign@example.com',
            'password' => Hash::make('secret123'),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
            'owner_user_id' => $owner->id,
            'email_verified_at' => now(),
        ]);
        $tenant = Tenant::query()->forceCreate([
            'user_id' => $tenantUser->id,
            'owner_user_id' => $owner->id,
            'job' => 'Engineer',
            'family_member' => 1,
            'property_id' => $firstProperty->id,
            'unit_id' => null,
            'lease_start_date' => '2026-05-01',
            'lease_end_date' => '2026-06-01',
            'rent_type' => RENT_TYPE_MONTHLY,
            'general_rent' => 12000,
            'security_deposit' => 0,
            'late_fee' => 0,
            'incident_receipt' => 0,
            'status' => TENANT_STATUS_ACTIVE,
        ]);

        $booking = PublicPropertyBooking::query()->create([
            'owner_user_id' => $owner->id,
            'property_id' => $firstProperty->id,
            'option_id' => $firstOption->id,
            'property_unit_id' => null,
            'tenant_id' => $tenant->id,
            'user_id' => $tenantUser->id,
            'stay_mode' => 'months',
            'start_date' => '2026-05-01',
            'end_date' => '2026-06-01',
            'guests' => 1,
            'full_name' => 'Pending Guest',
            'email' => 'reassign@example.com',
            'phone' => '+260973333333',
            'payment_plan' => 'later',
            'status' => PublicPropertyBooking::STATUS_CONFIRMED,
            'source' => 'website',
            'has_assignment' => false,
            'assignment_created' => false,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($owner);

        $this->post(route('owner.website-leads.booking.assign-unit', $booking->id), [
            'property_id' => $secondProperty->id,
            'unit_id' => $secondUnit->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'property_id' => $secondProperty->id,
            'unit_id' => $secondUnit->id,
        ]);

        $this->assertDatabaseHas('public_property_bookings', [
            'id' => $booking->id,
            'property_id' => $secondProperty->id,
            'property_unit_id' => $secondUnit->id,
            'option_id' => null,
            'has_assignment' => true,
            'assignment_created' => true,
        ]);

        $this->get(route('owner.website-leads.index'))
            ->assertSee('Manual assignment');

        Mail::assertSent(TenantPortalActionMail::class, 1);
    }

    public function test_owner_assignment_rejects_units_from_another_property(): void
    {
        $owner = $this->createOwnerUser();
        [$firstProperty, , $firstOption] = $this->createPublicPropertyWithUnit($owner->id, [
            'name' => 'Original Website Property',
            'slug' => 'original-website-property',
        ]);
        [$secondProperty, $secondUnit] = $this->createPublicPropertyWithUnit($owner->id, [
            'name' => 'Other Property',
            'slug' => 'other-property',
        ]);

        $tenantUser = User::query()->forceCreate([
            'first_name' => 'Pending',
            'last_name' => 'Guest',
            'email' => 'invalid-unit@example.com',
            'password' => Hash::make('secret123'),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
            'owner_user_id' => $owner->id,
        ]);
        $tenant = Tenant::query()->forceCreate([
            'user_id' => $tenantUser->id,
            'owner_user_id' => $owner->id,
            'job' => 'Engineer',
            'family_member' => 1,
            'property_id' => $firstProperty->id,
            'unit_id' => null,
            'rent_type' => RENT_TYPE_MONTHLY,
            'general_rent' => 12000,
            'security_deposit' => 0,
            'late_fee' => 0,
            'incident_receipt' => 0,
            'status' => TENANT_STATUS_ACTIVE,
        ]);

        $booking = PublicPropertyBooking::query()->create([
            'owner_user_id' => $owner->id,
            'property_id' => $firstProperty->id,
            'option_id' => $firstOption->id,
            'tenant_id' => $tenant->id,
            'user_id' => $tenantUser->id,
            'stay_mode' => 'months',
            'start_date' => '2026-05-01',
            'end_date' => '2026-06-01',
            'guests' => 1,
            'full_name' => 'Pending Guest',
            'email' => 'invalid-unit@example.com',
            'phone' => '+260973333333',
            'payment_plan' => 'later',
            'status' => PublicPropertyBooking::STATUS_CONFIRMED,
            'source' => 'website',
            'has_assignment' => false,
            'assignment_created' => false,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($owner);

        $this->post(route('owner.website-leads.booking.assign-unit', $booking->id), [
            'property_id' => $firstProperty->id,
            'unit_id' => $secondUnit->id,
        ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Invalid unit selected for the chosen property.');

        $this->assertDatabaseHas('public_property_bookings', [
            'id' => $booking->id,
            'property_id' => $firstProperty->id,
            'property_unit_id' => null,
            'option_id' => $firstOption->id,
            'has_assignment' => false,
        ]);
    }

    public function test_owner_booking_cards_show_pending_fee_or_clear_from_tenant_invoices(): void
    {
        $owner = $this->createOwnerUser();
        [$property, $unit, $option] = $this->createPublicPropertyWithUnit($owner->id);

        $pendingUser = User::query()->forceCreate([
            'first_name' => 'Pending',
            'last_name' => 'Fee',
            'email' => 'pending-fee@example.com',
            'password' => Hash::make('secret123'),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
            'owner_user_id' => $owner->id,
        ]);
        $pendingTenant = Tenant::query()->forceCreate([
            'user_id' => $pendingUser->id,
            'owner_user_id' => $owner->id,
            'job' => 'Engineer',
            'family_member' => 1,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'rent_type' => RENT_TYPE_MONTHLY,
            'general_rent' => 0,
            'security_deposit' => 0,
            'late_fee' => 0,
            'incident_receipt' => 0,
            'status' => TENANT_STATUS_ACTIVE,
        ]);
        Invoice::query()->forceCreate([
            'tenant_id' => $pendingTenant->id,
            'owner_user_id' => $owner->id,
            'property_id' => $property->id,
            'property_unit_id' => $unit->id,
            'name' => 'Rent Invoice',
            'invoice_no' => 'INV-PENDING-' . random_int(1000, 9999),
            'month' => '2026-05',
            'due_date' => '2026-05-15',
            'amount' => 12000,
            'status' => INVOICE_STATUS_PENDING,
            'late_fee' => 0,
        ]);
        PublicPropertyBooking::query()->create([
            'owner_user_id' => $owner->id,
            'property_id' => $property->id,
            'option_id' => $option->id,
            'property_unit_id' => null,
            'tenant_id' => $pendingTenant->id,
            'user_id' => $pendingUser->id,
            'stay_mode' => 'months',
            'start_date' => '2026-05-01',
            'end_date' => '2026-06-01',
            'guests' => 1,
            'full_name' => 'Pending Fee Guest',
            'email' => 'pending-fee@example.com',
            'phone' => '+260971111111',
            'payment_plan' => 'later',
            'status' => PublicPropertyBooking::STATUS_CONFIRMED,
            'source' => 'website',
            'has_assignment' => false,
            'assignment_created' => false,
            'confirmed_at' => now(),
        ]);

        $clearUser = User::query()->forceCreate([
            'first_name' => 'Clear',
            'last_name' => 'Guest',
            'email' => 'clear@example.com',
            'password' => Hash::make('secret123'),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
            'owner_user_id' => $owner->id,
        ]);
        $clearTenant = Tenant::query()->forceCreate([
            'user_id' => $clearUser->id,
            'owner_user_id' => $owner->id,
            'job' => 'Engineer',
            'family_member' => 1,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'rent_type' => RENT_TYPE_MONTHLY,
            'general_rent' => 0,
            'security_deposit' => 0,
            'late_fee' => 0,
            'incident_receipt' => 0,
            'status' => TENANT_STATUS_ACTIVE,
        ]);
        Invoice::query()->forceCreate([
            'tenant_id' => $clearTenant->id,
            'owner_user_id' => $owner->id,
            'property_id' => $property->id,
            'property_unit_id' => $unit->id,
            'name' => 'Paid Invoice',
            'invoice_no' => 'INV-PAID-' . random_int(1000, 9999),
            'month' => '2026-04',
            'due_date' => '2026-04-15',
            'amount' => 12000,
            'status' => INVOICE_STATUS_PAID,
            'late_fee' => 0,
        ]);
        PublicPropertyBooking::query()->create([
            'owner_user_id' => $owner->id,
            'property_id' => $property->id,
            'option_id' => $option->id,
            'property_unit_id' => null,
            'tenant_id' => $clearTenant->id,
            'user_id' => $clearUser->id,
            'stay_mode' => 'months',
            'start_date' => '2026-06-01',
            'end_date' => '2026-07-01',
            'guests' => 1,
            'full_name' => 'Clear Guest',
            'email' => 'clear@example.com',
            'phone' => '+260972222222',
            'payment_plan' => 'later',
            'status' => PublicPropertyBooking::STATUS_CONFIRMED,
            'source' => 'website',
            'has_assignment' => false,
            'assignment_created' => false,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($owner);

        $this->get(route('owner.website-leads.index'))
            ->assertOk()
            ->assertSee('Pending Fee Guest')
            ->assertSee('Pending fee')
            ->assertSee('Clear Guest')
            ->assertSee('Clear');
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

    private function createPublicPropertyWithUnit(int $ownerUserId, array $overrides = []): array
    {
        $property = Property::query()->forceCreate([
            'owner_user_id' => $ownerUserId,
            'property_type' => PROPERTY_TYPE_OWN,
            'name' => $overrides['name'] ?? 'Website Lead Property',
            'number_of_unit' => 1,
            'description' => 'Public property description',
            'status' => RENT_CHARGE_ACTIVE_CLASS,
            'is_public' => true,
            'public_slug' => $overrides['slug'] ?? ('website-lead-property-' . random_int(1000, 9999)),
            'public_category' => 'apartment',
            'public_summary' => 'Public summary',
            'public_home_sections' => 'featured',
            'public_sort_order' => 1,
        ]);

        PropertyDetail::query()->forceCreate([
            'property_id' => $property->id,
            'address' => 'Kabulonga Road, Lusaka, Zambia',
        ]);

        $unit = PropertyUnit::query()->forceCreate([
            'property_id' => $property->id,
            'unit_name' => $overrides['unit_name'] ?? 'Unit A',
            'bedroom' => 1,
            'bath' => 1,
            'kitchen' => 1,
            'max_occupancy' => 2,
            'general_rent' => 12000,
            'description' => 'Test unit',
            'square_feet' => '45',
            'amenities' => 'WiFi,Parking',
            'parking' => 'yes',
        ]);

        $option = PublicPropertyOption::query()->forceCreate([
            'property_id' => $property->id,
            'property_unit_id' => $unit->id,
            'rental_kind' => 'whole_unit',
            'monthly_rate' => 12000,
            'nightly_rate' => 700,
            'max_guests' => 2,
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
}
