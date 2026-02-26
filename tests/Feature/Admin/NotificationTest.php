<?php

use App\Models\Notification;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
});

function createNotificationUser(): User
{
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    return $user;
}

// -- List Notifications --

test('unauthenticated user cannot list notifications', function () {
    $this->getJson('/api/v1/notifications')
        ->assertStatus(401);
});

test('authenticated user can list their notifications', function () {
    $user = createNotificationUser();
    Notification::factory()->count(3)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect(count($response->json('data')))->toBe(3);
});

test('user only sees their own notifications', function () {
    $user = createNotificationUser();
    $otherUser = User::factory()->create();

    Notification::factory()->count(2)->create(['user_id' => $user->id]);
    Notification::factory()->count(3)->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications')
        ->assertStatus(200);

    expect(count($response->json('data')))->toBe(2);
});

test('notifications are paginated', function () {
    $user = createNotificationUser();
    Notification::factory()->count(25)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications?per_page=10')
        ->assertStatus(200);

    expect(count($response->json('data')))->toBe(10)
        ->and($response->json('meta.total'))->toBe(25)
        ->and($response->json('meta.last_page'))->toBe(3);
});

test('notifications are ordered by created_at desc', function () {
    $user = createNotificationUser();
    $old = Notification::factory()->create(['user_id' => $user->id, 'created_at' => now()->subHour()]);
    $new = Notification::factory()->create(['user_id' => $user->id, 'created_at' => now()]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications')
        ->assertStatus(200);

    $ids = collect($response->json('data'))->pluck('id')->toArray();
    expect($ids[0])->toBe($new->id)
        ->and($ids[1])->toBe($old->id);
});

test('filter notifications by is_read', function () {
    $user = createNotificationUser();
    Notification::factory()->count(2)->create(['user_id' => $user->id, 'is_read' => false]);
    Notification::factory()->count(3)->read()->create(['user_id' => $user->id]);

    $unread = $this->actingAs($user)
        ->getJson('/api/v1/notifications?is_read=false')
        ->assertStatus(200);
    expect(count($unread->json('data')))->toBe(2);

    $read = $this->actingAs($user)
        ->getJson('/api/v1/notifications?is_read=true')
        ->assertStatus(200);
    expect(count($read->json('data')))->toBe(3);
});

test('filter notifications by category', function () {
    $user = createNotificationUser();
    Notification::factory()->count(2)->create(['user_id' => $user->id, 'category' => 'psb']);
    Notification::factory()->create(['user_id' => $user->id, 'category' => 'system']);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications?category=psb')
        ->assertStatus(200);

    expect(count($response->json('data')))->toBe(2);
});

test('filter notifications by priority', function () {
    $user = createNotificationUser();
    Notification::factory()->urgent()->count(2)->create(['user_id' => $user->id]);
    Notification::factory()->create(['user_id' => $user->id, 'priority' => 'low']);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications?priority=urgent')
        ->assertStatus(200);

    expect(count($response->json('data')))->toBe(2);
});

test('expired notifications are excluded', function () {
    $user = createNotificationUser();
    Notification::factory()->create(['user_id' => $user->id]);
    Notification::factory()->expired()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications')
        ->assertStatus(200);

    expect(count($response->json('data')))->toBe(1);
});

// -- Unread Count --

test('unread count returns correct number', function () {
    $user = createNotificationUser();
    Notification::factory()->count(3)->create(['user_id' => $user->id, 'is_read' => false]);
    Notification::factory()->read()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications/unread-count')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect($response->json('data.unread_count'))->toBe(3);
});

test('unread count excludes expired notifications', function () {
    $user = createNotificationUser();
    Notification::factory()->count(2)->create(['user_id' => $user->id, 'is_read' => false]);
    Notification::factory()->expired()->create(['user_id' => $user->id, 'is_read' => false]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications/unread-count')
        ->assertStatus(200);

    expect($response->json('data.unread_count'))->toBe(2);
});

test('unread count excludes other users notifications', function () {
    $user = createNotificationUser();
    $otherUser = User::factory()->create();

    Notification::factory()->count(2)->create(['user_id' => $user->id, 'is_read' => false]);
    Notification::factory()->count(5)->create(['user_id' => $otherUser->id, 'is_read' => false]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications/unread-count')
        ->assertStatus(200);

    expect($response->json('data.unread_count'))->toBe(2);
});

// -- Mark As Read --

test('mark notification as read', function () {
    $user = createNotificationUser();
    $notification = Notification::factory()->create(['user_id' => $user->id, 'is_read' => false]);

    $response = $this->actingAs($user)
        ->patchJson("/api/v1/notifications/{$notification->id}/read")
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect($response->json('data.is_read'))->toBeTrue()
        ->and($response->json('data.read_at'))->not->toBeNull();
});

test('cannot mark another users notification as read', function () {
    $user = createNotificationUser();
    $otherUser = User::factory()->create();
    $notification = Notification::factory()->create(['user_id' => $otherUser->id]);

    $this->actingAs($user)
        ->patchJson("/api/v1/notifications/{$notification->id}/read")
        ->assertStatus(404);
});

// -- Mark All As Read --

test('mark all notifications as read', function () {
    $user = createNotificationUser();
    Notification::factory()->count(3)->create(['user_id' => $user->id, 'is_read' => false]);

    $response = $this->actingAs($user)
        ->patchJson('/api/v1/notifications/mark-all-read')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect($response->json('data.updated_count'))->toBe(3);
    expect(Notification::forUser($user->id)->unread()->count())->toBe(0);
});

test('mark all as read only affects own notifications', function () {
    $user = createNotificationUser();
    $otherUser = User::factory()->create();

    Notification::factory()->count(2)->create(['user_id' => $user->id, 'is_read' => false]);
    Notification::factory()->count(3)->create(['user_id' => $otherUser->id, 'is_read' => false]);

    $this->actingAs($user)
        ->patchJson('/api/v1/notifications/mark-all-read')
        ->assertStatus(200);

    expect(Notification::forUser($otherUser->id)->unread()->count())->toBe(3);
});

// -- Delete --

test('delete own notification', function () {
    $user = createNotificationUser();
    $notification = Notification::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/notifications/{$notification->id}")
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect(Notification::find($notification->id))->toBeNull();
});

test('cannot delete another users notification', function () {
    $user = createNotificationUser();
    $otherUser = User::factory()->create();
    $notification = Notification::factory()->create(['user_id' => $otherUser->id]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/notifications/{$notification->id}")
        ->assertStatus(404);
});

// -- Bulk Delete --

test('bulk delete own notifications', function () {
    $user = createNotificationUser();
    $notifications = Notification::factory()->count(3)->create(['user_id' => $user->id]);

    $ids = $notifications->pluck('id')->toArray();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/notifications/bulk-delete', ['ids' => $ids])
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect($response->json('data.deleted_count'))->toBe(3);
});

test('bulk delete ignores other users notification ids', function () {
    $user = createNotificationUser();
    $otherUser = User::factory()->create();

    $ownNotification = Notification::factory()->create(['user_id' => $user->id]);
    $otherNotification = Notification::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/notifications/bulk-delete', [
            'ids' => [$ownNotification->id, $otherNotification->id],
        ])
        ->assertStatus(200);

    expect($response->json('data.deleted_count'))->toBe(1);
    expect(Notification::find($otherNotification->id))->not->toBeNull();
});

test('bulk delete validates ids are required', function () {
    $user = createNotificationUser();

    $this->actingAs($user)
        ->postJson('/api/v1/notifications/bulk-delete', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ids']);
});

// -- Notification response shape --

test('notification response has correct shape', function () {
    $user = createNotificationUser();
    Notification::factory()->psb()->withMetadata([
        'registration_number' => 'PSB-2026-00079',
        'full_name' => 'Test Student',
    ])->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications')
        ->assertStatus(200);

    $notification = $response->json('data.0');
    expect($notification)->toHaveKey('id')
        ->and($notification)->toHaveKey('user_id')
        ->and($notification)->toHaveKey('type')
        ->and($notification)->toHaveKey('title')
        ->and($notification)->toHaveKey('message')
        ->and($notification)->toHaveKey('priority')
        ->and($notification)->toHaveKey('category')
        ->and($notification)->toHaveKey('metadata')
        ->and($notification)->toHaveKey('is_read')
        ->and($notification)->toHaveKey('read_at')
        ->and($notification)->toHaveKey('expires_at')
        ->and($notification)->toHaveKey('action_url')
        ->and($notification)->toHaveKey('action_label')
        ->and($notification)->toHaveKey('created_at')
        ->and($notification)->toHaveKey('updated_at')
        ->and($notification['metadata'])->toHaveKey('registration_number');
});
