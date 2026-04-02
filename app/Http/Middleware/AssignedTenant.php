<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AssignedTenant
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = auth()->user()?->tenant;

        if (
            is_null($tenant) ||
            is_null($tenant->property_id) ||
            is_null($tenant->unit_id) ||
            !in_array((int) $tenant->status, [TENANT_STATUS_DRAFT, TENANT_STATUS_ACTIVE], true)
        ) {
            return response()->json([
                'status' => false,
                'data' => [],
                'message' => __('You do not have an assigned stay yet. Please explore listings or contact Savant support.'),
            ], 403);
        }

        return $next($request);
    }
}
