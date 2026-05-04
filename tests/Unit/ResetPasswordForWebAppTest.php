<?php

namespace Tests\Unit;

use App\Models\User;
use App\Notifications\ResetPasswordForWebApp;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ResetPasswordForWebAppTest extends TestCase
{
    public function test_password_reset_notification_uses_the_web_app_url(): void
    {
        config([
            'app.frontend_url' => 'https://savantapartments.com',
            'app.url' => 'https://admin.savantapartments.com',
        ]);

        $user = new User([
            'email' => 'georgemunganga@gmail.com',
            'first_name' => 'George',
            'last_name' => 'Munganga',
        ]);

        $mailMessage = (new ResetPasswordForWebApp('reset-token'))->toMail($user);

        $this->assertSame('https://savantapartments.com/set-password?email=georgemunganga%40gmail.com&token=reset-token', $mailMessage->actionUrl);
    }

    public function test_user_model_sends_the_custom_web_app_password_reset_notification(): void
    {
        Notification::fake();

        $user = new User([
            'email' => 'tenant@example.com',
            'first_name' => 'Tenant',
            'last_name' => 'User',
        ]);

        $user->sendPasswordResetNotification('abc123');

        Notification::assertSentTo($user, ResetPasswordForWebApp::class);
    }
}
