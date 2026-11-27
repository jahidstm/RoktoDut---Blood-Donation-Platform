<?php

namespace App\Notifications;

use App\Models\BloodRequest;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/** Sent to donors who offered to help a request its owner has withdrawn. */
class BloodRequestCancelledNotification extends Notification
{
    public function __construct(private readonly BloodRequest $bloodRequest) {}

    public function via(object $notifiable): array
    {
        $driver = (string) config('broadcasting.default', 'null');

        return in_array($driver, ['null', 'log'], true) ? ['database'] : ['database', 'broadcast'];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toArray(object $notifiable): array
    {
        $group = $this->bloodRequest->blood_group?->value ?? (string) $this->bloodRequest->blood_group;

        return [
            'request_id' => $this->bloodRequest->id,
            'status' => 'cancelled',
            'message' => "{$group} রক্তের যে অনুরোধে আপনি সাড়া দিয়েছিলেন সেটি বাতিল করা হয়েছে। আর যেতে হবে না।",
            'url' => route('requests.show', $this->bloodRequest->id),
            'created_at' => now()->toISOString(),
        ];
    }
}
