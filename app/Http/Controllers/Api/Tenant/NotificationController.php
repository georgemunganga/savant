<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Traits\ResponseTrait;
use Carbon\Carbon;

class NotificationController extends Controller
{
    use ResponseTrait;

    public function index()
    {
        $userId = auth()->id();

        $notifications = Notification::query()
            ->leftJoin('users', 'users.id', '=', 'notifications.sender_id')
            ->leftJoin('file_managers', function ($join) {
                $join->on('file_managers.origin_id', '=', 'notifications.sender_id')
                    ->where('file_managers.origin_type', '=', 'App\Models\User');
            })
            ->where(function ($query) use ($userId) {
                $query->where('notifications.user_id', $userId)
                    ->orWhereNull('notifications.user_id');
            })
            ->orderByDesc('notifications.id')
            ->select([
                'notifications.id',
                'notifications.title',
                'notifications.body',
                'notifications.url',
                'notifications.is_seen',
                'notifications.created_at',
                'users.first_name',
                'users.last_name',
                'file_managers.folder_name',
                'file_managers.file_name',
            ])
            ->get()
            ->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'title' => $notification->title,
                    'body' => $notification->body,
                    'url' => $notification->url,
                    'is_seen' => (bool) $notification->is_seen,
                    'time' => Carbon::parse($notification->created_at)->diffForHumans(),
                    'sender_name' => trim(($notification->first_name ?? '') . ' ' . ($notification->last_name ?? '')) ?: __('Savant'),
                    'sender_image' => !empty($notification->folder_name) && !empty($notification->file_name)
                        ? getFileUrl($notification->folder_name, $notification->file_name)
                        : null,
                ];
            })
            ->values();

        return $this->success([
            'notifications' => $notifications,
        ]);
    }

    public function markAllRead()
    {
        Notification::query()
            ->where(function ($query) {
                $query->where('notifications.user_id', auth()->id())
                    ->orWhereNull('notifications.user_id');
            })
            ->update(['is_seen' => ACTIVE]);

        return $this->success([], __('All notifications marked as seen.'));
    }
}
