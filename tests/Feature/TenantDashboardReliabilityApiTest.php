<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

class TenantDashboardReliabilityApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Passport::loadKeysFrom(storage_path());
        app(ClientRepository::class)->createPersonalAccessClient(
            null,
            'Test Personal Access Client',
            'http://localhost'
        );
    }

    public function test_logout_revokes_the_current_access_token(): void
    {
        $user = User::query()->forceCreate([
            'first_name' => 'Portal',
            'last_name' => 'Tenant',
            'email' => 'logout@example.test',
            'password' => Hash::make('secret123!'),
            'contact_number' => '26000000999',
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
            'owner_user_id' => null,
            'email_verified_at' => now(),
        ]);

        $tokenResult = $user->createToken('tenant-dashboard-test');

        $this->withHeader('Authorization', 'Bearer ' . $tokenResult->accessToken)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Logout Successful');

        $this->assertTrue($tokenResult->token->fresh()->revoked);
    }

    public function test_user_seeder_creates_owner_support_config_without_duplicates(): void
    {
        $this->seed(UserSeeder::class);
        $this->seed(UserSeeder::class);

        $owner = User::query()->where('email', 'owner@gmail.com')->firstOrFail();

        foreach (['Plumbing', 'Electrical', 'Security'] as $name) {
            $this->assertDatabaseHas('maintenance_issues', [
                'owner_user_id' => $owner->id,
                'name' => $name,
                'status' => ACTIVE,
            ]);

            $this->assertSame(
                1,
                DB::table('maintenance_issues')
                    ->where('owner_user_id', $owner->id)
                    ->where('name', $name)
                    ->count()
            );
        }

        foreach (['Maintenance', 'Billing', 'General'] as $name) {
            $this->assertDatabaseHas('ticket_topics', [
                'owner_user_id' => $owner->id,
                'name' => $name,
                'status' => ACTIVE,
            ]);

            $this->assertSame(
                1,
                DB::table('ticket_topics')
                    ->where('owner_user_id', $owner->id)
                    ->where('name', $name)
                    ->count()
            );
        }
    }
}
