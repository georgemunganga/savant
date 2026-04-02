<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SmsMail\MailService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Password;

class TenantAccessService
{
    public function buildWebappUrl(string $path, array $query = []): string
    {
        $baseUrl = env('WEBAPP_URL')
            ?: env('FRONTEND_URL')
            ?: (str_contains((string) config('app.url'), 'localhost')
                ? 'http://localhost:5173'
                : config('app.url'));

        $baseUrl = rtrim((string) $baseUrl, '/');
        $path = '/' . ltrim($path, '/');
        $queryString = http_build_query($query);

        return $queryString ? "{$baseUrl}{$path}?{$queryString}" : "{$baseUrl}{$path}";
    }

    public function issuePasswordSetupToken(User $user): string
    {
        return Password::broker()->createToken($user);
    }

    public function sendAccountSetupEmail(User $user, ?int $ownerUserId = null): string
    {
        $ownerUserId = $ownerUserId ?? $user->owner_user_id ?? $user->id;
        $token = $this->issuePasswordSetupToken($user);
        $setupLink = $this->buildWebappUrl('/set-password', [
            'email' => $user->email,
            'token' => $token,
        ]);

        $emails = [$user->email];
        $subject = __('Set up your Savant account');
        $message = __('Your Savant account is ready. Use the link below to create your password and sign in to the tenant portal.');
        $template = EmailTemplate::query()
            ->where('owner_user_id', $ownerUserId)
            ->where('category', EMAIL_TEMPLATE_ACCOUNT_SETUP)
            ->where('status', ACTIVE)
            ->first();

        if ($template) {
            $customizedFieldsArray = [
                '{{email}}' => $user->email,
                '{{user_name}}' => $user->name,
                '{{set_password_link}}' => $setupLink,
                '{{app_name}}' => getOption('app_name'),
            ];
            $content = getEmailTemplate($template->body, $customizedFieldsArray);
            MailService::sendCustomizeMail($emails, $template->subject, $content);
        } else {
            MailService::sendTenantPortalActionMail(
                $emails,
                $subject,
                $message,
                $ownerUserId,
                [
                    'title' => __('Finish setting up your account'),
                    'button_label' => __('Set Password'),
                    'button_url' => $setupLink,
                    'email' => $user->email,
                ]
            );
        }

        return $token;
    }

    public function sendAssignmentEmail(
        Tenant $tenant,
        ?Property $property = null,
        ?PropertyUnit $unit = null,
        ?int $ownerUserId = null
    ): void {
        $tenant->loadMissing('user');
        $property = $property ?? $tenant->property;
        $unit = $unit ?? $tenant->unit;

        if (!$tenant->user || !$property || !$unit) {
            return;
        }

        $ownerUserId = $ownerUserId ?? $tenant->owner_user_id ?? $tenant->user->owner_user_id;
        $emails = [$tenant->user->email];
        $subject = __('Your Savant stay has been assigned');
        $message = __('Your Savant stay details are now available in the tenant portal.');
        $template = EmailTemplate::query()
            ->where('owner_user_id', $ownerUserId)
            ->where('category', EMAIL_TEMPLATE_TENANT_ASSIGNMENT)
            ->where('status', ACTIVE)
            ->first();

        $stayStart = $tenant->lease_start_date
            ? Carbon::parse($tenant->lease_start_date)->format('d M Y')
            : __('To be confirmed');
        $stayEnd = $tenant->lease_end_date
            ? Carbon::parse($tenant->lease_end_date)->format('d M Y')
            : __('Open-ended');
        $portalLink = $this->buildWebappUrl('/auth');

        if ($template) {
            $customizedFieldsArray = [
                '{{user_name}}' => $tenant->user->name,
                '{{property_name}}' => $property->name,
                '{{unit_name}}' => $unit->unit_name,
                '{{stay_start}}' => $stayStart,
                '{{stay_end}}' => $stayEnd,
                '{{app_name}}' => getOption('app_name'),
            ];
            $content = getEmailTemplate($template->body, $customizedFieldsArray);
            MailService::sendCustomizeMail($emails, $template->subject, $content);
        } else {
            MailService::sendTenantPortalActionMail(
                $emails,
                $subject,
                $message,
                $ownerUserId,
                [
                    'title' => __('Your stay details are ready'),
                    'button_label' => __('Open Tenant Portal'),
                    'button_url' => $portalLink,
                    'meta' => [
                        __('Property') => $property->name,
                        __('Unit') => $unit->unit_name,
                        __('Stay start') => $stayStart,
                        __('Stay end') => $stayEnd,
                    ],
                ]
            );
        }
    }
}
