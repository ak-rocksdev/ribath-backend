<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $notifications = $this->notificationService->listNotifications(
            $request->user(),
            $request->only(['is_read', 'category', 'priority', 'per_page'])
        );

        return $this->paginatedResponse($notifications, 'Notifications retrieved');
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->notificationService->getUnreadCount($request->user());

        return $this->successResponse(['unread_count' => $count], 'Unread count retrieved');
    }

    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return $this->errorResponse('Notification not found', null, 404);
        }

        $notification = $this->notificationService->markAsRead($notification);

        return $this->successResponse($notification, 'Notification marked as read');
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $updatedCount = $this->notificationService->markAllAsRead($request->user());

        return $this->successResponse(
            ['updated_count' => $updatedCount],
            'All notifications marked as read'
        );
    }

    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return $this->errorResponse('Notification not found', null, 404);
        }

        $this->notificationService->deleteNotification($notification);

        return $this->successResponse(null, 'Notification deleted');
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'string|uuid',
        ]);

        $deletedCount = $this->notificationService->bulkDelete(
            $request->user(),
            $request->input('ids')
        );

        return $this->successResponse(
            ['deleted_count' => $deletedCount],
            'Notifications deleted'
        );
    }
}
