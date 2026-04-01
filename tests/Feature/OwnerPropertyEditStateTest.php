<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\PublicPropertyOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OwnerPropertyEditStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_property_information_endpoint_rehydrates_saved_whole_property_option_values(): void
    {
        $owner = $this->createOwner();

        $property = Property::query()->forceCreate([
            'owner_user_id' => $owner->id,
            'property_type' => PROPERTY_TYPE_OWN,
            'name' => 'Website Demo',
            'number_of_unit' => 1,
            'description' => 'Demo property',
            'is_public' => true,
            'public_slug' => 'website-demo',
            'public_category' => 'apartment',
            'status' => 4,
        ]);

        PublicPropertyOption::query()->forceCreate([
            'property_id' => $property->id,
            'property_unit_id' => null,
            'rental_kind' => 'whole_property',
            'monthly_rate' => 12500,
            'nightly_rate' => 650,
            'max_guests' => 6,
            'status' => ACTIVE,
            'sort_order' => 0,
            'is_default' => true,
        ]);

        $response = $this->actingAs($owner)
            ->get(route('owner.property.getPropertyInformation', ['property_id' => $property->id]));

        $response->assertOk();

        $html = (string) $response->json('data');

        $this->assertStringContainsString('name="whole_property_option[monthly_rate]"', $html);
        $this->assertStringContainsString('value="12500"', $html);
        $this->assertStringContainsString('name="whole_property_option[nightly_rate]"', $html);
        $this->assertStringContainsString('value="650"', $html);
        $this->assertStringContainsString('name="whole_property_option[max_guests]"', $html);
        $this->assertStringContainsString('value="6"', $html);
        $this->assertStringContainsString('name="enable_whole_property_option" value="1"', $html);
        $this->assertStringContainsString('checked', $html);
    }

    public function test_rent_charge_endpoint_rehydrates_saved_unit_public_option_values(): void
    {
        $owner = $this->createOwner();

        $property = Property::query()->forceCreate([
            'owner_user_id' => $owner->id,
            'property_type' => PROPERTY_TYPE_OWN,
            'name' => 'Units Demo',
            'number_of_unit' => 1,
            'description' => 'Demo property',
            'is_public' => true,
            'public_slug' => 'units-demo',
            'public_category' => 'boarding',
            'status' => 4,
        ]);

        $unit = PropertyUnit::query()->forceCreate([
            'property_id' => $property->id,
            'unit_name' => 'Room A',
            'bedroom' => 1,
            'bath' => 1,
            'kitchen' => 0,
            'general_rent' => 4000,
            'rent_type' => PROPERTY_UNIT_RENT_TYPE_MONTHLY,
            'monthly_due_day' => 1,
            'max_occupancy' => 2,
        ]);

        PublicPropertyOption::query()->forceCreate([
            'property_id' => $property->id,
            'property_unit_id' => $unit->id,
            'rental_kind' => 'private_room',
            'monthly_rate' => 4100,
            'nightly_rate' => 275,
            'max_guests' => 2,
            'status' => ACTIVE,
            'sort_order' => 1,
            'is_default' => true,
        ]);

        $response = $this->actingAs($owner)
            ->get(route('owner.property.getRentCharge', ['property_id' => $property->id]));

        $response->assertOk();

        $html = (string) $response->json('data.view');

        $this->assertStringContainsString('name="propertyUnit[public_monthly_rate][]"', $html);
        $this->assertStringContainsString('value="4100"', $html);
        $this->assertStringContainsString('name="propertyUnit[public_nightly_rate][]"', $html);
        $this->assertStringContainsString('value="275"', $html);
        $this->assertStringContainsString('name="propertyUnit[public_max_guests][]"', $html);
        $this->assertStringContainsString('value="2"', $html);
        $this->assertStringContainsString('js-public-unit-enabled', $html);
        $this->assertStringContainsString('Enabled', $html);
    }

    private function createOwner(): User
    {
        return User::query()->forceCreate([
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => 'owner-' . Str::random(6) . '@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_OWNER,
            'remember_token' => Str::random(10),
        ]);
    }
}
