# Notification System + Laravel Reverb Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a real-time notification system with Laravel Reverb WebSocket broadcasting, replacing the Supabase notification infrastructure.

**Architecture:** Notifications are stored in a `notifications` table (UUID PK, per-user, per-school). When a PSB registration is created, an event fires a listener that creates notifications for admins and broadcasts them via Reverb. Frontend receives real-time pushes via Laravel Echo on private channels, with REST API fallback for CRUD operations.

**Tech Stack:** Laravel 12, Laravel Reverb (WebSocket), Laravel Broadcasting, Spatie Permission, Sanctum auth, Pest tests, Laravel Echo + pusher-js (frontend)

---

## Task 1: Install Laravel Reverb & Broadcasting

**Files:**
- Modify: `.env`
- Created by installer: `config/broadcasting.php`, `config/reverb.php`, `routes/channels.php`

**Step 1: Install broadcasting (includes Reverb)**

Run:
```bash
echo "yes" | php artisan install:broadcasting
```

This publishes `config/broadcasting.php`, `config/reverb.php`, `routes/channels.php`, and adds `laravel/reverb` to `composer.json`.

**Step 2: Verify installation**

Run:
```bash
php artisan about | grep -i reverb
```
Expected: Reverb listed as installed package.

**Step 3: Update `.env` broadcast connection**

Change `BROADCAST_CONNECTION=log` to `BROADCAST_CONNECTION=reverb`.

Verify the installer added these env vars (if not, add them):
```
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST="localhost"
REVERB_PORT=8080
REVERB_SCHEME=http
```

**Step 4: Run tests to verify nothing broke**

Run: `php artisan test`
Expected: All 193 existing tests pass.

**Step 5: Commit**

```bash
git add -A && git commit -m "Install Laravel Reverb and broadcasting"
```

---

## Task 2: Create notifications migration

**Files:**
- Create: `database/migrations/2026_02_26_100000_create_notifications_table.php`

**Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignUuid('school_id')->nullable()->constrained()->onDelete('set null');
            $table->string('type', 20)->default('info');
            $table->string('title', 255);
            $table->text('message');
            $table->string('priority', 10)->default('medium');
            $table->string('category', 20)->default('system');
            $table->jsonb('metadata')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('action_url', 500)->nullable();
            $table->string('action_label', 100)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_read', 'created_at']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
```

**Step 2: Run migration**

Run: `php artisan migrate`
Expected: `notifications` table created.

**Step 3: Run tests**

Run: `php artisan test`
Expected: All 193 tests still pass.

**Step 4: Commit**

```bash
git add database/migrations/2026_02_26_100000_create_notifications_table.php && git commit -m "Add notifications table migration"
```

---

## Task 3: Create Notification model and factory

**Files:**
- Create: `app/Models/Notification.php`
- Create: `database/factories/NotificationFactory.php`

**Step 1: Write the Notification model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory, HasUuids;

    public const TYPE_INFO = 'info';
    public const TYPE_SUCCESS = 'success';
    public const TYPE_WARNING = 'warning';
    public const TYPE_ERROR = 'error';
    public const TYPE_SYSTEM = 'system';

    public const TYPES = [
        self::TYPE_INFO,
        self::TYPE_SUCCESS,
        self::TYPE_WARNING,
        self::TYPE_ERROR,
        self::TYPE_SYSTEM,
    ];

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_MEDIUM,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    public const CATEGORY_SYSTEM = 'system';
    public const CATEGORY_ACADEMIC = 'academic';
    public const CATEGORY_FINANCIAL = 'financial';
    public const CATEGORY_ADMINISTRATIVE = 'administrative';
    public const CATEGORY_PSB = 'psb';

    public const CATEGORIES = [
        self::CATEGORY_SYSTEM,
        self::CATEGORY_ACADEMIC,
        self::CATEGORY_FINANCIAL,
        self::CATEGORY_ADMINISTRATIVE,
        self::CATEGORY_PSB,
    ];

    protected $fillable = [
        'user_id',
        'school_id',
        'type',
        'title',
        'message',
        'priority',
        'category',
        'metadata',
        'is_read',
        'read_at',
        'expires_at',
        'action_url',
        'action_label',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeByPriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
```

**Step 2: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'school_id' => null,
            'type' => fake()->randomElement(Notification::TYPES),
            'title' => fake()->sentence(4),
            'message' => fake()->paragraph(1),
            'priority' => fake()->randomElement(Notification::PRIORITIES),
            'category' => fake()->randomElement(Notification::CATEGORIES),
            'metadata' => null,
            'is_read' => false,
            'read_at' => null,
            'expires_at' => null,
            'action_url' => null,
            'action_label' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn () => [
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    public function unread(): static
    {
        return $this->state(fn () => [
            'is_read' => false,
            'read_at' => null,
        ]);
    }

    public function urgent(): static
    {
        return $this->state(fn () => [
            'priority' => Notification::PRIORITY_URGENT,
        ]);
    }

    public function psb(): static
    {
        return $this->state(fn () => [
            'type' => Notification::TYPE_INFO,
            'category' => Notification::CATEGORY_PSB,
            'action_url' => '/admin/pendaftaran-masuk',
            'action_label' => 'Lihat Pendaftaran',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function withMetadata(array $metadata): static
    {
        return $this->state(fn () => [
            'metadata' => $metadata,
        ]);
    }
}
```

**Step 3: Add notifications relation to User model**

In `app/Models/User.php`, add:
```php
use App\Models\Notification as AppNotification;

public function notifications(): HasMany
{
    return $this->hasMany(AppNotification::class);
}
```

Note: Import `App\Models\Notification as AppNotification` to avoid conflict with Laravel's built-in `Illuminate\Notifications\Notifiable` trait which also defines a `notifications()` method. Actually — the `Notifiable` trait already defines `notifications()`. We should **rename** our relation to `appNotifications()` to avoid the conflict:

```php
public function appNotifications(): HasMany
{
    return $this->hasMany(\App\Models\Notification::class);
}
```

**Step 4: Run tests**

Run: `php artisan test`
Expected: All 193 tests pass.

**Step 5: Commit**

```bash
git add app/Models/Notification.php database/factories/NotificationFactory.php app/Models/User.php && git commit -m "Add Notification model and factory"
```

---

## Task 4: Create NotificationService

**Files:**
- Create: `app/Services/NotificationService.php`

**Step 1: Write the service**

```php
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
```

**Step 2: Commit**

```bash
git add app/Services/NotificationService.php && git commit -m "Add NotificationService"
```

---

## Task 5: Create NotificationController + routes

**Files:**
- Create: `app/Http/Controllers/Api/Admin/NotificationController.php`
- Modify: `routes/api.php`

**Step 1: Write the controller**

```php
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
```

**Step 2: Add routes to `routes/api.php`**

Add inside the `Route::prefix('v1')` group, after the existing routes:

```php
use App\Http\Controllers\Api\Admin\NotificationController;

// Notification routes
Route::prefix('notifications')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::patch('/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::delete('/{notification}', [NotificationController::class, 'destroy']);
    Route::post('/bulk-delete', [NotificationController::class, 'bulkDelete']);
});
```

**Step 3: Run tests**

Run: `php artisan test`
Expected: All 193 existing tests pass.

**Step 4: Commit**

```bash
git add app/Http/Controllers/Api/Admin/NotificationController.php routes/api.php && git commit -m "Add NotificationController and routes"
```

---

## Task 6: Write notification CRUD tests

**Files:**
- Create: `tests/Feature/Admin/NotificationTest.php`

**Step 1: Write the test file**

```php
<?php

use App\Models\Notification;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
});

function createAuthenticatedUser(): User
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
    $user = createAuthenticatedUser();
    Notification::factory()->count(3)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect(count($response->json('data')))->toBe(3);
});

test('user only sees their own notifications', function () {
    $user = createAuthenticatedUser();
    $otherUser = User::factory()->create();

    Notification::factory()->count(2)->create(['user_id' => $user->id]);
    Notification::factory()->count(3)->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications')
        ->assertStatus(200);

    expect(count($response->json('data')))->toBe(2);
});

test('notifications are paginated', function () {
    $user = createAuthenticatedUser();
    Notification::factory()->count(25)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications?per_page=10')
        ->assertStatus(200);

    expect(count($response->json('data')))->toBe(10)
        ->and($response->json('meta.total'))->toBe(25)
        ->and($response->json('meta.last_page'))->toBe(3);
});

test('notifications are ordered by created_at desc', function () {
    $user = createAuthenticatedUser();
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
    $user = createAuthenticatedUser();
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
    $user = createAuthenticatedUser();
    Notification::factory()->count(2)->create(['user_id' => $user->id, 'category' => 'psb']);
    Notification::factory()->create(['user_id' => $user->id, 'category' => 'system']);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications?category=psb')
        ->assertStatus(200);

    expect(count($response->json('data')))->toBe(2);
});

test('filter notifications by priority', function () {
    $user = createAuthenticatedUser();
    Notification::factory()->urgent()->count(2)->create(['user_id' => $user->id]);
    Notification::factory()->create(['user_id' => $user->id, 'priority' => 'low']);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications?priority=urgent')
        ->assertStatus(200);

    expect(count($response->json('data')))->toBe(2);
});

test('expired notifications are excluded', function () {
    $user = createAuthenticatedUser();
    Notification::factory()->create(['user_id' => $user->id]);
    Notification::factory()->expired()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications')
        ->assertStatus(200);

    expect(count($response->json('data')))->toBe(1);
});

// -- Unread Count --

test('unread count returns correct number', function () {
    $user = createAuthenticatedUser();
    Notification::factory()->count(3)->create(['user_id' => $user->id, 'is_read' => false]);
    Notification::factory()->read()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications/unread-count')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect($response->json('data.unread_count'))->toBe(3);
});

test('unread count excludes expired notifications', function () {
    $user = createAuthenticatedUser();
    Notification::factory()->count(2)->create(['user_id' => $user->id, 'is_read' => false]);
    Notification::factory()->expired()->create(['user_id' => $user->id, 'is_read' => false]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/notifications/unread-count')
        ->assertStatus(200);

    expect($response->json('data.unread_count'))->toBe(2);
});

test('unread count excludes other users notifications', function () {
    $user = createAuthenticatedUser();
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
    $user = createAuthenticatedUser();
    $notification = Notification::factory()->create(['user_id' => $user->id, 'is_read' => false]);

    $response = $this->actingAs($user)
        ->patchJson("/api/v1/notifications/{$notification->id}/read")
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect($response->json('data.is_read'))->toBeTrue()
        ->and($response->json('data.read_at'))->not->toBeNull();
});

test('cannot mark another users notification as read', function () {
    $user = createAuthenticatedUser();
    $otherUser = User::factory()->create();
    $notification = Notification::factory()->create(['user_id' => $otherUser->id]);

    $this->actingAs($user)
        ->patchJson("/api/v1/notifications/{$notification->id}/read")
        ->assertStatus(404);
});

// -- Mark All As Read --

test('mark all notifications as read', function () {
    $user = createAuthenticatedUser();
    Notification::factory()->count(3)->create(['user_id' => $user->id, 'is_read' => false]);

    $response = $this->actingAs($user)
        ->patchJson('/api/v1/notifications/mark-all-read')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect($response->json('data.updated_count'))->toBe(3);
    expect(Notification::forUser($user->id)->unread()->count())->toBe(0);
});

test('mark all as read only affects own notifications', function () {
    $user = createAuthenticatedUser();
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
    $user = createAuthenticatedUser();
    $notification = Notification::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/notifications/{$notification->id}")
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect(Notification::find($notification->id))->toBeNull();
});

test('cannot delete another users notification', function () {
    $user = createAuthenticatedUser();
    $otherUser = User::factory()->create();
    $notification = Notification::factory()->create(['user_id' => $otherUser->id]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/notifications/{$notification->id}")
        ->assertStatus(404);
});

// -- Bulk Delete --

test('bulk delete own notifications', function () {
    $user = createAuthenticatedUser();
    $notifications = Notification::factory()->count(3)->create(['user_id' => $user->id]);

    $ids = $notifications->pluck('id')->toArray();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/notifications/bulk-delete', ['ids' => $ids])
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect($response->json('data.deleted_count'))->toBe(3);
});

test('bulk delete ignores other users notification ids', function () {
    $user = createAuthenticatedUser();
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
    $user = createAuthenticatedUser();

    $this->actingAs($user)
        ->postJson('/api/v1/notifications/bulk-delete', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ids']);
});

// -- Notification response shape --

test('notification response has correct shape', function () {
    $user = createAuthenticatedUser();
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
```

**Step 2: Run tests**

Run: `php artisan test tests/Feature/Admin/NotificationTest.php`
Expected: All new tests pass.

Run: `php artisan test`
Expected: All tests pass (193 + ~25 new).

**Step 3: Commit**

```bash
git add tests/Feature/Admin/NotificationTest.php && git commit -m "Add notification CRUD tests"
```

---

## Task 7: Create broadcast event and PSB listener

**Files:**
- Create: `app/Events/NewNotificationEvent.php`
- Create: `app/Events/RegistrationCreated.php`
- Create: `app/Listeners/SendRegistrationNotification.php`
- Modify: `app/Providers/EventServiceProvider.php` (or `bootstrap/app.php` if using Laravel 12 style)
- Modify: `app/Services/PsbService.php` (fire event in `register()`)
- Modify: `routes/channels.php`

**Step 1: Write the broadcast event**

```php
<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewNotificationEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Notification $notification
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->notification->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'type' => $this->notification->type,
            'title' => $this->notification->title,
            'message' => $this->notification->message,
            'priority' => $this->notification->priority,
            'category' => $this->notification->category,
            'metadata' => $this->notification->metadata,
            'is_read' => $this->notification->is_read,
            'action_url' => $this->notification->action_url,
            'action_label' => $this->notification->action_label,
            'created_at' => $this->notification->created_at->toISOString(),
        ];
    }
}
```

**Step 2: Write the RegistrationCreated event**

```php
<?php

namespace App\Events;

use App\Models\Registration;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RegistrationCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Registration $registration
    ) {}
}
```

**Step 3: Write the listener**

```php
<?php

namespace App\Listeners;

use App\Events\NewNotificationEvent;
use App\Events\RegistrationCreated;
use App\Models\Notification;
use App\Models\User;

class SendRegistrationNotification
{
    public function handle(RegistrationCreated $event): void
    {
        $registration = $event->registration;

        $adminUsers = User::permission('manage-registrations')->get();

        foreach ($adminUsers as $adminUser) {
            $notification = Notification::create([
                'user_id' => $adminUser->id,
                'school_id' => $registration->school_id,
                'type' => Notification::TYPE_INFO,
                'title' => "Pendaftaran Baru: {$registration->full_name}",
                'message' => "Pendaftaran {$registration->registration_number} telah masuk. Program: {$registration->preferred_program}, Tipe: {$registration->registrant_type}.",
                'priority' => Notification::PRIORITY_MEDIUM,
                'category' => Notification::CATEGORY_PSB,
                'metadata' => [
                    'registration_id' => $registration->id,
                    'registration_number' => $registration->registration_number,
                    'full_name' => $registration->full_name,
                    'preferred_program' => $registration->preferred_program,
                    'registrant_type' => $registration->registrant_type,
                    'guardian_phone' => $registration->guardian_phone,
                ],
                'action_url' => '/admin/pendaftaran-masuk',
                'action_label' => 'Lihat Pendaftaran',
            ]);

            NewNotificationEvent::dispatch($notification);
        }
    }
}
```

**Step 4: Register the event-listener mapping**

Check if `app/Providers/EventServiceProvider.php` exists. In Laravel 12, events are auto-discovered, but we should register explicitly for clarity.

If `EventServiceProvider.php` exists, add to `$listen`:
```php
\App\Events\RegistrationCreated::class => [
    \App\Listeners\SendRegistrationNotification::class,
],
```

If Laravel 12 auto-discovery is used (no EventServiceProvider), the listener will be auto-discovered based on the type-hinted `handle()` method.

**Step 5: Fire event in PsbService::register()**

In `app/Services/PsbService.php`, at the end of the `register()` method, after the `Registration::create()` call:

```php
use App\Events\RegistrationCreated;

// At end of register() method, before return:
$registration = Registration::create([...]);

RegistrationCreated::dispatch($registration);

return $registration;
```

**Step 6: Configure channel auth in `routes/channels.php`**

```php
<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
```

**Step 7: Run tests**

Run: `php artisan test`
Expected: All existing tests pass.

**Step 8: Commit**

```bash
git add app/Events/ app/Listeners/ app/Services/PsbService.php routes/channels.php && git commit -m "Add broadcasting events and PSB registration listener"
```

---

## Task 8: Write broadcasting and auto-notification tests

**Files:**
- Create: `tests/Feature/Admin/NotificationBroadcastTest.php`

**Step 1: Write the test file**

```php
<?php

use App\Events\NewNotificationEvent;
use App\Events\RegistrationCreated;
use App\Listeners\SendRegistrationNotification;
use App\Models\Notification;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
});

// -- NewNotificationEvent --

test('NewNotificationEvent broadcasts on correct private channel', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->create(['user_id' => $user->id]);

    $event = new NewNotificationEvent($notification);

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(1)
        ->and($channels[0]->name)->toBe('App.Models.User.'.$user->id);
});

test('NewNotificationEvent broadcast data has correct shape', function () {
    $notification = Notification::factory()->psb()->withMetadata([
        'registration_number' => 'PSB-2026-00001',
    ])->create();

    $event = new NewNotificationEvent($notification);
    $data = $event->broadcastWith();

    expect($data)->toHaveKey('id')
        ->and($data)->toHaveKey('type')
        ->and($data)->toHaveKey('title')
        ->and($data)->toHaveKey('message')
        ->and($data)->toHaveKey('priority')
        ->and($data)->toHaveKey('category')
        ->and($data)->toHaveKey('metadata')
        ->and($data)->toHaveKey('is_read')
        ->and($data)->toHaveKey('created_at')
        ->and($data['metadata'])->toHaveKey('registration_number');
});

test('NewNotificationEvent uses correct broadcast name', function () {
    $notification = Notification::factory()->create();
    $event = new NewNotificationEvent($notification);

    expect($event->broadcastAs())->toBe('notification.created');
});

// -- SendRegistrationNotification listener --

test('registration creation creates notifications for admins', function () {
    Event::fake([NewNotificationEvent::class]);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $pengurus = User::factory()->create();
    $pengurus->assignRole('pengurus_pesantren');

    $regularUser = User::factory()->create();

    $registration = Registration::factory()->create([
        'full_name' => 'Ahmad Test',
        'registration_number' => 'PSB-2026-00099',
        'preferred_program' => 'regular',
        'registrant_type' => 'wali',
    ]);

    $listener = new SendRegistrationNotification();
    $listener->handle(new RegistrationCreated($registration));

    // super_admin and pengurus_pesantren both have manage-registrations
    $adminNotifications = Notification::where('user_id', $admin->id)->get();
    $pengurusNotifications = Notification::where('user_id', $pengurus->id)->get();
    $regularNotifications = Notification::where('user_id', $regularUser->id)->get();

    expect($adminNotifications)->toHaveCount(1)
        ->and($pengurusNotifications)->toHaveCount(1)
        ->and($regularNotifications)->toHaveCount(0);
});

test('registration notification has correct content', function () {
    Event::fake([NewNotificationEvent::class]);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $registration = Registration::factory()->create([
        'full_name' => 'Muhammad Ibrahim',
        'registration_number' => 'PSB-2026-00100',
        'preferred_program' => 'tahfidz',
        'registrant_type' => 'wali',
        'guardian_phone' => '081234567890',
    ]);

    $listener = new SendRegistrationNotification();
    $listener->handle(new RegistrationCreated($registration));

    $notification = Notification::where('user_id', $admin->id)->first();

    expect($notification->type)->toBe('info')
        ->and($notification->category)->toBe('psb')
        ->and($notification->priority)->toBe('medium')
        ->and($notification->title)->toContain('Muhammad Ibrahim')
        ->and($notification->message)->toContain('PSB-2026-00100')
        ->and($notification->metadata['registration_number'])->toBe('PSB-2026-00100')
        ->and($notification->metadata['full_name'])->toBe('Muhammad Ibrahim')
        ->and($notification->metadata['preferred_program'])->toBe('tahfidz')
        ->and($notification->metadata['guardian_phone'])->toBe('081234567890')
        ->and($notification->action_url)->toBe('/admin/pendaftaran-masuk')
        ->and($notification->action_label)->toBe('Lihat Pendaftaran');
});

test('registration notification dispatches broadcast event', function () {
    Event::fake([NewNotificationEvent::class]);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $registration = Registration::factory()->create();

    $listener = new SendRegistrationNotification();
    $listener->handle(new RegistrationCreated($registration));

    Event::assertDispatched(NewNotificationEvent::class, function ($event) use ($admin) {
        return $event->notification->user_id === $admin->id;
    });
});

test('public PSB registration fires RegistrationCreated event', function () {
    Event::fake([RegistrationCreated::class]);

    $this->seed(\Database\Seeders\SchoolSeeder::class);

    $period = RegistrationPeriod::factory()->create([
        'is_active' => true,
        'registration_open' => now()->subDay(),
        'registration_close' => now()->addDay(),
    ]);

    $this->postJson('/api/v1/public/psb/register', [
        'registrant_type' => 'wali',
        'full_name' => 'Test Student',
        'birth_place' => 'Solo',
        'birth_date' => '2010-01-15',
        'gender' => 'M',
        'preferred_program' => 'regular',
        'guardian_name' => 'Test Parent',
        'guardian_phone' => '081234567890',
        'info_source' => 'website',
    ])->assertStatus(201);

    Event::assertDispatched(RegistrationCreated::class);
});
```

**Step 2: Run tests**

Run: `php artisan test tests/Feature/Admin/NotificationBroadcastTest.php`
Expected: All broadcast tests pass.

Run: `php artisan test`
Expected: All tests pass.

**Step 3: Commit**

```bash
git add tests/Feature/Admin/NotificationBroadcastTest.php && git commit -m "Add notification broadcasting and auto-dispatch tests"
```

---

## Task 9: Frontend — Install Echo + create notification service

**Files:**
- Install: `laravel-echo`, `pusher-js` npm packages (in frontend repo)
- Create: `src/services/api/laravelNotificationService.ts`
- Create: `src/lib/echo.ts`

**Step 1: Install Echo and pusher-js**

Run from frontend repo:
```bash
cd C:\laragon\www\ribath-masjid-hub && npm install laravel-echo pusher-js
```

**Step 2: Create Echo configuration**

Create `src/lib/echo.ts`:
```typescript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

import { apiConfig } from './env';
import { getStoredToken } from '@/services/api/apiClient';

// Make Pusher available globally (required by Echo)
(window as unknown as Record<string, unknown>).Pusher = Pusher;

let echoInstance: Echo | null = null;

export function getEcho(): Echo {
  if (echoInstance) return echoInstance;

  const token = getStoredToken();

  echoInstance = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${apiConfig.baseUrl}/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: token ? `Bearer ${token}` : '',
        Accept: 'application/json',
      },
    },
  });

  return echoInstance;
}

export function disconnectEcho(): void {
  if (echoInstance) {
    echoInstance.disconnect();
    echoInstance = null;
  }
}
```

**Step 3: Create Laravel notification service**

Create `src/services/api/laravelNotificationService.ts`:
```typescript
import { apiClient } from './apiClient';

export interface LaravelNotification {
  id: string;
  user_id: number;
  school_id: string | null;
  type: 'info' | 'success' | 'warning' | 'error' | 'system';
  title: string;
  message: string;
  priority: 'low' | 'medium' | 'high' | 'urgent';
  category: 'system' | 'academic' | 'financial' | 'administrative' | 'psb';
  metadata: Record<string, unknown> | null;
  is_read: boolean;
  read_at: string | null;
  expires_at: string | null;
  action_url: string | null;
  action_label: string | null;
  created_at: string;
  updated_at: string;
}

export interface NotificationListResponse {
  data: LaravelNotification[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export const laravelNotificationService = {
  async listNotifications(params?: {
    is_read?: boolean;
    category?: string;
    priority?: string;
    per_page?: number;
    page?: number;
  }): Promise<NotificationListResponse> {
    const query = new URLSearchParams();
    if (params?.is_read !== undefined) query.set('is_read', String(params.is_read));
    if (params?.category) query.set('category', params.category);
    if (params?.priority) query.set('priority', params.priority);
    if (params?.per_page) query.set('per_page', String(params.per_page));
    if (params?.page) query.set('page', String(params.page));

    const queryString = query.toString();
    const path = `/notifications${queryString ? `?${queryString}` : ''}`;
    const response = await apiClient.get<LaravelNotification[]>(path);

    return {
      data: response.data ?? [],
      meta: response.meta ?? { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    };
  },

  async getUnreadCount(): Promise<number> {
    const response = await apiClient.get<{ unread_count: number }>('/notifications/unread-count');
    return response.data?.unread_count ?? 0;
  },

  async markAsRead(notificationId: string): Promise<LaravelNotification> {
    const response = await apiClient.patch<LaravelNotification>(`/notifications/${notificationId}/read`);
    return response.data;
  },

  async markAllAsRead(): Promise<number> {
    const response = await apiClient.patch<{ updated_count: number }>('/notifications/mark-all-read');
    return response.data?.updated_count ?? 0;
  },

  async deleteNotification(notificationId: string): Promise<void> {
    await apiClient.delete(`/notifications/${notificationId}`);
  },

  async bulkDelete(ids: string[]): Promise<number> {
    const response = await apiClient.post<{ deleted_count: number }>('/notifications/bulk-delete', { ids });
    return response.data?.deleted_count ?? 0;
  },
};
```

**Step 4: Add env vars to frontend `.env`**

Add to `C:\laragon\www\ribath-masjid-hub\.env`:
```
VITE_REVERB_APP_KEY=<from backend .env REVERB_APP_KEY>
VITE_REVERB_HOST="ribath-backend.local"
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME="http"
```

**Step 5: Commit frontend changes**

```bash
cd C:\laragon\www\ribath-masjid-hub
git add src/lib/echo.ts src/services/api/laravelNotificationService.ts .env package.json package-lock.json
git commit -m "Add Laravel Echo config and notification service"
```

---

## Task 10: Frontend — Rewrite useRealtimeNotifications hook

**Files:**
- Modify: `src/hooks/useRealtimeNotifications.ts`

**Step 1: Rewrite the hook**

Replace the entire contents of `useRealtimeNotifications.ts`:

```typescript
import { useState, useCallback, useEffect, useRef } from 'react';
import { getEcho, disconnectEcho } from '@/lib/echo';
import { laravelNotificationService, LaravelNotification } from '@/services/api/laravelNotificationService';

export interface Notification {
  id: string;
  type: 'info' | 'success' | 'warning' | 'error' | 'system';
  title: string;
  message: string;
  timestamp: Date;
  read: boolean;
  priority: 'low' | 'medium' | 'high' | 'urgent';
  category: string;
  metadata?: Record<string, unknown>;
  expiresAt?: Date | undefined;
  actionUrl?: string;
  actionLabel?: string;
}

interface NotificationSubscriptionConfig {
  userId: string;
  userRole: string;
  enableSound?: boolean;
  enableToast?: boolean;
  enableBadge?: boolean;
  categories?: string[];
  priorities?: string[];
}

interface NotificationState {
  notifications: Notification[];
  unreadCount: number;
  isConnected: boolean;
  connectionStatus: 'connecting' | 'connected' | 'disconnected';
  lastActivity: Date | null;
}

function mapLaravelToNotification(item: LaravelNotification): Notification {
  return {
    id: item.id,
    type: item.type,
    title: item.title,
    message: item.message,
    timestamp: new Date(item.created_at),
    read: item.is_read,
    priority: item.priority,
    category: item.category,
    metadata: item.metadata ?? undefined,
    expiresAt: item.expires_at ? new Date(item.expires_at) : undefined,
    actionUrl: item.action_url ?? undefined,
    actionLabel: item.action_label ?? undefined,
  };
}

export function useRealtimeNotifications(config: NotificationSubscriptionConfig) {
  const [state, setState] = useState<NotificationState>({
    notifications: [],
    unreadCount: 0,
    isConnected: false,
    connectionStatus: 'disconnected',
    lastActivity: null,
  });

  const channelRef = useRef<ReturnType<ReturnType<typeof getEcho>['private']> | null>(null);

  const fetchNotifications = useCallback(async () => {
    if (!config.userId) return;
    try {
      const [listResponse, unreadCount] = await Promise.all([
        laravelNotificationService.listNotifications({ per_page: 50 }),
        laravelNotificationService.getUnreadCount(),
      ]);

      setState(prev => ({
        ...prev,
        notifications: listResponse.data.map(mapLaravelToNotification),
        unreadCount,
        lastActivity: new Date(),
      }));
    } catch {
      // Silently fail — user may be logged out
    }
  }, [config.userId]);

  // Subscribe to Reverb private channel
  useEffect(() => {
    if (!config.userId) return;

    fetchNotifications();

    try {
      const echo = getEcho();
      const channel = echo.private(`App.Models.User.${config.userId}`);
      channelRef.current = channel;

      setState(prev => ({ ...prev, connectionStatus: 'connecting' }));

      channel.listen('.notification.created', (data: LaravelNotification) => {
        const mapped = mapLaravelToNotification(data);

        setState(prev => ({
          ...prev,
          notifications: [mapped, ...prev.notifications],
          unreadCount: prev.unreadCount + 1,
          isConnected: true,
          connectionStatus: 'connected',
          lastActivity: new Date(),
        }));
      });

      // Reverb connection events
      channel.subscribed(() => {
        setState(prev => ({ ...prev, isConnected: true, connectionStatus: 'connected' }));
      });

      channel.error(() => {
        setState(prev => ({ ...prev, isConnected: false, connectionStatus: 'disconnected' }));
      });
    } catch {
      setState(prev => ({ ...prev, connectionStatus: 'disconnected' }));
    }

    return () => {
      if (channelRef.current) {
        channelRef.current = null;
      }
      disconnectEcho();
    };
  }, [config.userId, fetchNotifications]);

  const markAsRead = useCallback(async (notificationId: string) => {
    try {
      await laravelNotificationService.markAsRead(notificationId);
      setState(prev => ({
        ...prev,
        notifications: prev.notifications.map(n =>
          n.id === notificationId ? { ...n, read: true } : n
        ),
        unreadCount: Math.max(0, prev.unreadCount - 1),
      }));
    } catch {
      // Ignore errors
    }
  }, []);

  const markAllAsRead = useCallback(async () => {
    try {
      await laravelNotificationService.markAllAsRead();
      setState(prev => ({
        ...prev,
        notifications: prev.notifications.map(n => ({ ...n, read: true })),
        unreadCount: 0,
      }));
    } catch {
      // Ignore errors
    }
  }, []);

  const deleteNotification = useCallback(async (notificationId: string) => {
    try {
      await laravelNotificationService.deleteNotification(notificationId);
      setState(prev => {
        const deleted = prev.notifications.find(n => n.id === notificationId);
        return {
          ...prev,
          notifications: prev.notifications.filter(n => n.id !== notificationId),
          unreadCount: deleted && !deleted.read ? prev.unreadCount - 1 : prev.unreadCount,
        };
      });
    } catch {
      // Ignore errors
    }
  }, []);

  const sendNotification = useCallback(async (_notification: Omit<Notification, 'id' | 'timestamp'>) => {
    // Manual notification sending not implemented from frontend
  }, []);

  const reconnect = useCallback(() => {
    disconnectEcho();
    setState(prev => ({ ...prev, connectionStatus: 'connecting' }));
    // Re-trigger useEffect by updating a dependency — simplest: refetch
    fetchNotifications();
    try {
      const echo = getEcho();
      const channel = echo.private(`App.Models.User.${config.userId}`);
      channelRef.current = channel;

      channel.listen('.notification.created', (data: LaravelNotification) => {
        const mapped = mapLaravelToNotification(data);
        setState(prev => ({
          ...prev,
          notifications: [mapped, ...prev.notifications],
          unreadCount: prev.unreadCount + 1,
          isConnected: true,
          connectionStatus: 'connected',
          lastActivity: new Date(),
        }));
      });

      channel.subscribed(() => {
        setState(prev => ({ ...prev, isConnected: true, connectionStatus: 'connected' }));
      });
    } catch {
      setState(prev => ({ ...prev, connectionStatus: 'disconnected' }));
    }
  }, [config.userId, fetchNotifications]);

  const refresh = useCallback(async () => {
    await fetchNotifications();
  }, [fetchNotifications]);

  return {
    ...state,
    markAsRead,
    markAllAsRead,
    deleteNotification,
    sendNotification,
    reconnect,
    refresh,
  };
}

// Hook for notification preferences (localStorage-only)
export function useNotificationPreferences(userId: string) {
  const [preferences, setPreferences] = useState({
    enableSound: true,
    enableToast: true,
    enableBadge: true,
    categories: ['system', 'academic', 'financial', 'psb'],
    priorities: ['medium', 'high', 'urgent'],
    quietHours: {
      enabled: false,
      start: '22:00',
      end: '07:00',
    },
  });

  const updatePreferences = useCallback(async (newPreferences: Partial<typeof preferences>) => {
    setPreferences(prev => {
      const updated = { ...prev, ...newPreferences };
      localStorage.setItem(`notification_prefs_${userId}`, JSON.stringify(updated));
      return updated;
    });
  }, [userId]);

  useEffect(() => {
    try {
      const saved = localStorage.getItem(`notification_prefs_${userId}`);
      if (saved) {
        setPreferences(JSON.parse(saved));
      }
    } catch {
      // Ignore parse errors
    }
  }, [userId]);

  return {
    preferences,
    updatePreferences,
  };
}
```

**Step 2: Update NotificationCenter category labels**

In `src/components/shared/NotificationCenter.tsx`, update `CategoryLabels` to include `psb`:

```typescript
const CategoryLabels: Record<string, string> = {
  system: 'Sistem',
  academic: 'Akademik',
  financial: 'Keuangan',
  administrative: 'Administrasi',
  psb: 'PSB',
};
```

Also update the categories list in `PreferencesPanel`:
```typescript
{['system', 'academic', 'financial', 'administrative', 'psb'].map(category => (
```

And update `MetadataLabels` to use English field names from Laravel:
```typescript
const MetadataLabels: Record<string, string> = {
  registration_number: 'No. Pendaftaran',
  full_name: 'Nama',
  preferred_program: 'Program',
  registrant_type: 'Pendaftar',
  status: 'Status',
  guardian_phone: 'No. HP Wali',
};
```

**Step 3: Commit frontend**

```bash
cd C:\laragon\www\ribath-masjid-hub
git add src/hooks/useRealtimeNotifications.ts src/components/shared/NotificationCenter.tsx
git commit -m "Wire notifications to Laravel API with Reverb real-time"
```

---

## Task 11: Backend — Add broadcasting auth route

**Files:**
- Modify: `routes/api.php` or `routes/channels.php`

**Step 1: Verify broadcasting auth route exists**

After `install:broadcasting`, Laravel auto-registers `POST /broadcasting/auth`. However, since our API is prefixed with `/api/v1`, the frontend auth endpoint should be `{baseUrl}/broadcasting/auth`.

The broadcasting auth route is registered at `/broadcasting/auth` (outside the `/api/v1` prefix). This means the frontend Echo config should use:
```typescript
authEndpoint: `${apiConfig.baseUrl.replace('/api/v1', '')}/broadcasting/auth`,
```

Or we register a custom auth route inside our API prefix. The simpler approach: update the frontend `echo.ts` auth endpoint to point to the correct path.

**Step 2: Ensure Sanctum guard works for broadcasting**

In `routes/channels.php`, the channel auth uses the default guard. We need to ensure broadcasting auth uses Sanctum. In `config/broadcasting.php`, the auth middleware should include `auth:sanctum`.

Check `config/broadcasting.php` after installation and ensure the auth middleware is:
```php
'middleware' => ['auth:sanctum'],
```

If the default is `['web']`, change it to `['auth:sanctum']`.

**Step 3: Run tests**

Run: `php artisan test`
Expected: All tests pass.

**Step 4: Commit**

```bash
git add config/broadcasting.php routes/channels.php && git commit -m "Configure broadcasting auth with Sanctum"
```

---

## Task 12: Supabase notification data import

**Files:**
- Create: `scripts/import_notifications.sql`

**Step 1: Write the import script**

Map the 9 real PSB notification records from Supabase. The Supabase admin user UUIDs need to be mapped to Laravel user IDs (by email match). Write the SQL script that:
- Looks up the Laravel user ID for each admin email
- Inserts the 9 PSB notifications with the new schema
- Maps `read` -> `is_read`, adds `read_at` where read=true
- Maps metadata field names (Indonesian -> English where needed)
- Sets `school_id` from the default school

This step is data-dependent and should be written after verifying which admin emails exist in the Laravel database.

**Step 2: Run locally**

Run: `PGPASSWORD=password psql -U postgres -h 127.0.0.1 -d ribath_app_local -f scripts/import_notifications.sql`

**Step 3: Commit**

```bash
git add scripts/import_notifications.sql && git commit -m "Add Supabase notification data import script"
```

---

## Task 13: Production deployment — Reverb + Nginx

**Step 1: Push code to main**

```bash
git push origin main
```

**Step 2: Run deploy script on server**

```bash
ssh ribath-prod "bash /srv/www/ribath-backend/scripts/deploy.sh --seed"
```

**Step 3: Add Reverb env vars to production `.env`**

SSH to server, edit `/srv/www/ribath-backend/shared/env/.env`:
```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<generate>
REVERB_APP_KEY=<generate>
REVERB_APP_SECRET=<generate>
REVERB_HOST="0.0.0.0"
REVERB_PORT=8080
REVERB_SCHEME=https
```

**Step 4: Add Nginx WebSocket proxy**

Add to the Nginx server block for `apiribath.hyperscore.cloud`:

```nginx
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 60s;
    proxy_send_timeout 60s;
}
```

Reload Nginx: `sudo systemctl reload nginx`

**Step 5: Create Supervisor config for Reverb**

Create `/etc/supervisor/conf.d/ribath-reverb.conf`:
```ini
[program:ribath-reverb]
command=php /srv/www/ribath-backend/current/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/ribath-reverb.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start ribath-reverb
```

**Step 6: Update frontend production env**

Update `VITE_REVERB_*` vars in frontend `.env.production`:
```
VITE_REVERB_APP_KEY=<same as backend REVERB_APP_KEY>
VITE_REVERB_HOST="apiribath.hyperscore.cloud"
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME="https"
```

Deploy frontend.

**Step 7: Run import script on production**

```bash
PGPASSWORD=<prod_password> psql -U ak_rocks -h 127.0.0.1 -d ribath_app_prod -f scripts/import_notifications.sql
```

**Step 8: Verify**

- Check WebSocket: `curl -i -N -H "Connection: Upgrade" -H "Upgrade: websocket" https://apiribath.hyperscore.cloud/app/`
- Check API: `curl https://apiribath.hyperscore.cloud/api/v1/notifications -H "Authorization: Bearer <token>"`
- Check Reverb logs: `sudo tail -f /var/log/ribath-reverb.log`
- Browser test: Open frontend, check notification bell shows connection status "Terhubung"

---

## Summary

| Task | Description | Est. Tests |
|------|-------------|-----------|
| 1 | Install Reverb & Broadcasting | 0 (verify existing pass) |
| 2 | Notifications migration | 0 (verify existing pass) |
| 3 | Notification model + factory | 0 (verify existing pass) |
| 4 | NotificationService | 0 |
| 5 | NotificationController + routes | 0 (verify existing pass) |
| 6 | Notification CRUD tests | ~25 |
| 7 | Broadcast event + PSB listener | 0 (verify existing pass) |
| 8 | Broadcasting tests | ~5 |
| 9 | Frontend: Echo + service | 0 |
| 10 | Frontend: Rewrite hook + UI tweaks | 0 |
| 11 | Broadcasting auth config | 0 (verify existing pass) |
| 12 | Supabase data import | 0 |
| 13 | Production deployment | 0 |

**Total new tests:** ~30
**Total after implementation:** ~223 (193 existing + 30 new)
