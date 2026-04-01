<?php

namespace App\Http\Middleware;

use App\Traits\ResponseTrait;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class Tenant
{
    use ResponseTrait;
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if (Auth::user()->role != USER_ROLE_TENANT) {
            if ($request->wantsJson()) {
                $message = __("Unauthorized");
                return $this->error([], $message);
            } else {
                abort('403');
            }
        }

        $tenant = Auth::user()->tenant;
        if (
            Auth::user()->status != USER_STATUS_ACTIVE ||
            is_null($tenant) ||
            (int) $tenant->status !== TENANT_STATUS_ACTIVE ||
            is_null($tenant->property_id) ||
            is_null($tenant->unit_id)
        ) {
            if ($request->wantsJson()) {
                return $this->error([], __('Your account is inactive. Please contact with admin'));
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('login')->with('error', __('Your account is inactive. Please contact with admin'));
        }

        return $next($request);
    }
}
