<?php

namespace Tests\Feature;

use App\Http\Requests\Owner\Property\RentChargeRequest;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\PublicPropertyOption;
use App\Models\User;
use App\Services\PropertyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class OwnerPublicPropertyAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_public_unit_option_requires_rental_kind_and_rates(): void
    {
        $request = new RentChargeRequest();
        $request->replace([
            'propertyUnit' => [
                'public_enabled' => ['1'],
                'public_rental_kind' => [''],
                'public_monthly_rate' => [''],
                'public_nightly_rate' => [''],
            ],
        ]);

        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('propertyUnit.public_rental_kind.0', $validator->errors()->toArray());
        $this->assertArrayHasKey('propertyUnit.public_monthly_rate.0', $validator->errors()->toArray());
        $this->assertArrayHasKey('propertyUnit.public_nightly_rate.0', $validator->errors()->toArray());
    }

    public function test_rent_charge_store_preserves_manual_public_category_and_auto_derives_default_option(): void
    {
        $owner = User::query()->forceCreate([
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => 'owner@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_OWNER,
            'remember_token' => Str::random(10),
        ]);
        $this->actingAs($owner);

        $property = Property::query()->forceCreate([
            'owner_user_id' => $owner->id,
            'property_type' => PROPERTY_TYPE_OWN,
            'name' => 'Boarding Demo',
            'number_of_unit' => 2,
            'description' => 'Shared room boarding setup',
            'is_public' => true,
            'public_slug' => 'boarding-demo',
            'public_category' => 'boarding',
            'public_summary' => null,
            'public_sort_order' => 0,
            'status' => 4,
        ]);

        $unitA = PropertyUnit::query()->forceCreate([
            'property_id' => $property->id,
            'unit_name' => 'Room A',
            'bedroom' => 1,
            'bath' => 1,
            'kitchen' => 0,
            'general_rent' => 5000,
            'rent_type' => PROPERTY_UNIT_RENT_TYPE_MONTHLY,
            'monthly_due_day' => 1,
            'max_occupancy' => 1,
        ]);

        $unitB = PropertyUnit::query()->forceCreate([
            'property_id' => $property->id,
            'unit_name' => 'Room B',
            'bedroom' => 1,
            'bath' => 1,
            'kitchen' => 0,
            'general_rent' => 3000,
            'rent_type' => PROPERTY_UNIT_RENT_TYPE_MONTHLY,
            'monthly_due_day' => 1,
            'max_occupancy' => 1,
        ]);

        $response = app(PropertyService::class)->rentChargeStore(new Request([
            'property_id' => $property->id,
            'propertyUnit' => [
                'id' => [$unitA->id, $unitB->id],
                'general_rent' => [5000, 3000],
                'security_deposit_type' => [0, 0],
                'security_deposit' => [0, 0],
                'late_fee_type' => [0, 0],
                'late_fee' => [0, 0],
                'incident_receipt' => [0, 0],
                'rent_type' => [PROPERTY_UNIT_RENT_TYPE_MONTHLY, PROPERTY_UNIT_RENT_TYPE_MONTHLY],
                'monthly_due_day' => [1, 1],
                'yearly_due_day' => [null, null],
                'lease_start_date' => [null, null],
                'lease_end_date' => [null, null],
                'lease_payment_due_date' => [null, null],
                'public_enabled' => ['1', '1'],
                'public_rental_kind' => ['private_room', 'shared_space'],
                'public_monthly_rate' => [5000, 3000],
                'public_nightly_rate' => [250, 150],
                'public_max_guests' => [1, 1],
            ],
        ]));

        $this->assertSame(200, $response->getStatusCode());

        $property->refresh();
        $this->assertSame('boarding', $property->public_category);
        $this->assertSame('Shared room boarding setup', $property->public_summary);

        $options = PublicPropertyOption::query()
            ->where('property_id', $property->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $options);
        $this->assertSame([0, 1], $options->pluck('sort_order')->map(fn ($value) => (int) $value)->all());
        $this->assertSame(1, $options->where('is_default', true)->count());
        $this->assertSame((string) $unitB->id, (string) optional($options->firstWhere('is_default', true))->property_unit_id);
    }
}
