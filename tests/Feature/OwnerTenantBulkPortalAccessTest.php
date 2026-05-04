<?php

namespace Tests\Feature;

use App\Mail\TenantPortalActionMail;
use App\Models\Owner;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OwnerTenantBulkPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_send_bulk_portal_access_to_multiple_draft_tenants(): void
    {
        Mail::fake();
        $this->enableTenantPortalMail();

        $owner = $this->createOwnerUser('owner1@example.test');
        [$firstUser, $firstTenant] = $this->createTenantForOwner($owner->id, [
            'email' => 'draft-one@example.test',
            'email_verified_at' => null,
        ]);
        [$secondUser, $secondTenant] = $this->createTenantForOwner($owner->id, [
            'email' => 'draft-two@example.test',
            'email_verified_at' => null,
        ]);

        $response = $this->actingAs($owner)
            ->withoutMiddleware()
            ->postJson(route('owner.tenant.bulk-portal-access.store'), [
                'tenant_ids' => [$firstTenant->id, $secondTenant->id],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.sent_count', 2)
            ->assertJsonPath('data.skipped_count', 0);

        Mail::assertSent(
            TenantPortalActionMail::class,
            fn (TenantPortalActionMail $mail) => isset($mail->content['button_url'])
                && str_contains($mail->content['button_url'], '/set-password')
                && (
                    str_contains($mail->content['button_url'], 'email=' . urlencode($firstUser->email))
                    || str_contains($mail->content['button_url'], 'email=' . urlencode($secondUser->email))
                )
        );
        Mail::assertSent(TenantPortalActionMail::class, 2);
    }

    public function test_bulk_portal_access_targets_any_unverified_tenant_and_skips_verified_or_out_of_scope_users(): void
    {
        Mail::fake();
        $this->enableTenantPortalMail();

        $owner = $this->createOwnerUser('owner2@example.test');
        $otherOwner = $this->createOwnerUser('owner3@example.test');

        [, $draftTenant] = $this->createTenantForOwner($owner->id, [
            'email' => 'eligible-draft@example.test',
            'email_verified_at' => null,
        ]);
        [, $activeTenant] = $this->createTenantForOwner($owner->id, [
            'email' => 'eligible-active@example.test',
            'email_verified_at' => null,
        ], [
            'status' => TENANT_STATUS_ACTIVE,
        ]);
        [, $verifiedTenant] = $this->createTenantForOwner($owner->id, [
            'email' => 'verified@example.test',
            'email_verified_at' => now(),
        ], [
            'status' => TENANT_STATUS_ACTIVE,
        ]);
        [, $missingEmailTenant] = $this->createTenantForOwner($owner->id, [
            'email' => '',
            'email_verified_at' => null,
        ]);
        [, $deletedUserTenant] = $this->createTenantForOwner($owner->id, [
            'email' => 'deleted@example.test',
            'email_verified_at' => null,
            'status' => USER_STATUS_DELETED,
        ]);
        [, $foreignTenant] = $this->createTenantForOwner($otherOwner->id, [
            'email' => 'foreign@example.test',
            'email_verified_at' => null,
        ]);

        $response = $this->actingAs($owner)
            ->withoutMiddleware()
            ->postJson(route('owner.tenant.bulk-portal-access.store'), [
                'tenant_ids' => [
                    $draftTenant->id,
                    $activeTenant->id,
                    $verifiedTenant->id,
                    $missingEmailTenant->id,
                    $deletedUserTenant->id,
                    $foreignTenant->id,
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.sent_count', 2)
            ->assertJsonPath('data.skipped_count', 4);

        Mail::assertSent(TenantPortalActionMail::class, 2);

        $results = collect($response->json('data.results'));

        $this->assertSame('sent', $results->firstWhere('tenant_id', $draftTenant->id)['status']);
        $this->assertSame('sent', $results->firstWhere('tenant_id', $activeTenant->id)['status']);
        $this->assertSame('skipped', $results->firstWhere('tenant_id', $verifiedTenant->id)['status']);
        $this->assertSame('skipped', $results->firstWhere('tenant_id', $missingEmailTenant->id)['status']);
        $this->assertSame('skipped', $results->firstWhere('tenant_id', $deletedUserTenant->id)['status']);
        $this->assertSame('skipped', $results->firstWhere('tenant_id', $foreignTenant->id)['status']);
    }

    private function createOwnerUser(string $email): User
    {
        $owner = User::query()->forceCreate([
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => $email,
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

    private function createTenantForOwner(int $ownerUserId, array $userOverrides = [], array $tenantOverrides = []): array
    {
        $user = User::query()->forceCreate(array_merge([
            'first_name' => 'Portal',
            'last_name' => 'Tenant',
            'email' => 'tenant' . random_int(1000, 9999) . '@example.test',
            'password' => Hash::make('secret123!'),
            'contact_number' => '26000000' . random_int(100, 999),
            'status' => USER_STATUS_ACTIVE,
            'role' => USER_ROLE_TENANT,
            'owner_user_id' => $ownerUserId,
            'email_verified_at' => now(),
        ], $userOverrides));

        $tenant = Tenant::query()->forceCreate(array_merge([
            'user_id' => $user->id,
            'owner_user_id' => $ownerUserId,
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
