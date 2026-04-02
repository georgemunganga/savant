<?php

namespace Tests\Feature;

use App\Mail\SignUpMail;
use App\Mail\TenantPortalActionMail;
use App\Models\Owner;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

class TenantAccountSetupApiTest extends TestCase
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

    public function test_owner_tenant_create_sends_a_setup_link_email_instead_of_a_plaintext_password_mail(): void
    {
        Mail::fake();
        $this->enableTenantPortalMail();

        $owner = $this->createOwnerUser();
        $this->actingAs($owner);

        $response = app(TenantService::class)->step1(new Request([
            'first_name' => 'Jane',
            'last_name' => 'Tenant',
            'email' => 'jane.tenant@example.test',
            'contact_number' => '260971111111',
            'job' => 'Engineer',
            'family_member' => 1,
            'tenant_type' => 'person',
        ]));

        $payload = $response->getData(true);
        $user = User::query()->where('email', 'jane.tenant@example.test')->firstOrFail();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['status']);
        $this->assertSame(USER_ROLE_TENANT, (int) $user->role);
        $this->assertFalse(Hash::check('12345678', $user->password));

        Mail::assertSent(
            TenantPortalActionMail::class,
            fn (TenantPortalActionMail $mail) => isset($mail->content['button_url'])
                && str_contains($mail->content['button_url'], '/set-password')
                && str_contains($mail->content['button_url'], 'email=jane.tenant%40example.test')
        );
        Mail::assertNotSent(SignUpMail::class);
    }

    public function test_password_reset_validate_and_complete_updates_the_tenant_password(): void
    {
        [$user] = $this->createTenantPortalUser([
            'email' => 'setup@example.test',
            'status' => USER_STATUS_UNVERIFIED,
            'email_verified_at' => null,
        ], [
            'status' => TENANT_STATUS_DRAFT,
        ]);

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/password-reset/validate', [
            'email' => $user->email,
            'token' => $token,
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.valid', true);

        $this->postJson('/api/password-reset/complete', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.redirect_to', '/auth');

        $user->refresh();

        $this->assertSame(USER_STATUS_ACTIVE, (int) $user->status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('new-secret-123', $user->password));
    }

    public function test_unassigned_tenant_login_returns_assignment_flags(): void
    {
        [$user] = $this->createTenantPortalUser([
            'email' => 'portal@example.test',
            'password' => Hash::make('secret123!'),
        ], [
            'status' => TENANT_STATUS_DRAFT,
            'property_id' => null,
            'unit_id' => null,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret123!',
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.has_assignment', false)
            ->assertJsonPath('data.user.tenant_status', TENANT_STATUS_DRAFT);
    }

    public function test_unassigned_tenant_can_open_dashboard_but_not_assignment_only_routes(): void
    {
        [$user] = $this->createTenantPortalUser([], [
            'status' => TENANT_STATUS_ACTIVE,
            'property_id' => null,
            'unit_id' => null,
        ]);

        Passport::actingAs($user);

        $this->getJson('/api/tenant/dashboard')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.webapp.hasAssignment', false)
            ->assertJsonPath('data.webapp.activeStay', 'No rented apartment yet')
            ->assertJsonPath('data.webapp.exploreListingsHref', '/listings');

        $this->getJson('/api/tenant/invoices')
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'You do not have an assigned stay yet. Please explore listings or contact Savant support.'
            );
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

    private function createTenantPortalUser(array $userOverrides = [], array $tenantOverrides = []): array
    {
        $user = User::query()->forceCreate(array_merge([
            'first_name' => 'Portal',
            'last_name' => 'Tenant',
            'email' => 'tenant' . random_int(1000, 9999) . '@example.test',
            'password' => Hash::make('secret123!'),
            'contact_number' => '26000000' . random_int(100, 999),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
            'owner_user_id' => null,
            'email_verified_at' => now(),
        ], $userOverrides));

        $tenant = Tenant::query()->forceCreate(array_merge([
            'user_id' => $user->id,
            'owner_user_id' => null,
            'job' => 'Engineer',
            'family_member' => 1,
            'property_id' => null,
            'unit_id' => null,
            'rent_type' => RENT_TYPE_MONTHLY,
            'due_date' => 5,
            'lease_start_date' => null,
            'lease_end_date' => null,
            'general_rent' => 0,
            'security_deposit' => 0,
            'security_deposit_type' => 0,
            'late_fee' => 0,
            'late_fee_type' => 0,
            'incident_receipt' => 0,
            'status' => TENANT_STATUS_DRAFT,
            'tenant_type' => 'person',
        ], $tenantOverrides));

        return [$user, $tenant];
    }

    private function enableTenantPortalMail(): void
    {
        config([
            'settings.send_email_status' => ACTIVE,
            'settings.app_name' => 'Savant',
        ]);

        putenv('MAIL_STATUS=1');
        putenv('MAIL_USERNAME=tester@example.test');
        $_ENV['MAIL_STATUS'] = '1';
        $_ENV['MAIL_USERNAME'] = 'tester@example.test';
        $_SERVER['MAIL_STATUS'] = '1';
        $_SERVER['MAIL_USERNAME'] = 'tester@example.test';
    }
}
