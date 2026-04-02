<?php

namespace App\Services;

use App\Models\Notification;
use App\Traits\ResponseTrait;
use Exception;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    use ResponseTrait;
    public function status($id)
    {
        DB::beginTransaction();
        try {
            $notification = Notification::query()
                ->where('id', $id)
                ->where(function ($query) {
                    $query->where('user_id', auth()->id())
                        ->orWhereNull('user_id');
                })
                ->firstOrFail();
            $notification->is_seen = ACTIVE;
            $notification->save();
            DB::commit();
            return $this->success([]);
        } catch (Exception $e) {
            DB::rollBack();
            $message = getErrorMessage($e, $e->getMessage());
            return $this->error([],  $message);
        }
    }
}
