<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class NotificationService
{
    public function listNotifications(User $user, array $filters): LengthAwarePaginator
    {
        $query = Notification::forUser($user->id)->notExpired();

        if (isset($filters['is_read'])) {
            $query->where('is_read', filter_var($filters['is_read'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['category'])) {
            $query->byCategory($filters['category']);
        }

        if (! empty($filters['priority'])) {
            $query->byPriority($filters['priority']);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 20);
    }

    public function getUnreadCount(User $user): int
    {
        return Notification::forUser($user->id)->unread()->notExpired()->count();
    }

    public function createNotification(array $data): Notification
    {
        return Notification::create($data);
    }

    public function markAsRead(Notification $notification): Notification
    {
        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return $notification->fresh();
    }

    public function markAllAsRead(User $user): int
    {
        return Notification::forUser($user->id)
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    public function deleteNotification(Notification $notification): void
    {
        $notification->delete();
    }

    public function bulkDelete(User $user, array $notificationIds): int
    {
        return Notification::forUser($user->id)
            ->whereIn('id', $notificationIds)
            ->delete();
    }
}
