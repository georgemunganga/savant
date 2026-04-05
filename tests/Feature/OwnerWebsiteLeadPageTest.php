<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyDetail;
use App\Models\PropertyUnit;
use App\Models\PublicPropertyBooking;
use App\Models\PublicPropertyOption;
use App\Models\PublicPropertyWaitlist;
use App\Models\Tenant;
use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
            ->assertSee('Live Bookings');

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

    private function createPublicPropertyWithUnit(int $ownerUserId): array
    {
        $property = Property::query()->forceCreate([
            'owner_user_id' => $ownerUserId,
            'property_type' => PROPERTY_TYPE_OWN,
            'name' => 'Website Lead Property',
            'number_of_unit' => 1,
            'description' => 'Public property description',
            'status' => RENT_CHARGE_ACTIVE_CLASS,
            'is_public' => true,
            'public_slug' => 'website-lead-property',
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
            'unit_name' => 'Unit A',
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
}
