<?php

namespace App\Http\Middleware;

use App\Traits\ResponseTrait;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TenantPortal
{
    use ResponseTrait;

    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if (!$user || (int) $user->role !== USER_ROLE_TENANT) {
            if ($request->wantsJson()) {
                return $this->error([], __('Unauthorized'));
            }

            abort(403);
        }

        $tenant = $user->tenant;
        if (
            (int) $user->status !== USER_STATUS_ACTIVE ||
            is_null($tenant) ||
            !in_array((int) $tenant->status, [TENANT_STATUS_DRAFT, TENANT_STATUS_ACTIVE], true)
        ) {
            if ($request->wantsJson()) {
                return response()->json([
                    'status' => false,
                    'data' => [],
                    'message' => __('Your account is inactive. Please contact with admin'),
                ], 403);
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('login')->with('error', __('Your account is inactive. Please contact with admin'));
        }

        return $next($request);
    }
}
