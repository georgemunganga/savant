<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\NoticeBoard;
use App\Traits\ResponseTrait;
use Carbon\Carbon;

class NoticeController extends Controller
{
    use ResponseTrait;

    public function index()
    {
        $today = date('Y-m-d');
        $tenant = auth()->user()->tenant;

        $notices = NoticeBoard::query()
            ->where(function ($query) use ($tenant) {
                $query->where('unit_id', $tenant->unit_id)
                    ->orWhere('unit_all', ACTIVE);
            })
            ->where('start_date', '<=', $today)
            ->where('owner_user_id', auth()->user()->owner_user_id)
            ->latest()
            ->select(['id', 'title', 'details', 'start_date', 'end_date'])
            ->get()
            ->map(function ($notice) {
                $isCurrent = !$notice->end_date || Carbon::parse($notice->end_date)->gte(Carbon::today());

                return [
                    'id' => $notice->id,
                    'title' => $notice->title,
                    'message' => $notice->details,
                    'published_at' => Carbon::parse($notice->start_date)->format('d M Y'),
                    'severity' => $isCurrent ? __('Current') : __('Archived'),
                ];
            })
            ->values();

        return $this->success([
            'notices' => $notices,
        ]);
    }
}
