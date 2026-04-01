<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyDetail;
use App\Models\PropertyUnit;
use App\Models\PublicPropertyOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPropertyCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_returns_only_public_properties_and_honors_section_sort_order(): void
    {
        $later = $this->createPublicProperty([
            'name' => 'Later Featured',
            'slug' => 'later-featured',
            'summary' => 'Later featured property',
            'home_sections' => 'featured,popular',
            'sort_order' => 2,
        ]);

        $earlier = $this->createPublicProperty([
            'name' => 'Earlier Featured',
            'slug' => 'earlier-featured',
            'summary' => 'Earlier featured property',
            'home_sections' => 'featured',
            'sort_order' => 1,
        ]);

        $this->createPublicProperty([
            'name' => 'Hidden Property',
            'slug' => 'hidden-property',
            'summary' => 'Hidden property',
            'is_public' => false,
        ]);

        $response = $this->getJson('/api/public/home');

        $response
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.sections.featured.0.id', (string) $earlier->id)
            ->assertJsonPath('data.sections.featured.1.id', (string) $later->id);
    }

    public function test_listings_return_all_public_properties_and_support_filters(): void
    {
        $lusaka = $this->createPublicProperty([
            'name' => 'Lusaka Apartment',
            'slug' => 'lusaka-apartment',
            'summary' => 'Lusaka apartment',
            'address' => 'Kabulonga Road, Lusaka, Zambia',
            'category' => 'apartment',
            'monthly_rate' => 11000,
            'max_guests' => 3,
        ]);

        $kitwe = $this->createPublicProperty([
            'name' => 'Kitwe Boarding',
            'slug' => 'kitwe-boarding',
            'summary' => 'Kitwe boarding',
            'address' => 'Parklands, Kitwe, Zambia',
            'category' => 'boarding',
            'monthly_rate' => 3200,
            'max_guests' => 1,
        ]);

        $allResponse = $this->getJson('/api/public/properties');
        $allResponse
            ->assertOk()
            ->assertJsonCount(2, 'data.properties');

        $filteredResponse = $this->getJson('/api/public/properties?location=Lusaka&category=apartment&minPrice=10000&maxPrice=12000&guests=2');
        $filteredResponse
            ->assertOk()
            ->assertJsonCount(1, 'data.properties')
            ->assertJsonPath('data.properties.0.id', (string) $lusaka->id)
            ->assertJsonMissing(['id' => (string) $kitwe->id]);
    }

    public function test_property_detail_returns_only_active_public_options_and_lowest_starting_price(): void
    {
        $property = $this->createPublicProperty([
            'name' => 'Detailed Property',
            'slug' => 'detailed-property',
            'summary' => 'Detailed property summary',
            'monthly_rate' => 14000,
            'nightly_rate' => 850,
        ]);

        $unit = PropertyUnit::query()->forceCreate([
            'property_id' => $property->id,
            'unit_name' => 'Budget Wing',
            'bedroom' => 1,
            'bath' => 1,
            'kitchen' => 1,
            'max_occupancy' => 2,
            'general_rent' => 8000,
            'description' => 'Budget wing',
            'square_feet' => '40',
            'amenities' => 'WiFi,Laundry',
            'parking' => 'yes',
        ]);

        PublicPropertyOption::query()->forceCreate([
            'property_id' => $property->id,
            'property_unit_id' => $unit->id,
            'rental_kind' => 'whole_unit',
            'monthly_rate' => 8000,
            'nightly_rate' => 450,
            'max_guests' => 2,
            'status' => ACTIVE,
            'sort_order' => 2,
            'is_default' => false,
        ]);

        $inactiveUnit = PropertyUnit::query()->forceCreate([
            'property_id' => $property->id,
            'unit_name' => 'Inactive Wing',
            'bedroom' => 1,
            'bath' => 1,
            'kitchen' => 1,
            'max_occupancy' => 1,
            'general_rent' => 6000,
            'description' => 'Inactive wing',
            'square_feet' => '32',
            'amenities' => 'WiFi',
            'parking' => 'no',
        ]);

        PublicPropertyOption::query()->forceCreate([
            'property_id' => $property->id,
            'property_unit_id' => $inactiveUnit->id,
            'rental_kind' => 'private_room',
            'monthly_rate' => 6000,
            'nightly_rate' => 300,
            'max_guests' => 1,
            'status' => DEACTIVATE,
            'sort_order' => 3,
            'is_default' => false,
        ]);

        $listingResponse = $this->getJson('/api/public/properties?stayMode=months');
        $listingResponse
            ->assertOk()
            ->assertJsonPath('data.properties.0.monthly_rate', 8000);

        $detailResponse = $this->getJson("/api/public/properties/{$property->id}");
        $detailResponse
            ->assertOk()
            ->assertJsonPath('data.property.id', (string) $property->id)
            ->assertJsonCount(2, 'data.property.options');
    }

    public function test_property_can_be_fetched_by_public_slug(): void
    {
        $property = $this->createPublicProperty([
            'name' => 'Slug Property',
            'slug' => 'slug-property',
            'summary' => 'Slug summary',
        ]);

        $response = $this->getJson('/api/public/properties/by-slug/slug-property');

        $response
            ->assertOk()
            ->assertJsonPath('data.property.id', (string) $property->id)
            ->assertJsonPath('data.property.slug', 'slug-property');
    }

    private function createPublicProperty(array $overrides = []): Property
    {
        $property = Property::query()->forceCreate([
            'property_type' => PROPERTY_TYPE_OWN,
            'name' => $overrides['name'] ?? 'Public Property',
            'number_of_unit' => 1,
            'description' => 'Public property description',
            'status' => RENT_CHARGE_ACTIVE_CLASS,
            'is_public' => $overrides['is_public'] ?? true,
            'public_slug' => $overrides['slug'] ?? 'public-property',
            'public_category' => $overrides['category'] ?? 'apartment',
            'public_summary' => $overrides['summary'] ?? 'Public property summary',
            'public_home_sections' => $overrides['home_sections'] ?? 'featured',
            'public_sort_order' => $overrides['sort_order'] ?? 1,
        ]);

        PropertyDetail::query()->forceCreate([
            'property_id' => $property->id,
            'address' => $overrides['address'] ?? 'Kabulonga Road, Lusaka, Zambia',
        ]);

        $unit = PropertyUnit::query()->forceCreate([
            'property_id' => $property->id,
            'unit_name' => 'Primary Unit',
            'bedroom' => 2,
            'bath' => 1,
            'kitchen' => 1,
            'max_occupancy' => $overrides['max_guests'] ?? 2,
            'general_rent' => $overrides['monthly_rate'] ?? 12000,
            'description' => 'Primary unit',
            'square_feet' => '50',
            'amenities' => 'WiFi,Parking',
            'parking' => 'yes',
        ]);

        PublicPropertyOption::query()->forceCreate([
            'property_id' => $property->id,
            'property_unit_id' => $unit->id,
            'rental_kind' => 'whole_unit',
            'monthly_rate' => $overrides['monthly_rate'] ?? 12000,
            'nightly_rate' => $overrides['nightly_rate'] ?? 700,
            'max_guests' => $overrides['max_guests'] ?? 2,
            'status' => ACTIVE,
            'sort_order' => 1,
            'is_default' => true,
        ]);

        return $property;
    }
}
