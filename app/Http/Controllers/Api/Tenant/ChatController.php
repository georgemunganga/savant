<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\User;
use App\Services\ChatService;
use App\Traits\ResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    use ResponseTrait;

    public function index()
    {
        $tenantUser = auth()->user();
        $owner = User::query()
            ->select(['id', 'first_name', 'last_name', 'last_seen'])
            ->where('id', $tenantUser->owner_user_id)
            ->firstOrFail();

        $messages = Chat::query()
            ->where(function ($query) use ($tenantUser, $owner) {
                $query->where('sender_id', $tenantUser->id)
                    ->where('receiver_id', $owner->id);
            })
            ->orWhere(function ($query) use ($tenantUser, $owner) {
                $query->where('sender_id', $owner->id)
                    ->where('receiver_id', $tenantUser->id);
            })
            ->orderBy('created_at')
            ->get(['id', 'sender_id', 'receiver_id', 'message', 'is_seen', 'created_at']);

        $unreadCount = (clone $messages)
            ->where('sender_id', $owner->id)
            ->where('is_seen', 0)
            ->count();

        Chat::query()
            ->where('sender_id', $owner->id)
            ->where('receiver_id', $tenantUser->id)
            ->update(['is_seen' => 1]);

        $lastMessage = $messages->last();
        $data['threads'] = [[
            'id' => $owner->id,
            'user' => trim($owner->first_name . ' ' . $owner->last_name),
            'preview' => $lastMessage?->message ?? __('Start a conversation'),
            'unread' => $unreadCount,
            'online' => !empty($owner->last_seen) && Carbon::parse($owner->last_seen)->gt(now()->subMinutes(5)),
        ]];
        $data['messages'] = [
            $owner->id => $messages->map(function ($message) use ($tenantUser, $owner) {
                return [
                    'id' => $message->id,
                    'sender' => (int) $message->sender_id === (int) $tenantUser->id
                        ? 'You'
                        : trim($owner->first_name . ' ' . $owner->last_name),
                    'body' => $message->message,
                ];
            })->values(),
        ];

        return $this->success($data);
    }

    public function store(Request $request)
    {
        $request->merge([
            'receiver_id' => auth()->user()->owner_user_id,
        ]);

        $chatService = new ChatService();
        return $chatService->store($request);
    }
}
