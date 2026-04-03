<?php

namespace Tests\Unit;

use App\Services\TenantAccessService;
use Tests\TestCase;

class TenantAccessServiceTest extends TestCase
{
    public function test_it_prefers_the_configured_frontend_url(): void
    {
        config([
            'app.frontend_url' => 'https://savantapartments.com',
            'app.url' => 'https://admin.savantapartments.com',
        ]);

        $service = new TenantAccessService();
        $url = $service->buildWebappUrl('/set-password', [
            'email' => 'tenant@example.com',
            'token' => 'abc123',
        ]);

        $this->assertSame(
            'https://savantapartments.com/set-password?email=tenant%40example.com&token=abc123',
            $url
        );
    }

    public function test_it_strips_the_admin_subdomain_when_frontend_url_is_not_configured(): void
    {
        config([
            'app.frontend_url' => null,
            'app.url' => 'https://admin.savantapartments.com',
        ]);

        $service = new TenantAccessService();
        $url = $service->buildWebappUrl('/set-password', [
            'email' => 'tenant@example.com',
            'token' => 'abc123',
        ]);

        $this->assertSame(
            'https://savantapartments.com/set-password?email=tenant%40example.com&token=abc123',
            $url
        );
    }
}
