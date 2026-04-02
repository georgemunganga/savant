<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeactivationRequest;
use App\Models\User;
use App\Services\SmsMail\MailService;
use App\Traits\ResponseTrait;

class AccountController extends Controller
{
    use ResponseTrait;

    public function requestDeactivation(DeactivationRequest $request)
    {
        $tenantUser = auth()->user();
        $owner = User::query()->find($tenantUser->owner_user_id);

        $tenantName = trim($tenantUser->first_name . ' ' . $tenantUser->last_name) ?: $tenantUser->email;
        $body = __('Tenant :name requested account deactivation. Reason: :reason', [
            'name' => $tenantName,
            'reason' => $request->reason,
        ]);

        if ($owner) {
            addNotification(
                __('Tenant deactivation request'),
                $body,
                null,
                null,
                $owner->id,
                $tenantUser->id
            );

            if (getOption('send_email_status', 0) == ACTIVE && !empty($owner->email)) {
                MailService::sendTenantPortalActionMail(
                    [$owner->email],
                    __('Tenant deactivation request'),
                    __('A tenant requested account deactivation in the Savant portal.'),
                    $owner->id,
                    [
                        'title' => __('Tenant deactivation request'),
                        'button_label' => __('Review tenant'),
                        'button_url' => rtrim((string) config('app.url'), '/') . '/owner/tenant',
                        'meta' => [
                            __('Tenant') => $tenantName,
                            __('Email') => $tenantUser->email,
                            __('Reason') => $request->reason,
                        ],
                    ]
                );
            }
        }

        return $this->success([], __('Your deactivation request has been sent. Savant will review it shortly.'));
    }
}
