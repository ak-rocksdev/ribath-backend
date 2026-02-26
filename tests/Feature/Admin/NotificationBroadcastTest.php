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

function createBroadcastAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    return $user;
}

// -- NewNotificationEvent --

test('NewNotificationEvent broadcasts on correct private channel', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->create(['user_id' => $user->id]);

    $event = new NewNotificationEvent($notification);

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(1)
        ->and($channels[0]->name)->toBe('private-App.Models.User.'.$user->id);
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
        'registrant_type' => 'guardian',
    ]);

    $listener = new SendRegistrationNotification();
    $listener->handle(new RegistrationCreated($registration));

    $adminNotifications = Notification::where('user_id', $admin->id)->get();
    $pengurusNotifications = Notification::where('user_id', $pengurus->id)->get();
    $regularNotifications = Notification::where('user_id', $regularUser->id)->get();

    expect($adminNotifications)->toHaveCount(1)
        ->and($pengurusNotifications)->toHaveCount(1)
        ->and($regularNotifications)->toHaveCount(0);
});

test('registration notification has correct content', function () {
    Event::fake([NewNotificationEvent::class]);

    $admin = createBroadcastAdmin();

    $registration = Registration::factory()->create([
        'full_name' => 'Muhammad Ibrahim',
        'registration_number' => 'PSB-2026-00100',
        'preferred_program' => 'tahfidz',
        'registrant_type' => 'guardian',
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

    $admin = createBroadcastAdmin();

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
        'registrant_type' => 'guardian',
        'full_name' => 'Test Student',
        'birth_place' => 'Solo',
        'birth_date' => '2010-01-15',
        'gender' => 'L',
        'preferred_program' => 'regular',
        'guardian_name' => 'Test Parent',
        'guardian_phone' => '081234567890',
        'info_source' => 'website',
    ])->assertStatus(201);

    Event::assertDispatched(RegistrationCreated::class);
});
